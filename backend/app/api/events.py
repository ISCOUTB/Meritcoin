"""
Endpoint: POST /events/ingest

Recibe eventos académicos desde el plugin de Moodle,
valida el HMAC y dispara el flujo completo de procesamiento.
"""

import json
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
    """
    Flujo:
    1. verify_hmac valida la firma del body
    2. Parsea el JSON como AcademicEvent
    3. Delega a events_service.process_event()
    """
    try:
        event = AcademicEvent.model_validate_json(body)
    except ValidationError as e:
        raise HTTPException(status_code=422, detail=e.errors())

    logger.info(f"Evento recibido: {event.event_id} tipo={event.event_type}")

    result = await events_service.process_event(db, event)
    return result
