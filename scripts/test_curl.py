#!/usr/bin/env python3
"""
MeritCoin — Generador de comandos curl para prueba manual.

Este script NO necesita requests ni librerías extras.
Genera los comandos curl que puedes copiar y pegar en tu terminal
para probar el backend manualmente.

USO:
  python scripts/test_curl.py

Luego copia y pega cada comando curl en tu terminal (CMD o PowerShell).
"""

import hashlib
import hmac
import json
import time

HMAC_SECRET = "cambia-este-secreto-en-produccion"
BACKEND_URL = "http://localhost:8000"
STUDENT_WALLET = "0x70997970C51812dc3A010C7d01b50e0d17dc79C8"


def compute_hmac(payload: str) -> str:
    return hmac.new(
        key=HMAC_SECRET.encode("utf-8"),
        msg=payload.encode("utf-8"),
        digestmod=hashlib.sha256,
    ).hexdigest()


def main():
    ts = int(time.time())

    print("=" * 60)
    print("  MERITCOIN — COMANDOS CURL DE PRUEBA")
    print("=" * 60)
    print()
    print("Copia y pega cada comando en tu terminal (CMD o PowerShell).")
    print("Ejecuta en ORDEN: primero el health check, luego los demás.")
    print()

    # ── 1. Health check ──────────────────────────────────────────────────
    print("-" * 60)
    print("1. HEALTH CHECK")
    print("-" * 60)
    print(f'curl -s {BACKEND_URL}/health')
    print()
    print("Esperado: blockchain_connected=true + direcciones de contratos")
    print()

    # ── 2. Evento de completion ──────────────────────────────────────────
    payload_completion = json.dumps({
        "event_id": f"evt-manual-completion-{ts}",
        "student_wallet": STUDENT_WALLET,
        "student_id": "STU-MANUAL-001",
        "course_id": "COURSE-MANUAL-101",
        "course_name": "Prueba Manual Completion",
        "event_type": "completion",
        "grade": None,
    }, ensure_ascii=False)
    sig = compute_hmac(payload_completion)

    print("-" * 60)
    print("2. EVENTO COMPLETION (100 MRT)")
    print("-" * 60)
    # Formato para CMD/PowerShell de Windows (comillas dobles escapadas).
    escaped = payload_completion.replace('"', '\\"')
    print(f'curl -s -X POST {BACKEND_URL}/events/ingest ^')
    print(f'  -H "Content-Type: application/json" ^')
    print(f'  -H "X-HMAC-Signature: {sig}" ^')
    print(f'  -d "{escaped}"')
    print()
    print('Esperado: "status":"processed", badge_tx y mrt_tx con hashes 0x...')
    print()

    # ── 3. Evento de grade aprobatoria ───────────────────────────────────
    payload_grade = json.dumps({
        "event_id": f"evt-manual-grade-{ts}",
        "student_wallet": STUDENT_WALLET,
        "student_id": "STU-MANUAL-001",
        "course_id": "COURSE-MANUAL-102",
        "course_name": "Prueba Manual Grade",
        "event_type": "grade",
        "grade": 4.5,
    }, ensure_ascii=False)
    sig_grade = compute_hmac(payload_grade)

    print("-" * 60)
    print("3. EVENTO GRADE APROBATORIA (50 MRT)")
    print("-" * 60)
    escaped = payload_grade.replace('"', '\\"')
    print(f'curl -s -X POST {BACKEND_URL}/events/ingest ^')
    print(f'  -H "Content-Type: application/json" ^')
    print(f'  -H "X-HMAC-Signature: {sig_grade}" ^')
    print(f'  -d "{escaped}"')
    print()
    print('Esperado: "status":"processed", mrt_tx con hash (50 MRT)')
    print()

    # ── 4. Consultar balance ─────────────────────────────────────────────
    print("-" * 60)
    print("4. CONSULTAR BALANCE MRT")
    print("-" * 60)
    print(f'curl -s {BACKEND_URL}/students/{STUDENT_WALLET}/balance')
    print()
    print("Esperado: balance_mrt=150.0 (100 completion + 50 grade)")
    print()

    # ── 5. Consultar badges ──────────────────────────────────────────────
    print("-" * 60)
    print("5. CONSULTAR BADGES")
    print("-" * 60)
    print(f'curl -s {BACKEND_URL}/students/{STUDENT_WALLET}/badges')
    print()
    print("Esperado: Array con los badges emitidos")
    print()

    # ── 6. HMAC inválido ─────────────────────────────────────────────────
    print("-" * 60)
    print("6. PRUEBA HMAC INVALIDO (debe fallar con 401)")
    print("-" * 60)
    print(f'curl -s -X POST {BACKEND_URL}/events/ingest ^')
    print(f'  -H "Content-Type: application/json" ^')
    print(f'  -H "X-HMAC-Signature: firma_falsa_12345" ^')
    print(f'  -d "{{\\"event_id\\":\\"evt-hack\\"}}"')
    print()
    print('Esperado: HTTP 401, "Firma HMAC inválida"')
    print()


if __name__ == "__main__":
    main()
