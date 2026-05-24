"""
BReCAI Federated Learning Server — FastAPI Application.

Orchestrates federated learning rounds with modality-aware aggregation.
Designed for real hospital clients to connect to over REST API.

Architecture constants (must match training code):
    HIDDEN=128, N_HEADS=4, ATT_DIM=128, CLIN_OUT=64,
    DROP_RATE=0.55, CONCH_DIM=512, CLIN_DIM=19
"""
from __future__ import annotations

import asyncio
import base64
import io
import logging
import os
import time
from contextlib import asynccontextmanager
from datetime import datetime, timezone
from typing import Optional

import torch
from dotenv import load_dotenv
from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware

from app.aggregator import modality_aware_fedavg
from app.blockchain import hash_weights, record_round_on_chain, verify_round
from app.models import (
    ClientInfo,
    ClientModality,
    ClientsResponse,
    ClientStatus,
    ErrorResponse,
    HistoryResponse,
    MetricsResponse,
    RegisterClientRequest,
    RegisterClientResponse,
    RoundMetric,
    ServerStatus,
    StartRoundRequest,
    StartRoundResponse,
    StatusResponse,
    StopResponse,
    SubmitWeightsRequest,
    SubmitWeightsResponse,
    VerifyRoundResponse,
)
from app.state import Submission, fl_state

load_dotenv()

# --- Logging setup ---
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("fl.main")

# --- Configuration ---
FL_SECRET = os.getenv("FL_SECRET", "changeme")
WEIGHTS_DIR = "/app/weights"
ROUND_TIMEOUT_S = 120  # Max seconds to wait for client submissions

# Model architecture constants (for reference/validation)
MODEL_CONSTANTS = {
    "HIDDEN": 128,
    "N_HEADS": 4,
    "ATT_DIM": 128,
    "CLIN_OUT": 64,
    "DROP_RATE": 0.55,
    "CONCH_DIM": 512,
    "CLIN_DIM": 19,
}

# Background task handle for round orchestration
_round_task: Optional[asyncio.Task] = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan: startup and shutdown."""
    os.makedirs(WEIGHTS_DIR, exist_ok=True)
    os.makedirs("/app/logs", exist_ok=True)
    log.info("FL Server started. Status: %s", fl_state.status.value)
    log.info("Model constants: %s", MODEL_CONSTANTS)
    yield
    # Shutdown: cancel any running round task
    global _round_task
    if _round_task and not _round_task.done():
        _round_task.cancel()
    log.info("FL Server shutting down.")


app = FastAPI(
    title="BReCAI Federated Learning Server",
    description="Aggregator server for federated breast cancer detection model training",
    version="1.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# --- Authentication ---

def _verify_secret(x_fl_secret: Optional[str]) -> None:
    """Verify the FL secret header. Raises 401 if invalid."""
    if not x_fl_secret or x_fl_secret != FL_SECRET:
        raise HTTPException(status_code=401, detail="Invalid or missing X-FL-Secret header")


# --- Background round orchestration ---

async def _run_fl_session():
    """
    Background task that manages the FL round lifecycle:
    1. Wait for min_clients to register
    2. Wait for submissions (with timeout)
    3. Aggregate weights
    4. Advance to next round or complete
    """
    global _round_task

    try:
        total_rounds = fl_state.total_rounds
        min_clients = fl_state.min_clients

        for round_num in range(fl_state.current_round, total_rounds + 1):
            fl_state.current_round = round_num
            fl_state.set_status(ServerStatus.WAITING_CLIENTS)
            log.info("Round %d/%d: Waiting for %d clients...", round_num, total_rounds, min_clients)

            # Wait for minimum clients to register
            while fl_state.get_registered_client_count() < min_clients:
                if fl_state.status == ServerStatus.IDLE:
                    log.info("Session stopped during client wait.")
                    return
                await asyncio.sleep(2)

            # Move to training phase
            fl_state.set_status(ServerStatus.TRAINING)
            fl_state.round_start_time = time.time()
            log.info("Round %d: Training phase started. %d clients registered.",
                     round_num, fl_state.get_registered_client_count())

            # Wait for submissions with timeout
            deadline = time.time() + ROUND_TIMEOUT_S
            registered_count = fl_state.get_registered_client_count()

            while time.time() < deadline:
                if fl_state.status == ServerStatus.IDLE:
                    log.info("Session stopped during training wait.")
                    return

                submissions = fl_state.get_submissions_for_round(round_num)
                if len(submissions) >= registered_count:
                    break
                await asyncio.sleep(2)

            # Timeout reached or all submitted — aggregate
            submissions = fl_state.get_submissions_for_round(round_num)
            if not submissions:
                log.warning("Round %d: No submissions received. Skipping aggregation.", round_num)
                fl_state.advance_round()
                continue

            # Mark dropped clients
            submitted_ids = {s.client_id for s in submissions}
            for client in fl_state.get_clients():
                if client.client_id not in submitted_ids and client.status != ClientStatus.DROPPED:
                    client.status = ClientStatus.DROPPED
                    log.warning("Client %s (%s) dropped (no submission).", client.client_id, client.name)

            # Aggregation phase
            fl_state.set_status(ServerStatus.AGGREGATING)
            log.info("Round %d: Aggregating %d submissions...", round_num, len(submissions))

            # Load current global weights as fallback
            global_weights_path = os.path.join(WEIGHTS_DIR, "global_weights.pt")
            current_global = None
            if os.path.exists(global_weights_path):
                try:
                    current_global = torch.load(global_weights_path, map_location="cpu", weights_only=False)
                except Exception as e:
                    log.error("Failed to load current global weights: %s", e)

            # Run modality-aware FedAvg
            aggregated_sd, agg_meta = modality_aware_fedavg(submissions, current_global)

            if not aggregated_sd:
                log.error("Round %d: Aggregation produced empty result.", round_num)
                fl_state.advance_round()
                continue

            # Save aggregated weights
            tmp_path = global_weights_path + ".tmp"
            torch.save(aggregated_sd, tmp_path)
            os.replace(tmp_path, global_weights_path)

            # Also save round-specific snapshot
            round_path = os.path.join(WEIGHTS_DIR, f"global_weights_r{round_num}.pt")
            torch.save(aggregated_sd, round_path)

            # Compute weights hash for blockchain
            weights_hash = hash_weights(aggregated_sd)

            # Calculate average accuracy from valid submissions
            valid_accs = [s.local_accuracy for s in submissions if s.local_accuracy >= 0.5]
            avg_accuracy = sum(valid_accs) / len(valid_accs) if valid_accs else None

            # Record on blockchain (non-blocking, fire-and-forget)
            tx_hash = record_round_on_chain(
                round_number=round_num,
                weights_hash=weights_hash,
                accuracy=avg_accuracy,
                num_clients=len(submissions),
            )

            # Build round history entry
            round_entry = {
                "round_number": round_num,
                "participants": agg_meta.get("participants", len(submissions)),
                "excluded": agg_meta.get("excluded", []),
                "global_accuracy": avg_accuracy,
                "aggregation_time_s": agg_meta.get("aggregation_time_s", 0),
                "weights_hash": weights_hash,
                "blockchain_tx": tx_hash,
                "blockchain_status": "pending_verification" if tx_hash else (
                    "disabled" if not os.getenv("SEPOLIA_RPC_URL") else "failed"
                ),
                "image_contributors": agg_meta.get("image_contributors", 0),
                "clinical_contributors": agg_meta.get("clinical_contributors", 0),
                "fusion_contributors": agg_meta.get("fusion_contributors", 0),
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }
            fl_state.add_round_history(round_entry)

            log.info(
                "Round %d complete: %d participants, avg_acc=%.3f, hash=%s...%s",
                round_num, len(submissions),
                avg_accuracy or 0, weights_hash[:8], weights_hash[-8:],
            )

            # Advance to next round
            fl_state.advance_round()

        # All rounds completed
        fl_state.set_status(ServerStatus.COMPLETED)
        log.info("FL session completed: %d rounds finished.", total_rounds)

    except asyncio.CancelledError:
        log.info("FL session task cancelled.")
        fl_state.set_status(ServerStatus.IDLE)
    except Exception as e:
        log.error("FL session error: %s", e, exc_info=True)
        fl_state.set_status(ServerStatus.ERROR)


# --- Endpoints ---

@app.get("/fl/status", response_model=StatusResponse)
async def get_status():
    """Get current FL server status. Public endpoint (no auth required)."""
    state = fl_state.get_status()
    return StatusResponse(
        status=state["status"],
        current_round=state["current_round"],
        total_rounds=state["total_rounds"],
        registered_clients=state["registered_clients"],
        submissions_this_round=state["submissions_this_round"],
        message=f"FL server operational. Status: {state['status'].value}",
    )


@app.post("/fl/start-round", response_model=StartRoundResponse)
async def start_round(
    request: StartRoundRequest,
    x_fl_secret: Optional[str] = Header(None),
):
    """Start a new FL training session."""
    _verify_secret(x_fl_secret)

    if fl_state.status not in (ServerStatus.IDLE, ServerStatus.COMPLETED, ServerStatus.ERROR):
        raise HTTPException(
            status_code=409,
            detail=f"Cannot start: server is currently '{fl_state.status.value}'. Stop first.",
        )

    fl_state.start_session(request.num_rounds, request.min_clients)

    # Launch background orchestration
    global _round_task
    if _round_task and not _round_task.done():
        _round_task.cancel()
    _round_task = asyncio.create_task(_run_fl_session())

    log.info("FL session initiated: %d rounds, min %d clients.", request.num_rounds, request.min_clients)

    return StartRoundResponse(
        message=f"FL session started. Waiting for {request.min_clients} client(s) to register.",
        status=ServerStatus.WAITING_CLIENTS,
        total_rounds=request.num_rounds,
        min_clients=request.min_clients,
    )


@app.post("/fl/register-client", response_model=RegisterClientResponse)
async def register_client(
    request: RegisterClientRequest,
    x_fl_secret: Optional[str] = Header(None),
):
    """Register a new FL client (hospital node)."""
    _verify_secret(x_fl_secret)

    if fl_state.status == ServerStatus.IDLE:
        raise HTTPException(status_code=409, detail="No active FL session. Start a round first.")

    client_id = fl_state.register_client(request.name, request.modality, request.data_size)

    return RegisterClientResponse(
        client_id=client_id,
        message=f"Client '{request.name}' registered successfully with modality '{request.modality.value}'.",
    )


@app.post("/fl/submit-weights", response_model=SubmitWeightsResponse)
async def submit_weights(
    request: SubmitWeightsRequest,
    x_fl_secret: Optional[str] = Header(None),
):
    """Submit locally-trained weights for the current round."""
    _verify_secret(x_fl_secret)

    # Validate client exists
    client = fl_state.get_client(request.client_id)
    if client is None:
        raise HTTPException(status_code=404, detail=f"Client '{request.client_id}' not found. Register first.")

    # Validate round number
    if request.round_number != fl_state.current_round:
        raise HTTPException(
            status_code=400,
            detail=f"Wrong round. Expected {fl_state.current_round}, got {request.round_number}.",
        )

    # Check for duplicate submission
    if fl_state.has_client_submitted(request.client_id, request.round_number):
        return SubmitWeightsResponse(
            accepted=False,
            message=f"Duplicate submission ignored. Client already submitted for round {request.round_number}.",
        )

    # Decode and save weights
    try:
        weights_bytes = base64.b64decode(request.weights_base64)
        weights_buffer = io.BytesIO(weights_bytes)
        state_dict = torch.load(weights_buffer, map_location="cpu", weights_only=False)
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Failed to decode weights: {str(e)}")

    # Save to disk
    weights_filename = f"client_{request.client_id}_round_{request.round_number}.pt"
    weights_path = os.path.join(WEIGHTS_DIR, weights_filename)
    torch.save(state_dict, weights_path)

    # Record submission
    submission = Submission(
        client_id=request.client_id,
        round_number=request.round_number,
        weights_path=weights_path,
        local_accuracy=request.local_accuracy,
        local_loss=request.local_loss,
        data_size=client.data_size,
        modality=client.modality,
        timestamp=time.time(),
    )
    fl_state.record_submission(submission)

    log.info(
        "Weights received: client=%s round=%d acc=%.3f loss=%.4f",
        request.client_id, request.round_number, request.local_accuracy, request.local_loss,
    )

    return SubmitWeightsResponse(
        accepted=True,
        message=f"Weights accepted for round {request.round_number}.",
    )


@app.get("/fl/global-weights")
async def get_global_weights(x_fl_secret: Optional[str] = Header(None)):
    """Download current global model weights as base64-encoded state dict."""
    _verify_secret(x_fl_secret)

    weights_path = os.path.join(WEIGHTS_DIR, "global_weights.pt")
    if not os.path.exists(weights_path):
        raise HTTPException(status_code=404, detail="No global weights available yet.")

    try:
        buffer = io.BytesIO()
        state_dict = torch.load(weights_path, map_location="cpu", weights_only=False)
        torch.save(state_dict, buffer)
        buffer.seek(0)
        encoded = base64.b64encode(buffer.read()).decode("utf-8")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to load global weights: {str(e)}")

    return {
        "weights_base64": encoded,
        "round": fl_state.current_round,
        "message": "Global weights for current round.",
    }


@app.get("/fl/metrics", response_model=MetricsResponse)
async def get_metrics(x_fl_secret: Optional[str] = Header(None)):
    """Get metrics history for all completed rounds."""
    _verify_secret(x_fl_secret)

    history = fl_state.get_round_history()
    rounds = []
    for entry in history:
        rounds.append(RoundMetric(
            round_number=entry.get("round_number", 0),
            participants=entry.get("participants", 0),
            global_accuracy=entry.get("global_accuracy"),
            global_loss=None,
            aggregation_time_s=entry.get("aggregation_time_s", 0),
            blockchain_tx=entry.get("blockchain_tx"),
            blockchain_status=entry.get("blockchain_status", "disabled"),
            timestamp=entry.get("timestamp", ""),
        ))

    return MetricsResponse(rounds=rounds)


@app.get("/fl/history", response_model=HistoryResponse)
async def get_history(x_fl_secret: Optional[str] = Header(None)):
    """Full round history with blockchain transaction details."""
    _verify_secret(x_fl_secret)

    history = fl_state.get_round_history()
    rounds = []
    for entry in history:
        rounds.append(RoundMetric(
            round_number=entry.get("round_number", 0),
            participants=entry.get("participants", 0),
            global_accuracy=entry.get("global_accuracy"),
            global_loss=None,
            aggregation_time_s=entry.get("aggregation_time_s", 0),
            blockchain_tx=entry.get("blockchain_tx"),
            blockchain_status=entry.get("blockchain_status", "disabled"),
            timestamp=entry.get("timestamp", ""),
        ))

    final_acc = rounds[-1].global_accuracy if rounds else None

    return HistoryResponse(
        rounds=rounds,
        total_rounds_completed=len(rounds),
        final_accuracy=final_acc,
    )


@app.post("/fl/stop", response_model=StopResponse)
async def stop_session(x_fl_secret: Optional[str] = Header(None)):
    """Stop the current FL session."""
    _verify_secret(x_fl_secret)

    global _round_task
    if _round_task and not _round_task.done():
        _round_task.cancel()

    fl_state.stop_session()

    return StopResponse(
        message="FL session stopped.",
        status=ServerStatus.IDLE,
    )


@app.get("/fl/verify-round/{round_id}", response_model=VerifyRoundResponse)
async def verify_round_endpoint(
    round_id: int,
    x_fl_secret: Optional[str] = Header(None),
):
    """Verify a round's weights against blockchain record."""
    _verify_secret(x_fl_secret)

    # Find local hash from history
    history = fl_state.get_round_history()
    local_hash = None
    for entry in history:
        if entry.get("round_number") == round_id:
            local_hash = entry.get("weights_hash")
            break

    result = verify_round(round_id, local_hash)

    return VerifyRoundResponse(
        round_number=result["round_number"],
        local_hash=result.get("local_hash"),
        on_chain_hash=result.get("on_chain_hash"),
        match=result.get("match"),
        message=result.get("message", ""),
    )


@app.get("/fl/clients", response_model=ClientsResponse)
async def list_clients(x_fl_secret: Optional[str] = Header(None)):
    """List all registered clients and their status."""
    _verify_secret(x_fl_secret)

    clients = fl_state.get_clients()
    client_infos = [
        ClientInfo(
            client_id=c.client_id,
            name=c.name,
            modality=c.modality,
            data_size=c.data_size,
            status=c.status,
            rounds_participated=c.rounds_participated,
            last_accuracy=c.last_accuracy,
        )
        for c in clients
    ]

    return ClientsResponse(clients=client_infos)


# --- Health check (no auth) ---

@app.get("/health")
async def health():
    """Basic health check endpoint."""
    return {"status": "healthy", "timestamp": datetime.now(timezone.utc).isoformat()}
