"""
Servicio principal: orquesta el flujo completo de procesamiento de eventos.

Flujo:
  1. Reservar event_id en BD ANTES de cualquier side effect (idempotencia real)
  2. Calcular recompensa MRT
  3. Llamar contrato ERC-20 para mint MRT
  4. Registrar auditoría
  5. Marcar evento como processed (o failed si algo falla)
"""

import logging

from sqlalchemy.ext.asyncio import AsyncSession

from app.models.events import AcademicEvent, EventResponse
from app.services import audit_service, tokens_service
from app.services.blockchain import blockchain

logger = logging.getLogger(__name__)


async def process_event(db: AsyncSession, event: AcademicEvent) -> EventResponse:
    """
    Procesa un evento académico para recompensar MRT.
    Las insignias se gestionan por un flujo aparte y manual.
    """
    # ── 1. Reservar event_id antes de cualquier mint ─────────────────────────
    reserved = await audit_service.reserve_event(db, event)
    if not reserved:
        logger.warning("Evento duplicado rechazado: %s", event.event_id)
        return EventResponse(
            event_id=event.event_id,
            status="duplicate",
            message="Evento ya fue procesado anteriormente",
        )

    wallet = (event.student_wallet or "").strip()
    has_wallet = bool(wallet)

    mrt_amount = float(event.coins_amount or 0)
    if mrt_amount <= 0:
        mrt_amount = float(tokens_service.calculate_mrt_reward(event))

    # ── 2. Mint MRT ──────────────────────────────────────────────────────────
    tx_mrt = None
    try:
        if has_wallet and mrt_amount > 0:
            tx_mrt = blockchain.mint_mrt(wallet, mrt_amount)
            logger.info(
                "Mint MRT exitoso: event_id=%s wallet=%s amount=%s tx=%s",
                event.event_id, wallet, mrt_amount, tx_mrt,
            )
        elif not has_wallet:
            logger.warning("Evento %s sin wallet; se omite mint de MRT", event.event_id)
        else:
            logger.info(
                "Evento %s con recompensa no positiva (%s); no se acuñan tokens",
                event.event_id, mrt_amount,
            )

        # ── 3. Auditoría ─────────────────────────────────────────────────────
        await audit_service.record_audit(
            db=db,
            event_id=event.event_id,
            cid_ipfs=None,
            tx_badge=None,
            tx_mrt=tx_mrt,
            badge_id=None,
            mrt_amount=str(mrt_amount),
        )

        # ── 4. Marcar como procesado ─────────────────────────────────────────
        await audit_service.mark_event_processed(db, event.event_id)
        await db.commit()

    except Exception as e:
        await db.rollback()
        await audit_service.mark_event_failed(db, event.event_id, str(e))
        logger.error("Error procesando evento %s: %s", event.event_id, e)
        raise

    # ── 5. Respuesta ─────────────────────────────────────────────────────────
    message_parts = [f"Evento {event.event_id} procesado"]

    if tx_mrt:
        message_parts.append(f"{mrt_amount} {event.coin_symbol or 'MRT'} acuñados")
    elif not has_wallet:
        message_parts.append("Tokens no acuñados: estudiante sin wallet")
    elif mrt_amount <= 0:
        message_parts.append("Tokens no acuñados: recompensa no válida")
    else:
        message_parts.append("Tokens no acuñados")

    return EventResponse(
        event_id=event.event_id,
        status="processed",
        badge_tx=None,
        mrt_tx=tx_mrt,
        cid_ipfs=None,
        message=" | ".join(message_parts),
    )