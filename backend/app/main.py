"""
MeritCoin Backend — Punto de entrada FastAPI.
"""

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI

from app.api import events, students, badges, tokens
from app.core.config import settings
from app.core.database import init_db

# ── Logging ──────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.DEBUG if settings.debug else logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)


# ── Lifespan (startup / shutdown) ────────────────────────────────────────
@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("Iniciando MeritCoin Backend...")
    await init_db()
    logger.info("Tablas de BD creadas/verificadas")
    yield
    logger.info("Cerrando MeritCoin Backend")


# ── App ──────────────────────────────────────────────────────────────────
app = FastAPI(
    title="MeritCoin API",
    description="Backend off-chain para el sistema de insignias digitales MeritCoin (MRT)",
    version="0.3.0",
    lifespan=lifespan,
)

# ── Routers ──────────────────────────────────────────────────────────────
app.include_router(events.router)
app.include_router(students.router)
app.include_router(badges.router)
app.include_router(tokens.router)


@app.get("/health", tags=["System"])
async def health_check():
    """Endpoint de salud del servicio."""
    from app.services.blockchain import blockchain
    return {
        "status": "ok",
        "blockchain_connected": blockchain.is_connected(),
        "badge_contract": settings.badge_contract_address or "not configured",
        "mrt_contract": settings.mrt_contract_address or "not configured",
    }
