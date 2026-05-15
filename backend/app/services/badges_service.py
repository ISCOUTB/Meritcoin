"""
Lógica de negocio para el sistema de insignias MeritCoin.

Cubre:
  - Skills:     listar, crear
  - Templates:  crear, listar, obtener, actualizar, eliminar (soft-delete)
  - Awards:     otorgar, listar por estudiante, revocar
  - Verificación pública por award_id
"""

import logging
from datetime import datetime, timezone
from typing import List, Optional

from fastapi import HTTPException, status
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.core.config import settings
from app.models.audit import EventRecord
from app.models.wallets import WalletRegistry
from app.models.badges import BadgeAward, BadgeTemplate, Skill
from app.models.badges_schema import (
    BadgeAwardCreate,
    BadgeTemplateCreate,
    BadgeTemplateUpdate,
    PublicVerifyResponse,
)
from app.services.blockchain import blockchain
from app.services.ipfs_service import upload_json_to_ipfs, get_ipfs_gateway_url
from app.workers.processor import enqueue  # ← NUEVO

logger = logging.getLogger(__name__)


# ── Helpers internos ──────────────────────────────────────────────────────────

def _criteria_to_str(criteria: Optional[List[str]]) -> Optional[str]:
    if not criteria:
        return None
    return "\n".join(c.strip() for c in criteria if c.strip())


def criteria_from_str(raw: Optional[str]) -> List[str]:
    if not raw:
        return []
    return [c for c in raw.split("\n") if c.strip()]


async def _get_or_create_skills(
    db: AsyncSession,
    skill_ids: Optional[List[str]],
    new_skill_names: Optional[List[str]],
) -> List[Skill]:
    skills: List[Skill] = []

    if skill_ids:
        result = await db.execute(select(Skill).where(Skill.id.in_(skill_ids)))
        found = result.scalars().all()
        if len(found) != len(skill_ids):
            found_ids = {s.id for s in found}
            missing = [sid for sid in skill_ids if sid not in found_ids]
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Skills no encontradas: {missing}",
            )
        skills.extend(found)

    if new_skill_names:
        for raw_name in new_skill_names:
            name = raw_name.strip()
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


async def _assert_teacher_can_award(
    db: AsyncSession,
    teacher_id: str,
    student_id: str,
    course_id: str,
) -> None:
    student_id_normalized = student_id if student_id.startswith("STU-") else f"STU-{student_id}"
    course_id_normalized = course_id if course_id.startswith("COURSE-") else f"COURSE-{course_id}"

    query = (
        select(EventRecord)
        .where(EventRecord.student_id == student_id_normalized)
        .where(EventRecord.course_id == course_id_normalized)
    )
    result = await db.execute(query)
    if not result.scalars().first():
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail=(
                f"El profesor '{teacher_id}' no puede otorgar insignias "
                f"al estudiante '{student_id}' en el curso '{course_id}'."
            ),
        )


# ── Skills ────────────────────────────────────────────────────────────────────

async def list_skills(db: AsyncSession, search: Optional[str] = None) -> List[Skill]:
    query = select(Skill).order_by(Skill.name)
    if search:
        query = query.where(Skill.name.ilike(f"%{search}%"))
    result = await db.execute(query)
    return result.scalars().all()


async def create_skill(
    db: AsyncSession,
    name: str,
    description: Optional[str] = None,
) -> Skill:
    existing = await db.execute(select(Skill).where(Skill.name == name))
    if existing.scalar_one_or_none():
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail=f"La skill '{name}' ya existe.",
        )
    skill = Skill(name=name, description=description)
    db.add(skill)
    await db.commit()
    await db.refresh(skill)
    return skill


# ── Templates ─────────────────────────────────────────────────────────────────

async def create_template(db: AsyncSession, data: BadgeTemplateCreate) -> BadgeTemplate:
    skills = await _get_or_create_skills(db, data.skill_ids, data.new_skills)
    template = BadgeTemplate(
        name=data.name,
        description=data.description,
        image_url=data.image_url,
        criteria=_criteria_to_str(data.criteria),
        created_by_id=data.created_by_id,
        created_by_role=data.created_by_role.value,
    )
    template.skills = skills
    db.add(template)
    await db.commit()
    await db.refresh(template)
    result = await db.execute(
        select(BadgeTemplate)
        .options(selectinload(BadgeTemplate.skills))
        .where(BadgeTemplate.id == template.id)
    )
    return result.scalar_one()


async def list_templates(
    db: AsyncSession,
    created_by_id: Optional[str] = None,
    only_active: bool = True,
) -> List[BadgeTemplate]:
    query = (
        select(BadgeTemplate)
        .options(selectinload(BadgeTemplate.skills))
        .order_by(BadgeTemplate.created_at.desc())
    )
    if only_active:
        query = query.where(BadgeTemplate.is_active == True)  # noqa: E712
    if created_by_id:
        query = query.where(BadgeTemplate.created_by_id == created_by_id)
    result = await db.execute(query)
    return result.scalars().all()


async def get_template(db: AsyncSession, template_id: str) -> BadgeTemplate:
    result = await db.execute(
        select(BadgeTemplate)
        .options(selectinload(BadgeTemplate.skills))
        .where(BadgeTemplate.id == template_id)
    )
    template = result.scalar_one_or_none()
    if not template:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Plantilla no encontrada.",
        )
    return template


async def update_template(
    db: AsyncSession,
    template_id: str,
    data: BadgeTemplateUpdate,
    requester_id: str,
    requester_role: str,
) -> BadgeTemplate:
    template = await get_template(db, template_id)
    if requester_role != "admin" and template.created_by_id != requester_id:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Solo el creador o un admin puede editar esta plantilla.",
        )

    if data.name is not None:
        template.name = data.name
    if data.description is not None:
        template.description = data.description
    if data.image_url is not None:
        template.image_url = data.image_url
    if data.is_active is not None:
        template.is_active = data.is_active
    if data.criteria is not None:
        template.criteria = _criteria_to_str(data.criteria)
    if data.skill_ids is not None or data.new_skills is not None:
        template.skills = await _get_or_create_skills(db, data.skill_ids, data.new_skills)

    await db.commit()
    result = await db.execute(
        select(BadgeTemplate)
        .options(selectinload(BadgeTemplate.skills))
        .where(BadgeTemplate.id == template_id)
    )
    return result.scalar_one()


async def delete_template(
    db: AsyncSession,
    template_id: str,
    requester_id: str,
    requester_role: str,
) -> None:
    template = await get_template(db, template_id)
    if requester_role != "admin" and template.created_by_id != requester_id:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Solo el creador o un admin puede eliminar esta plantilla.",
        )

    awards_result = await db.execute(
        select(BadgeAward).where(BadgeAward.template_id == template_id).limit(1)
    )
    if awards_result.scalar_one_or_none():
        template.is_active = False
        await db.commit()
        return

    await db.delete(template)
    await db.commit()


# ── Awards ────────────────────────────────────────────────────────────────────

async def award_badge(db: AsyncSession, data: BadgeAwardCreate) -> BadgeAward:
    """
    Otorga una insignia a un estudiante.

    Flujo:
      1. Validar plantilla activa y permisos del emisor.
      2. Intentar mint en blockchain si el estudiante tiene wallet.
      3. Si falla, guardar como 'pending' y encolar reintento automático.
      4. Si la plantilla tiene mrt_reward, acuñar MRT (no bloquea el badge).
      5. Guardar el BadgeAward en BD.
    """

    template = await get_template(db, data.template_id)
    if not template.is_active:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="La plantilla está desactivada.",
        )

    if data.issued_by_role == "teacher":
        if not data.course_id:
            raise HTTPException(
                status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
                detail="'course_id' es requerido para profesores.",
            )
        await _assert_teacher_can_award(db, data.issued_by_id, data.student_id, data.course_id)

    badge_id = int(data.template_id.replace("-", ""), 16) % (2 ** 256)
    badge_metadata = {
        "@context": "https://w3id.org/openbadges/v2",
        "type": "BadgeClass",
        "id": f"{settings.public_base_url}/badges/{data.template_id}",
        "name": template.name,
        "description": template.description,
        "image": template.image_url,
        "criteria": {"narrative": template.criteria or ""},
    }

    try:
        cid = await upload_json_to_ipfs(badge_metadata)
        badge_uri = await get_ipfs_gateway_url(cid)
    except Exception as exc:
        logger.warning("IPFS no disponible, usando URI fallback: %s", exc)
        cid = None  # ← FIX: antes no se definía y reventaba más abajo
        badge_uri = f"{settings.public_base_url}/badges/{data.template_id}"

    tx_hash: Optional[str] = None
    chain_status = "pending"
    needs_enqueue = False  # ← NUEVO

    if not data.student_wallet:
        wallet_row = await db.scalar(
            select(WalletRegistry.wallet_address)
            .where(WalletRegistry.student_id == data.student_id)
        )
        if wallet_row:
            data.student_wallet = wallet_row
            logger.info("Wallet auto-resuelto para %s: %s", data.student_id, wallet_row)
        else:
            logger.warning("No hay wallet registrado para student_id=%s", data.student_id)

    if data.student_wallet and blockchain.is_connected():
        try:
            tx_hash = await blockchain.mint_badge(data.student_wallet, badge_id, badge_uri)
            chain_status = "confirmed"
            logger.info("Badge minteado en blockchain: tx=%s", tx_hash)
        except Exception as exc:
            logger.warning("Error al mintear badge, encolando reintento: %s", exc)
            chain_status = "pending"   # ← era "failed", ahora queda pending
            needs_enqueue = True       # ← NUEVO

        mrt_reward: Optional[float] = getattr(template, "mrt_reward", None)
        if mrt_reward and float(mrt_reward) > 0:
            try:
                mrt_tx = await blockchain.mint_mrt(data.student_wallet, float(mrt_reward))
                logger.info("%.4f MRT acuñados por badge: tx=%s", mrt_reward, mrt_tx)
            except Exception as exc:
                logger.warning("Error al mintear MRT por badge (no crítico): %s", exc)
    else:
        chain_status = "skipped"
        logger.warning(
            "Blockchain no disponible o wallet no proporcionado — "
            "badge guardado off-chain sin transacción."
        )

    award = BadgeAward(
        template_id=data.template_id,
        student_id=data.student_id,
        student_wallet=data.student_wallet,
        issued_by_id=data.issued_by_id,
        issued_by_role=data.issued_by_role.value,
        course_id=data.course_id,
        tx_hash=tx_hash,
        chain_status=chain_status,
        ipfs_cid=cid,
    )
    db.add(award)
    await db.commit()

    # ← NUEVO: encolar reintento automático ahora que el award tiene ID
    if needs_enqueue:
        await enqueue(
            event_id=award.id,
            tx_type="mint_badge",
            wallet=data.student_wallet,
            badge_id=str(badge_id),
            uri=badge_uri,
        )
        logger.info("mint_badge encolado para reintento: award_id=%s", award.id)

    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.id == award.id)
    )
    return result.scalar_one()


async def get_student_awards(db: AsyncSession, student_id: str) -> List[BadgeAward]:
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.student_id == student_id)
        .order_by(BadgeAward.issued_at.desc())
    )
    return result.scalars().all()


async def revoke_award(
    db: AsyncSession,
    award_id: str,
    requester_id: str,
    requester_role: str,
) -> BadgeAward:
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.id == award_id)
    )
    award = result.scalar_one_or_none()
    if not award:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Insignia no encontrada.")
    if award.revoked:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="La insignia ya fue revocada.")
    if requester_role != "admin" and award.issued_by_id != requester_id:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Solo el emisor original o un admin puede revocar.")

    award.revoked = True
    award.revoked_at = datetime.now(timezone.utc).replace(tzinfo=None)
    award.revoked_by_id = requester_id
    await db.commit()
    await db.refresh(award)
    return award


# ── Verificación pública ──────────────────────────────────────────────────────

async def get_public_verification(db: AsyncSession, award_id: str) -> PublicVerifyResponse:
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.id == award_id)
    )
    award = result.scalar_one_or_none()
    if not award:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Insignia no encontrada.")

    template = award.template
    return PublicVerifyResponse(
        award_id=award.id,
        valid=not award.revoked,
        student_id=award.student_id,
        badge_name=template.name,
        badge_description=template.description,
        badge_image_url=template.image_url,
        criteria=criteria_from_str(template.criteria),
        skills=[s.name for s in template.skills],
        issued_by_id=award.issued_by_id,
        issued_by_role=award.issued_by_role,
        issued_at=award.issued_at,
        chain_status=award.chain_status,
        tx_hash=award.tx_hash,
        ipfs_cid=award.ipfs_cid,
        ipfs_url=f"{settings.ipfs_gateway_url}/ipfs/{award.ipfs_cid}" if award.ipfs_cid else None,
        revoked=award.revoked,
        revoked_at=award.revoked_at,
    )


async def retry_chain(db: AsyncSession, award_id: str) -> BadgeAward:
    """
    Reintenta el mint en blockchain de un award que quedó con
    chain_status 'skipped', 'failed' o 'pending'.
    """
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.id == award_id)
    )
    award = result.scalar_one_or_none()
    if not award:
        raise HTTPException(status_code=404, detail="Insignia no encontrada.")
    if award.revoked:
        raise HTTPException(status_code=400, detail="No se puede reintentar el mint de una insignia revocada.")
    if award.chain_status == "confirmed":
        raise HTTPException(status_code=409, detail="La insignia ya fue confirmada en blockchain.")
    if not award.student_wallet:
        raise HTTPException(status_code=422, detail="El award no tiene wallet — no es posible mintear.")
    if not blockchain.is_connected():
        raise HTTPException(status_code=503, detail="Nodo blockchain no disponible. Intenta más tarde.")

    badge_id = int(award.template.id.replace("-", ""), 16) % (2 ** 256)
    badge_uri = (
        await get_ipfs_gateway_url(award.ipfs_cid)
        if award.ipfs_cid
        else f"{settings.public_base_url}/badges/{award.template.id}"
    )

    try:
        tx_hash = await blockchain.mint_badge(award.student_wallet, badge_id, badge_uri)
        award.tx_hash = tx_hash
        award.chain_status = "confirmed"
    except Exception as exc:
        award.chain_status = "failed"
        await db.commit()
        raise HTTPException(status_code=502, detail=f"Error al mintear: {exc}")

    mrt_reward = getattr(award.template, "mrt_reward", None)
    if mrt_reward and float(mrt_reward) > 0:
        try:
            await blockchain.mint_mrt(award.student_wallet, float(mrt_reward))
        except Exception as exc:
            logger.warning("Error MRT en retry (no crítico): %s", exc)

    await db.commit()
    result = await db.execute(
        select(BadgeAward)
        .options(selectinload(BadgeAward.template).selectinload(BadgeTemplate.skills))
        .where(BadgeAward.id == award.id)
    )
    return result.scalar_one()