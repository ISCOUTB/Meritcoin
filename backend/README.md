# Backend MeritCoin (`meritcoin-backend`)

Servicio FastAPI off-chain que recibe eventos académicos del plugin Moodle,
valida la firma HMAC, acuña tokens MRT (ERC-20) e insignias (ERC-1155) en
Hyperledger Besu, gestiona wallets custodiales por semestre y registra
toda la auditoría en PostgreSQL.

## Estructura

```
backend/
├── app/
│   ├── api/
│   │   ├── events.py       # POST /events/ingest
│   │   ├── students.py     # GET /students/{wallet}/badges|balance|summary
│   │   ├── tokens.py       # POST /tokens/spend
│   │   ├── badges.py       # CRUD de skills, templates, awards y verificación pública
│   │   └── wallets.py      # POST /wallets/provision, GET, expire-course, PATCH
│   ├── core/
│   │   ├── config.py       # Settings con pydantic-settings (variables de entorno)
│   │   ├── database.py     # AsyncSession SQLAlchemy + asyncpg
│   │   ├── security.py     # verify_hmac (dependency), compute_hmac
│   │   └── wallet_crypto.py  # Encriptación/desencriptación Fernet de claves privadas
│   ├── models/
│   │   ├── events.py       # AcademicEvent, EventResponse, StudentBadge, StudentBalance
│   │   ├── audit.py        # EventRecord, AuditLog (tablas SQLAlchemy)
│   │   ├── badges.py       # BadgeTemplate, BadgeAward, Skill (tablas SQLAlchemy)
│   │   ├── badges_schema.py  # Schemas Pydantic para badges
│   │   └── wallets.py      # WalletRegistry, CourseEnrollment (tablas SQLAlchemy)
│   ├── services/
│   │   ├── events_service.py   # Orquestador del flujo completo de eventos
│   │   ├── audit_service.py    # Idempotencia y auditoría en BD
│   │   ├── blockchain.py       # Singleton BlockchainService (web3.py + asyncio.Lock)
│   │   ├── badges_service.py   # CRUD de skills, templates y awards
│   │   ├── tokens_service.py   # Cálculo de MRT (fallback si coins_amount = 0)
│   │   ├── certificate.py      # Generación de certificados PDF (ReportLab)
│   │   └── wallet_service.py   # provision_wallet, expire_course, update_expires_at
│   ├── workers/
│   │   └── ipfs.py             # Simulador de pin IPFS (desarrollo)
│   └── main.py                 # FastAPI app, lifespan, CORS, routers
├── alembic/                    # Migraciones de base de datos
├── tests/
│   ├── conftest.py             # Fixtures: async DB, mock blockchain, HMAC
│   ├── test_events.py          # Tests de /events/ingest
│   ├── test_blockchain.py      # Tests del servicio blockchain
│   ├── test_students.py        # Tests de /students/
│   └── test_wallets.py         # 18 tests de wallets custodiales
├── requirements.txt
├── pytest.ini
└── Dockerfile
```

## Responsabilidad del backend

El backend **no** calcula cuántos MRT gana un estudiante — ese cálculo ocurre
en el plugin Moodle según las reglas configuradas por curso y actividad. El
backend recibe el evento con `coins_amount` ya calculado, valida la firma HMAC,
garantiza idempotencia, acuña los tokens en blockchain y registra la auditoría.

Adicionalmente expone endpoints para el dashboard del estudiante (saldo + badges),
el marketplace (quema de MRT al canjear recompensas) y la gestión de wallets
custodiales por semestre.

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

### Wallets custodiales

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/wallets/provision` | Genera wallet custodial para un estudiante en un curso piloto. Reutiliza la wallet si ya existe; reactiva el enrollment si estaba expirado (rematrícula). |
| GET | `/wallets/{student_id}` | Retorna la dirección de la wallet. **Nunca expone la clave privada.** |
| POST | `/wallets/expire-course` | Cierra todos los enrollments activos de un curso. Guarda snapshot de MRT. Llamado automáticamente por `expire_courses_task`. |
| PATCH | `/wallets/enrollments/{student_id}/{course_id}` | Sobreescribe la fecha de expiración de un enrollment activo. |

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

# Wallets custodiales
# Generar: python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
# ⚠️ OBLIGATORIO para el sistema de wallets — sin esto el backend no arranca
WALLET_ENCRYPTION_KEY=<clave-fernet-de-32-bytes-base64>

# Debug
DEBUG=true
```

### Notas de configuración

- Si el backend corre dentro de Docker Compose, usa `meritcoin-postgres` y
  `meritcoin-besu` como hosts. Si corre fuera, usa `localhost`.
- `DEPLOYER_PRIVATE_KEY` debe corresponder a la cuenta con `MINTER_ROLE`
  y `BURNER_ROLE` en los contratos.
- Si `WALLET_ENCRYPTION_KEY` no está configurada, el backend lanza un error
  al arrancar — es obligatoria para el sistema de wallets custodiales.
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

## Tests

```bash
cd backend
docker compose exec backend pytest tests/ -v --tb=short
# o en local:
python -m pytest tests/ -v --tb=short
```

El suite de tests (41 en total) cubre:

**Eventos:**
- Ingesta válida de eventos con HMAC correcto
- Rechazo de firma HMAC inválida (401)
- Detección y rechazo de eventos duplicados
- Mint de MRT cuando el estudiante tiene wallet
- Omisión del mint cuando no hay wallet

**Blockchain:**
- Mint, burn y consulta de balance
- Manejo de errores de blockchain (Besu no disponible)

**Estudiantes:**
- Consulta de balance, badges y summary

**Wallets custodiales (18 tests):**
- Provisionado de wallet nueva (`created=True`)
- Reutilización de wallet existente en curso nuevo
- Wallets distintas para estudiantes distintos
- Rematrícula: reactivación con saldo en 0
- Expiración de curso: snapshot MRT en todos los enrollments activos
- `expire_course` no afecta otros cursos
- Sobreescritura de `expires_at`
- `update_expires_at` retorna `False` si el enrollment no existe
- `get_wallet` retorna `None` si el estudiante no existe
- La clave privada nunca se expone en el endpoint

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

1. Antes de cualquier mint, se inserta el `event_id` en `EventRecord` con `status = "processing"`.
2. Si el insert falla por `IntegrityError` (clave duplicada), el evento se rechaza con `status = "duplicate"` sin reintentar el mint.
3. Si el mint falla, se hace rollback completo y `mark_event_failed` abre una sesión independiente para registrar el error.

## Wallets custodiales — detalle técnico

### Modelo de datos

```
wallet_registry (una fila por estudiante)
  student_id       TEXT  PRIMARY KEY  -- "STU-{moodle_userid}"
  wallet_address   TEXT  UNIQUE       -- "0x..."
  private_key_enc  TEXT               -- Fernet(private_key_hex)
  created_at       DATETIME

course_enrollment (una fila por estudiante × curso × semestre)
  id               INTEGER PRIMARY KEY
  student_id       TEXT REFERENCES wallet_registry
  course_id        TEXT               -- "COURSE-{moodle_courseid}"
  expires_at       DATETIME           -- fecha de cierre del semestre
  status           TEXT               -- "active" | "expired"
  mrt_snapshot     FLOAT DEFAULT 0    -- saldo MRT al momento de expirar
  expired_at       DATETIME
```

### Ciclo de rematrícula

Cuando un estudiante se rematricula en un curso ya expirado:
- La wallet **permanece igual** (no se genera una nueva).
- Se crea un enrollment nuevo con `status=active`, `mrt_snapshot=0` y `expired_at=None`.
- Los badges del semestre anterior se conservan en blockchain.

### Encriptación de claves privadas

```python
from cryptography.fernet import Fernet

# Al provisionar:
fernet = Fernet(settings.WALLET_ENCRYPTION_KEY)
encrypted = fernet.encrypt(private_key_hex.encode()).decode()

# Al firmar transacciones (solo en memoria, nunca en respuesta HTTP):
decrypted = fernet.decrypt(encrypted.encode()).decode()
```

## Concurrencia en blockchain

`BlockchainService._send_tx` está protegido por un `asyncio.Lock` que
serializa todas las transacciones del deployer. Esto evita el problema de
nonce duplicado cuando llegan dos requests simultáneos.

## Dependencias principales

| Paquete | Versión | Uso |
|---------|---------|-----|
| fastapi | ≥0.111 | Framework web asíncrono |
| uvicorn | ≥0.29 | Servidor ASGI |
| pydantic / pydantic-settings | ≥2.0 | Validación y configuración |
| sqlalchemy[asyncio] + asyncpg | ≥2.0 | Base de datos async |
| alembic | ≥1.13 | Migraciones de BD |
| web3 | ≥6.0 | Interacción con contratos EVM |
| eth-account | ≥0.10 | Generación de wallets Ethereum |
| cryptography | ≥42.0 | Encriptación Fernet de claves privadas |
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

### Provisionar wallet custodial

```bash
curl -X POST http://localhost:8000/wallets/provision \
  -H "Content-Type: application/json" \
  -d '{
    "student_id": "STU-42",
    "course_id": "COURSE-7",
    "expires_at": "2026-12-15T00:00:00Z"
  }'
```

Respuesta:
```json
{
  "wallet_address": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
  "created": true
}
```

### Expirar curso al fin de semestre

```bash
curl -X POST http://localhost:8000/wallets/expire-course \
  -H "Content-Type: application/json" \
  -d '{"course_id": "COURSE-7"}'
```

Respuesta:
```json
{
  "course_id": "COURSE-7",
  "expired_count": 35
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

### Consultar saldo MRT

```bash
curl http://localhost:8000/students/0x70997970C51812dc3A010C7d01b50e0d17dc79C8/balance
```

## Integración con el plugin Moodle

El plugin `local_meritcoin` se encarga de:
- Calcular `coins_amount` según reglas configuradas por curso/actividad
- Aplicar el límite de MRT por estudiante/curso
- Encolar eventos en MariaDB con idempotencia MD5
- Provisionar wallets custodiales via `wallet_service.php` (cursos piloto)
- Enviar eventos firmados al backend via `send_events_task` (cron cada minuto)
- Procesar canjes del marketplace via `process_redemptions_task`
- Cerrar enrollments de cursos vencidos via `expire_courses_task` (cron 2 AM)

El backend se encarga de:
- Validar autenticidad del evento (HMAC)
- Garantizar idempotencia a nivel de `event_id`
- Acuñar MRT en Besu (o registrar sin mint si no hay wallet)
- Gestionar el ciclo de vida completo de wallets custodiales
- Exponer saldo y badges para el dashboard del estudiante
- Quemar MRT cuando el marketplace ejecuta un canje confirmado
- Mantener auditoría técnica completa en PostgreSQL
