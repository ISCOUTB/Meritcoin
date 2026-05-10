"""
Servicio de auditoría e idempotencia de eventos.

Responsabilidades:
  - reserve_event():        guarda el event_id ANTES de cualquier side effect
                            para garantizar idempotencia real.
  - record_audit():         registra el resultado de una emisión (tx hashes).
  - mark_event_processed(): actualiza el estado a "processed".
  - mark_event_failed():    actualiza el estado a "failed" abriendo
                            una sesión nueva para no depender de la sesión
                            que pudo haber sido rollbackeada.
  - get_event():            consulta un EventRecord por event_id.
"""

import logging
from typing import Optional

from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.database import async_session
from app.models.audit import AuditLog, EventRecord
from app.models.events import AcademicEvent

logger = logging.getLogger(__name__)


async def reserve_event(db: AsyncSession, event: AcademicEvent) -> bool:
    """
    Inserta el event_id en BD antes de ejecutar side effects externos.

    Retorna True si se reservó correctamente.
    Retorna False si el event_id ya existe (evento duplicado).
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
    """
    Registra la auditoría completa de una emisión en la tabla AuditLog.

    Usa flush() para que el registro quede en la transacción activa
    sin hacer commit; el commit lo hace el caller (events_service).
    """
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
    """
    Marca un EventRecord como procesado exitosamente.

    Usa flush() — el commit lo hace el caller.
    Lanza ValueError si el event_id no existe en BD.
    """
    result = await db.execute(
        select(EventRecord).where(EventRecord.event_id == event_id)
    )
    record = result.scalar_one_or_none()
    if not record:
        raise ValueError(f"Evento no encontrado en BD: {event_id}")

    record.status = "processed"
    record.last_error = None
    await db.flush()
    logger.info("Evento %s marcado como processed", event_id)


async def mark_event_failed(event_id: str, error: str) -> None:
    """
    Marca un EventRecord como fallido usando una sesión independiente.

    Abre una sesión nueva para garantizar que el registro se guarda
    incluso si la sesión original fue rollbackeada. Esta función es
    llamada desde el bloque except de events_service después del rollback.
    """
    async with async_session() as fresh_session:
        try:
            result = await fresh_session.execute(
                select(EventRecord).where(EventRecord.event_id == event_id)
            )
            record = result.scalar_one_or_none()
            if not record:
                logger.error(
                    "No se pudo marcar evento %s como failed: no existe en BD",
                    event_id,
                )
                return

            record.status = "failed"
            record.last_error = (error or "")[:4000]
            await fresh_session.commit()
            logger.error("Evento %s marcado como failed: %s", event_id, record.last_error)
        except Exception as exc:
            await fresh_session.rollback()
            logger.critical(
                "Fallo crítico al marcar evento %s como failed: %s",
                event_id, exc,
                exc_info=True,
            )


async def get_event(db: AsyncSession, event_id: str) -> Optional[EventRecord]:
    """Retorna el EventRecord de un event_id, o None si no existe."""
    result = await db.execute(
        select(EventRecord).where(EventRecord.event_id == event_id)
    )
    return result.scalar_one_or_none()
