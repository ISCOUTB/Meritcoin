"""
Configuración centralizada del backend MeritCoin.
Lee variables de entorno (o .env) usando pydantic-settings.
"""

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file="../../.env",       # Ruta relativa al .env de la raíz
        env_file_encoding="utf-8",
        extra="ignore",              # Ignorar variables que no estén declaradas
    )

    # ── FastAPI ────────────────────────────────────────────────────────
    debug: bool = True
    fastapi_port: int = 8000

    # ── Base de datos PostgreSQL ───────────────────────────────────────
    database_url: str = "postgresql+asyncpg://meritcoin:meritcoin_pass@localhost:5432/meritcoin_db"

    # ── Seguridad HMAC ────────────────────────────────────────────────
    hmac_secret: str = "cambia-este-secreto-en-produccion"

    # ── Blockchain ────────────────────────────────────────────────────
    blockchain_rpc_url: str = "http://127.0.0.1:8545"
    deployer_private_key: str = "0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80"
    badge_contract_address: str = ""
    mrt_contract_address: str = ""

    # ── Tokens MRT por tipo de evento ─────────────────────────────────
    mrt_reward_completion: int = 100   # MRT por completar un curso
    mrt_reward_grade: int = 50         # MRT por calificación aprobatoria


settings = Settings()
