"""
Router: POST /events/ingest

Recibe eventos académicos desde el plugin de Moodle,
valida el HMAC y dispara el flujo completo de procesamiento.
"""

import logging

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import ValidationError
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.database import get_db
from app.core.security import verify_hmac
from app.models.events import AcademicEvent, EventResponse
from app.services import events_service

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/events", tags=["Events"])


@router.post(
    "/ingest",
    response_model=EventResponse,
    status_code=status.HTTP_200_OK,
    summary="Recibir evento académico de Moodle",
    description=(
        "Recibe un evento firmado con HMAC-SHA256, valida la firma, "
        "procesa la recompensa MRT y registra la auditoría."
    ),
)
async def ingest_event(
    body: bytes = Depends(verify_hmac),
    db: AsyncSession = Depends(get_db),
) -> EventResponse:
    """
    Punto de entrada para eventos del plugin Moodle.

    El body ya fue validado por verify_hmac (dependency).
    Si el payload JSON es inválido retorna 422.
    Si el procesamiento interno falla retorna 500.
    """
    try:
        event = AcademicEvent.model_validate_json(body)
    except ValidationError as exc:
        logger.warning("Payload inválido en /events/ingest: %s", exc.errors())
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=exc.errors(),
        )

    logger.info(
        "Evento recibido: id=%s type=%s student=%s course=%s activity=%s coins=%s",
        event.event_id,
        event.event_type,
        event.student_id,
        event.course_id,
        event.activity_id,
        event.coins_amount,
    )

    try:
        return await events_service.process_event(db, event)
    except HTTPException:
        raise
    except Exception as exc:
        logger.exception("Error procesando evento %s", event.event_id)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error al procesar evento: {exc}",
        )
