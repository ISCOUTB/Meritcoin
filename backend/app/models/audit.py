"""
Modelos SQLAlchemy para persistencia en PostgreSQL.

Tablas:
- events:    Eventos académicos recibidos (idempotencia por event_id)
- audit_log: Log de auditoría con CIDs y hashes de transacciones

CAMBIOS v0.2.0:
- EventRecord: nuevos campos activity_id, activity_name, coins_amount, coin_symbol
"""

from datetime import datetime

from sqlalchemy import Column, DateTime, Float, String, Text, func

from app.core.database import Base


class EventRecord(Base):
    """
    Tabla de eventos académicos procesados.

    Campos nuevos en v0.2.0:
      - activity_id:   ID del course module (None = calificación final del curso)
      - activity_name: Nombre de la actividad o del curso
      - coins_amount:  Monedas emitidas en este evento
      - coin_symbol:   Símbolo de la moneda (ej: MRT, BIO)
    """
    __tablename__ = "events"

    event_id        = Column(String(255), primary_key=True)
    student_wallet  = Column(String(42),  nullable=False, index=True)
    student_id      = Column(String(255), nullable=False)
    course_id       = Column(String(255), nullable=False)
    course_name     = Column(String(500), default="")
    activity_id     = Column(String(255), nullable=True)     # nuevo v0.2.0
    activity_name   = Column(String(500), nullable=True)     # nuevo v0.2.0
    event_type      = Column(String(50),  nullable=False)
    grade           = Column(Float,       nullable=True)
    coins_amount    = Column(Float,       nullable=True)     # nuevo v0.2.0
    coin_symbol     = Column(String(10),  nullable=True, default="MRT")  # nuevo v0.2.0
    processed_at    = Column(DateTime,    server_default=func.now(), nullable=False)


class AuditLog(Base):
    """Tabla de auditoría: trazabilidad completa de cada emisión."""
    __tablename__ = "audit_log"

    event_id    = Column(String(255), primary_key=True)
    cid_ipfs    = Column(Text,        nullable=False)
    tx_badge    = Column(String(66),  nullable=True)   # 0x + 64 hex chars
    tx_mrt      = Column(String(66),  nullable=True)
    badge_id    = Column(String(255), nullable=True)
    mrt_amount  = Column(String(255), nullable=True)
    created_at  = Column(DateTime,    server_default=func.now(), nullable=False)
