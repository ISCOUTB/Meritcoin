"""
Endpoints de consulta para estudiantes:
- GET /students/{wallet}/badges   → insignias de un estudiante
- GET /students/{wallet}/balance  → saldo MRT
- GET /students/{wallet}/summary  → resumen completo (para Moodle dashboard)
"""

import logging
from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.database import get_db
from app.models.audit import AuditLog, EventRecord
from app.models.events import StudentBadge, StudentBalance
from app.services.blockchain import blockchain

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/students", tags=["Students"])


# ─── Modelo de respuesta para el dashboard de Moodle ─────────────────────────

class BadgeSummaryItem(BaseModel):
    """Formato de badge esperado por dashboard.php de Moodle."""
    name: str
    image_url: Optional[str] = None
    awarded_at: Optional[int] = None  # Unix timestamp (int para PHP)


class StudentSummaryResponse(BaseModel):
    """Respuesta del endpoint /summary consumido por el plugin Moodle."""
    mrt_balance: float
    badges: List[BadgeSummaryItem]


# ─── Endpoints existentes (sin cambios) ──────────────────────────────────────

@router.get(
    "/{wallet}/badges",
    response_model=List[StudentBadge],
    summary="Listar insignias de un estudiante",
)
async def get_student_badges(
    wallet: str,
    db: AsyncSession = Depends(get_db),
) -> List[StudentBadge]:
    """
    Consulta la tabla audit_log + events para armar la lista
    de insignias emitidas a un wallet.
    """
    query = (
        select(EventRecord, AuditLog)
        .join(AuditLog, EventRecord.event_id == AuditLog.event_id)
        .where(EventRecord.student_wallet == wallet)
        .order_by(EventRecord.processed_at.desc())
    )
    result = await db.execute(query)
    rows = result.all()

    badges = []
    for event, audit in rows:
        badges.append(StudentBadge(
            badge_id=int(audit.badge_id) if audit.badge_id else 0,
            course_id=event.course_id,
            course_name=event.course_name,
            event_type=event.event_type,
            uri=f"ipfs://{audit.cid_ipfs}" if audit.cid_ipfs else "",
            tx_hash=audit.tx_badge or "",
            issued_at=event.processed_at,
        ))

    return badges


@router.get(
    "/{wallet}/balance",
    response_model=StudentBalance,
    summary="Consultar saldo MRT de un estudiante",
)
async def get_student_balance(wallet: str) -> StudentBalance:
    """
    Consulta el balance MRT directamente desde la blockchain.
    """
    try:
        balance_mrt, balance_wei = blockchain.get_mrt_balance(wallet)
    except Exception as e:
        logger.error(f"Error consultando balance de {wallet}: {e}")
        balance_mrt, balance_wei = "0", "0"

    return StudentBalance(
        wallet=wallet,
        balance_mrt=balance_mrt,
        balance_wei=balance_wei,
    )


# ─── Endpoint NUEVO para el dashboard de Moodle ──────────────────────────────

@router.get(
    "/{wallet}/summary",
    response_model=StudentSummaryResponse,
    summary="Resumen completo del estudiante (consumido por Moodle)",
)
async def get_student_summary(
    wallet: str,
    db: AsyncSession = Depends(get_db),
) -> StudentSummaryResponse:
    """
    Combina balance MRT + badges en una sola llamada.
    Llamado desde dashboard.php del plugin local_meritcoin.

    El plugin Moodle envía la wallet del estudiante obtenida
    del campo de perfil personalizado 'wallet'.
    """

    # 1. Balance desde blockchain
    try:
        balance_mrt, _ = blockchain.get_mrt_balance(wallet)
        balance_float = float(balance_mrt)
    except Exception as e:
        logger.error(f"[summary] Error balance {wallet}: {e}")
        balance_float = 0.0

    # 2. Badges desde BD
    query = (
        select(EventRecord, AuditLog)
        .join(AuditLog, EventRecord.event_id == AuditLog.event_id)
        .where(EventRecord.student_wallet == wallet)
        .order_by(EventRecord.processed_at.desc())
    )
    result = await db.execute(query)
    rows = result.all()

    badges = []
    for event, audit in rows:
        if not audit.badge_id:
            continue
        badges.append(BadgeSummaryItem(
            name=f"Badge #{audit.badge_id} — {event.course_name}",
            image_url=None,  # TODO: agregar URL de imagen si tienes metadata IPFS
            awarded_at=(
                int(event.processed_at.timestamp())
                if event.processed_at else None
            ),
        ))

    return StudentSummaryResponse(
        mrt_balance=balance_float,
        badges=badges,
    )
