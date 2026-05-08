"""
Servicio de auditoría e idempotencia:
- reserva eventos antes de side effects externos
- registra auditoría
- marca eventos como processed/failed
"""

import logging
from typing import Optional

from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.audit import AuditLog, EventRecord
from app.models.events import AcademicEvent

logger = logging.getLogger(__name__)


async def reserve_event(db: AsyncSession, event: AcademicEvent) -> bool:
    """
    Reserva el event_id antes de ejecutar side effects externos.
    Retorna False si el evento ya existe.
    """
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
        status="processing",
        last_error=None,
    )
    db.add(record)

    try:
        await db.commit()
        logger.info("Evento %s reservado en BD", event.event_id)
        return True
    except IntegrityError:
        await db.rollback()
        logger.warning("Evento duplicado detectado al reservar: %s", event.event_id)
        return False


async def record_audit(
    db: AsyncSession,
    event_id: str,
    cid_ipfs: Optional[str] = None,
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
    logger.info("Auditoría registrada para evento %s", event_id)


async def mark_event_processed(db: AsyncSession, event_id: str) -> None:
    """Marca un evento reservado como procesado."""
    result = await db.execute(
        select(EventRecord).where(EventRecord.event_id == event_id)
    )
    record = result.scalar_one_or_none()
    if not record:
        raise ValueError(f"Evento no encontrado: {event_id}")

    record.status = "processed"
    record.last_error = None
    await db.flush()
    logger.info("Evento %s marcado como processed", event_id)


async def mark_event_failed(db: AsyncSession, event_id: str, error: str) -> None:
    """Marca un evento reservado como fallido."""
    result = await db.execute(
        select(EventRecord).where(EventRecord.event_id == event_id)
    )
    record = result.scalar_one_or_none()
    if not record:
        raise ValueError(f"Evento no encontrado: {event_id}")

    record.status = "failed"
    record.last_error = (error or "")[:4000]
    await db.commit()
    logger.error("Evento %s marcado como failed: %s", event_id, record.last_error)


async def get_event(db: AsyncSession, event_id: str) -> Optional[EventRecord]:
    result = await db.execute(
        select(EventRecord).where(EventRecord.event_id == event_id)
    )
    return result.scalar_one_or_none()


async def event_exists(db: AsyncSession, event_id: str) -> bool:
    """Compatibilidad: considera existente cualquier reserva previa."""
    result = await db.execute(
        select(EventRecord.event_id).where(EventRecord.event_id == event_id)
    )
    return result.scalar_one_or_none() is not None