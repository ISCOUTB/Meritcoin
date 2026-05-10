"""
MeritCoin Backend — Punto de entrada FastAPI.
"""

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.api import events, students, badges, tokens
from app.core.config import settings
from app.core.database import init_db
from app.services.blockchain import blockchain

from app.api.wallets import router as wallets_router

# ── Logging ──────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.DEBUG if settings.debug else logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)


# ── Lifespan (startup / shutdown) ─────────────────────────────────────────────
@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("Iniciando MeritCoin Backend...")
    await init_db()
    logger.info("Tablas de BD creadas/verificadas")
    if blockchain.is_connected():
        logger.info("Conectado al nodo blockchain en %s", settings.blockchain_rpc_url)
    else:
        logger.warning(
            "No se pudo conectar al nodo blockchain en %s — "
            "los endpoints de mint/burn fallarán hasta que Besu esté disponible",
            settings.blockchain_rpc_url,
        )
    yield
    logger.info("Cerrando MeritCoin Backend")


# ── App ───────────────────────────────────────────────────────────────────────
app = FastAPI(
    title="MeritCoin API",
    description="Backend off-chain para el sistema de insignias digitales MeritCoin (MRT)",
    version="0.5.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── Routers ───────────────────────────────────────────────────────────────────
app.include_router(events.router)
app.include_router(students.router)
app.include_router(badges.router)
app.include_router(tokens.router)
app.include_router(wallets_router)

@app.get("/health", tags=["System"])
async def health_check():
    """Estado del servicio y conexión al nodo blockchain."""
    try:
        chain_ok = blockchain.is_connected()
    except Exception:
        chain_ok = False

    return {
        "status": "ok",
        "blockchain_connected": chain_ok,
        "badge_contract": settings.badge_contract_address or "not configured",
        "mrt_contract": settings.mrt_contract_address or "not configured",
    }
