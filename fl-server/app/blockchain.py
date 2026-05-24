"""
Blockchain integration stub for Sepolia testnet.

Records FL round metadata (weights hash, accuracy, participants) on-chain.
All operations are non-blocking. If blockchain is unavailable, the FL process
continues unaffected.
"""
from __future__ import annotations

import hashlib
import io
import logging
import os
import threading
from typing import Optional

import torch

log = logging.getLogger("fl.blockchain")

# Environment configuration
SEPOLIA_RPC_URL = os.getenv("SEPOLIA_RPC_URL", "")
SEPOLIA_PRIVATE_KEY = os.getenv("SEPOLIA_PRIVATE_KEY", "")
CONTRACT_ADDRESS = os.getenv("CONTRACT_ADDRESS", "")


def is_blockchain_enabled() -> bool:
    """Check if blockchain integration is configured."""
    return bool(SEPOLIA_RPC_URL and SEPOLIA_PRIVATE_KEY and CONTRACT_ADDRESS)


def hash_weights(state_dict: dict[str, torch.Tensor]) -> str:
    """
    Compute SHA-256 hash of a serialized state dict.

    This provides a deterministic fingerprint of the model weights
    that can be verified against on-chain records.
    """
    buffer = io.BytesIO()
    torch.save(state_dict, buffer)
    buffer.seek(0)
    return hashlib.sha256(buffer.read()).hexdigest()


def record_round_on_chain(
    round_number: int,
    weights_hash: str,
    accuracy: Optional[float],
    num_clients: int,
) -> Optional[str]:
    """
    Record FL round metadata on Sepolia (non-blocking).

    Returns tx_hash if transaction was submitted, None otherwise.
    Failures are logged but never raise exceptions.
    """
    if not is_blockchain_enabled():
        log.info("Blockchain disabled (no RPC URL configured). Skipping on-chain record.")
        return None

    # Fire-and-forget in a background thread
    tx_result: dict = {"tx_hash": None}

    def _submit():
        try:
            from web3 import Web3

            w3 = Web3(Web3.HTTPProvider(SEPOLIA_RPC_URL))
            if not w3.is_connected():
                log.warning("Cannot connect to Sepolia RPC at %s", SEPOLIA_RPC_URL)
                return

            account = w3.eth.account.from_key(SEPOLIA_PRIVATE_KEY)

            # Minimal ABI for recording round data
            # Expected contract function: recordRound(uint256, bytes32, uint256, uint256)
            contract_abi = [
                {
                    "inputs": [
                        {"name": "roundNumber", "type": "uint256"},
                        {"name": "weightsHash", "type": "bytes32"},
                        {"name": "accuracy", "type": "uint256"},
                        {"name": "numClients", "type": "uint256"},
                    ],
                    "name": "recordRound",
                    "outputs": [],
                    "stateMutability": "nonpayable",
                    "type": "function",
                }
            ]

            contract = w3.eth.contract(
                address=Web3.to_checksum_address(CONTRACT_ADDRESS),
                abi=contract_abi,
            )

            # Convert accuracy to basis points (0.95 -> 9500)
            acc_bps = int((accuracy or 0) * 10000)
            hash_bytes = bytes.fromhex(weights_hash)

            nonce = w3.eth.get_transaction_count(account.address)
            tx = contract.functions.recordRound(
                round_number, hash_bytes, acc_bps, num_clients
            ).build_transaction({
                "from": account.address,
                "nonce": nonce,
                "gas": 200000,
                "gasPrice": w3.eth.gas_price,
            })

            signed = account.sign_transaction(tx)
            tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
            tx_result["tx_hash"] = tx_hash.hex()
            log.info("Round %d recorded on-chain. tx=%s", round_number, tx_result["tx_hash"])

        except Exception as e:
            log.error("Blockchain submission failed for round %d: %s", round_number, e)

    thread = threading.Thread(target=_submit, daemon=True)
    thread.start()

    # We do not wait for the thread. The tx_hash will be None immediately.
    # The actual hash gets logged asynchronously.
    return None


def verify_round(round_number: int, local_hash: Optional[str] = None) -> dict:
    """
    Verify a round's on-chain record against local data.

    Returns a dict with verification status.
    """
    if not is_blockchain_enabled():
        return {
            "round_number": round_number,
            "local_hash": local_hash,
            "on_chain_hash": None,
            "match": None,
            "message": "Blockchain disabled. Cannot verify.",
        }

    try:
        from web3 import Web3

        w3 = Web3(Web3.HTTPProvider(SEPOLIA_RPC_URL))
        if not w3.is_connected():
            return {
                "round_number": round_number,
                "local_hash": local_hash,
                "on_chain_hash": None,
                "match": None,
                "message": "Cannot connect to Sepolia RPC.",
            }

        # Minimal ABI for reading round data
        contract_abi = [
            {
                "inputs": [{"name": "roundNumber", "type": "uint256"}],
                "name": "getRound",
                "outputs": [
                    {"name": "weightsHash", "type": "bytes32"},
                    {"name": "accuracy", "type": "uint256"},
                    {"name": "numClients", "type": "uint256"},
                    {"name": "timestamp", "type": "uint256"},
                ],
                "stateMutability": "view",
                "type": "function",
            }
        ]

        contract = w3.eth.contract(
            address=Web3.to_checksum_address(CONTRACT_ADDRESS),
            abi=contract_abi,
        )

        result = contract.functions.getRound(round_number).call()
        on_chain_hash = result[0].hex()

        match = None
        if local_hash:
            match = on_chain_hash == local_hash

        return {
            "round_number": round_number,
            "local_hash": local_hash,
            "on_chain_hash": on_chain_hash,
            "match": match,
            "message": "Verified" if match else "Hash mismatch" if match is False else "No local hash to compare",
        }

    except Exception as e:
        log.error("Verification failed for round %d: %s", round_number, e)
        return {
            "round_number": round_number,
            "local_hash": local_hash,
            "on_chain_hash": None,
            "match": None,
            "message": f"Verification error: {str(e)}",
        }
