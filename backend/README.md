# Backend MeritCoin - API FastAPI

Servicio off-chain que recibe eventos académicos de Moodle, genera metadatos
Open Badges v2, interactúa con la blockchain (**Hyperledger Besu**) y registra todo en
PostgreSQL para auditoría.

Este servicio actúa como la capa de procesamiento entre el plugin Moodle
(`local_meritcoin`) y los contratos inteligentes `MeritBadges1155` (ERC-1155)
y `MeritCoinERC20` (ERC-20).

---

## Estructura

```text
backend/
├── app/
│   ├── api/
│   │   ├── events.py          # POST /events/ingest (HMAC)
│   │   ├── students.py        # GET /students/{wallet}/badges, /balance, /summary
│   │   └── tokens.py          # POST /tokens/spend
│   ├── core/
│   │   ├── config.py          # Settings con pydantic-settings
│   │   ├── database.py        # AsyncSession (SQLAlchemy + asyncpg)
│   │   └── security.py        # verify_hmac, compute_hmac
│   ├── models/
│   │   ├── events.py          # AcademicEvent, EventResponse, StudentBadge, StudentBalance, StudentSummary
│   │   └── audit.py           # EventRecord, AuditLog (tablas SQLAlchemy)
│   ├── services/
│   │   ├── events_service.py  # Orquestador del flujo completo
│   │   ├── blockchain.py      # Wrapper web3.py para contratos
│   │   ├── badges.py          # Generación de metadatos OBv2
│   │   ├── tokens.py          # Lógica de mint/burn MRT
│   │   └── audit.py           # Registro en PostgreSQL
│   ├── workers/
│   │   └── ipfs.py            # Simulador de pin IPFS
│   └── main.py                # FastAPI app, lifespan, routers
├── tests/
│   ├── conftest.py            # Fixtures: async DB, mock blockchain, HMAC
│   ├── test_events.py         # Tests del endpoint /events/ingest
│   ├── test_blockchain.py     # Tests del servicio blockchain
│   └── test_students.py       # Tests de consulta de balance, badges y summary
├── requirements.txt
└── pytest.ini
```

---

## Responsabilidad del backend

El backend **no** calcula las reglas pedagógicas ni decide cuántas monedas gana un estudiante: ese cálculo ocurre en el plugin Moodle. El backend recibe el evento ya resuelto (`coins_amount`), valida la firma HMAC, garantiza idempotencia, genera los metadatos de la insignia, ejecuta las transacciones on-chain y registra la auditoría.

También expone endpoints de lectura para el dashboard del estudiante y endpoints operativos para el marketplace, incluyendo consulta de saldo y quema de MRT cuando un canje debe reflejarse on-chain.

---

## Dependencias principales

| Paquete | Uso |
|---------|-----|
| fastapi | Framework web asíncrono |
| uvicorn | Servidor ASGI |
| pydantic / pydantic-settings | Validación y configuración |
| sqlalchemy[asyncio] + asyncpg | Base de datos async |
| web3 | Interacción con contratos EVM |
| pytest + pytest-asyncio + httpx | Testing |

---

## Variables de entorno

Crear archivo `backend/.env`:

```env
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@meritcoin-postgres:5432/meritcoin_db
HMAC_SECRET=cambia-este-secreto-en-produccion
BLOCKCHAIN_RPC_URL=http://meritcoin-besu:8545
DEPLOYER_PRIVATE_KEY=<clave-privada-del-emisor>
BADGE_CONTRACT_ADDRESS=<direccion_despues_del_deploy>
MRT_CONTRACT_ADDRESS=<direccion_despues_del_deploy>
DEBUG=true
```

### Notas de configuración

- Si el backend corre dentro de Docker Compose, usa `meritcoin-postgres` y `meritcoin-besu` como hosts.
- Si lo ejecutas fuera de Docker, ajusta `DATABASE_URL` y `BLOCKCHAIN_RPC_URL` a `localhost` o a la IP correspondiente.
- `DEPLOYER_PRIVATE_KEY` debe corresponder a la cuenta que tiene `ISSUER_ROLE` y `MINTER_ROLE` en los contratos.
- En producción, esta clave **no** debe permanecer en texto plano ni reutilizar claves de desarrollo.

---

## Endpoints

### GET /health

Estado del servicio y conexión a blockchain.

**Respuesta esperada:**
```json
{
  "status": "ok",
  "blockchain_connected": true,
  "chain_id": 1337
}
```

> El `chain_id` exacto depende de la configuración de la red Besu.

### POST /events/ingest

Recibe un evento académico firmado con HMAC-SHA256.

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
  "activity_id": "cmid-55",
  "activity_name": "Quiz 1",
  "event_type": "grade",
  "grade": 4.5,
  "coins_amount": 5,
  "timestamp": "2026-03-10T21:00:00Z"
}
```

**Tipos de evento comunes:**
- `completion`
- `grade`

**Respuesta (EventResponse):**
```json
{
  "event_id": "evt-moodle-12345",
  "status": "processed",
  "badge_tx": "0xabc...",
  "mrt_tx": "0xdef...",
  "cid_ipfs": "Qm...",
  "message": "Badge + 5 MRT"
}
```

**Errores comunes:**
- `401`: Firma HMAC inválida.
- `409`: Evento duplicado, si la implementación expone conflicto explícito.
- `422`: Payload inválido.
- `500`: Error interno al mintear o registrar auditoría.

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

### GET /students/{wallet}/summary

Devuelve una vista agregada para el dashboard del estudiante.

**Respuesta:**
```json
{
  "wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
  "mrt_balance": "100.0",
  "badges": [
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
}
```

### POST /tokens/spend

Quema MRT cuando un canje del marketplace debe reflejarse on-chain.

**Body de ejemplo:**
```json
{
  "wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
  "amount": 10,
  "reason": "marketplace redemption"
}
```

**Respuesta de ejemplo:**
```json
{
  "status": "processed",
  "wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
  "amount": 10,
  "tx_hash": "0x123..."
}
```

---

## Flujo de procesamiento

```text
POST /events/ingest
    │
    ├─ 1. verify_hmac()          Valida firma X-HMAC-Signature
    ├─ 2. Parsear AcademicEvent  Pydantic validation
    ├─ 3. Verificar idempotencia Rechazar o ignorar si event_id ya existe
    ├─ 4. Generar metadatos OBv2 JSON con assertion, badge, issuer
    ├─ 5. Simular IPFS pin       Retorna CID simulado
    ├─ 6. mintBadge (ERC-1155)   blockchain.mint_badge(wallet, id, uri)
    ├─ 7. mint MRT (ERC-20)      blockchain.mint_tokens(wallet, amount)
    ├─ 8. Registrar evento       PostgreSQL (events / audit_log)
    └─ 9. Retornar EventResponse Con tx hashes y CID
```

### Flujo de gasto de tokens

```text
POST /tokens/spend
    │
    ├─ 1. Validar payload
    ├─ 2. Verificar saldo on-chain
    ├─ 3. Ejecutar burn MRT (ERC-20)
    ├─ 4. Registrar auditoría
    └─ 5. Retornar tx hash
```

---

## Metadatos de badges

Por cada evento procesado, el backend genera metadatos compatibles con **Open Badges v2 (OBv2)**. En desarrollo, el pin en IPFS es simulado y retorna un CID sintético (`QmSimulated...`), suficiente para pruebas end-to-end y trazabilidad interna.

El contenido de esos metadatos incluye, como mínimo:
- Nombre y descripción del badge.
- Curso o actividad asociada.
- Wallet del receptor en forma ofuscada o derivada.
- Fecha de emisión.
- Referencia de verificación ligada al contrato ERC-1155.

---

## Seguridad HMAC

Toda petición POST al backend debe incluir el header `X-HMAC-Signature`. El cálculo es:

```python
import hashlib, hmac

signature = hmac.new(
    key=HMAC_SECRET.encode("utf-8"),
    msg=body_bytes,
    digestmod=hashlib.sha256,
).hexdigest()
```

Si la firma no coincide, el backend retorna HTTP 401.

---

## Idempotencia y auditoría

La idempotencia se basa en `event_id`. Si Moodle envía dos veces el mismo evento, el backend debe evitar reemitir la insignia y los MRT.

Para eso, el backend registra el procesamiento en PostgreSQL y consulta ese historial antes de volver a ejecutar mint on-chain. Esto evita duplicados y preserva la trazabilidad de qué se intentó procesar, cuándo y con qué resultado.

---

## Ejecutar en desarrollo

```bash
# Instalar dependencias
cd backend
pip install -r requirements.txt

# Ejecutar tests
python -m pytest tests/ -v

# Levantar servidor
python -m uvicorn app.main:app --reload --port 8000
```

### Requisitos previos

- PostgreSQL activo.
- Besu activo y accesible por RPC.
- Contratos desplegados y direcciones cargadas en `backend/.env`.
- Variables `HMAC_SECRET`, `BADGE_CONTRACT_ADDRESS` y `MRT_CONTRACT_ADDRESS` correctamente configuradas.

> Nota en Windows: usar `python -m uvicorn` en lugar de `uvicorn` directamente si el comando no está en PATH.

---

## Ejemplos de prueba

### Health check

```bash
curl http://localhost:8000/health
```

### Ingesta de evento

```bash
curl -X POST http://localhost:8000/events/ingest   -H "Content-Type: application/json"   -H "X-HMAC-Signature: <firma>"   -d '{
    "event_id": "evt-001",
    "student_wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
    "student_id": "STU-001",
    "course_id": "COURSE-101",
    "course_name": "Introduccion a Blockchain",
    "activity_id": "cmid-10",
    "activity_name": "Quiz 1",
    "event_type": "grade",
    "grade": 4.5,
    "coins_amount": 5,
    "timestamp": "2026-03-10T21:00:00Z"
  }'
```

### Summary de estudiante

```bash
curl http://localhost:8000/students/0x70997970C51812dc3A010C7d01b50e0d17dc79C8/summary
```

---

## Tests

El backend incluye pruebas para:

- Ingesta válida de eventos.
- Firma HMAC inválida.
- Detección de eventos duplicados.
- Mint de badges y tokens.
- Consulta de balance, badges y summary.
- Manejo de errores de blockchain y persistencia.

```bash
python -m pytest tests/ -v --tb=short
```

---

## Integración con el plugin Moodle

El plugin `local_meritcoin` se encarga de:
- Resolver las reglas pedagógicas por curso, actividad o tipo de actividad.
- Aplicar el límite de MRT por estudiante/curso.
- Encolar eventos en MariaDB.
- Enviar eventos firmados al backend mediante una tarea programada.

El backend se encarga de:
- Validar autenticidad del evento.
- Emitir badge y MRT en Besu.
- Exponer saldo y badges para el dashboard.
- Reflejar gasto on-chain cuando el marketplace ejecuta un canje.
- Mantener la auditoría técnica en PostgreSQL.

---

## Estado

Backend alineado con la versión actual del proyecto:
- Integración con **Hyperledger Besu**.
- Endpoints de lectura para dashboard.
- Soporte para gasto de MRT desde marketplace.
- Trazabilidad off-chain en PostgreSQL.
- Preparado para despliegue en SAVIO junto al resto del sistema.
