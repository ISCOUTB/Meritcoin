"""Endpoints del sistema de insignias."""

import logging
from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query, status
from fastapi.responses import Response
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.database import get_db
from app.models.badges_schema import (
    BadgeAwardCreate, BadgeAwardResponse, BadgeTemplateCreate, BadgeTemplateResponse,
    BadgeTemplateUpdate, PublicVerifyResponse, SkillCreate, SkillResponse,
)
from app.services import badges_service as badge_service
from app.services.badges_service import _criteria_from_str
from app.services.certificate import generate_certificate_pdf

logger = logging.getLogger(__name__)
router = APIRouter(tags=["Badges"])


# ── Skills ────────────────────────────────────────────────────────────────────

@router.get("/skills", response_model=List[SkillResponse], summary="Listar skills")
async def list_skills(search: Optional[str] = Query(None), db: AsyncSession = Depends(get_db)):
    return await badge_service.list_skills(db, search=search)

@router.post("/skills", response_model=SkillResponse, status_code=201, summary="Crear skill")
async def create_skill(data: SkillCreate, db: AsyncSession = Depends(get_db)):
    return await badge_service.create_skill(db, name=data.name, description=data.description)


# ── Templates ─────────────────────────────────────────────────────────────────

@router.post("/badges/templates", response_model=BadgeTemplateResponse, status_code=201)
async def create_template(data: BadgeTemplateCreate, db: AsyncSession = Depends(get_db)):
    return _t(await badge_service.create_template(db, data))

@router.get("/badges/templates", response_model=List[BadgeTemplateResponse])
async def list_templates(
    created_by_id: Optional[str] = Query(None),
    only_active: bool = Query(True),
    db: AsyncSession = Depends(get_db),
):
    return [_t(t) for t in await badge_service.list_templates(db, created_by_id, only_active)]

@router.get("/badges/templates/{template_id}", response_model=BadgeTemplateResponse)
async def get_template(template_id: str, db: AsyncSession = Depends(get_db)):
    return _t(await badge_service.get_template(db, template_id))

@router.patch("/badges/templates/{template_id}", response_model=BadgeTemplateResponse)
async def update_template(
    template_id: str, data: BadgeTemplateUpdate,
    requester_id: str = Query(...), requester_role: str = Query(...),
    db: AsyncSession = Depends(get_db),
):
    return _t(await badge_service.update_template(db, template_id, data, requester_id, requester_role))

@router.delete("/badges/templates/{template_id}", status_code=204)
async def delete_template(
    template_id: str,
    requester_id: str = Query(...), requester_role: str = Query(...),
    db: AsyncSession = Depends(get_db),
):
    await badge_service.delete_template(db, template_id, requester_id, requester_role)


# ── Awards ────────────────────────────────────────────────────────────────────

@router.post("/badges/award", response_model=BadgeAwardResponse, status_code=201)
async def award_badge(data: BadgeAwardCreate, db: AsyncSession = Depends(get_db)):
    return _a(await badge_service.award_badge(db, data))

@router.get("/badges/student/{student_id}", response_model=List[BadgeAwardResponse])
async def get_student_awards(student_id: str, db: AsyncSession = Depends(get_db)):
    return [_a(a) for a in await badge_service.get_student_awards(db, student_id)]

@router.delete("/badges/award/{award_id}", response_model=BadgeAwardResponse)
async def revoke_award(
    award_id: str,
    requester_id: str = Query(...), requester_role: str = Query(...),
    db: AsyncSession = Depends(get_db),
):
    return _a(await badge_service.revoke_award(db, award_id, requester_id, requester_role))


# ── Verificación pública ──────────────────────────────────────────────────────

@router.get("/verify/{award_id}", response_model=PublicVerifyResponse, tags=["Public"])
async def verify_badge(award_id: str, db: AsyncSession = Depends(get_db)):
    return await badge_service.get_public_verification(db, award_id)


# ── Certificado PDF ───────────────────────────────────────────────────────────

@router.get("/badges/award/{award_id}/certificate", response_class=Response)
async def download_certificate(award_id: str, db: AsyncSession = Depends(get_db)):
    v = await badge_service.get_public_verification(db, award_id)
    if v.revoked:
        raise HTTPException(status_code=410, detail="Insignia revocada; el certificado no es válido.")
    pdf_bytes = generate_certificate_pdf(
        award_id=v.award_id, student_id=v.student_id, badge_name=v.badge_name,
        badge_description=v.badge_description, criteria=v.criteria, skills=v.skills,
        issued_by_id=v.issued_by_id, issued_at=v.issued_at,
        chain_status=v.chain_status, tx_hash=v.tx_hash,
    )
    return Response(content=pdf_bytes, media_type="application/pdf",
        headers={"Content-Disposition": f'attachment; filename="certificado_{award_id[:8]}.pdf"'})


# ── Helpers ───────────────────────────────────────────────────────────────────

def _t(t) -> BadgeTemplateResponse:
    return BadgeTemplateResponse(
        id=t.id, name=t.name, description=t.description, image_url=t.image_url,
        criteria=_criteria_from_str(t.criteria),
        skills=[SkillResponse(id=s.id, name=s.name, description=s.description, created_at=s.created_at) for s in t.skills],
        created_by_id=t.created_by_id, created_by_role=t.created_by_role,
        is_active=t.is_active, created_at=t.created_at, updated_at=t.updated_at,
    )

def _a(a) -> BadgeAwardResponse:
    return BadgeAwardResponse(
        id=a.id, template=_t(a.template), student_id=a.student_id,
        student_wallet=a.student_wallet, issued_by_id=a.issued_by_id,
        issued_by_role=a.issued_by_role, course_id=a.course_id,
        revoked=a.revoked, revoked_at=a.revoked_at,
        tx_hash=a.tx_hash, chain_status=a.chain_status, issued_at=a.issued_at,
    )