"""
Router: endpoints de gestión de wallets custodiales.

Rutas:
  POST  /wallets/provision                              → provisiona wallet para un estudiante
  GET   /wallets/{student_id}                           → consulta wallet (sin private key)
  POST  /wallets/expire-course                          → cierra todos los enrollments de un curso
  PATCH /wallets/enrollments/{student_id}/{course_id}   → sobreescribe fecha de expiración
"""

import logging
from datetime import datetime, timezone

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, field_validator
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.database import get_db
from app.services import wallet_service

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/wallets", tags=["Wallets"])


# ── Schemas ───────────────────────────────────────────────────────────────────

class ProvisionRequest(BaseModel):
    student_id: str
    course_id:  str
    expires_at: datetime   # ISO 8601 — tomado de mdl_course.enddate o admin override

    @field_validator('expires_at', mode='before')
    @classmethod
    def strip_timezone(cls, v):
        if isinstance(v, str):
            # Parsear string ISO y quitar tzinfo
            dt = datetime.fromisoformat(v.replace('Z', '+00:00'))
            return dt.astimezone(timezone.utc).replace(tzinfo=None)
        if isinstance(v, datetime) and v.tzinfo is not None:
            return v.astimezone(timezone.utc).replace(tzinfo=None)
        return v


class ProvisionResponse(BaseModel):
    wallet_address: str
    created: bool          # True = wallet nueva, False = wallet reutilizada


class WalletResponse(BaseModel):
    student_id:     str
    wallet_address: str
    status:         str
    created_at:     datetime
    last_active_at: datetime | None


class ExpireCourseRequest(BaseModel):
    course_id: str


class ExpireCourseResponse(BaseModel):
    course_id:     str
    expired_count: int


class UpdateExpiresAtRequest(BaseModel):
    expires_at: datetime

    @field_validator('expires_at', mode='before')
    @classmethod
    def strip_timezone(cls, v):
        if isinstance(v, str):
            # Parsear string ISO y quitar tzinfo
            dt = datetime.fromisoformat(v.replace('Z', '+00:00'))
            return dt.astimezone(timezone.utc).replace(tzinfo=None)
        if isinstance(v, datetime) and v.tzinfo is not None:
            return v.astimezone(timezone.utc).replace(tzinfo=None)
        return v


# ── Endpoints ─────────────────────────────────────────────────────────────────

@router.post(
    "/provision",
    response_model=ProvisionResponse,
    summary="Provisionar wallet para un estudiante en un curso piloto",
)
async def provision_wallet(
    body: ProvisionRequest,
    db: AsyncSession = Depends(get_db),
) -> ProvisionResponse:
    """
    Llamado por el plugin Moodle en el primer evento de un estudiante
    en un curso con pilot_enabled = true.

    - Si el estudiante ya tiene wallet → la reutiliza.
    - Si se rematricula en el mismo curso → reactiva el enrollment con saldo 0.
    """
    result = await wallet_service.provision_wallet(
        db         = db,
        student_id = body.student_id,
        course_id  = body.course_id,
        expires_at = body.expires_at,
    )
    return ProvisionResponse(**result)


@router.get(
    "/{student_id}",
    response_model=WalletResponse,
    summary="Consultar wallet de un estudiante",
)
async def get_wallet(
    student_id: str,
    db: AsyncSession = Depends(get_db),
) -> WalletResponse:
    """Retorna la wallet del estudiante. Nunca expone la clave privada."""
    wallet = await wallet_service.get_wallet(db, student_id)
    if wallet is None:
        raise HTTPException(status_code=404, detail=f"Wallet no encontrada para student_id={student_id}")
    return WalletResponse(
        student_id     = wallet.student_id,
        wallet_address = wallet.wallet_address,
        status         = wallet.status,
        created_at     = wallet.created_at,
        last_active_at = wallet.last_active_at,
    )


@router.post(
    "/expire-course",
    response_model=ExpireCourseResponse,
    summary="Cerrar todos los enrollments activos de un curso",
)
async def expire_course(
    body: ExpireCourseRequest,
    db: AsyncSession = Depends(get_db),
) -> ExpireCourseResponse:
    """
    Llamado por la tarea programada del plugin al final del semestre.
    Guarda el snapshot de MRT y marca los enrollments como expired.
    """
    result = await wallet_service.expire_course(db, body.course_id)
    return ExpireCourseResponse(**result)


@router.patch(
    "/enrollments/{student_id}/{course_id}",
    summary="Sobreescribir fecha de expiración de un enrollment",
    status_code=204,
)
async def update_expires_at(
    student_id: str,
    course_id:  str,
    body: UpdateExpiresAtRequest,
    db: AsyncSession = Depends(get_db),
) -> None:
    """
    Permite al admin sobreescribir la fecha de cierre del semestre
    para un estudiante/curso específico, o para todos si se llama
    en batch desde el plugin.
    """
    updated = await wallet_service.update_expires_at(
        db         = db,
        student_id = student_id,
        course_id  = course_id,
        expires_at = body.expires_at,
    )
    if not updated:
        raise HTTPException(
            status_code=404,
            detail=f"Enrollment activo no encontrado para {student_id}/{course_id}",
        )