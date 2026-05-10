# Backend MeritCoin (`meritcoin-backend`)

Servicio FastAPI off-chain que recibe eventos académicos del plugin Moodle,
valida la firma HMAC, acuña tokens MRT (ERC-20) e insignias (ERC-1155) en
Hyperledger Besu, y registra toda la auditoría en PostgreSQL.

## Estructura

```
backend/
├── app/
│   ├── api/
│   │   ├── events.py     # POST /events/ingest
│   │   ├── students.py   # GET /students/{wallet}/badges|balance|summary
│   │   ├── tokens.py     # POST /tokens/spend
│   │   └── badges.py     # CRUD de skills, templates, awards y verificación pública
│   ├── core/
│   │   ├── config.py     # Settings con pydantic-settings (variables de entorno)
│   │   ├── database.py   # AsyncSession SQLAlchemy + asyncpg
│   │   └── security.py   # verify_hmac (dependency), compute_hmac
│   ├── models/
│   │   ├── events.py     # AcademicEvent, EventResponse, StudentBadge, StudentBalance
│   │   ├── audit.py      # EventRecord, AuditLog (tablas SQLAlchemy)
│   │   ├── badges.py     # BadgeTemplate, BadgeAward, Skill (tablas SQLAlchemy)
│   │   └── badges_schema.py  # Schemas Pydantic para badges
│   ├── services/
│   │   ├── events_service.py   # Orquestador del flujo completo de eventos
│   │   ├── audit_service.py    # Idempotencia y auditoría en BD
│   │   ├── blockchain.py       # Singleton BlockchainService (web3.py + asyncio.Lock)
│   │   ├── badges_service.py   # CRUD de skills, templates y awards
│   │   ├── tokens_service.py   # Cálculo de MRT (fallback si coins_amount = 0)
│   │   └── certificate.py      # Generación de certificados PDF (ReportLab)
│   ├── workers/
│   │   └── ipfs.py             # Simulador de pin IPFS (desarrollo)
│   └── main.py                 # FastAPI app, lifespan, CORS, routers
├── alembic/                    # Migraciones de base de datos
├── tests/
│   ├── conftest.py             # Fixtures: async DB, mock blockchain, HMAC
│   ├── test_events.py          # Tests de /events/ingest
│   ├── test_blockchain.py      # Tests del servicio blockchain
│   └── test_students.py        # Tests de /students/
├── requirements.txt
├── pytest.ini
└── Dockerfile
```

## Responsabilidad del backend

El backend **no** calcula cuántos MRT gana un estudiante — ese cálculo ocurre
en el plugin Moodle según las reglas configuradas por curso y actividad. El
backend recibe el evento con `coins_amount` ya calculado, valida la firma HMAC,
garantiza idempotencia, acuña los tokens en blockchain y registra la auditoría.

Adicionalmente expone endpoints para el dashboard del estudiante (saldo + badges)
y el marketplace (quema de MRT al canjear recompensas).

## Flujo de procesamiento de eventos

```
POST /events/ingest
        │
        ▼
verify_hmac() — valida X-HMAC-Signature (401 si falla)
        │
        ▼
AcademicEvent.model_validate_json() — validación Pydantic (422 si falla)
        │
        ▼
audit_service.reserve_event() — inserta event_id en BD
        │
        ├─ event_id ya existe → status = "duplicate" (200, no reintenta)
        │
        ▼
coins_amount del plugin = fuente de verdad
        │
        ├─ coins_amount = 0 → fallback local (WARNING en logs)
        │
        ▼
blockchain.mint_mrt(wallet, amount)  ←── asyncio.Lock (serializa nonces)
        │
        ▼
audit_service.record_audit() — registra tx_hash en AuditLog
        │
        ▼
audit_service.mark_event_processed() — status = "processed"
        │
        ├─ Cualquier error → rollback + mark_event_failed (sesión independiente)
        │
        ▼
EventResponse { event_id, status, mrt_tx, message }
```

## Endpoints

### Sistema

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/health` | Estado del servicio y conexión a blockchain |

### Eventos

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| POST | `/events/ingest` | HMAC | Recibe evento académico del plugin Moodle |

### Estudiantes

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/students/{wallet}/badges` | Insignias del flujo automático (audit_log) |
| GET | `/students/{wallet}/balance` | Saldo MRT desde blockchain |
| GET | `/students/{wallet}/summary` | Balance + badges para dashboard Moodle |

### Tokens

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/tokens/spend` | Quema MRT al canjear en marketplace |

### Insignias (sistema manual)

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/skills` | Listar skills |
| POST | `/skills` | Crear skill |
| POST | `/badges/templates` | Crear plantilla de insignia |
| GET | `/badges/templates` | Listar plantillas |
| GET | `/badges/templates/{id}` | Obtener plantilla |
| PATCH | `/badges/templates/{id}` | Actualizar plantilla |
| DELETE | `/badges/templates/{id}` | Eliminar plantilla (soft-delete si tiene awards) |
| POST | `/badges/award` | Otorgar insignia a estudiante |
| GET | `/badges/student/{student_id}` | Insignias de un estudiante |
| DELETE | `/badges/award/{award_id}` | Revocar insignia |
| GET | `/verify/{award_id}` | Verificación pública (sin auth) |
| GET | `/badges/award/{award_id}/certificate` | Descargar certificado PDF |

### Documentación interactiva

```
http://localhost:8000/docs      # Swagger UI
http://localhost:8000/redoc     # ReDoc
```

## Variables de entorno

Crear archivo `backend/.env`:

```env
# Base de datos
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@meritcoin-postgres:5432/meritcoin_db

# Seguridad
HMAC_SECRET=cambia-este-secreto-en-produccion

# Blockchain (Hyperledger Besu)
BLOCKCHAIN_RPC_URL=http://meritcoin-besu:8545
DEPLOYER_PRIVATE_KEY=<clave-privada-del-deployer>
BADGE_CONTRACT_ADDRESS=<direccion-del-contrato-ERC1155>
MRT_CONTRACT_ADDRESS=<direccion-del-contrato-ERC20>

# URL pública del backend (usada en badge_uri y certificados)
PUBLIC_BASE_URL=http://localhost:8000

# Debug
DEBUG=true
```

### Notas de configuración

- Si el backend corre dentro de Docker Compose, usa `meritcoin-postgres` y
  `meritcoin-besu` como hosts. Si corre fuera, usa `localhost`.
- `DEPLOYER_PRIVATE_KEY` debe corresponder a la cuenta con `MINTER_ROLE`
  y `BURNER_ROLE` en los contratos.
- Si `DEPLOYER_PRIVATE_KEY` no está configurada, el backend arranca igual
  pero los endpoints de mint/burn fallarán con `RuntimeError`.
- Si Besu no está disponible al arrancar, el servicio inicia igual;
  el health check refleja el estado real de conexión.

## Instalación y ejecución

### Con Docker Compose (recomendado)

El backend se levanta automáticamente con el resto del sistema:

```bash
docker compose up -d
```

### En local (sin Docker)

```bash
cd backend

# Crear entorno virtual
python -m venv .venv
source .venv/bin/activate        # Linux/Mac
.venv\Scripts\activate           # Windows

# Instalar dependencias
pip install -r requirements.txt

# Configurar variables de entorno
cp .env.example .env             # editar con tus valores

# Levantar servidor
python -m uvicorn app.main:app --reload --port 8000
```

> **Nota Windows (Git Bash):** usar `python -m uvicorn` en lugar de `uvicorn`
> directamente si el comando no está en PATH.

## Tests

```bash
cd backend
python -m pytest tests/ -v --tb=short
```

El suite de tests cubre:

- Ingesta válida de eventos con HMAC correcto
- Rechazo de firma HMAC inválida (401)
- Detección y rechazo de eventos duplicados
- Mint de MRT cuando el estudiante tiene wallet
- Omisión del mint cuando no hay wallet
- Consulta de balance, badges y summary
- Manejo de errores de blockchain (Besu no disponible)

## Seguridad HMAC

Toda petición `POST /events/ingest` debe incluir el header `X-HMAC-Signature`.
El cálculo es:

```python
import hashlib, hmac

signature = hmac.new(
    key=HMAC_SECRET.encode("utf-8"),
    msg=body_bytes,
    digestmod=hashlib.sha256,
).hexdigest()
```

Equivalente en PHP (plugin Moodle):
```php
hash_hmac("sha256", $body, $hmac_secret)
```

El backend usa `hmac.compare_digest()` para la comparación, evitando
timing attacks. Si la firma no coincide retorna HTTP 401.

## Idempotencia

La idempotencia se garantiza a nivel de `event_id`:

1. Antes de cualquier mint, se inserta el `event_id` en `EventRecord` con
   `status = "processing"`.
2. Si el insert falla por `IntegrityError` (clave duplicada), el evento se
   rechaza con `status = "duplicate"` sin reintentar el mint.
3. Si el mint falla, se hace rollback completo y `mark_event_failed` abre
   una sesión independiente para registrar el error sin depender de la
   sesión rollbackeada.

Esto garantiza que un estudiante nunca recibe MRT dos veces por el mismo evento,
incluso si el plugin Moodle reintenta el envío.

## Concurrencia en blockchain

`BlockchainService._send_tx` está protegido por un `asyncio.Lock` que
serializa todas las transacciones del deployer. Esto evita el problema de
nonce duplicado cuando llegan dos requests simultáneos (mint + burn al mismo
tiempo), que causaría que una transacción falle con `nonce too low` en Besu.

## Dependencias principales

| Paquete | Versión | Uso |
|---------|---------|-----|
| fastapi | ≥0.111 | Framework web asíncrono |
| uvicorn | ≥0.29 | Servidor ASGI |
| pydantic / pydantic-settings | ≥2.0 | Validación y configuración |
| sqlalchemy[asyncio] + asyncpg | ≥2.0 | Base de datos async |
| alembic | ≥1.13 | Migraciones de BD |
| web3 | ≥6.0 | Interacción con contratos EVM |
| reportlab | ≥4.0 | Generación de certificados PDF |
| pytest + pytest-asyncio + httpx | — | Testing |

## Ejemplos de uso

### Health check

```bash
curl http://localhost:8000/health
```

Respuesta esperada:
```json
{
  "status": "ok",
  "blockchain_connected": true,
  "badge_contract": "0xABC...",
  "mrt_contract": "0xDEF..."
}
```

### Ingesta de evento (con HMAC)

```bash
BODY='{"event_id":"evt-001","student_wallet":"0x70997970C51812dc3A010C7d01b50e0d17dc79C8","student_id":"42","course_id":"7","course_name":"Blockchain Aplicado","activity_id":"55","activity_name":"Quiz 1","event_type":"grade","grade":4.5,"coins_amount":5.0,"coin_symbol":"MRT","timestamp":"2026-05-10T14:00:00Z"}'

SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "cambia-este-secreto-en-produccion" | awk '{print $2}')

curl -X POST http://localhost:8000/events/ingest \
  -H "Content-Type: application/json" \
  -H "X-HMAC-Signature: $SIG" \
  -d "$BODY"
```

Respuesta:
```json
{
  "event_id": "evt-001",
  "status": "processed",
  "badge_tx": null,
  "mrt_tx": "0xabc123...",
  "cid_ipfs": null,
  "message": "Evento evt-001 procesado | 5.0 MRT acuñados"
}
```

### Consultar saldo MRT

```bash
curl http://localhost:8000/students/0x70997970C51812dc3A010C7d01b50e0d17dc79C8/balance
```

### Summary para dashboard

```bash
curl http://localhost:8000/students/0x70997970C51812dc3A010C7d01b50e0d17dc79C8/summary
```

### Canjear tokens MRT

```bash
curl -X POST http://localhost:8000/tokens/spend \
  -H "Content-Type: application/json" \
  -d '{
    "student_id": "42",
    "student_wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
    "amount": 10.0,
    "reward_id": "reward-abc",
    "course_id": "7"
  }'
```

### Verificar insignia (sin auth)

```bash
curl http://localhost:8000/verify/<award_id>
```

## Integración con el plugin Moodle

El plugin `local_meritcoin` se encarga de:
- Calcular `coins_amount` según reglas configuradas por curso/actividad
- Aplicar el límite de MRT por estudiante/curso
- Encolar eventos en MariaDB con idempotencia MD5
- Enviar eventos firmados al backend via `send_events_task` (cron cada minuto)
- Procesar canjes del marketplace via `process_redemptions_task`

El backend se encarga de:
- Validar autenticidad del evento (HMAC)
- Garantizar idempotencia a nivel de `event_id`
- Acuñar MRT en Besu (o registrar sin mint si no hay wallet)
- Exponer saldo y badges para el dashboard del estudiante
- Quemar MRT cuando el marketplace ejecuta un canje confirmado
- Mantener auditoría técnica completa en PostgreSQL
