"""
Validación HMAC para asegurar que los eventos provienen del plugin de Moodle.

El plugin envía un header X-HMAC-Signature con el HMAC-SHA256 del body.
"""

import hashlib
import hmac

from fastapi import Header, HTTPException, Request

from app.core.config import settings


async def verify_hmac(
    request: Request,
    x_hmac_signature: str = Header(..., description="HMAC-SHA256 del body"),
) -> bytes:
    """
    Dependency de FastAPI que valida el HMAC del request body.
    Retorna el body raw si la firma es válida.
    Lanza HTTP 401 si no coincide.
    """
    body = await request.body()

    expected = hmac.new(
        key=settings.hmac_secret.encode("utf-8"),
        msg=body,
        digestmod=hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected, x_hmac_signature):
        raise HTTPException(
            status_code=401,
            detail="Firma HMAC inválida",
        )

    return body


def compute_hmac(payload: bytes) -> str:
    """Utilidad para generar HMAC (usada en tests y en el plugin)."""
    return hmac.new(
        key=settings.hmac_secret.encode("utf-8"),
        msg=payload,
        digestmod=hashlib.sha256,
    ).hexdigest()
