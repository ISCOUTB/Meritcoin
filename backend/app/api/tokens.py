"""
Router: POST /tokens/spend

Permite canjear (quemar) tokens MRT de un estudiante
en el marketplace de recompensas.
"""

import logging

from fastapi import APIRouter, HTTPException, status
from pydantic import BaseModel, Field

from app.services.blockchain import blockchain

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/tokens", tags=["Tokens"])


class SpendRequest(BaseModel):
    """Payload para canjear tokens MRT."""
    student_id: str = Field(..., description="ID del estudiante en Moodle")
    student_wallet: str = Field(..., description="Dirección Ethereum del estudiante")
    amount: float = Field(..., gt=0, description="Cantidad de MRT a canjear (debe ser > 0)")
    reward_id: str = Field(..., description="ID de la recompensa canjeada en el marketplace")
    course_id: str = Field(..., description="Curso en el que se realiza el canje")


class SpendResponse(BaseModel):
    """Resultado del canje de tokens MRT."""
    tx_hash: str = Field(..., description="Hash de la transacción de quema en blockchain")
    student_wallet: str
    amount: float
    reward_id: str


@router.post(
    "/spend",
    response_model=SpendResponse,
    status_code=status.HTTP_200_OK,
    summary="Canjear tokens MRT",
    description="Quema tokens MRT de un estudiante al canjear una recompensa en el marketplace.",
)
async def spend_tokens(data: SpendRequest) -> SpendResponse:
    """
    Canjea (quema) tokens MRT de un wallet de estudiante.

    Retorna 503 si Besu no está disponible.
    Retorna 500 si la transacción falla por cualquier otra razón.
    """
    if not blockchain.is_connected():
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Nodo blockchain no disponible — intenta más tarde",
        )

    try:
        tx_hash = blockchain.burn_mrt(data.student_wallet, data.amount)
        logger.info(
            "MRT canjeados — student=%s wallet=%s amount=%.4f tx=%s",
            data.student_id, data.student_wallet, data.amount, tx_hash,
        )
        return SpendResponse(
            tx_hash=tx_hash,
            student_wallet=data.student_wallet,
            amount=data.amount,
            reward_id=data.reward_id,
        )
    except Exception as exc:
        logger.error("Error al quemar MRT para %s: %s", data.student_wallet, exc, exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=str(exc),
        )
