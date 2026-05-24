"""
Pydantic models for FL server request/response schemas.
"""
from __future__ import annotations

from enum import Enum
from typing import Optional

from pydantic import BaseModel, Field


# --- Enums ---

class ServerStatus(str, Enum):
    IDLE = "idle"
    WAITING_CLIENTS = "waiting_clients"
    TRAINING = "training"
    AGGREGATING = "aggregating"
    COMPLETED = "completed"
    ERROR = "error"


class ClientModality(str, Enum):
    MULTIMODAL = "multimodal"
    IMAGE_ONLY = "image_only"
    CLINICAL_ONLY = "clinical_only"


class ClientStatus(str, Enum):
    REGISTERED = "registered"
    TRAINING = "training"
    SUBMITTED = "submitted"
    DROPPED = "dropped"


# --- Request models ---

class StartRoundRequest(BaseModel):
    num_rounds: int = Field(ge=1, le=500, description="Total FL rounds to execute")
    min_clients: int = Field(ge=1, le=100, description="Minimum clients before starting")


class RegisterClientRequest(BaseModel):
    name: str = Field(min_length=1, max_length=128, description="Client display name")
    modality: ClientModality = Field(description="Data modality the client holds")
    data_size: int = Field(ge=1, description="Number of samples in client dataset")


class SubmitWeightsRequest(BaseModel):
    client_id: str = Field(description="Registered client identifier")
    round_number: int = Field(ge=1, description="Which FL round these weights belong to")
    weights_base64: str = Field(description="Base64-encoded serialized state dict")
    local_accuracy: float = Field(ge=0.0, le=1.0, description="Client local accuracy")
    local_loss: float = Field(ge=0.0, description="Client local training loss")


# --- Response models ---

class StatusResponse(BaseModel):
    status: ServerStatus
    current_round: int
    total_rounds: int
    registered_clients: int
    submissions_this_round: int
    message: str = ""


class RegisterClientResponse(BaseModel):
    client_id: str
    message: str


class SubmitWeightsResponse(BaseModel):
    accepted: bool
    message: str


class StartRoundResponse(BaseModel):
    message: str
    status: ServerStatus
    total_rounds: int
    min_clients: int


class StopResponse(BaseModel):
    message: str
    status: ServerStatus


class RoundMetric(BaseModel):
    round_number: int
    participants: int
    global_accuracy: Optional[float] = None
    global_loss: Optional[float] = None
    aggregation_time_s: float = 0.0
    blockchain_tx: Optional[str] = None
    blockchain_status: str = "disabled"
    timestamp: str = ""


class MetricsResponse(BaseModel):
    rounds: list[RoundMetric]


class ClientInfo(BaseModel):
    client_id: str
    name: str
    modality: ClientModality
    data_size: int
    status: ClientStatus
    rounds_participated: int = 0
    last_accuracy: Optional[float] = None


class ClientsResponse(BaseModel):
    clients: list[ClientInfo]


class HistoryResponse(BaseModel):
    rounds: list[RoundMetric]
    total_rounds_completed: int
    final_accuracy: Optional[float] = None


class VerifyRoundResponse(BaseModel):
    round_number: int
    local_hash: Optional[str] = None
    on_chain_hash: Optional[str] = None
    match: Optional[bool] = None
    message: str = ""


class ErrorResponse(BaseModel):
    detail: str
