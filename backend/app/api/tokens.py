import logging
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
from app.services.blockchain import blockchain

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/tokens", tags=["tokens"])


class SpendRequest(BaseModel):
    student_id: str
    student_wallet: str
    amount: float
    reward_id: str
    course_id: str


class SpendResponse(BaseModel):
    tx_hash: str
    student_wallet: str
    amount: float
    reward_id: str


@router.post("/spend", response_model=SpendResponse)
async def spend_tokens(data: SpendRequest):
    if not blockchain.is_connected():
        raise HTTPException(status_code=503, detail="Blockchain no disponible")

    if data.amount <= 0:
        raise HTTPException(status_code=400, detail="El amount debe ser mayor a 0")

    try:
        tx_hash = blockchain.burn_mrt(data.student_wallet, data.amount)
        logger.info(
            f"MRT quemados — student={data.student_id} "
            f"wallet={data.student_wallet} amount={data.amount} tx={tx_hash}"
        )
        return SpendResponse(
            tx_hash=tx_hash,
            student_wallet=data.student_wallet,
            amount=data.amount,
            reward_id=data.reward_id,
        )
    except Exception as exc:
        logger.error(f"Error al quemar MRT: {exc}")
        raise HTTPException(status_code=500, detail=str(exc))