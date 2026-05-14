"""
Router: endpoints del sistema de insignias manuales (BadgeAward).

Rutas:
  Skills:
    GET  /skills                              → listar skills
    POST /skills                              → crear skill

  Templates:
    POST   /badges/templates                  → crear plantilla
    GET    /badges/templates                  → listar plantillas
    GET    /badges/templates/{id}             → obtener plantilla
    PATCH  /badges/templates/{id}             → actualizar plantilla
    DELETE /badges/templates/{id}             → eliminar plantilla

  Awards:
    POST   /badges/award                      → otorgar insignia
    GET    /badges/student/{student_id}       → insignias de un estudiante
    DELETE /badges/award/{award_id}           → revocar insignia

  Público:
    GET    /verify/{award_id}                 → verificar insignia (sin auth)
    GET    /badges/award/{award_id}/certificate → descargar PDF del certificado
"""

import logging
from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query, status
from fastapi.responses import Response
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.config import settings
from app.core.database import get_db
from app.models.badges_schema import (
    BadgeAwardCreate,
    BadgeAwardResponse,
    BadgeTemplateCreate,
    BadgeTemplateResponse,
    BadgeTemplateUpdate,
    PublicVerifyResponse,
    SkillCreate,
    SkillResponse,
)
from app.services import badges_service
from app.services.badges_service import criteria_from_str
from app.services.certificate import generate_certificate_pdf

logger = logging.getLogger(__name__)
router = APIRouter(tags=["Badges"])


# ── Skills ────────────────────────────────────────────────────────────────────

@router.get("/skills", response_model=List[SkillResponse], summary="Listar skills")
async def list_skills(
    search: Optional[str] = Query(None, description="Filtro por nombre (parcial)"),
    db: AsyncSession = Depends(get_db),
) -> List[SkillResponse]:
    return await badges_service.list_skills(db, search=search)


@router.post(
    "/skills",
    response_model=SkillResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Crear skill",
)
async def create_skill(
    data: SkillCreate,
    db: AsyncSession = Depends(get_db),
) -> SkillResponse:
    return await badges_service.create_skill(db, name=data.name, description=data.description)


# ── Templates ─────────────────────────────────────────────────────────────────

@router.post(
    "/badges/templates",
    response_model=BadgeTemplateResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Crear plantilla de insignia",
)
async def create_template(
    data: BadgeTemplateCreate,
    db: AsyncSession = Depends(get_db),
) -> BadgeTemplateResponse:
    return _map_template(await badges_service.create_template(db, data))


@router.get(
    "/badges/templates",
    response_model=List[BadgeTemplateResponse],
    summary="Listar plantillas",
)
async def list_templates(
    created_by_id: Optional[str] = Query(None),
    only_active: bool = Query(True),
    db: AsyncSession = Depends(get_db),
) -> List[BadgeTemplateResponse]:
    templates = await badges_service.list_templates(db, created_by_id, only_active)
    return [_map_template(t) for t in templates]


@router.get(
    "/badges/templates/{template_id}",
    response_model=BadgeTemplateResponse,
    summary="Obtener plantilla por ID",
)
async def get_template(
    template_id: str,
    db: AsyncSession = Depends(get_db),
) -> BadgeTemplateResponse:
    return _map_template(await badges_service.get_template(db, template_id))


@router.patch(
    "/badges/templates/{template_id}",
    response_model=BadgeTemplateResponse,
    summary="Actualizar plantilla",
)
async def update_template(
    template_id: str,
    data: BadgeTemplateUpdate,
    requester_id: str = Query(..., description="ID del usuario que hace la petición"),
    requester_role: str = Query(..., description="Rol: admin | teacher"),
    # TODO: reemplazar por JWT/token de sesión cuando se implemente autenticación.
    # Actualmente cualquier cliente puede auto-declararse admin — solo aceptable en desarrollo.
    db: AsyncSession = Depends(get_db),
) -> BadgeTemplateResponse:
    return _map_template(
        await badges_service.update_template(db, template_id, data, requester_id, requester_role)
    )


@router.delete(
    "/badges/templates/{template_id}",
    status_code=status.HTTP_204_NO_CONTENT,
    summary="Eliminar plantilla",
)
async def delete_template(
    template_id: str,
    requester_id: str = Query(...),
    requester_role: str = Query(...),
    # TODO: reemplazar por JWT/token de sesión cuando se implemente autenticación.
    # Actualmente cualquier cliente puede auto-declararse admin — solo aceptable en desarrollo.
    db: AsyncSession = Depends(get_db),
) -> None:
    await badges_service.delete_template(db, template_id, requester_id, requester_role)


# ── Awards ────────────────────────────────────────────────────────────────────

@router.post(
    "/badges/award",
    response_model=BadgeAwardResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Otorgar insignia a un estudiante",
)
async def award_badge(
    data: BadgeAwardCreate,
    db: AsyncSession = Depends(get_db),
) -> BadgeAwardResponse:
    return _map_award(await badges_service.award_badge(db, data))

@router.post(
    "/badges/award/{award_id}/retry-chain",
    response_model=BadgeAwardResponse,
    summary="Reintentar mint en blockchain",
)
async def retry_chain(
    award_id: str,
    db: AsyncSession = Depends(get_db),
) -> BadgeAwardResponse:
    """
    Reintenta el mint de un award con chain_status skipped o failed
    sin crear un registro duplicado.
    """
    return _map_award(await badges_service.retry_chain(db, award_id))


@router.get(
    "/badges/student/{student_id}",
    response_model=List[BadgeAwardResponse],
    summary="Listar insignias de un estudiante",
)
async def get_student_awards(
    student_id: str,
    db: AsyncSession = Depends(get_db),
) -> List[BadgeAwardResponse]:
    awards = await badges_service.get_student_awards(db, student_id)
    return [_map_award(a) for a in awards]


@router.delete(
    "/badges/award/{award_id}",
    response_model=BadgeAwardResponse,
    summary="Revocar insignia",
)
async def revoke_award(
    award_id: str,
    requester_id: str = Query(...),
    requester_role: str = Query(...),
    # TODO: reemplazar por JWT/token de sesión cuando se implemente autenticación.
    # Actualmente cualquier cliente puede auto-declararse admin — solo aceptable en desarrollo.
    db: AsyncSession = Depends(get_db),
) -> BadgeAwardResponse:
    return _map_award(
        await badges_service.revoke_award(db, award_id, requester_id, requester_role)
    )


# ── Verificación pública ──────────────────────────────────────────────────────

@router.get(
    "/verify/{award_id}",
    response_model=PublicVerifyResponse,
    tags=["Public"],
    summary="Verificar insignia (sin autenticación)",
)
async def verify_badge(
    award_id: str,
    db: AsyncSession = Depends(get_db),
) -> PublicVerifyResponse:
    return await badges_service.get_public_verification(db, award_id)


# ── Certificado PDF ───────────────────────────────────────────────────────────

@router.get(
    "/badges/award/{award_id}/certificate",
    response_class=Response,
    summary="Descargar certificado PDF de una insignia",
)
async def download_certificate(
    award_id: str,
    db: AsyncSession = Depends(get_db),
) -> Response:
    """
    Genera y retorna el certificado PDF de una insignia otorgada.
    Retorna HTTP 410 si la insignia fue revocada.
    """
    verification = await badges_service.get_public_verification(db, award_id)
    if verification.revoked:
        raise HTTPException(
            status_code=status.HTTP_410_GONE,
            detail="Insignia revocada — el certificado ya no es válido.",
        )

    pdf_bytes = generate_certificate_pdf(
        award_id=verification.award_id,
        student_id=verification.student_id,
        badge_name=verification.badge_name,
        badge_description=verification.badge_description,
        criteria=verification.criteria,
        skills=verification.skills,
        issued_by_id=verification.issued_by_id,
        issued_at=verification.issued_at,
        chain_status=verification.chain_status,
        tx_hash=verification.tx_hash,
        verify_base_url=f"{settings.public_base_url}/verify",
    )

    return Response(
        content=pdf_bytes,
        media_type="application/pdf",
        headers={
            "Content-Disposition": f'attachment; filename="certificado_{award_id[:8]}.pdf"',
        },
    )


# ── Mappers (ORM → Schema) ────────────────────────────────────────────────────

def _map_template(template) -> BadgeTemplateResponse:
    """Convierte un modelo ORM BadgeTemplate al schema de respuesta."""
    return BadgeTemplateResponse(
        id=template.id,
        name=template.name,
        description=template.description,
        image_url=template.image_url,
        criteria=criteria_from_str(template.criteria),
        skills=[
            SkillResponse(
                id=skill.id,
                name=skill.name,
                description=skill.description,
                created_at=skill.created_at,
            )
            for skill in template.skills
        ],
        created_by_id=template.created_by_id,
        created_by_role=template.created_by_role,
        is_active=template.is_active,
        created_at=template.created_at,
        updated_at=template.updated_at,
    )


def _map_award(award) -> BadgeAwardResponse:
    """Convierte un modelo ORM BadgeAward al schema de respuesta."""
    return BadgeAwardResponse(
        id=award.id,
        template=_map_template(award.template),
        student_id=award.student_id,
        student_wallet=award.student_wallet,
        issued_by_id=award.issued_by_id,
        issued_by_role=award.issued_by_role,
        course_id=award.course_id,
        revoked=award.revoked,
        revoked_at=award.revoked_at,
        tx_hash=award.tx_hash,
        chain_status=award.chain_status,
        issued_at=award.issued_at,
    )
