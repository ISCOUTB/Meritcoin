"""
Router: endpoints de consulta para estudiantes.

Rutas:
  GET /students/{wallet}/badges   → insignias de un estudiante (tabla audit_log)
  GET /students/{wallet}/balance  → saldo MRT (blockchain con fallback BD)
  GET /students/{wallet}/summary  → resumen completo consumido por el plugin Moodle
"""

import logging
from typing import List, Optional

from fastapi import APIRouter, Depends
from pydantic import BaseModel
from sqlalchemy import Numeric, func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.database import get_db
from app.models.audit import AuditLog, EventRecord
from app.models.badges import BadgeAward, BadgeTemplate
from app.models.events import StudentBadge, StudentBalance
from app.services.blockchain import blockchain

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/students", tags=["Students"])


# ── Modelos propios del módulo ────────────────────────────────────────────────

class BadgeSummaryItem(BaseModel):
    """Formato de badge esperado por dashboard.php del plugin Moodle."""
    name: str
    image_url: Optional[str] = None
    awarded_at: Optional[int] = None  # Unix timestamp como int (más fácil para PHP)


class StudentSummaryResponse(BaseModel):
    """Respuesta del endpoint /summary consumido por el plugin Moodle."""
    mrt_balance: float
    badges: List[BadgeSummaryItem]


# ── Endpoints ─────────────────────────────────────────────────────────────────

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
    Lista las insignias del flujo automático (vía audit_log + events).
    Las insignias del flujo manual (BadgeAward) se consultan en /badges/student/{id}.
    """
    query = (
        select(EventRecord, AuditLog)
        .join(AuditLog, EventRecord.event_id == AuditLog.event_id)
        .where(EventRecord.student_wallet == wallet)
        .order_by(EventRecord.processed_at.desc())
    )
    result = await db.execute(query)
    rows = result.all()

    return [
        StudentBadge(
            badge_id=int(audit.badge_id) if audit.badge_id else 0,
            course_id=event.course_id,
            course_name=event.course_name,
            event_type=event.event_type,
            uri=f"ipfs://{audit.cid_ipfs}" if audit.cid_ipfs else "",
            tx_hash=audit.tx_badge or "",
            issued_at=event.processed_at,
        )
        for event, audit in rows
    ]


@router.get(
    "/{wallet}/balance",
    response_model=StudentBalance,
    summary="Consultar saldo MRT de un estudiante",
)
async def get_student_balance(wallet: str) -> StudentBalance:
    """Consulta el balance MRT directamente desde la blockchain."""
    try:
        balance_mrt, balance_wei = blockchain.get_mrt_balance(wallet)
    except Exception as exc:
        logger.error("Error consultando balance de %s: %s", wallet, exc)
        balance_mrt, balance_wei = "0", "0"

    return StudentBalance(
        wallet=wallet,
        balance_mrt=balance_mrt,
        balance_wei=balance_wei,
    )


@router.get(
    "/{wallet}/summary",
    response_model=StudentSummaryResponse,
    summary="Resumen completo del estudiante (consumido por el plugin Moodle)",
)
async def get_student_summary(
    wallet: str,
    db: AsyncSession = Depends(get_db),
) -> StudentSummaryResponse:
    """
    Combina balance MRT + badges en una sola llamada para el dashboard de Moodle.

    Balance: primero intenta blockchain; si falla, suma desde audit_log en BD.
    Badges: solo incluye las no revocadas del flujo manual (BadgeAward).
    """
    # ── 1. Balance MRT ────────────────────────────────────────────────────────
    balance_float = 0.0
    try:
        balance_mrt, _ = blockchain.get_mrt_balance(wallet)
        balance_float = float(str(balance_mrt))
    except Exception as exc:
        logger.warning("Blockchain no disponible para %s: %s — usando BD como fallback", wallet, exc)
        try:
            result_sum = await db.execute(
                select(func.sum(AuditLog.mrt_amount.cast(Numeric)))
                .join(EventRecord, AuditLog.event_id == EventRecord.event_id)
                .where(EventRecord.student_wallet == wallet)
                .where(EventRecord.status == "processed")
            )
            total = result_sum.scalar_one_or_none()
            balance_float = float(total) if total else 0.0
        except Exception as exc2:
            logger.error("Error en fallback BD para balance de %s: %s", wallet, exc2)
            balance_float = 0.0

    # ── 2. Badges ─────────────────────────────────────────────────────────────
    badges: List[BadgeSummaryItem] = []
    try:
        query = (
            select(BadgeAward, BadgeTemplate)
            .join(BadgeTemplate, BadgeAward.template_id == BadgeTemplate.id)
            .where(BadgeAward.student_wallet == wallet)
            .where(BadgeAward.revoked == False)  # noqa: E712
            .order_by(BadgeAward.issued_at.desc())
        )
        result = await db.execute(query)
        badges = [
            BadgeSummaryItem(
                name=template.name,
                image_url=template.image_url,
                awarded_at=int(award.issued_at.timestamp()) if award.issued_at else None,
            )
            for award, template in result.all()
        ]
    except Exception as exc:
        logger.warning("Error cargando badges de %s: %s", wallet, exc)

    return StudentSummaryResponse(mrt_balance=balance_float, badges=badges)
