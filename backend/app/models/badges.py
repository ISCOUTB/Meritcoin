"""
Modelos SQLAlchemy para el sistema de insignias personalizables.

Tablas (desde v0.3.0):
  - skills:                 Habilidades reutilizables entre plantillas.
  - badge_templates:        Plantillas de insignia creadas por admin/profesor.
  - badge_template_skills:  Relación ManyToMany entre plantillas y skills.
  - badge_awards:           Insignias otorgadas a estudiantes (instancias concretas).
"""

import uuid

from sqlalchemy import Boolean, Column, DateTime, Float, ForeignKey, String, Text, func
from sqlalchemy.orm import relationship

from app.core.database import Base


def _uuid() -> str:
    """Genera un UUID v4 como string. Usado como default de PKs."""
    return str(uuid.uuid4())


class BadgeTemplateSkill(Base):
    """Tabla asociativa ManyToMany entre BadgeTemplate y Skill."""
    __tablename__ = "badge_template_skills"

    template_id = Column(
        String(36), ForeignKey("badge_templates.id", ondelete="CASCADE"), primary_key=True
    )
    skill_id = Column(
        String(36), ForeignKey("skills.id", ondelete="CASCADE"), primary_key=True
    )


class Skill(Base):
    """Habilidad reutilizable que puede asociarse a múltiples plantillas."""
    __tablename__ = "skills"

    id          = Column(String(36),  primary_key=True, default=_uuid)
    name        = Column(String(255), nullable=False, unique=True, index=True)
    description = Column(Text,        nullable=True)
    created_at  = Column(DateTime,    server_default=func.now(), nullable=False)

    templates = relationship(
        "BadgeTemplate",
        secondary="badge_template_skills",
        back_populates="skills",
    )


class BadgeTemplate(Base):
    """
    Plantilla de insignia creada por un profesor o admin.

    El campo `criteria` almacena una lista de criterios separados por \n.
    Los helpers _criteria_to_str / _criteria_from_str en badges_service
    manejan la conversión.

    El campo `mrt_reward` permite definir un bonus de MRT al otorgar
    esta insignia manualmente. Es opcional (None = sin bonus).
    """
    __tablename__ = "badge_templates"

    id              = Column(String(36),  primary_key=True, default=_uuid)
    name            = Column(String(255), nullable=False)
    description     = Column(Text,        nullable=False)
    image_url       = Column(String(500), nullable=True)
    criteria        = Column(Text,        nullable=True)
    created_by_id   = Column(String(255), nullable=False)
    created_by_role = Column(String(50),  nullable=False, default="teacher")
    is_active       = Column(Boolean,     nullable=False, default=True)
    mrt_reward      = Column(Float,       nullable=True, default=None)
    created_at      = Column(DateTime,    server_default=func.now(), nullable=False)
    updated_at      = Column(DateTime,    server_default=func.now(), onupdate=func.now(), nullable=False)

    skills = relationship(
        "Skill",
        secondary="badge_template_skills",
        back_populates="templates",
    )
    awards = relationship(
        "BadgeAward",
        back_populates="template",
        cascade="all, delete-orphan",
    )


class BadgeAward(Base):
    """
    Instancia concreta de una insignia otorgada a un estudiante.

    chain_status:
      "pending"   → enviado a Besu, esperando confirmación.
      "confirmed" → confirmado en Besu.
      "failed"    → la transacción falló en blockchain.
      "skipped"   → blockchain no disponible o wallet no proporcionado.
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
    tx_hash        = Column(String(66),  nullable=True)
    chain_status   = Column(String(20),  nullable=False, default="pending")
    issued_at      = Column(DateTime,    server_default=func.now(), nullable=False)

    template = relationship("BadgeTemplate", back_populates="awards")
