"""
Validación HMAC para autenticar que los eventos provienen del plugin Moodle.

El plugin calcula HMAC-SHA256 del body JSON y lo envía en el header
X-HMAC-Signature. El backend lo verifica antes de procesar cualquier evento.

Uso como dependency de FastAPI:

    @router.post("/events/ingest")
    async def ingest(body: bytes = Depends(verify_hmac)):
        ...
"""

import hashlib
import hmac
import logging

from fastapi import Header, HTTPException, Request, status

from app.core.config import settings

logger = logging.getLogger(__name__)


async def verify_hmac(
    request: Request,
    x_hmac_signature: str = Header(
        ...,
        description="HMAC-SHA256 hexdigest del body del request",
    ),
) -> bytes:
    """
    Dependency de FastAPI: valida el header X-HMAC-Signature.

    - Lee el body raw del request.
    - Calcula el HMAC esperado con el secreto configurado.
    - Compara usando compare_digest para evitar timing attacks.
    - Retorna el body raw si la firma es válida.
    - Lanza HTTP 401 si la firma es inválida o está vacía.
    """
    if not x_hmac_signature:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Header X-HMAC-Signature ausente o vacío",
        )

    body = await request.body()

    expected = hmac.new(
        key=settings.hmac_secret.encode("utf-8"),
        msg=body,
        digestmod=hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected, x_hmac_signature.lower()):
        logger.warning("Firma HMAC inválida recibida — posible request no autorizado")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Firma HMAC inválida",
        )

    return body


def compute_hmac(payload: bytes) -> str:
    """
    Genera el HMAC-SHA256 de un payload.

    Usada en tests y como referencia para el cálculo en el plugin PHP.

    Ejemplo en PHP (plugin Moodle):
        hash_hmac('sha256', $body, $hmac_secret)
    """
    return hmac.new(
        key=settings.hmac_secret.encode("utf-8"),
        msg=payload,
        digestmod=hashlib.sha256,
    ).hexdigest()
