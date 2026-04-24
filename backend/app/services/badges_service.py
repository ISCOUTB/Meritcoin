"""
Servicio de insignias: genera metadatos OBv2 y simula IPFS.
"""

import hashlib
import json
from datetime import datetime

from app.models.events import AcademicEvent


def generate_badge_id(event: AcademicEvent) -> int:
    """
    Genera un badge_id numérico determinístico a partir del course_id y event_type.
    Esto asegura que el mismo curso+tipo siempre produce el mismo badge_id.
    """
    raw = f"{event.course_id}:{event.event_type}"
    # Usar los primeros 8 bytes del hash como entero
    h = hashlib.sha256(raw.encode()).hexdigest()[:16]
    return int(h, 16) % (2**32)  # Limitar a uint32 para manejabilidad


def generate_obv2_metadata(event: AcademicEvent) -> dict:
    """
    Genera metadatos compatibles con Open Badges v2 (OBv2).
    No incluye datos personales reales.
    """
    return {
        "@context": "https://w3id.org/openbadges/v2",
        "type": "Assertion",
        "id": f"urn:meritcoin:badge:{event.event_id}",
        "recipient": {
            "type": "ethereumAddress",
            "identity": event.student_wallet,
            "hashed": False,
        },
        "badge": {
            "type": "BadgeClass",
            "id": f"urn:meritcoin:class:{event.course_id}:{event.event_type}",
            "name": f"{event.course_name} — {event.event_type.value.capitalize()}",
            "description": f"Insignia emitida por evento '{event.event_type.value}' en el curso {event.course_id}",
            "issuer": {
                "type": "Issuer",
                "id": "urn:meritcoin:issuer:utb",
                "name": "Universidad Tecnológica de Bolívar — MeritCoin",
            },
        },
        "issuedOn": datetime.utcnow().isoformat() + "Z",
        "verification": {
            "type": "BlockchainVerification",
            "contractAddress": "pending",  # Se llena después del mint
        },
    }


def simulate_ipfs_pin(metadata: dict) -> str:
    """
    Simula el pin de metadatos en IPFS.
    Retorna un CID fake pero determinístico basado en el contenido.
    En producción esto sería una llamada real a IPFS/Pinata/web3.storage.
    """
    content = json.dumps(metadata, sort_keys=True).encode()
    h = hashlib.sha256(content).hexdigest()
    # Formato de CID v0 simulado (Qm + base58-like)
    return f"QmSimulated{h[:40]}"
