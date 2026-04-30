"""
Endpoint: POST /events/ingest

Recibe eventos académicos desde el plugin de Moodle,
valida el HMAC y dispara el flujo completo de procesamiento.
"""

import logging

from fastapi import APIRouter, Depends, HTTPException
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
    summary="Recibir evento académico de Moodle",
    description="Recibe un evento firmado con HMAC, procesa la insignia y acuña MRT.",
)
async def ingest_event(
    body: bytes = Depends(verify_hmac),
    db: AsyncSession = Depends(get_db),
) -> EventResponse:
    try:
        event = AcademicEvent.model_validate_json(body)
    except ValidationError as e:
        logger.warning("Payload inválido recibido en /events/ingest: %s", e.errors())
        raise HTTPException(status_code=422, detail=e.errors())

    logger.info(
        "Evento recibido: id=%s type=%s wallet=%s course=%s activity=%s coins=%s",
        event.event_id,
        event.event_type,
        event.student_wallet,
        event.course_id,
        getattr(event, "activity_id", None),
        getattr(event, "coins_amount", None),
    )

    try:
        result = await events_service.process_event(db, event)
        return result
    except HTTPException:
        raise
    except Exception as e:
        logger.exception("Error procesando evento %s", event.event_id)
        raise HTTPException(status_code=500, detail=f"Error processing event: {str(e)}")