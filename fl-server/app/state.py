"""
Server state management for the FL aggregator.

Maintains in-memory state with persistence to JSON for crash recovery.
All access is thread-safe via a reentrant lock.
"""
from __future__ import annotations

import json
import logging
import os
import threading
import time
import uuid
from typing import Any, Optional

from app.models import ClientModality, ClientStatus, ServerStatus

log = logging.getLogger("fl.state")

STATE_FILE = "/app/logs/state.json"
HISTORY_FILE = "/app/logs/history.json"


class ClientRecord:
    """In-memory record for a registered FL client."""

    def __init__(self, client_id: str, name: str, modality: ClientModality, data_size: int):
        self.client_id = client_id
        self.name = name
        self.modality = modality
        self.data_size = data_size
        self.status = ClientStatus.REGISTERED
        self.rounds_participated = 0
        self.last_accuracy: Optional[float] = None

    def to_dict(self) -> dict:
        return {
            "client_id": self.client_id,
            "name": self.name,
            "modality": self.modality.value,
            "data_size": self.data_size,
            "status": self.status.value,
            "rounds_participated": self.rounds_participated,
            "last_accuracy": self.last_accuracy,
        }

    @classmethod
    def from_dict(cls, d: dict) -> "ClientRecord":
        rec = cls(
            client_id=d["client_id"],
            name=d["name"],
            modality=ClientModality(d["modality"]),
            data_size=d["data_size"],
        )
        rec.status = ClientStatus(d.get("status", "registered"))
        rec.rounds_participated = d.get("rounds_participated", 0)
        rec.last_accuracy = d.get("last_accuracy")
        return rec


class Submission:
    """Record of a single client weight submission for a round."""

    def __init__(self, client_id: str, round_number: int, weights_path: str,
                 local_accuracy: float, local_loss: float, data_size: int,
                 modality: ClientModality, timestamp: float):
        self.client_id = client_id
        self.round_number = round_number
        self.weights_path = weights_path
        self.local_accuracy = local_accuracy
        self.local_loss = local_loss
        self.data_size = data_size
        self.modality = modality
        self.timestamp = timestamp


class FLState:
    """
    Central state manager for the FL server.
    Thread-safe, persists to disk, recoverable on restart.
    """

    def __init__(self):
        self._lock = threading.RLock()
        self.status: ServerStatus = ServerStatus.IDLE
        self.current_round: int = 0
        self.total_rounds: int = 0
        self.min_clients: int = 1
        self.clients: dict[str, ClientRecord] = {}
        self.submissions: dict[int, dict[str, Submission]] = {}
        self.round_history: list[dict[str, Any]] = []
        self.round_start_time: float = 0.0
        self.session_start_time: float = 0.0
        self._load_from_disk()

    def _load_from_disk(self) -> None:
        """Attempt to recover state from disk on startup."""
        if not os.path.exists(STATE_FILE):
            log.info("No persisted state found, starting fresh.")
            return

        try:
            with open(STATE_FILE, "r") as f:
                data = json.load(f)

            self.status = ServerStatus(data.get("status", "idle"))
            self.current_round = data.get("current_round", 0)
            self.total_rounds = data.get("total_rounds", 0)
            self.min_clients = data.get("min_clients", 1)
            self.session_start_time = data.get("session_start_time", 0.0)
            self.round_start_time = data.get("round_start_time", 0.0)

            for cd in data.get("clients", []):
                rec = ClientRecord.from_dict(cd)
                self.clients[rec.client_id] = rec

            log.info(
                "Restored state: status=%s, round=%d/%d, clients=%d",
                self.status.value, self.current_round, self.total_rounds, len(self.clients),
            )
        except Exception as e:
            log.error("Failed to load state from disk: %s. Starting fresh.", e)
            self.status = ServerStatus.IDLE

        # Load round history
        if os.path.exists(HISTORY_FILE):
            try:
                with open(HISTORY_FILE, "r") as f:
                    self.round_history = json.load(f)
            except Exception as e:
                log.error("Failed to load history: %s", e)
                self.round_history = []

    def _persist(self) -> None:
        """Write current state to disk atomically."""
        os.makedirs(os.path.dirname(STATE_FILE), exist_ok=True)

        data = {
            "status": self.status.value,
            "current_round": self.current_round,
            "total_rounds": self.total_rounds,
            "min_clients": self.min_clients,
            "session_start_time": self.session_start_time,
            "round_start_time": self.round_start_time,
            "clients": [c.to_dict() for c in self.clients.values()],
        }

        tmp = STATE_FILE + ".tmp"
        with open(tmp, "w") as f:
            json.dump(data, f, indent=2)
        os.replace(tmp, STATE_FILE)

    def _persist_history(self) -> None:
        """Write round history to disk atomically."""
        os.makedirs(os.path.dirname(HISTORY_FILE), exist_ok=True)
        tmp = HISTORY_FILE + ".tmp"
        with open(tmp, "w") as f:
            json.dump(self.round_history, f, indent=2)
        os.replace(tmp, HISTORY_FILE)

    # --- Public API (all thread-safe) ---

    def start_session(self, num_rounds: int, min_clients: int) -> None:
        with self._lock:
            self.status = ServerStatus.WAITING_CLIENTS
            self.current_round = 1
            self.total_rounds = num_rounds
            self.min_clients = min_clients
            self.session_start_time = time.time()
            self.round_start_time = time.time()
            self.submissions = {}
            # Reset client statuses but keep registrations
            for c in self.clients.values():
                c.status = ClientStatus.REGISTERED
                c.rounds_participated = 0
            self._persist()
            log.info("FL session started: %d rounds, min %d clients", num_rounds, min_clients)

    def register_client(self, name: str, modality: ClientModality, data_size: int) -> str:
        with self._lock:
            client_id = str(uuid.uuid4())[:8]
            self.clients[client_id] = ClientRecord(client_id, name, modality, data_size)
            self._persist()
            log.info("Client registered: id=%s name=%s modality=%s", client_id, name, modality.value)
            return client_id

    def get_status(self) -> dict:
        with self._lock:
            current_subs = len(self.submissions.get(self.current_round, {}))
            return {
                "status": self.status,
                "current_round": self.current_round,
                "total_rounds": self.total_rounds,
                "registered_clients": len(self.clients),
                "submissions_this_round": current_subs,
            }

    def record_submission(self, submission: Submission) -> None:
        with self._lock:
            rnd = submission.round_number
            if rnd not in self.submissions:
                self.submissions[rnd] = {}
            self.submissions[rnd][submission.client_id] = submission

            # Update client record
            if submission.client_id in self.clients:
                client = self.clients[submission.client_id]
                client.status = ClientStatus.SUBMITTED
                client.last_accuracy = submission.local_accuracy
                client.rounds_participated += 1

            self._persist()

    def get_submissions_for_round(self, round_number: int) -> list[Submission]:
        with self._lock:
            return list(self.submissions.get(round_number, {}).values())

    def has_client_submitted(self, client_id: str, round_number: int) -> bool:
        with self._lock:
            return client_id in self.submissions.get(round_number, {})

    def get_registered_client_count(self) -> int:
        with self._lock:
            return len(self.clients)

    def advance_round(self) -> None:
        with self._lock:
            if self.current_round >= self.total_rounds:
                self.status = ServerStatus.COMPLETED
            else:
                self.current_round += 1
                self.round_start_time = time.time()
                self.status = ServerStatus.TRAINING
                # Reset client statuses for new round
                for c in self.clients.values():
                    if c.status == ClientStatus.SUBMITTED:
                        c.status = ClientStatus.REGISTERED
            self._persist()

    def set_status(self, status: ServerStatus) -> None:
        with self._lock:
            self.status = status
            self._persist()

    def add_round_history(self, entry: dict) -> None:
        with self._lock:
            self.round_history.append(entry)
            self._persist_history()

    def get_round_history(self) -> list[dict]:
        with self._lock:
            return list(self.round_history)

    def get_clients(self) -> list[ClientRecord]:
        with self._lock:
            return list(self.clients.values())

    def get_client(self, client_id: str) -> Optional[ClientRecord]:
        with self._lock:
            return self.clients.get(client_id)

    def stop_session(self) -> None:
        with self._lock:
            self.status = ServerStatus.IDLE
            self.current_round = 0
            self.total_rounds = 0
            self._persist()
            log.info("FL session stopped.")


# Singleton instance
fl_state = FLState()
