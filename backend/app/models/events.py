"""
Schemas Pydantic para eventos académicos y respuestas de la API.

Modelos:
  - EventType:      Enum de tipos de evento soportados.
  - AcademicEvent:  Payload del plugin Moodle → backend.
  - EventResponse:  Respuesta tras procesar un evento.
  - StudentBadge:   Insignia en el historial de un estudiante.
  - StudentBalance: Saldo MRT de un estudiante.
"""

from datetime import datetime
from enum import Enum
from typing import Optional

from pydantic import BaseModel, Field


class EventType(str, Enum):
    """Tipos de eventos académicos soportados."""
    COMPLETION = "completion"   # Curso completado
    GRADE      = "grade"        # Calificación registrada


class AcademicEvent(BaseModel):
    """
    Payload que envía el plugin de Moodle al backend en cada evento.

    Campos desde v0.2.0:
      activity_id:   ID ofuscado del course module (ej: "CM-42").
                     None indica calificación final del curso.
      activity_name: Nombre de la actividad o del curso.
      coins_amount:  Tokens calculados en Moodle según reglas del curso.
                     Es la fuente de verdad del reward; el backend usa
                     tokens_service solo como fallback si viene None o 0.
      coin_symbol:   Símbolo de la moneda del curso (ej: "MRT", "BIO").
    """
    event_id:       str             = Field(...,  description="ID único del evento (garantía de idempotencia)")
    student_wallet: Optional[str]   = Field(None, pattern=r"^0x[0-9a-fA-F]{40}$",
                                            description="Dirección Ethereum del estudiante; null si aún no tiene wallet")
    student_id:     str             = Field(...,  description="ID del estudiante en Moodle (sin datos personales)")
    course_id:      str             = Field(...,  description="ID del curso en Moodle")
    course_name:    str             = Field("",   description="Nombre completo del curso")
    activity_id:    Optional[str]   = Field(None, description="ID del course module; None = calificación final")
    activity_name:  Optional[str]   = Field(None, description="Nombre de la actividad o del curso")
    event_type:     EventType       = Field(...,  description="Tipo de evento: completion o grade")
    grade:          Optional[float] = Field(None, description="Calificación obtenida (solo para event_type=grade)")
    coins_amount:   Optional[float] = Field(None, ge=0, description="Tokens a acuñar, calculados en Moodle")
    coin_symbol:    Optional[str]   = Field("MRT",       description="Símbolo de la moneda del curso")
    coin_name:      Optional[str]   = Field("MeritCoin", description="Nombre completo de la moneda")
    timestamp:      datetime        = Field(default_factory=lambda: datetime.now())

    model_config = {"json_schema_extra": {"example": {
        "event_id":       "evt-moodle-3-5-42-grade-1741651200",
        "student_wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
        "student_id":     "STU-3",
        "course_id":      "COURSE-5",
        "course_name":    "Introducción a Blockchain",
        "activity_id":    "CM-42",
        "activity_name":  "Quiz Semana 3",
        "event_type":     "grade",
        "grade":          85.0,
        "coins_amount":   1.0,
        "coin_symbol":    "MRT",
        "coin_name":      "MeritCoin",
        "timestamp":      "2026-03-10T21:00:00Z",
    }}}


class EventResponse(BaseModel):
    """Respuesta al endpoint /events/ingest tras procesar un evento."""
    event_id:  str
    status:    str           = "processed"
    badge_tx:  Optional[str] = None
    mrt_tx:    Optional[str] = None
    cid_ipfs:  Optional[str] = None
    message:   str           = ""


class StudentBadge(BaseModel):
    """Representación de una insignia en el historial de un estudiante."""
    badge_id:      str
    course_id:     str
    course_name:   str
    activity_name: Optional[str] = None
    event_type:    str
    uri:           str
    tx_hash:       str
    issued_at:     datetime


class StudentBalance(BaseModel):
    """Saldo MRT de un estudiante."""
    wallet:      str
    balance_mrt: str   # String para evitar pérdida de precisión con decimales
    balance_wei: str
