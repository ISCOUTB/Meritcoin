"""
Worker de procesamiento de eventos.

Responsabilidades:
  1. Re-exporta process_event para el endpoint /events/ingest (flujo síncrono).
  2. enqueue(): persiste transacciones fallidas en pending_transactions.
  3. retry_loop(): tarea asyncio que cada 30s reintenta las pendientes
     cuando la blockchain está disponible.
"""

import asyncio
import logging
from datetime import datetime, timedelta

from sqlalchemy import select

from app.core.database import async_session
from app.models.audit import PendingTransaction
from app.services.blockchain import blockchain
from app.services.events_service import process_event

logger = logging.getLogger(__name__)

_RETRY_INTERVAL = 30   # segundos entre cada ciclo del worker
_MAX_ATTEMPTS   = 5    # después de 5 fallos se abandona


async def enqueue(
    event_id: str,
    tx_type: str,
    wallet: str,
    amount: float | None = None,
    badge_id: str | None = None,
    uri: str | None = None,
) -> None:
    """Persiste una transacción fallida para reintento posterior."""
    async with async_session() as db:
        pending = PendingTransaction(
            event_id  = event_id,
            tx_type   = tx_type,
            wallet    = wallet,
            amount    = amount,
            badge_id  = badge_id,
            uri       = uri,
            attempts  = 0,
            retry_after = datetime.now(),
        )
        db.add(pending)
        await db.commit()
        logger.info("Transacción encolada para reintento: event_id=%s tipo=%s", event_id, tx_type)


async def _process_pending(db, pending: PendingTransaction) -> bool:
    """Intenta ejecutar una transacción pendiente. Retorna True si tuvo éxito."""
    try:
        if pending.tx_type == "mint_mrt":
            await blockchain.mint_mrt(pending.wallet, pending.amount)
        elif pending.tx_type == "mint_badge":
            await blockchain.mint_badge(pending.wallet, int(pending.badge_id), pending.uri)
        elif pending.tx_type == "burn_mrt":
            await blockchain.burn_mrt(pending.wallet, pending.amount)
        else:
            logger.error("Tipo de transacción desconocido: %s", pending.tx_type)
            return False

        logger.info(
            "Reintento exitoso: event_id=%s tipo=%s wallet=%s",
            pending.event_id, pending.tx_type, pending.wallet,
        )
        return True

    except Exception as exc:
        logger.warning(
            "Reintento fallido (intento %d/%d): event_id=%s — %s",
            pending.attempts + 1, _MAX_ATTEMPTS, pending.event_id, exc,
        )
        return False


async def retry_loop() -> None:
    """
    Tarea asyncio que corre indefinidamente.
    Cada 30s revisa pending_transactions y reintenta las que estén listas.
    """
    logger.info("retry_loop iniciado — revisando cada %ds", _RETRY_INTERVAL)

    while True:
        await asyncio.sleep(_RETRY_INTERVAL)

        if not blockchain.is_connected():
            logger.debug("retry_loop: blockchain no disponible, saltando ciclo")
            continue

        try:
            async with async_session() as db:
                now = datetime.now()
                result = await db.execute(
                    select(PendingTransaction)
                    .where(PendingTransaction.attempts < _MAX_ATTEMPTS)
                    .where(PendingTransaction.retry_after <= now)
                    .order_by(PendingTransaction.created_at)
                )
                pendings = result.scalars().all()

                if not pendings:
                    continue

                logger.info("retry_loop: %d transacciones pendientes encontradas", len(pendings))

                for pending in pendings:
                    success = await _process_pending(db, pending)

                    if success:
                        await db.delete(pending)
                    else:
                        pending.attempts += 1
                        pending.last_error = f"Intento {pending.attempts} fallido"
                        # backoff exponencial: 1min, 2min, 4min, 8min...
                        pending.retry_after = datetime.now() + timedelta(
                            minutes=2 ** pending.attempts
                        )

                await db.commit()

        except Exception as exc:
            logger.error("Error en retry_loop: %s", exc, exc_info=True)


__all__ = ["process_event", "enqueue", "retry_loop"]