# MeritCoin — Sistema de Recompensas Académicas Digitales

Plataforma híbrida off-chain/on-chain para recompensas académicas digitales,
integrada con Moodle y respaldada por una red blockchain permissionada basada en Hyperledger Besu.

El sistema permite emitir:

- Tokens ERC-20 (`MeritCoin / MRT`)
- Insignias verificables ERC-1155 (`MeritBadges`)

La arquitectura combina:

- Moodle Plugin API
- FastAPI
- Hyperledger Besu (QBFT)
- PostgreSQL
- Smart Contracts Solidity
- Metadata distribuida compatible con IPFS

Desarrollado como proyecto académico en la **Universidad Tecnológica de Bolívar**.

---

## Características principales

- Arquitectura híbrida off-chain/on-chain
- Red blockchain privada permissionada
- Consenso QBFT mediante Hyperledger Besu
- Marketplace académico basado en MRT
- Emisión verificable de badges ERC-1155
- Integración completa con Moodle
- Comunicación segura mediante HMAC-SHA256
- Smart contracts compatibles con EVM
- Infraestructura contenerizada con Docker

---

## Arquitectura

> ⚠️ Diagrama visual pendiente de actualización (`docs/images/architecture.png`)

```text
+----------------------+
| Moodle Plugin        |
| PHP + Moodle API     |
+----------+-----------+
           |
           v
+----------------------+
| FastAPI Backend      |
| Blockchain Gateway   |
+----------+-----------+
           |
     +-----+------+
     |            |
     v            v
+---------+   +-------------------+
|PostgreSQL|   | Metadata Storage |
| Audit DB |   | IPFS-compatible  |
+---------+   +-------------------+
                    |
                    v
      +----------------------------------+
      | Hyperledger Besu QBFT Network    |
      | Permissioned Validator Nodes     |
      +----------------+-----------------+
                       |
             +---------+---------+
             |                   |
             v                   v
      +-------------+     +-------------+
      | ERC-1155    |     | ERC-20      |
      | MeritBadges |     | MeritCoin   |
      +-------------+     +-------------+
```

## Flujo de funcionamiento

1. Un estudiante completa una actividad o recibe una calificación en Moodle
2. El **observer** del plugin captura el evento y resuelve las monedas según la regla configurada por el profesor en `local_meritcoin_rules`
3. Se verifica que el estudiante no haya superado el **límite de MRT por curso** (configurable, por defecto 16) — si lo supera, el evento es descartado
4. El evento se encola en `local_meritcoin_queue` (estado `pending` o `pending_wallet` si el estudiante aún no tiene wallet)
5. Una **tarea programada** envía el evento al backend FastAPI firmado con HMAC-SHA256
6. El backend genera metadatos **Open Badges v2 (OBv2)**, simula pin en IPFS y llama a los contratos: `mintBadge` (ERC-1155) y `mint` MRT (ERC-20) en **Besu**
7. El resultado queda registrado en `local_meritcoin_earnings` (ganancias por curso) y en PostgreSQL (audit_log) para trazabilidad completa

---

## Estructura del repositorio

```text
meritcoin/
├── backend/                           # Backend FastAPI (procesamiento off-chain)
│   ├── app/
│   │   ├── api/                       # Endpoints REST
│   │   ├── core/                      # Configuración, DB, seguridad
│   │   ├── models/                    # SQLAlchemy + Pydantic
│   │   ├── services/                  # Blockchain, badges, tokens, audit
│   │   ├── workers/                   # Procesamiento asíncrono
│   │   └── main.py
│   ├── tests/                         # Tests backend
│   ├── requirements.txt
│   ├── pytest.ini
│   └── Dockerfile
│
├── contracts/                         # Smart contracts Solidity
│   ├── contracts/
│   │   ├── MeritCoinERC20.sol
│   │   └── MeritBadges1155.sol
│   ├── scripts/
│   │   └── deploy.js
│   ├── test/                          # Tests Hardhat + Chai
│   ├── hardhat.config.js
│   └── package.json
│
├── besu/
│   └── QBFT-Network/                  # Red privada Hyperledger Besu
│       ├── docker-compose.yml         # Red QBFT de 4 nodos
│       ├── genesis.json               # Bloque génesis
│       ├── qbftConfigFile.json        # Configuración QBFT
│       └── networkFiles/              # Claves y datos de nodos
│
├── plugin/                            # Plugin Moodle local_meritcoin
│   ├── classes/
│   │   ├── api_client.php             # Cliente HTTP FastAPI
│   │   ├── observer.php               # Captura de eventos Moodle
│   │   ├── rules_service.php          # Reglas MRT por curso
│   │   ├── form/
│   │   └── task/
│   ├── db/
│   │   ├── install.xml
│   │   ├── upgrade.php
│   │   ├── access.php
│   │   ├── events.php
│   │   └── tasks.php
│   ├── lang/
│   ├── styles/
│   ├── dashboard.php
│   ├── marketplace.php
│   ├── rewards.php
│   ├── manage.php
│   ├── settings.php
│   ├── lib.php
│   └── version.php
│
├── scripts/                           # Scripts auxiliares y E2E
│   ├── test_e2e.py
│   ├── test_curl.py
│   └── GUIA_FASE5.md
│
├── docs/                              # Diagramas y documentación adicional
│   └── images/
│
├── docker-compose.yml                 # Stack principal
├── .env.example
├── README.md
└── arc42-meritcoin.md
```
## Esquema de base de datos (v0.5.0)

| Tabla | Propósito |
|-------|-----------|
| `local_meritcoin_queue` | Cola de eventos pendientes de envío al backend |
| `local_meritcoin_rules` | Reglas de monedas por curso/actividad (configuradas por el profesor) |
| `local_meritcoin_earnings` | Ledger de monedas ganadas por curso (saldo disponible) |
| `local_meritcoin_spend` | Ledger de monedas gastadas en el mercado de recompensas |
| `local_meritcoin_course_config` | Configuración de moneda por curso (nombre, símbolo, contrato ERC-20) |
| `local_meritcoin_rewards` | Recompensas creadas por el profesor por curso |
| `local_meritcoin_redemptions` | Historial de canjes de recompensas |

El saldo gastable de un estudiante en un curso se calcula como:
`earned (earnings) − spent (spend)`, de forma independiente por curso.

---

## Límite de MRT por estudiante por curso

Cada estudiante puede recibir un máximo configurable de MRT en un curso durante todo el semestre (por defecto 16 MRT). Este límite se gestiona en **Administración del sitio → Plugins locales → MeritCoin → Student MRT limit per course**.

El límite **no se reinicia**: acumula todo el historial del curso. Los MRT gastados en el marketplace siguen contando hacia el límite (se evalúa el total recibido, no el saldo actual).

Cuando un evento provoca que el total histórico de MRT otorgados al estudiante en ese curso supere el límite, el observer lo descarta y no lo envía al backend.

---

## Capabilities (permisos)

| Capability | Contexto | Rol por defecto | Uso |
|---|---|---|---|
| `local/meritcoin:manage` | Sistema | manager | Configuración global del plugin |
| `local/meritcoin:viewqueue` | Sistema | manager | Ver cola de eventos (panel admin) |
| `local/meritcoin:manage_rules` | Curso | editingteacher, manager | Crear/editar/eliminar reglas del curso |
| `local/meritcoin:view_report` | Curso | student, teacher, manager | Ver informe de ganancias del curso |

---

## Requisitos

- **Docker** y **Docker Compose** v2+
- **Node.js** 18+ y **npm**
- **Python** 3.11+
- **Git**
- **Java** 21+ (requerido por Hyperledger Besu si se corre fuera de Docker)

---

## Inicio rápido

### 1. Clonar el repositorio

```bash
git clone <url-del-repo>
cd meritcoin
cp .env.example .env
```

---

### 2. Levantar la red blockchain Besu (QBFT)

La red blockchain corre de forma independiente al stack principal.

```bash
cd besu/QBFT-Network
docker compose up -d
```

Esto inicia:

- 4 nodos Hyperledger Besu
- consenso QBFT
- red privada permissionada
- validadores distribuidos

| Nodo | RPC HTTP | P2P |
|---|---|---|
| besu-node-1 | localhost:8545 | 30303 |
| besu-node-2 | localhost:8546 | 30304 |
| besu-node-3 | localhost:8547 | 30305 |
| besu-node-4 | localhost:8548 | 30306 |

Verificar generación de bloques:

```bash
curl -s http://localhost:8545 -X POST -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
```

---

### 3. Levantar servicios principales

```bash
docker compose up -d
```

Servicios principales:

| Servicio | URL |
|---|---|
| Moodle | http://localhost:8080 |
| FastAPI Backend | http://localhost:8000 |
| PostgreSQL | localhost:5432 |

---

### 4. Instalar dependencias de contratos

```bash
cd contracts
npm install
```

Ejecutar tests:

```bash
npx hardhat test
```

---

### 5. Desplegar contratos inteligentes

```bash
npx hardhat run scripts/deploy.js --network besu
```

Salida esperada:

```text
MeritCoin ERC20 deployed to:    0x...
MeritBadge ERC1155 deployed to: 0x...
```

Copiar ambas direcciones.

---

### 6. Configurar variables del backend

Editar:

```text
backend/.env
```

Variables principales:

```env
BLOCKCHAIN_RPC_URL=http://host.docker.internal:8545

MRT_CONTRACT_ADDRESS=0x...
BADGE_CONTRACT_ADDRESS=0x...

DEPLOYER_PRIVATE_KEY=0x...

HMAC_SECRET=change-this-secret

WALLET_ENCRYPTION_KEY=your-fernet-key
```

---

### 7. Reiniciar backend

```bash
docker compose up -d --force-recreate backend
```

Verificar conexión blockchain:

```bash
curl http://localhost:8000/health
```

Respuesta esperada:

```json
{
  "blockchain_connected": true
}
```

## Flujo de pruebas end-to-end (E2E)

El proyecto incluye un flujo completo de pruebas desde Moodle hasta la emisión de tokens e insignias en la red blockchain Besu.

El flujo E2E cubre:

1. Captura de eventos académicos desde Moodle
2. Encolado de eventos en MariaDB
3. Envío firmado mediante HMAC-SHA256
4. Procesamiento en FastAPI
5. Emisión de MRT (ERC-20)
6. Emisión de badges ERC-1155
7. Registro de auditoría en PostgreSQL
8. Visualización en dashboard y marketplace

---

### Ejecutar pruebas E2E

```bash
python scripts/test_e2e.py
```

---

### Verificar estado del backend

```bash
curl http://localhost:8000/health
```

---

### Verificar generación de bloques Besu

```bash
curl -s http://localhost:8545 -X POST -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
```

---

### Documentación adicional

| Documento | Descripción |
|---|---|
| `scripts/GUIA_FASE5.md` | Flujo completo de pruebas manuales |
| `arc42-meritcoin.md` | Documentación de arquitectura |
| `docs/images/` | Diagramas y arquitectura visual |


## API del backend

El backend FastAPI actúa como gateway entre Moodle y la red blockchain Besu.

### Endpoints principales

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/health` | Estado del backend y conexión blockchain |
| POST | `/events/ingest` | Procesamiento de eventos académicos |
| GET | `/students/{wallet}/summary` | Balance MRT + badges |
| GET | `/students/{wallet}/balance` | Balance ERC-20 |
| GET | `/students/{wallet}/badges` | Insignias ERC-1155 |
| POST | `/tokens/spend` | Quema de MRT para marketplace |

### Seguridad API

- HMAC-SHA256
- Validación de payloads
- Idempotencia por `event_id`
- Control de roles y permisos
- Integración segura con Besu mediante JSON-RPC
---

## Contratos inteligentes

| Contrato | Estándar | Descripción |
|---|---|---|
| `MeritCoinERC20` | ERC-20 | Token académico MRT |
| `MeritBadges1155` | ERC-1155 | Insignias verificables |

### Características

- OpenZeppelin 5.x
- AccessControl
- Pausable
- Compatibilidad EVM
- Integración Hyperledger Besu
- Metadata Open Badges v2
---

## Reglas de recompensa

Las monedas se calculan en el plugin (no en el backend) a partir de las reglas configuradas por el profesor en cada curso.

| Tipo de regla | Configuración | Comportamiento |
|---|---|---|
| **Por actividad** | `rule_scope = activity`, `cmid` específico | Se aplica solo al completar esa actividad |
| **Por tipo de actividad** | `rule_scope = activity_type`, `mod_type` | Se aplica a todos los módulos del mismo tipo |
| **Por curso** | `rule_scope = course`, `cmid = NULL` | Se aplica al completar el curso entero |

El valor de monedas es un monto fijo (`coins_amount`) definido por el profesor.
Las reglas se pueden habilitar o deshabilitar sin borrarlas.

---

## Seguridad

La arquitectura implementa múltiples capas de seguridad:

- Comunicación firmada mediante HMAC-SHA256
- Validación de eventos académicos
- Idempotencia contra duplicación de recompensas
- Roles y permisos mediante AccessControl
- Red blockchain permissionada
- Wallets custodiales cifradas
- Validación de transacciones blockchain
- Separación off-chain/on-chain
- Persistencia de auditoría en PostgreSQL

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| LMS | Moodle 4.3 |
| Plugin | PHP + Moodle Plugin API |
| Backend | FastAPI + SQLAlchemy |
| Smart Contracts | Solidity + OpenZeppelin |
| Blockchain | Hyperledger Besu |
| Consenso | QBFT |
| Blockchain Client | web3.py |
| Bases de datos | MariaDB + PostgreSQL |
| Infraestructura | Docker Compose |
| Metadata | Open Badges v2 + IPFS-compatible |

---

## Estado de las pruebas

| Componente | Tests | Framework | Estado |
|------------|-------|-----------|--------|
| Contratos Solidity | 19 | Hardhat + Chai | ✅ Estables |
| Backend FastAPI | 23 | pytest + httpx | ✅ Estables |
| E2E flujo completo | 8 | Python (stdlib) | ✅ Estables |
| **Total** | **50** | | |

---

## Estado del proyecto

| Fase | Estado |
|---|---|
| Infraestructura Docker | ✅ |
| Smart Contracts ERC-20 / ERC-1155 | ✅ |
| Backend FastAPI | ✅ |
| Plugin Moodle | ✅ |
| Marketplace académico | ✅ |
| Dashboard estudiantil | ✅ |
| Integración Hyperledger Besu | ✅ |
| Red QBFT permissionada | ✅ |
| Auditoría distribuida | ✅ |
| Testing E2E | ✅ |
| Integración SAVIO | 🔄 |

---

## Roadmap

Próximas líneas de evolución:

- Observabilidad blockchain
- Integración IPFS distribuida real
- Gobernanza de validadores
- Identidad descentralizada (DID)
- Certificados académicos verificables
- Despliegue institucional multi-nodo

---

## Licencia

Proyecto académico — Universidad Tecnológica de Bolívar, 2026.
