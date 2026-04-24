"""
Servicio principal: orquesta el flujo completo de procesamiento de eventos.

Flujo:
  1. Validar idempotencia (rechazar event_id duplicado)
  2. Generar metadatos OBv2
  3. Simular pin en IPFS (CID fake pero consistente)
  4. Llamar contrato ERC-1155 para mintBadge
  5. Llamar contrato ERC-20 para mint MRT
  6. Registrar todo en audit_log
"""

import logging

from sqlalchemy.ext.asyncio import AsyncSession

from app.models.events import AcademicEvent, EventResponse
from app.services import audit_service, badges_service, tokens_service
from app.services.blockchain import blockchain

logger = logging.getLogger(__name__)


async def process_event(db: AsyncSession, event: AcademicEvent) -> EventResponse:
    """
    Procesa un evento académico de principio a fin.
    Retorna un EventResponse con los hashes de transacción y CID.
    """
    # ── 1. Idempotencia ──────────────────────────────────────────────
    if await audit_service.event_exists(db, event.event_id):
        logger.warning(f"Evento duplicado rechazado: {event.event_id}")
        return EventResponse(
            event_id=event.event_id,
            status="duplicate",
            message="Evento ya fue procesado anteriormente",
        )

    # ── 2. Generar metadatos OBv2 ────────────────────────────────────
    badge_id = badges_service.generate_badge_id(event)
    metadata = badges_service.generate_obv2_metadata(event)

    # ── 3. Simular IPFS ──────────────────────────────────────────────
    cid_ipfs = badges_service.simulate_ipfs_pin(metadata)
    logger.info(f"Metadatos generados — CID: {cid_ipfs}")

    # ── 4. Mint de insignia ERC-1155 ─────────────────────────────────
    tx_badge = None
    try:
        ipfs_uri = f"ipfs://{cid_ipfs}"
        tx_badge = blockchain.mint_badge(event.student_wallet, badge_id, ipfs_uri)
    except Exception as e:
        logger.error(f"Error mintBadge: {e}")
        # Continuar — registramos el intento en auditoría

    # ── 5. Mint de tokens MRT ERC-20 ─────────────────────────────────
    tx_mrt = None
    mrt_amount = tokens_service.calculate_mrt_reward(event)
    if mrt_amount > 0:
        try:
            tx_mrt = blockchain.mint_mrt(event.student_wallet, mrt_amount)
        except Exception as e:
            logger.error(f"Error mint MRT: {e}")

    # ── 6. Registrar en BD ───────────────────────────────────────────
    await audit_service.record_event(db, event)
    await audit_service.record_audit(
        db=db,
        event_id=event.event_id,
        cid_ipfs=cid_ipfs,
        tx_badge=tx_badge,
        tx_mrt=tx_mrt,
        badge_id=str(badge_id),
        mrt_amount=str(mrt_amount),
    )

    return EventResponse(
        event_id=event.event_id,
        status="processed",
        badge_tx=tx_badge,
        mrt_tx=tx_mrt,
        cid_ipfs=cid_ipfs,
        message=f"Badge #{badge_id} emitido, {mrt_amount} MRT acuñados",
    )
