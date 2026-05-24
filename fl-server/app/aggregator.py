"""
Modality-aware Federated Averaging for BReCAI.

Weight groups (must match CrossAttentionFusion model architecture):
  - IMAGE weights: mil.*, img_proj.*, img_head.*
  - CLINICAL weights: clin_enc.*, clin_proj.*, clin_head.*
  - FUSION weights: img2clin.*, clin2img.*, norm_*, gate.*, clf.*

Aggregation rules:
  - IMAGE weights are averaged only from clients that have image data
  - CLINICAL weights are averaged only from clients that have clinical data
  - FUSION weights are averaged only from multimodal clients
  - Weighting is proportional to data_size (number of samples)
  - Clients with local accuracy below 50% are excluded (divergence protection)
"""
from __future__ import annotations

import logging
import re
import time
from typing import Optional

import torch

from app.models import ClientModality
from app.state import Submission

log = logging.getLogger("fl.aggregator")

# Divergence threshold: exclude clients with accuracy below this
ACCURACY_THRESHOLD = 0.50

# Weight group patterns
IMAGE_PATTERNS = [
    re.compile(r"^mil\."),
    re.compile(r"^img_proj\."),
    re.compile(r"^img_head\."),
]

CLINICAL_PATTERNS = [
    re.compile(r"^clin_enc\."),
    re.compile(r"^clin_proj\."),
    re.compile(r"^clin_head\."),
]

FUSION_PATTERNS = [
    re.compile(r"^img2clin\."),
    re.compile(r"^clin2img\."),
    re.compile(r"^norm_"),
    re.compile(r"^gate\."),
    re.compile(r"^clf\."),
]


def _classify_key(key: str) -> str:
    """Classify a state dict key into IMAGE, CLINICAL, or FUSION group."""
    for pattern in IMAGE_PATTERNS:
        if pattern.match(key):
            return "image"
    for pattern in CLINICAL_PATTERNS:
        if pattern.match(key):
            return "clinical"
    for pattern in FUSION_PATTERNS:
        if pattern.match(key):
            return "fusion"
    # Default: treat unknown keys as fusion (requires multimodal clients)
    log.warning("Unknown weight key '%s', treating as fusion group.", key)
    return "fusion"


def _client_has_modality(modality: ClientModality, group: str) -> bool:
    """Check if a client modality qualifies to contribute to a weight group."""
    if group == "image":
        return modality in (ClientModality.MULTIMODAL, ClientModality.IMAGE_ONLY)
    elif group == "clinical":
        return modality in (ClientModality.MULTIMODAL, ClientModality.CLINICAL_ONLY)
    elif group == "fusion":
        return modality == ClientModality.MULTIMODAL
    return False


def modality_aware_fedavg(
    submissions: list[Submission],
    global_state_dict: Optional[dict[str, torch.Tensor]] = None,
) -> tuple[dict[str, torch.Tensor], dict]:
    """
    Perform modality-aware Federated Averaging.

    Args:
        submissions: List of client submissions for this round.
        global_state_dict: Current global weights (used as fallback if no clients
                           contribute to a weight group).

    Returns:
        Tuple of (aggregated_state_dict, aggregation_metadata).
    """
    t_start = time.time()

    # Filter out divergent clients (accuracy below threshold)
    valid_submissions = []
    excluded_clients = []
    for sub in submissions:
        if sub.local_accuracy < ACCURACY_THRESHOLD:
            excluded_clients.append(sub.client_id)
            log.warning(
                "Excluding client %s: accuracy %.2f%% below threshold %.0f%%",
                sub.client_id, sub.local_accuracy * 100, ACCURACY_THRESHOLD * 100,
            )
        else:
            valid_submissions.append(sub)

    if not valid_submissions:
        log.error("No valid submissions after filtering. Keeping global weights.")
        return global_state_dict or {}, {
            "participants": 0,
            "excluded": excluded_clients,
            "error": "no_valid_submissions",
        }

    # Load all client state dicts
    client_weights: list[tuple[Submission, dict[str, torch.Tensor]]] = []
    for sub in valid_submissions:
        try:
            sd = torch.load(sub.weights_path, map_location="cpu", weights_only=False)
            if isinstance(sd, dict) and "state_dict" in sd:
                sd = sd["state_dict"]
            client_weights.append((sub, sd))
        except Exception as e:
            log.error("Failed to load weights from client %s: %s", sub.client_id, e)

    if not client_weights:
        log.error("No weights could be loaded. Keeping global weights.")
        return global_state_dict or {}, {
            "participants": 0,
            "excluded": excluded_clients,
            "error": "load_failed",
        }

    # Use the first client's keys as reference
    ref_keys = list(client_weights[0][1].keys())

    # Classify keys into groups
    key_groups: dict[str, str] = {k: _classify_key(k) for k in ref_keys}

    # Group clients by what they can contribute
    aggregated_sd: dict[str, torch.Tensor] = {}

    group_stats = {"image": 0, "clinical": 0, "fusion": 0}

    for key in ref_keys:
        group = key_groups[key]

        # Find eligible clients for this weight group
        eligible = [
            (sub, sd) for sub, sd in client_weights
            if _client_has_modality(sub.modality, group) and key in sd
        ]

        if not eligible:
            # No eligible clients for this group; keep global weights if available
            if global_state_dict and key in global_state_dict:
                aggregated_sd[key] = global_state_dict[key].clone()
            else:
                # Use first client as fallback (should not happen in practice)
                aggregated_sd[key] = client_weights[0][1][key].clone()
            continue

        # Weighted average by data_size
        total_samples = sum(sub.data_size for sub, _ in eligible)
        if total_samples == 0:
            total_samples = len(eligible)

        weighted_sum = torch.zeros_like(eligible[0][1][key], dtype=torch.float32)
        for sub, sd in eligible:
            weight = sub.data_size / total_samples
            weighted_sum += sd[key].float() * weight

        aggregated_sd[key] = weighted_sum
        group_stats[group] = max(group_stats[group], len(eligible))

    elapsed = time.time() - t_start

    metadata = {
        "participants": len(client_weights),
        "excluded": excluded_clients,
        "image_contributors": group_stats["image"],
        "clinical_contributors": group_stats["clinical"],
        "fusion_contributors": group_stats["fusion"],
        "aggregation_time_s": round(elapsed, 3),
    }

    log.info(
        "Aggregation complete: %d participants, %d excluded, %.2fs. "
        "Contributors — image: %d, clinical: %d, fusion: %d",
        metadata["participants"], len(excluded_clients), elapsed,
        group_stats["image"], group_stats["clinical"], group_stats["fusion"],
    )

    return aggregated_sd, metadata
