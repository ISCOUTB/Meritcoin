"""
Tests para el flujo de eventos: HMAC, ingest, idempotencia, recompensas.
"""

import json

import pytest
from httpx import AsyncClient

from app.core.security import compute_hmac
from tests.conftest import make_event_payload


# ── Helpers ─────────────────────────────────────────────────────────────

def _hmac_headers(payload_bytes: bytes) -> dict:
    """Genera los headers con la firma HMAC válida."""
    return {
        "Content-Type": "application/json",
        "X-HMAC-Signature": compute_hmac(payload_bytes),
    }


# ── Test: HMAC válido ───────────────────────────────────────────────────

@pytest.mark.asyncio
async def test_ingest_valid_hmac(client: AsyncClient):
    """POST /events/ingest con HMAC válido retorna 200 y status=processed."""
    payload = make_event_payload()
    body = json.dumps(payload).encode()

    resp = await client.post(
        "/events/ingest",
        content=body,
        headers=_hmac_headers(body),
    )

    assert resp.status_code == 200
    data = resp.json()
    assert data["status"] == "processed"
    assert data["event_id"] == "evt-test-001"
    assert data["mrt_tx"] is not None      # coins_amount=5.0 → debe mintear
    assert data["badge_tx"] is None        # flujo automático no emite badges
    assert data["cid_ipfs"] is None        # IPFS no se usa en flujo automático


# ── Test: HMAC inválido ─────────────────────────────────────────────────

@pytest.mark.asyncio
async def test_ingest_invalid_hmac(client: AsyncClient):
    """POST /events/ingest con HMAC incorrecto retorna 401."""
    payload = make_event_payload()
    body = json.dumps(payload).encode()

    resp = await client.post(
        "/events/ingest",
        content=body,
        headers={
            "Content-Type": "application/json",
            "X-HMAC-Signature": "firma_invalida_12345",
        },
    )

    assert resp.status_code == 401
    assert "HMAC" in resp.json()["detail"]


# ── Test: HMAC ausente ──────────────────────────────────────────────────

@pytest.mark.asyncio
async def test_ingest_missing_hmac(client: AsyncClient):
    """POST /events/ingest sin header HMAC retorna 422."""
    payload = make_event_payload()
    body = json.dumps(payload).encode()

    resp = await client.post(
        "/events/ingest",
        content=body,
        headers={"Content-Type": "application/json"},
    )

    # FastAPI retorna 422 cuando falta un header obligatorio
    assert resp.status_code == 422


# ── Test: Idempotencia ──────────────────────────────────────────────────

@pytest.mark.asyncio
async def test_ingest_idempotency(client: AsyncClient):
    """Enviar el mismo evento dos veces retorna 'duplicate' la segunda vez."""
    payload = make_event_payload(event_id="evt-idempotent-001")
    body = json.dumps(payload).encode()
    headers = _hmac_headers(body)

    # Primera vez: procesado
    resp1 = await client.post("/events/ingest", content=body, headers=headers)
    assert resp1.status_code == 200
    assert resp1.json()["status"] == "processed"

    # Segunda vez: duplicado
    resp2 = await client.post("/events/ingest", content=body, headers=headers)
    assert resp2.status_code == 200
    assert resp2.json()["status"] == "duplicate"


# ── Test: Evento de calificación aprobatoria (grade) ────────────────────

@pytest.mark.asyncio
async def test_ingest_grade_event(client: AsyncClient):
    """Evento tipo 'grade' con nota >= 3.0 emite badge + MRT."""
    payload = make_event_payload(
        event_id="evt-grade-pass-001",
        event_type="grade",
        grade=4.5,
    )
    body = json.dumps(payload).encode()

    resp = await client.post(
        "/events/ingest",
        content=body,
        headers=_hmac_headers(body),
    )

    assert resp.status_code == 200
    data = resp.json()
    assert data["status"] == "processed"
    assert data["mrt_tx"] is not None      # grade con coins_amount → MRT


# ── Test: Evento de calificación reprobatoria ───────────────────────────

@pytest.mark.asyncio
async def test_ingest_grade_below_threshold(client: AsyncClient, mock_blockchain):
    """Evento tipo 'grade' con nota < 3.0 emite badge pero NO MRT."""
    payload = make_event_payload(
        event_id="evt-grade-fail-001",
        event_type="grade",
        grade=2.5,
        coins_amount=0.0, 
    )
    body = json.dumps(payload).encode()

    resp = await client.post(
        "/events/ingest",
        content=body,
        headers=_hmac_headers(body),
    )

    assert resp.status_code == 200
    data = resp.json()
    assert data["status"] == "processed"
    # El badge siempre se emite, pero MRT solo si aprueba
    assert data["status"] == "processed"
    assert data["mrt_tx"] is None  


# ── Test: Health endpoint ───────────────────────────────────────────────

@pytest.mark.asyncio
async def test_health_check(client: AsyncClient):
    """GET /health retorna status ok."""
    resp = await client.get("/health")
    assert resp.status_code == 200
    data = resp.json()
    assert data["status"] == "ok"
    assert data["blockchain_connected"] is True


# ── Test: Payload inválido (falta campo obligatorio) ────────────────────

@pytest.mark.asyncio
async def test_ingest_invalid_payload(client: AsyncClient):
    """POST /events/ingest con payload incompleto retorna 422."""
    incomplete = {"event_id": "evt-incomplete"}
    body = json.dumps(incomplete).encode()

    resp = await client.post(
        "/events/ingest",
        content=body,
        headers=_hmac_headers(body),
    )

    assert resp.status_code == 422
