"""
Endpoints de consulta para estudiantes:
- GET /students/{wallet}/badges   → insignias de un estudiante
- GET /students/{wallet}/balance  → saldo MRT
- GET /students/{wallet}/summary  → resumen completo (para Moodle dashboard)
"""

import logging
from sqlalchemy import select, Numeric
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
    Balance MRT: suma desde audit_log (fallback si blockchain no responde).
    Badges: desde badge_awards (flujo manual separado).
    """

    # 1. Intentar balance desde blockchain; fallback a suma en BD
    balance_float = 0.0
    try:
        balance_mrt, _ = blockchain.get_mrt_balance(wallet)
        balance_float = float(str(balance_mrt))
    except Exception as e:
        logger.warning("[summary] Blockchain no disponible para %s: %s — usando BD", wallet, e)
        try:
            from sqlalchemy import func as sqlfunc
            result_sum = await db.execute(
                select(sqlfunc.sum(AuditLog.mrt_amount.cast(Numeric)))
                .join(EventRecord, AuditLog.event_id == EventRecord.event_id)
                .where(EventRecord.student_wallet == wallet)
                .where(EventRecord.status == "processed")
            )
            total = result_sum.scalar_one_or_none()
            logger.warning("DEBUG fallback total=%s type=%s", total, type(total))
            balance_float = float(total) if total else 0.0
        except Exception as e2:
            logger.error("[summary] Error fallback BD balance %s: %s", wallet, e2)
            balance_float = 0.0

    # 2. Badges desde badge_awards (flujo manual, separado de eventos MRT)
    badges: List[BadgeSummaryItem] = []
    try:
        from app.models.badges import BadgeAward, BadgeTemplate
        query = (
            select(BadgeAward, BadgeTemplate)
            .join(BadgeTemplate, BadgeAward.template_id == BadgeTemplate.id)
            .where(BadgeAward.student_wallet == wallet)
            .where(BadgeAward.revoked == False)
            .order_by(BadgeAward.issued_at.desc())
        )
        result = await db.execute(query)
        rows = result.all()
        for award, template in rows:
            badges.append(BadgeSummaryItem(
                name=template.name,
                image_url=template.image_url,
                awarded_at=int(award.issued_at.timestamp()) if award.issued_at else None,
            ))
    except Exception as e:
        logger.warning("[summary] Error cargando badges de %s: %s", wallet, e)

    return StudentSummaryResponse(
        mrt_balance=balance_float,
        badges=badges,
    )
