"""
Servicio principal: orquesta el flujo completo de procesamiento de eventos.

Flujo:
  1. Validar idempotencia (rechazar event_id duplicado)
  2. Calcular recompensa MRT
  3. Llamar contrato ERC-20 para mint MRT
  4. Registrar evento y auditoría
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
    if await audit_service.event_exists(db, event.event_id):
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

    tx_mrt = None

    if has_wallet and mrt_amount > 0:
        try:
            tx_mrt = blockchain.mint_mrt(wallet, mrt_amount)
            logger.info(
                "Mint MRT exitoso: event_id=%s wallet=%s amount=%s tx=%s",
                event.event_id,
                wallet,
                mrt_amount,
                tx_mrt,
            )
        except Exception as e:
            logger.error("Error mint MRT para evento %s: %s", event.event_id, e)
    elif not has_wallet:
        logger.warning("Evento %s sin wallet; se omite mint de MRT", event.event_id)
    else:
        logger.info(
            "Evento %s con recompensa MRT no positiva (%s); no se acuñan tokens",
            event.event_id,
            mrt_amount,
        )

    await audit_service.record_event(db, event)
    await audit_service.record_audit(
        db=db,
        event_id=event.event_id,
        cid_ipfs=None,
        tx_badge=None,
        tx_mrt=tx_mrt,
        badge_id=None,
        mrt_amount=str(mrt_amount),
    )

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