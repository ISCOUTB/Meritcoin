#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
MeritCoin - Test End-to-End (Fase 5)

Prueba el flujo completo:
  Evento academico -> Backend FastAPI -> Blockchain (Hardhat)

REQUISITOS (deben estar corriendo):
  1. Docker (Moodle + MariaDB + PostgreSQL):  docker compose up -d
  2. Hardhat node:                             cd contracts && npx hardhat node
  3. Contratos desplegados:                    npx hardhat run scripts/deploy.js --network localhost
  4. Backend FastAPI:                          cd backend && python -m uvicorn app.main:app --port 8000

EJECUTAR:
  cd meritcoin
  python scripts/test_e2e.py
"""

import argparse
import hashlib
import hmac
import json
import sys
import time

try:
    import requests
    USE_REQUESTS = True
except ImportError:
    import urllib.request
    import urllib.error
    USE_REQUESTS = False


# -- Configuracion por defecto ------------------------------------------------
BACKEND_URL = "http://localhost:8000"
DEFAULT_HMAC_SECRET = "cambia-este-secreto-en-produccion"
STUDENT_WALLET = "0x70997970C51812dc3A010C7d01b50e0d17dc79C8"

_hmac_secret = DEFAULT_HMAC_SECRET


def compute_hmac(payload_bytes):
    """Calcula HMAC-SHA256 del payload."""
    return hmac.new(
        key=_hmac_secret.encode("utf-8"),
        msg=payload_bytes,
        digestmod=hashlib.sha256,
    ).hexdigest()


def http_post(url, body, headers):
    """POST request. Retorna (status_code, response_text)."""
    if USE_REQUESTS:
        resp = requests.post(url, data=body, headers=headers, timeout=30)
        return resp.status_code, resp.text
    else:
        req = urllib.request.Request(url, data=body, headers=headers, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return resp.status, resp.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()


def http_get(url):
    """GET request. Retorna (status_code, response_text)."""
    if USE_REQUESTS:
        resp = requests.get(url, timeout=30)
        return resp.status_code, resp.text
    else:
        req = urllib.request.Request(url, method="GET")
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return resp.status, resp.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()


def print_step(num, desc):
    print("")
    print("=" * 60)
    print("  PASO %d: %s" % (num, desc))
    print("=" * 60)


def print_ok(msg):
    print("  [OK] %s" % msg)


def print_fail(msg):
    print("  [FAIL] %s" % msg)


def main():
    global _hmac_secret

    parser = argparse.ArgumentParser(description="MeritCoin E2E Test")
    parser.add_argument("--url", default=BACKEND_URL, help="Backend URL")
    parser.add_argument("--secret", default=DEFAULT_HMAC_SECRET, help="HMAC secret")
    parser.add_argument("--wallet", default=STUDENT_WALLET, help="Student wallet")
    args = parser.parse_args()

    base = args.url.rstrip("/")
    _hmac_secret = args.secret
    wallet = args.wallet
    passed = 0
    failed = 0

    print("")
    print("=" * 60)
    print("  MERITCOIN - TEST END-TO-END (FASE 5)")
    print("=" * 60)
    print("  Backend:  %s" % base)
    print("  Wallet:   %s" % wallet)

    # -- PASO 1: Health check --------------------------------------------------
    print_step(1, "Health check del backend")
    try:
        status, body = http_get("%s/health" % base)
        data = json.loads(body)
        if status == 200 and data.get("status") == "ok":
            print_ok("Backend OK - blockchain_connected=%s" % data.get("blockchain_connected"))
            print_ok("badge_contract=%s" % data.get("badge_contract"))
            print_ok("mrt_contract=%s" % data.get("mrt_contract"))
            if not data.get("blockchain_connected"):
                print_fail("Blockchain NO conectada. Esta corriendo 'npx hardhat node'?")
                print("  Abortando test.")
                sys.exit(1)
            passed += 1
        else:
            print_fail("Health check fallo: %d - %s" % (status, body))
            failed += 1
    except Exception as e:
        print_fail("No se pudo conectar al backend: %s" % e)
        print("  Esta corriendo 'python -m uvicorn app.main:app --port 8000'?")
        sys.exit(1)

    # -- PASO 2: Enviar evento de completion -----------------------------------
    print_step(2, "Enviar evento de COMPLETION (curso completado)")
    event_id_completion = "evt-e2e-completion-%d" % int(time.time())
    payload_completion = {
        "event_id": event_id_completion,
        "student_wallet": wallet,
        "student_id": "STU-E2E-001",
        "course_id": "COURSE-E2E-101",
        "course_name": "Blockchain Fundamentals (E2E Test)",
        "event_type": "completion",
        "grade": None,
    }
    body = json.dumps(payload_completion).encode()
    sig = compute_hmac(body)
    headers = {
        "Content-Type": "application/json",
        "X-HMAC-Signature": sig,
    }

    status, resp = http_post("%s/events/ingest" % base, body, headers)
    data = json.loads(resp)
    if status == 200 and data.get("status") == "processed":
        print_ok("Evento procesado: %s" % data.get("event_id"))
        print_ok("Badge TX:  %s" % data.get("badge_tx"))
        print_ok("MRT TX:    %s" % data.get("mrt_tx"))
        print_ok("CID IPFS:  %s" % data.get("cid_ipfs"))
        print_ok("Mensaje:   %s" % data.get("message"))
        passed += 1
    else:
        print_fail("Fallo: %d - %s" % (status, resp))
        failed += 1

    # -- PASO 3: Verificar idempotencia ----------------------------------------
    print_step(3, "Verificar IDEMPOTENCIA (mismo evento de nuevo)")
    status, resp = http_post("%s/events/ingest" % base, body, headers)
    data = json.loads(resp)
    if status == 200 and data.get("status") == "duplicate":
        print_ok("Duplicado detectado correctamente: %s" % data.get("message"))
        passed += 1
    else:
        print_fail("Deberia ser 'duplicate' pero fue: %s - %s" % (data.get("status"), resp))
        failed += 1

    # -- PASO 4: Enviar evento de grade (aprobatoria) --------------------------
    print_step(4, "Enviar evento de GRADE aprobatoria (nota=4.5)")
    event_id_grade = "evt-e2e-grade-pass-%d" % int(time.time())
    payload_grade = {
        "event_id": event_id_grade,
        "student_wallet": wallet,
        "student_id": "STU-E2E-001",
        "course_id": "COURSE-E2E-102",
        "course_name": "Smart Contracts Avanzados (E2E Test)",
        "event_type": "grade",
        "grade": 4.5,
    }
    body = json.dumps(payload_grade).encode()
    sig = compute_hmac(body)
    headers["X-HMAC-Signature"] = sig

    status, resp = http_post("%s/events/ingest" % base, body, headers)
    data = json.loads(resp)
    if status == 200 and data.get("status") == "processed":
        print_ok("Evento procesado: %s" % data.get("event_id"))
        print_ok("Badge TX:  %s" % data.get("badge_tx"))
        print_ok("MRT TX:    %s (50 MRT por grade aprobatoria)" % data.get("mrt_tx"))
        passed += 1
    else:
        print_fail("Fallo: %d - %s" % (status, resp))
        failed += 1

    # -- PASO 5: Enviar evento de grade (reprobatoria) -------------------------
    print_step(5, "Enviar evento de GRADE reprobatoria (nota=2.0)")
    event_id_fail = "evt-e2e-grade-fail-%d" % int(time.time())
    payload_fail = {
        "event_id": event_id_fail,
        "student_wallet": wallet,
        "student_id": "STU-E2E-001",
        "course_id": "COURSE-E2E-103",
        "course_name": "Criptografia Aplicada (E2E Test)",
        "event_type": "grade",
        "grade": 2.0,
    }
    body = json.dumps(payload_fail).encode()
    sig = compute_hmac(body)
    headers["X-HMAC-Signature"] = sig

    status, resp = http_post("%s/events/ingest" % base, body, headers)
    data = json.loads(resp)
    if status == 200 and data.get("status") == "processed":
        mrt_tx = data.get("mrt_tx")
        if mrt_tx is None:
            print_ok("Correctamente NO acuno MRT (nota < 3.0)")
        else:
            print_fail("No deberia haber acunado MRT pero lo hizo: %s" % mrt_tx)
            failed += 1
        print_ok("Badge TX:  %s (badge si se emite siempre)" % data.get("badge_tx"))
        passed += 1
    else:
        print_fail("Fallo: %d - %s" % (status, resp))
        failed += 1

    # -- PASO 6: Consultar balance MRT -----------------------------------------
    print_step(6, "Consultar balance MRT del estudiante")
    status, resp = http_get("%s/students/%s/balance" % (base, wallet))
    data = json.loads(resp)
    if status == 200:
        print_ok("Wallet:      %s" % data.get("wallet"))
        print_ok("Balance MRT: %s" % data.get("balance_mrt"))
        print_ok("Balance Wei: %s" % data.get("balance_wei"))
        passed += 1
    else:
        print_fail("Fallo: %d - %s" % (status, resp))
        failed += 1

    # -- PASO 7: Consultar badges ----------------------------------------------
    print_step(7, "Consultar badges del estudiante")
    status, resp = http_get("%s/students/%s/badges" % (base, wallet))
    data = json.loads(resp)
    if status == 200:
        print_ok("Total badges encontrados: %d" % len(data))
        for badge in data:
            print_ok("  Badge #%s - %s (%s) tx=%s..." % (
                badge.get("badge_id"),
                badge.get("course_name"),
                badge.get("event_type"),
                str(badge.get("tx_hash", ""))[:16],
            ))
        passed += 1
    else:
        print_fail("Fallo: %d - %s" % (status, resp))
        failed += 1

    # -- PASO 8: HMAC invalido -------------------------------------------------
    print_step(8, "Verificar rechazo de HMAC invalido")
    bad_headers = {
        "Content-Type": "application/json",
        "X-HMAC-Signature": "firma_falsa_atacante",
    }
    dummy = json.dumps({"event_id": "evt-hack"}).encode()
    status, resp = http_post("%s/events/ingest" % base, dummy, bad_headers)
    if status == 401:
        print_ok("Rechazado correctamente con 401 (firma HMAC invalida)")
        passed += 1
    else:
        print_fail("Deberia ser 401 pero fue: %d" % status)
        failed += 1

    # -- Resumen final ---------------------------------------------------------
    total = passed + failed
    print("")
    print("=" * 60)
    print("  RESULTADO FINAL: %d/%d pruebas pasaron" % (passed, total))
    print("=" * 60)
    if failed == 0:
        print("  [OK] TODAS LAS PRUEBAS PASARON - El flujo E2E funciona correctamente")
    else:
        print("  [FAIL] %d prueba(s) fallaron - revisa los errores arriba" % failed)
    print("")

    sys.exit(0 if failed == 0 else 1)


if __name__ == "__main__":
    main()
