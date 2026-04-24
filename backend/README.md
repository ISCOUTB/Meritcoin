# Backend MeritCoin - API FastAPI

Servicio off-chain que recibe eventos academicos de Moodle, genera metadatos
Open Badges v2, interactua con la blockchain (Hardhat) y registra todo en
PostgreSQL para auditoria.

## Estructura

```
backend/
├── app/
│   ├── api/
│   │   ├── events.py          # POST /events/ingest (HMAC)
│   │   └── students.py        # GET /students/{wallet}/badges y /balance
│   ├── core/
│   │   ├── config.py          # Settings con pydantic-settings
│   │   ├── database.py        # AsyncSession (SQLAlchemy + asyncpg)
│   │   └── security.py        # verify_hmac, compute_hmac
│   ├── models/
│   │   ├── events.py          # AcademicEvent, EventResponse, StudentBadge, StudentBalance
│   │   └── audit.py           # EventRecord, AuditLog (tablas SQLAlchemy)
│   ├── services/
│   │   ├── events_service.py  # Orquestador del flujo completo
│   │   ├── blockchain.py      # Wrapper web3.py para contratos
│   │   ├── badges.py          # Generacion de metadatos OBv2
│   │   ├── tokens.py          # Logica de recompensas MRT
│   │   └── audit.py           # Registro en PostgreSQL
│   ├── workers/
│   │   └── ipfs.py            # Simulador de pin IPFS
│   └── main.py                # FastAPI app, lifespan, routers
├── tests/
│   ├── conftest.py            # Fixtures: async DB, mock blockchain, HMAC
│   ├── test_events.py         # Tests del endpoint /events/ingest
│   └── test_blockchain.py     # Tests del servicio blockchain
├── requirements.txt
└── pytest.ini
```

## Dependencias principales

| Paquete | Uso |
|---------|-----|
| fastapi | Framework web asincrono |
| uvicorn | Servidor ASGI |
| pydantic / pydantic-settings | Validacion y configuracion |
| sqlalchemy[asyncio] + asyncpg | Base de datos async |
| web3 | Interaccion con contratos Ethereum |
| pytest + pytest-asyncio + httpx | Testing |

## Variables de entorno

Crear archivo `backend/.env`:

```env
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@localhost:5432/meritcoin_db
HMAC_SECRET=cambia-este-secreto-en-produccion
BLOCKCHAIN_RPC_URL=http://127.0.0.1:8545
DEPLOYER_PRIVATE_KEY=0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
BADGE_CONTRACT_ADDRESS=<direccion despues de deploy>
MRT_CONTRACT_ADDRESS=<direccion despues de deploy>
DEBUG=true
```

## Endpoints

### GET /health

Estado del servicio y conexion a blockchain.

**Respuesta:**
```json
{
  "status": "ok",
  "blockchain": true,
  "chain_id": 31337
}
```

### POST /events/ingest

Recibe un evento academico firmado con HMAC-SHA256.

**Headers requeridos:**
- `Content-Type: application/json`
- `X-HMAC-Signature: <hmac-sha256-del-body>`

**Body (AcademicEvent):**
```json
{
  "event_id": "evt-moodle-12345",
  "student_wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
  "student_id": "STU-001",
  "course_id": "COURSE-101",
  "course_name": "Introduccion a Blockchain",
  "event_type": "completion",
  "grade": null,
  "timestamp": "2026-03-10T21:00:00Z"
}
```

**Tipos de evento:** `completion` (curso completado), `grade` (calificacion registrada)

**Respuesta (EventResponse):**
```json
{
  "event_id": "evt-moodle-12345",
  "status": "processed",
  "badge_tx": "0xabc...",
  "mrt_tx": "0xdef...",
  "cid_ipfs": "Qm...",
  "message": "Badge + 100 MRT"
}
```

**Errores:**
- `401`: Firma HMAC invalida
- `409`: Evento duplicado (idempotencia por event_id)
- `422`: Payload invalido

### GET /students/{wallet}/badges

Lista todas las insignias emitidas a un wallet.

**Respuesta:**
```json
[
  {
    "badge_id": 1,
    "course_id": "COURSE-101",
    "course_name": "Introduccion a Blockchain",
    "event_type": "completion",
    "uri": "ipfs://QmSimulated...",
    "tx_hash": "0xabc...",
    "issued_at": "2026-03-10T21:00:00Z"
  }
]
```

### GET /students/{wallet}/balance

Consulta el saldo MRT directamente desde la blockchain.

**Respuesta:**
```json
{
  "wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
  "balance_mrt": "100.0",
  "balance_wei": "100000000000000000000"
}
```

## Flujo de procesamiento

```
POST /events/ingest
    │
    ├─ 1. verify_hmac()          Valida firma X-HMAC-Signature
    ├─ 2. Parsear AcademicEvent  Pydantic validation
    ├─ 3. Verificar idempotencia Rechazar si event_id ya existe
    ├─ 4. Generar metadatos OBv2 JSON con assertion, badge, issuer
    ├─ 5. Simular IPFS pin       Retorna CID simulado
    ├─ 6. mintBadge (ERC-1155)   blockchain.mint_badge(wallet, id, uri)
    ├─ 7. mint MRT (ERC-20)      blockchain.mint_tokens(wallet, amount)
    ├─ 8. Registrar en audit_log PostgreSQL (trazabilidad)
    └─ 9. Retornar EventResponse Con tx hashes y CID
```

## Recompensas MRT

| Evento | Tokens |
|--------|--------|
| `completion` (curso completado) | 100 MRT |
| `grade` con nota >= 3.0 | 50 MRT |
| `grade` con nota < 3.0 | 0 MRT |

## Seguridad HMAC

Toda peticion POST al backend debe incluir el header `X-HMAC-Signature`.
El calculo es:

```python
import hashlib, hmac

signature = hmac.new(
    key=HMAC_SECRET.encode("utf-8"),
    msg=body_bytes,
    digestmod=hashlib.sha256,
).hexdigest()
```

Si la firma no coincide, el backend retorna HTTP 401.

## Ejecutar

```bash
# Instalar dependencias
cd backend
pip install -r requirements.txt

# Ejecutar tests (23/23)
python -m pytest tests/ -v

# Levantar servidor (requiere Hardhat node y PostgreSQL activos)
python -m uvicorn app.main:app --reload --port 8000
```

Nota en Windows: usar `python -m uvicorn` en lugar de `uvicorn` directamente
si el comando no esta en PATH.

## Tests

23 pruebas con pytest + httpx + aiosqlite (DB en memoria):

- `test_events.py`: Ingesta valida, HMAC invalido, evento duplicado, payload invalido, etc.
- `test_blockchain.py`: Mint badge, mint tokens, get balance, manejo de errores

```bash
python -m pytest tests/ -v --tb=short
```
