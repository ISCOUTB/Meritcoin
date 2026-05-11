"""
Configuración de SQLAlchemy async para PostgreSQL.

Expone:
  - engine          motor async reutilizable
  - async_session   factory de sesiones
  - Base            clase base para todos los modelos ORM
  - get_db()        dependency de FastAPI (inyección de sesión por request)
  - init_db()       crea las tablas al arrancar (solo desarrollo)
"""

import logging

from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.orm import DeclarativeBase

from app.core.config import settings

logger = logging.getLogger(__name__)

# ── Motor ─────────────────────────────────────────────────────────────────────
engine = create_async_engine(
    settings.database_url,
    echo=settings.debug,   # Loguea SQL solo si DEBUG=true
    pool_pre_ping=True,    # Descarta conexiones muertas antes de usarlas
)

# ── Factory de sesiones ───────────────────────────────────────────────────────
async_session = async_sessionmaker(
    engine,
    class_=AsyncSession,
    expire_on_commit=False,  # Evita lazy-load tras commit en contexto async
)


# ── Clase base ORM ────────────────────────────────────────────────────────────
class Base(DeclarativeBase):
    """Clase base para todos los modelos SQLAlchemy."""


# ── Dependency de FastAPI ─────────────────────────────────────────────────────
async def get_db():
    """
    Inyecta una sesión de BD en cada request.

    El commit lo hace el servicio que llama a esta sesión.
    Esta función solo garantiza rollback en caso de excepción
    y cierre de la sesión al terminar el request.
    """
    async with async_session() as session:
        try:
            yield session
        except Exception:
            await session.rollback()
            raise
        finally:
            await session.close()


# ── Inicialización de tablas ──────────────────────────────────────────────────
async def init_db() -> None:
    """
    Crea todas las tablas declaradas en los modelos si no existen.

    Solo para desarrollo. En producción usar migraciones con Alembic.
    """
    logger.warning(
        "init_db() activo — las tablas se crean/verifican automáticamente. "
        "En producción usar migraciones Alembic."
    )
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
