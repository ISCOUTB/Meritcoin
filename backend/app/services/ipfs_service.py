"""
Cliente IPFS real usando el nodo Kubo local.
Sube metadatos JSON de badges a IPFS y retorna el CID.
"""
import json
import logging
import httpx
from app.core.config import settings

logger = logging.getLogger(__name__)


async def upload_json_to_ipfs(data: dict) -> str:
    """
    Sube un dict como JSON a IPFS y retorna el CID.
    También hace pin automático para evitar garbage collection.
    """
    content = json.dumps(data, ensure_ascii=False).encode("utf-8")
    async with httpx.AsyncClient(timeout=30) as client:
        # Subir archivo
        response = await client.post(
            f"{settings.ipfs_api_url}/api/v0/add",
            files={"file": ("metadata.json", content, "application/json")},
        )
        response.raise_for_status()
        cid = response.json()["Hash"]

        # Pin para evitar que sea eliminado por GC
        await client.post(
            f"{settings.ipfs_api_url}/api/v0/pin/add",
            params={"arg": cid},
        )
        logger.info("Metadata subida a IPFS: CID=%s", cid)
        return cid


async def get_ipfs_gateway_url(cid: str) -> str:
    """Retorna la URL pública del gateway para un CID dado."""
    return f"{settings.ipfs_gateway_url}/ipfs/{cid}"


async def is_ipfs_available() -> bool:
    """Verifica si el nodo IPFS responde."""
    try:
        async with httpx.AsyncClient(timeout=5) as client:
            r = await client.post(f"{settings.ipfs_api_url}/api/v0/id")
            return r.status_code == 200
    except Exception:
        return False