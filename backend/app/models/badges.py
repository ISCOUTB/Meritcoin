"""
Modelos SQLAlchemy para el sistema de insignias personalizables.

Tablas nuevas (v0.3.0):
- skills:            Habilidades reutilizables entre plantillas.
- badge_templates:   Plantillas de insignia creadas por admin/profesor.
- badge_template_skills: Tabla asociativa ManyToMany.
- badge_awards:      Insignias otorgadas a estudiantes (instancias concretas).
"""

import uuid

from sqlalchemy import Boolean, Column, DateTime, ForeignKey, String, Text, func
from sqlalchemy.orm import relationship

from app.core.database import Base


def _uuid() -> str:
    return str(uuid.uuid4())


class BadgeTemplateSkill(Base):
    __tablename__ = "badge_template_skills"
    template_id = Column(String(36), ForeignKey("badge_templates.id", ondelete="CASCADE"), primary_key=True)
    skill_id    = Column(String(36), ForeignKey("skills.id",           ondelete="CASCADE"), primary_key=True)


class Skill(Base):
    __tablename__ = "skills"
    id          = Column(String(36),  primary_key=True, default=_uuid)
    name        = Column(String(255), nullable=False, unique=True, index=True)
    description = Column(Text,        nullable=True)
    created_at  = Column(DateTime,    server_default=func.now(), nullable=False)
    templates   = relationship("BadgeTemplate", secondary="badge_template_skills", back_populates="skills")


class BadgeTemplate(Base):
    __tablename__ = "badge_templates"
    id              = Column(String(36),  primary_key=True, default=_uuid)
    name            = Column(String(255), nullable=False)
    description     = Column(Text,        nullable=False)
    image_url       = Column(String(500), nullable=True)
    criteria        = Column(Text,        nullable=True)   # criterios separados por '\n'
    created_by_id   = Column(String(255), nullable=False)
    created_by_role = Column(String(50),  nullable=False, default="teacher")
    is_active       = Column(Boolean,     nullable=False, default=True)
    created_at      = Column(DateTime,    server_default=func.now(), nullable=False)
    updated_at      = Column(DateTime,    server_default=func.now(), onupdate=func.now(), nullable=False)
    skills = relationship("Skill", secondary="badge_template_skills", back_populates="templates")
    awards = relationship("BadgeAward", back_populates="template", cascade="all, delete-orphan")


class BadgeAward(Base):
    """
    chain_status:
      'simulated' → estado actual (sin Besu)
      'pending'   → enviado a la cadena, esperando confirmación
      'confirmed' → confirmado en Besu (branch futuro)
    """
    __tablename__ = "badge_awards"
    id             = Column(String(36),  primary_key=True, default=_uuid)
    template_id    = Column(String(36),  ForeignKey("badge_templates.id", ondelete="RESTRICT"), nullable=False, index=True)
    student_id     = Column(String(255), nullable=False, index=True)
    student_wallet = Column(String(42),  nullable=True)
    issued_by_id   = Column(String(255), nullable=False)
    issued_by_role = Column(String(50),  nullable=False)
    course_id      = Column(String(255), nullable=True)
    revoked        = Column(Boolean,     nullable=False, default=False)
    revoked_at     = Column(DateTime,    nullable=True)
    revoked_by_id  = Column(String(255), nullable=True)
    tx_hash        = Column(String(66),  nullable=True)          # Para Besu (branch futuro)
    chain_status   = Column(String(20),  nullable=False, default="simulated")
    issued_at      = Column(DateTime,    server_default=func.now(), nullable=False)
    template = relationship("BadgeTemplate", back_populates="awards")