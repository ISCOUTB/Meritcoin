"""
Modelos SQLAlchemy para el sistema de wallets custodiales.

Tablas:
  - wallet_registry:   Una wallet por student_id, permanente.
  - course_enrollment: Una fila por (student_id, course_id). Expira al cierre del semestre.
"""

import uuid
from sqlalchemy import Boolean, Column, DateTime, Float, String, Text, UniqueConstraint, func

from app.core.database import Base


def _uuid() -> str:
    return str(uuid.uuid4())


class WalletRegistry(Base):
    """
    Wallet custodial generada por el backend para un estudiante.

    La clave privada se almacena encriptada con Fernet (WALLET_ENCRYPTION_KEY).
    Nunca se expone en ningún endpoint — solo se usa internamente para firmar tx.

    Un estudiante conserva la misma wallet para siempre,
    independientemente de los cursos en que se inscriba.
    """
    __tablename__ = "wallet_registry"

    id              = Column(String(36),  primary_key=True, default=_uuid)
    student_id      = Column(String(255), nullable=False, unique=True, index=True)
    wallet_address  = Column(String(42),  nullable=False, unique=True, index=True)
    private_key_enc = Column(Text,        nullable=False)   # Fernet-encrypted
    status          = Column(String(20),  nullable=False, default="active", index=True)
    created_at      = Column(DateTime,    server_default=func.now(), nullable=False)
    last_active_at  = Column(DateTime,    nullable=True)


class CourseEnrollment(Base):
    """
    Inscripción activa de un estudiante en un curso piloto.

    - expires_at: tomado de mdl_course.enddate o sobreescrito por admin.
    - mrt_snapshot: saldo MRT al momento del cierre (solo histórico).
    - Una fila por (student_id, course_id) — si el estudiante repite
      el curso el siguiente semestre se crea una fila nueva con UPDATE
      del status a 'active' y saldo en 0.
    """
    __tablename__ = "course_enrollment"

    id             = Column(String(36),  primary_key=True, default=_uuid)
    student_id     = Column(String(255), nullable=False, index=True)
    wallet_address = Column(String(42),  nullable=False)
    course_id      = Column(String(255), nullable=False, index=True)
    status         = Column(String(20),  nullable=False, default="active", index=True)
    mrt_snapshot   = Column(Float,       nullable=False, default=0.0)
    enrolled_at    = Column(DateTime,    server_default=func.now(), nullable=False)
    expires_at     = Column(DateTime,    nullable=False)
    expired_at     = Column(DateTime,    nullable=True)   # cuándo se ejecutó el cierre

    __table_args__ = (
        UniqueConstraint("student_id", "course_id", name="uq_enrollment_student_course"),
    )