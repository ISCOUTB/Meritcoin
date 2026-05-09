"""Lógica de negocio para el sistema de insignias."""

import logging
from typing import List, Optional

from fastapi import HTTPException, status
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.models.audit import EventRecord
from app.models.badges import BadgeAward, BadgeTemplate, Skill
from app.models.badges_schema import (
    BadgeAwardCreate, BadgeTemplateCreate, BadgeTemplateUpdate, PublicVerifyResponse,
)

logger = logging.getLogger(__name__)


def _criteria_to_str(criteria: Optional[List[str]]) -> Optional[str]:
    if not criteria:
        return None
    return "\n".join(c.strip() for c in criteria if c.strip())


def _criteria_from_str(raw: Optional[str]) -> List[str]:
    if not raw:
        return []
    return [c for c in raw.split("\n") if c.strip()]


async def _get_or_create_skills(db, skill_ids, new_skill_names) -> List[Skill]:
    skills = []
    if skill_ids:
        result = await db.execute(select(Skill).where(Skill.id.in_(skill_ids)))
        found = result.scalars().all()
        if len(found) != len(skill_ids):
            found_ids = {s.id for s in found}
            missing = [sid for sid in skill_ids if sid not in found_ids]
            raise HTTPException(status_code=404, detail=f"Skills no encontradas: {missing}")
        skills.extend(found)
    if new_skill_names:
        for name in new_skill_names:
            name = name.strip()
            if not name:
                continue
            existing = await db.execute(select(Skill).where(Skill.name == name))
            skill = existing.scalar_one_or_none()
            if not skill:
                skill = Skill(name=name)
                db.add(skill)
                await db.flush()
            skills.append(skill)
    return skills


async def _assert_teacher_can_award(db, teacher_id, student_id, course_id):
    """Valida que el estudiante tenga eventos en el curso dado (relación via EventRecord)."""
    query = select(EventRecord).where(EventRecord.student_id == student_id)
    if course_id:
        query = query.where(EventRecord.course_id == course_id)
    result = await db.execute(query)
    if not result.scalars().first():
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail=f"El profesor '{teacher_id}' no puede otorgar insignias al estudiante '{student_id}' en este curso.",
        )


# ── Skills ────────────────────────────────────────────────────────────────────

async def list_skills(db, search=None):
    query = select(Skill).order_by(Skill.name)
    if search:
        query = query.where(Skill.name.ilike(f"%{search}%"))
    result = await db.execute(query)
    return result.scalars().all()


async def create_skill(db, name: str, description=None):
    existing = await db.execute(select(Skill).where(Skill.name == name))
    if existing.scalar_one_or_none():
        raise HTTPException(status_code=409, detail=f"La skill '{name}' ya existe.")
    skill = Skill(name=name, description=description)
    db.add(skill)
    await db.commit()
    await db.refresh(skill)
    return skill


# ── Templates ─────────────────────────────────────────────────────────────────

async def create_template(db, data: BadgeTemplateCreate):
    skills = await _get_or_create_skills(db, data.skill_ids, data.new_skills)
    template = BadgeTemplate(
        name=data.name, description=data.description, image_url=data.image_url,
        criteria=_criteria_to_str(data.criteria),
        created_by_id=data.created_by_id, created_by_role=data.created_by_role.value,
    )
    template.skills = skills
    db.add(template)
    await db.commit()
    await db.refresh(template)
    result = await db.execute(
        select(BadgeTemplate).options(selectinload(BadgeTemplate.skills)).where(BadgeTemplate.id == template.id)
    )
    return result.scalar_one()


async def list_templates(db, created_by_id=None, only_active=True):
    query = select(BadgeTemplate).options(selectinload(BadgeTemplate.skills)).order_by(BadgeTemplate.created_at.desc())
    if only_active:
        query = query.where(BadgeTemplate.is_active == True)  # noqa: E712
    if created_by_id:
        query = query.where(BadgeTemplate.created_by_id == created_by_id)
    result = await db.execute(query)
    return result.scalars().all()


async def get_template(db, template_id: str):
    result = await db.execute(
        select(BadgeTemplate).options(selectinload(BadgeTemplate.skills)).where(BadgeTemplate.id == template_id)
    )
    t = result.scalar_one_or_none()
    if not t:
        raise HTTPException(status_code=404, detail="Plantilla no encontrada.")
    return t


async def update_template(db, template_id, data: BadgeTemplateUpdate, requester_id, requester_role):
    t = await get_template(db, template_id)
    if requester_role != "admin" and t.created_by_id != requester_id:
        raise HTTPException(status_code=403, detail="Solo el creador o un admin puede editar esta plantilla.")
    if data.name is not None:        t.name        = data.name
    if data.description is not None: t.description = data.description
    if data.image_url is not None:   t.image_url   = data.image_url
    if data.is_active is not None:   t.is_active   = data.is_active
    if data.criteria is not None:    t.criteria    = _criteria_to_str(data.criteria)
    if data.skill_ids is not None or data.new_skills is not None:
        t.skills = await _get_or_create_skills(db, data.skill_ids, data.new_skills)
    await db.commit()
    result = await db.execute(
        select(BadgeTemplate).options(selectinload(BadgeTemplate.skills)).where(BadgeTemplate.id == template_id)
    )
    return result.scalar_one()


async def delete_template(db, template_id, requester_id, requester_role):
    t = await get_template(db, template_id)
    if requester_role != "admin" and t.created_by_id != requester_id:
        raise HTTPException(status_code=403, detail="Solo el creador o un admin puede eliminar esta plantilla.")
    awards_q = await db.execute(select(BadgeAward).where(BadgeAward.template_id == template_id).limit(1))
    if awards_q.scalar_one_or_none():
        t.is_active = False  # soft delete si ya tiene insignias otorgadas
        await db.commit()
        return
    await db.delete(t)
    await db.commit()


# ── Awards ────────────────────────────────────────────────────────────────────

async def award_badge(db, data: BadgeAwardCreate):
    t = await get_template(db, data.template_id)
    if not t.is_active:
        raise HTTPException(status_code=400, detail="La plantilla está desactivada.")
    if data.issued_by_role == "teacher":
        if not data.course_id:
            raise HTTPException(status_code=422, detail="'course_id' es requerido para profesores.")
        await _assert_teacher_can_award(db, data.issued_by_id, data.student_id, data.course_id)

    simulated_tx = f"0xSIMULATED_{data.student_id}_{data.template_id[:8]}"
    award = BadgeAward(
        template_id=data.template_id, student_id=data.student_id,
        student_wallet=data.student_wallet, issued_by_id=data.issued_by_id,
        issued_by_role=data.issued_by_role.value, course_id=data.course_id,
        tx_hash=simulated_tx, chain_status="simulated",
    )
    db.add(award)
    await db.commit()
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.id == award.id)
    )
    return result.scalar_one()


async def get_student_awards(db, student_id: str):
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.student_id == student_id)
        .order_by(BadgeAward.issued_at.desc())
    )
    return result.scalars().all()


async def revoke_award(db, award_id, requester_id, requester_role):
    from datetime import datetime
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.id == award_id)
    )
    award = result.scalar_one_or_none()
    if not award:
        raise HTTPException(status_code=404, detail="Insignia no encontrada.")
    if award.revoked:
        raise HTTPException(status_code=400, detail="La insignia ya fue revocada.")
    if requester_role != "admin" and award.issued_by_id != requester_id:
        raise HTTPException(status_code=403, detail="Solo el emisor original o un admin puede revocar.")
    award.revoked = True
    award.revoked_at = datetime.utcnow()
    award.revoked_by_id = requester_id
    await db.commit()
    await db.refresh(award)
    return award


# ── Verificación pública ──────────────────────────────────────────────────────

async def get_public_verification(db, award_id: str) -> PublicVerifyResponse:
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.id == award_id)
    )
    award = result.scalar_one_or_none()
    if not award:
        raise HTTPException(status_code=404, detail="Insignia no encontrada.")
    t = award.template
    return PublicVerifyResponse(
        award_id=award.id, valid=not award.revoked, student_id=award.student_id,
        badge_name=t.name, badge_description=t.description, badge_image_url=t.image_url,
        criteria=_criteria_from_str(t.criteria), skills=[s.name for s in t.skills],
        issued_by_id=award.issued_by_id, issued_by_role=award.issued_by_role,
        issued_at=award.issued_at, chain_status=award.chain_status,
        tx_hash=award.tx_hash, revoked=award.revoked, revoked_at=award.revoked_at,
    )