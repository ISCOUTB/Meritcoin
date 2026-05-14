"""
Configuración centralizada del backend MeritCoin.

Lee variables de entorno usando pydantic-settings.
Docker Compose inyecta las variables via `env_file: .env` en el servicio,
por lo que NO se necesita apuntar a un archivo .env desde aquí.
Para desarrollo local fuera de Docker, exporta las variables manualmente
o usa un .env en el directorio desde donde lanzas uvicorn.
"""

from pydantic_settings import BaseSettings, SettingsConfigDict
from pydantic import field_validator
from typing import List


class Settings(BaseSettings):
    cors_origins: List[str] = ["*"]
    model_config = SettingsConfigDict(
        env_file=".env",           # Útil solo en desarrollo local fuera de Docker
        env_file_encoding="utf-8",
        extra="ignore",            # Ignorar variables no declaradas en este modelo
    )

    # ── FastAPI ───────────────────────────────────────────────────────────────
    debug: bool = False
    fastapi_port: int = 8000

    # ── Base de datos PostgreSQL ──────────────────────────────────────────────
    database_url: str = (
        "postgresql+asyncpg://meritcoin:meritcoin_pass@meritcoin-postgres:5432/meritcoin_db"
    )

    # ── Seguridad HMAC ────────────────────────────────────────────────────────
    # OBLIGATORIO en producción. El valor por defecto solo sirve para tests.
    hmac_secret: str = "cambia-este-secreto-en-produccion"

    # ── Blockchain (Hyperledger Besu) ─────────────────────────────────────────
    # En Docker Compose el host es el nombre del servicio: meritcoin-besu
    blockchain_rpc_url: str = "http://meritcoin-besu:8545"

    # OBLIGATORIO. Sin esta clave el backend no puede firmar transacciones.
    # No usar claves de desarrollo en producción.
    deployer_private_key: str = ""

    # Direcciones de los contratos desplegados en Besu.
    # Se obtienen ejecutando: npx hardhat run scripts/deploy.js --network besu
    badge_contract_address: str = ""
    mrt_contract_address: str = ""

    # ── Wallets custodiales ───────────────────────────────────────────────────────
    # Generar con: python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
    # OBLIGATORIO si se usa el sistema de wallets custodiales.
    wallet_encryption_key: str = ""

    # ── IPFS ───────────────────────────────────────────────────────────────
    ipfs_api_url: str = "http://ipfs:5001"
    ipfs_gateway_url: str = "http://localhost:8090"

    # ── URL pública del backend ────────────────────────────────────────────
    public_base_url: str = "http://localhost:8000"

settings = Settings()
