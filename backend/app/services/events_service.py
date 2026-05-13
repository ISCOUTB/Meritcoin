"""
Servicio principal: orquesta el flujo completo de procesamiento de eventos.

Flujo:
  1. Reservar event_id en BD ANTES de cualquier side effect (idempotencia real).
  2. Calcular recompensa MRT (fuente principal: coins_amount del plugin).
  3. Llamar contrato ERC-20 para mint MRT si el estudiante tiene wallet.
  4. Registrar auditoría en BD.
  5. Marcar evento como processed.
  6. Si cualquier paso 2-5 falla: rollback + marcar como failed.
"""

import logging

from sqlalchemy.ext.asyncio import AsyncSession

from app.models.events import AcademicEvent, EventResponse
from app.services import audit_service, tokens_service
from app.services.blockchain import blockchain

logger = logging.getLogger(__name__)


async def process_event(db: AsyncSession, event: AcademicEvent) -> EventResponse:
    """
    Procesa un evento académico y recompensa MRT al estudiante.

    Las insignias se gestionan por un flujo separado (badges_service).
    Retorna EventResponse con el estado final del procesamiento.
    """
    # ── 1. Idempotencia: reservar event_id antes de cualquier mint ────────────
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

    # coins_amount del plugin es la fuente de verdad.
    # calculate_mrt_reward solo actúa como fallback si coins_amount no viene.
    mrt_amount = float(event.coins_amount or 0)
    if mrt_amount <= 0:
        mrt_amount = float(tokens_service.calculate_mrt_reward(event))
        logger.warning(
            "coins_amount ausente o cero en evento %s — usando fallback local (%.4f MRT). "
            "Verificar reglas del plugin.",
            event.event_id, mrt_amount,
        )

    # ── 2-5. Mint + auditoría (con rollback total si algo falla) ──────────────
    tx_mrt: str | None = None

    try:
        # ── 2. Mint MRT ───────────────────────────────────────────────────────
        if has_wallet and mrt_amount > 0:
            tx_mrt = await blockchain.mint_mrt(wallet, mrt_amount)
            logger.info(
                "Mint MRT exitoso: event_id=%s wallet=%s amount=%.4f tx=%s",
                event.event_id, wallet, mrt_amount, tx_mrt,
            )
        elif not has_wallet:
            logger.warning(
                "Evento %s sin wallet registrado — se omite mint de MRT",
                event.event_id,
            )
        else:
            logger.info(
                "Evento %s con recompensa no positiva (%.4f) — no se acuñan tokens",
                event.event_id, mrt_amount,
            )

        # ── 3. Auditoría ──────────────────────────────────────────────────────
        await audit_service.record_audit(
            db=db,
            event_id=event.event_id,
            cid_ipfs=None,
            tx_badge=None,
            tx_mrt=tx_mrt,
            badge_id=None,
            mrt_amount=str(mrt_amount),
        )

        # ── 4. Marcar como procesado y confirmar transacción BD ───────────────
        await audit_service.mark_event_processed(db, event.event_id)
        await db.commit()

    except Exception as exc:
        await db.rollback()
        await audit_service.mark_event_failed(event.event_id, str(exc))
        logger.error("Error procesando evento %s: %s", event.event_id, exc, exc_info=True)
        # NO hacer raise — retornar status "queued" en vez de propagar error
        return EventResponse(
            event_id=event.event_id,
            status="queued",
            message="Blockchain no disponible — mint encolado para reintento automático",
        )

    # ── 5. Construir respuesta ────────────────────────────────────────────────
    coin_symbol = event.coin_symbol or "MRT"
    if tx_mrt:
        detail = f"{mrt_amount} {coin_symbol} acuñados"
    elif not has_wallet:
        detail = "Tokens no acuñados: estudiante sin wallet registrado"
    elif mrt_amount <= 0:
        detail = "Tokens no acuñados: recompensa no válida para este evento"
    else:
        detail = "Tokens no acuñados"

    return EventResponse(
        event_id=event.event_id,
        status="processed",
        badge_tx=None,
        mrt_tx=tx_mrt,
        cid_ipfs=None,
        message=f"Evento {event.event_id} procesado | {detail}",
    )
