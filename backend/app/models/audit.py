"""
Modelos SQLAlchemy para persistencia de eventos y auditoría.

Tablas:
  - events:    Registro de eventos académicos recibidos (idempotencia por event_id).
  - audit_log: Trazabilidad de cada emisión (IPFS CID, tx hashes).
"""

from sqlalchemy import Column, DateTime, Float, Integer, String, Text, func

from app.core.database import Base


class EventRecord(Base):
    """
    Registro de un evento académico recibido desde Moodle.

    El event_id es la clave primaria y garantiza idempotencia:
    si el mismo evento llega dos veces, el segundo es rechazado
    por IntegrityError en reserve_event().

    Campos desde v0.2.0:
      activity_id:   ID del course module (None = calificación final del curso).
      activity_name: Nombre de la actividad.
      coins_amount:  Tokens calculados en Moodle para este evento.
      coin_symbol:   Símbolo de la moneda del curso (ej: "MRT").
    """
    __tablename__ = "events"

    event_id       = Column(String(255), primary_key=True)
    student_wallet = Column(String(42),  nullable=True,  index=True)
    student_id     = Column(String(255), nullable=False)
    course_id      = Column(String(255), nullable=False)
    course_name    = Column(String(500), default="")
    activity_id    = Column(String(255), nullable=True)
    activity_name  = Column(String(500), nullable=True)
    event_type     = Column(String(50),  nullable=False)
    grade          = Column(Float,       nullable=True)
    coins_amount   = Column(Float,       nullable=True)
    coin_symbol    = Column(String(10),  nullable=True, default="MRT")
    status         = Column(String(20),  nullable=False, default="processing", index=True)
    last_error     = Column(Text,        nullable=True)
    processed_at   = Column(DateTime,    server_default=func.now(), nullable=False)


class AuditLog(Base):
    """
    Trazabilidad completa de cada emisión.

    Una fila por evento procesado. Contiene los identificadores
    on-chain (tx hashes) y off-chain (IPFS CID) del resultado.
    """
    __tablename__ = "audit_log"

    event_id   = Column(String(255), primary_key=True)
    cid_ipfs   = Column(Text,        nullable=True)
    tx_badge   = Column(String(66),  nullable=True)
    tx_mrt     = Column(String(66),  nullable=True)
    badge_id   = Column(String(255), nullable=True)
    mrt_amount = Column(String(255), nullable=True)  # String para preservar precisión decimal
    created_at = Column(DateTime,    server_default=func.now(), nullable=False)

class PendingTransaction(Base):
    """Transacciones blockchain que fallaron y esperan reintento."""
    __tablename__ = "pending_transactions"

    id        = Column(Integer, primary_key=True, autoincrement=True)
    event_id  = Column(String(255), nullable=False, index=True)
    tx_type   = Column(String(20),  nullable=False)   # 'mint_mrt', 'mint_badge'
    wallet    = Column(String(42),  nullable=False)
    amount    = Column(Float,       nullable=True)     # MRT
    badge_id  = Column(String(255), nullable=True)
    uri       = Column(Text,        nullable=True)
    attempts  = Column(Integer,     default=0)
    last_error= Column(Text,        nullable=True)
    created_at= Column(DateTime,    server_default=func.now())
    retry_after= Column(DateTime,   nullable=True)    # backoff
