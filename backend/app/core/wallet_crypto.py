"""
Encriptación/desencriptación de claves privadas de wallets custodiales.

Usa Fernet (AES-128-CBC + HMAC-SHA256) con la clave WALLET_ENCRYPTION_KEY.
La clave NUNCA se almacena en BD — solo existe como variable de entorno.

Generar una clave nueva:
    python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
"""

from cryptography.fernet import Fernet, InvalidToken

from app.core.config import settings


def _fernet() -> Fernet:
    return Fernet(settings.wallet_encryption_key.encode())


def encrypt_private_key(private_key: str) -> str:
    """Encripta una clave privada para almacenarla en BD."""
    return _fernet().encrypt(private_key.encode()).decode()


def decrypt_private_key(encrypted: str) -> str:
    """Desencripta una clave privada. Lanza InvalidToken si la clave es incorrecta."""
    try:
        return _fernet().decrypt(encrypted.encode()).decode()
    except InvalidToken as exc:
        raise ValueError("No se pudo desencriptar la clave privada — verifica WALLET_ENCRYPTION_KEY") from exc