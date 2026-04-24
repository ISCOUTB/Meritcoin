"""
Servicio de auditoría: registra cada operación en la tabla audit_log.
"""

import logging
from typing import Optional

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

from app.models.audit import AuditLog, EventRecord
from app.models.events import AcademicEvent

logger = logging.getLogger(__name__)


async def record_event(db: AsyncSession, event: AcademicEvent) -> None:
    """Registra el evento académico en la tabla events."""
    record = EventRecord(
        event_id=event.event_id,
        student_wallet=event.student_wallet,
        student_id=event.student_id,
        course_id=event.course_id,
        course_name=event.course_name,
        activity_id=event.activity_id,
        activity_name=event.activity_name,
        event_type=event.event_type.value,
        grade=event.grade,
        coins_amount=event.coins_amount,
        coin_symbol=event.coin_symbol,
    )
    db.add(record)
    await db.flush()
    logger.info(f"Evento {event.event_id} registrado en BD")


async def record_audit(
    db: AsyncSession,
    event_id: str,
    cid_ipfs: str,
    tx_badge: Optional[str] = None,
    tx_mrt: Optional[str] = None,
    badge_id: Optional[str] = None,
    mrt_amount: Optional[str] = None,
) -> None:
    """Registra la auditoría completa de una emisión."""
    log = AuditLog(
        event_id=event_id,
        cid_ipfs=cid_ipfs,
        tx_badge=tx_badge,
        tx_mrt=tx_mrt,
        badge_id=badge_id,
        mrt_amount=mrt_amount,
    )
    db.add(log)
    await db.flush()
    logger.info(f"Auditoría registrada para evento {event_id}")


async def event_exists(db: AsyncSession, event_id: str) -> bool:
    """Verifica si un evento ya fue procesado (idempotencia)."""
    result = await db.execute(
        select(EventRecord.event_id).where(EventRecord.event_id == event_id)
    )
    return result.scalar_one_or_none() is not None
