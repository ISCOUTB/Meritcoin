"""Schemas Pydantic para el sistema de insignias."""

from datetime import datetime
from enum import Enum
from typing import List, Optional
from pydantic import BaseModel, Field


class IssuedByRole(str, Enum):
    admin   = "admin"
    teacher = "teacher"


class ChainStatus(str, Enum):
    simulated = "simulated"
    pending   = "pending"
    confirmed = "confirmed"


# ── Skills ────────────────────────────────────────────────────────────────────

class SkillCreate(BaseModel):
    name:        str           = Field(..., min_length=1, max_length=255)
    description: Optional[str] = None

class SkillResponse(BaseModel):
    id:          str
    name:        str
    description: Optional[str] = None
    created_at:  datetime
    model_config = {"from_attributes": True}


# ── Badge Templates ───────────────────────────────────────────────────────────

class BadgeTemplateCreate(BaseModel):
    name:            str              = Field(..., min_length=1, max_length=255)
    description:     str              = Field(..., min_length=1)
    image_url:       Optional[str]        = None
    criteria:        Optional[List[str]]  = None
    skill_ids:       Optional[List[str]]  = None   # IDs de skills existentes
    new_skills:      Optional[List[str]]  = None   # Nombres de skills nuevas
    created_by_id:   str
    created_by_role: IssuedByRole = IssuedByRole.teacher

class BadgeTemplateUpdate(BaseModel):
    name:        Optional[str]       = None
    description: Optional[str]       = None
    image_url:   Optional[str]       = None
    criteria:    Optional[List[str]] = None
    skill_ids:   Optional[List[str]] = None
    new_skills:  Optional[List[str]] = None
    is_active:   Optional[bool]      = None

class BadgeTemplateResponse(BaseModel):
    id:             str
    name:           str
    description:    str
    image_url:      Optional[str]
    criteria:       List[str]
    skills:         List[SkillResponse]
    created_by_id:  str
    created_by_role: str
    is_active:      bool
    created_at:     datetime
    updated_at:     datetime
    model_config = {"from_attributes": True}


# ── Badge Awards ──────────────────────────────────────────────────────────────

class BadgeAwardCreate(BaseModel):
    template_id:    str           = Field(...)
    student_id:     str           = Field(...)
    student_wallet: Optional[str] = Field(None, pattern=r"^0x[0-9a-fA-F]{40}$")
    issued_by_id:   str           = Field(...)
    issued_by_role: IssuedByRole
    course_id:      Optional[str] = Field(None, description="Requerido si rol=teacher")

class BadgeAwardResponse(BaseModel):
    id:             str
    template:       BadgeTemplateResponse
    student_id:     str
    student_wallet: Optional[str]
    issued_by_id:   str
    issued_by_role: str
    course_id:      Optional[str]
    revoked:        bool
    revoked_at:     Optional[datetime]
    tx_hash:        Optional[str]
    chain_status:   str
    issued_at:      datetime
    model_config = {"from_attributes": True}


# ── Verificación pública ──────────────────────────────────────────────────────

class PublicVerifyResponse(BaseModel):
    award_id:          str
    valid:             bool
    student_id:        str
    badge_name:        str
    badge_description: str
    badge_image_url:   Optional[str]
    criteria:          List[str]
    skills:            List[str]
    issued_by_id:      str
    issued_by_role:    str
    issued_at:         datetime
    chain_status:      str
    tx_hash:           Optional[str]
    revoked:           bool
    revoked_at:        Optional[datetime]