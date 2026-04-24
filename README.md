<<<<<<< HEAD
# MeritCoin (MRT) - Sistema de Insignias Digitales Academicas

Sistema hibrido off-chain/on-chain que integra insignias digitales verificables
(ERC-1155) y tokens de recompensa (ERC-20) con la plataforma Moodle.

Desarrollado como proyecto academico en la Universidad Tecnologica de Bolivar.

## Arquitectura

```
+------------------+      HMAC/POST       +------------------+
|                  |  ----------------->  |                  |
|   Moodle (LMS)   |                      |  FastAPI Backend  |
|   + Plugin PHP    |  <-----------------  |   (off-chain)    |
|                  |      JSON Response   |                  |
+------------------+                      +--------+---------+
                                                   |
                                    +--------------+---------------+
                                    v              v               v
                             +-----------+  +-----------+  +------------+
                             | PostgreSQL |  |   IPFS    |  | Blockchain |
                             |  (audit)   |  | (simulado)|  | (Hardhat)  |
                             +-----------+  +-----------+  +------------+
                                                                |
                                                     +----------+----------+
                                                     v                     v
                                              +-------------+      +-------------+
                                              | ERC-1155     |      | ERC-20      |
                                              | MeritBadges  |      | MeritCoin   |
                                              +-------------+      +-------------+
```

## Flujo de funcionamiento

1. Un estudiante completa un curso o recibe una calificacion en Moodle
2. El **plugin** captura el evento y lo encola en `local_meritcoin_queue`
3. Una tarea programada envia el evento al **backend FastAPI** con firma HMAC-SHA256
4. El backend genera metadatos **Open Badges v2 (OBv2)** y simula pin en IPFS
5. El backend llama a los contratos: **mintBadge** (ERC-1155) y **mint** MRT (ERC-20)
6. Todo queda registrado en **PostgreSQL** (audit_log) para trazabilidad

## Estructura del repositorio

```
meritcoin/
├── contracts/             # Solidity + Hardhat (ERC-1155 y ERC-20)
│   ├── contracts/         # MeritBadges1155.sol, MeritCoinERC20.sol
│   ├── test/              # 19 tests con Hardhat + Chai
│   └── scripts/deploy.js  # Script de despliegue
├── backend/               # FastAPI (procesamiento off-chain)
│   ├── app/
│   │   ├── api/           # Endpoints: events, students
│   │   ├── core/          # Config, DB, HMAC security
│   │   ├── models/        # Pydantic + SQLAlchemy
│   │   ├── services/      # Blockchain, badges, tokens, audit
│   │   └── main.py        # App FastAPI
│   └── tests/             # 23 tests con pytest
├── plugin/                # Plugin Moodle local_meritcoin (PHP)
│   ├── classes/           # Observer, API client, scheduled task
│   ├── db/                # Tabla, eventos, tareas, permisos
│   ├── lang/              # Strings en/es
│   └── settings.php       # Configuracion admin
├── scripts/               # Scripts de prueba E2E
│   ├── test_e2e.py        # 8 pruebas automaticas
│   ├── test_curl.py       # Generador de comandos curl
│   └── GUIA_FASE5.md      # Guia paso a paso
├── docker-compose.yml     # Moodle + MariaDB + PostgreSQL
├── .env.example           # Variables de entorno
└── README.md              # Este archivo
```

## Requisitos

- **Docker** y **Docker Compose** v2+
- **Node.js** 18+ y **npm**
- **Python** 3.11+
- **Git**

## Inicio rapido

### 1. Clonar y configurar

```bash
git clone <url-del-repo>
cd meritcoin
cp .env.example .env
```

### 2. Levantar servicios con Docker

```bash
docker compose up -d
```

Primera vez tarda ~3 minutos (instalacion de Moodle). Verificar:
- Moodle: http://localhost:8080 (admin / Admin1234!)
- PostgreSQL: puerto 5432

### 3. Instalar y probar contratos

```bash
cd contracts
npm install
npx hardhat test          # 19/19 tests
```

### 4. Levantar nodo Hardhat y desplegar

**Terminal 1:**
```bash
cd contracts
npx hardhat node
```

**Terminal 2:**
```bash
cd contracts
npx hardhat run scripts/deploy.js --network localhost
```

Copiar las direcciones de los contratos desplegados.

### 5. Configurar y levantar backend

Crear `backend/.env`:
```env
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@localhost:5432/meritcoin_db
HMAC_SECRET=cambia-este-secreto-en-produccion
BLOCKCHAIN_RPC_URL=http://127.0.0.1:8545
DEPLOYER_PRIVATE_KEY=0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
BADGE_CONTRACT_ADDRESS=<direccion del paso 4>
MRT_CONTRACT_ADDRESS=<direccion del paso 4>
DEBUG=true
```

```bash
cd backend
pip install -r requirements.txt
python -m pytest tests/ -v    # 23/23 tests
python -m uvicorn app.main:app --reload --port 8000
```

### 6. Configurar plugin en Moodle

1. Moodle detecta el plugin automaticamente al reiniciar
2. Ir a: Administracion del sitio > Plugins > Plugins locales > MeritCoin
3. Habilitar y configurar:
   - URL Backend: `http://host.docker.internal:8000`
   - Secreto HMAC: `cambia-este-secreto-en-produccion`
   - Campo wallet: `wallet`
4. Crear campo de perfil "wallet" (tipo texto, nombre corto: `wallet`)

### 7. Ejecutar test E2E

```bash
cd meritcoin
python scripts/test_e2e.py    # 8/8 pruebas
```

## API del backend

| Metodo | Endpoint | Descripcion |
|--------|----------|-------------|
| GET | `/health` | Estado del servicio y conexion blockchain |
| POST | `/events/ingest` | Recibir evento academico (requiere HMAC) |
| GET | `/students/{wallet}/badges` | Listar insignias de un estudiante |
| GET | `/students/{wallet}/balance` | Consultar saldo MRT |

## Contratos inteligentes

| Contrato | Estandar | Descripcion |
|----------|----------|-------------|
| MeritBadges1155 | ERC-1155 | Insignias digitales con metadatos OBv2 |
| MeritCoinERC20 | ERC-20 | Token MRT de recompensa |

Ambos usan solo OpenZeppelin 5.x (sin librerias de pago).

## Recompensas MRT

| Evento | Tokens MRT |
|--------|-----------|
| Curso completado | 100 MRT |
| Calificacion >= 3.0 | 50 MRT |
| Calificacion < 3.0 | 0 MRT |

## Seguridad

- **HMAC-SHA256**: Toda comunicacion Moodle -> FastAPI esta firmada
- **Sin datos personales**: La blockchain solo almacena wallets y IDs ofuscados
- **Idempotencia**: Eventos duplicados son rechazados por event_id unico
- **Roles**: Contratos con ISSUER_ROLE y MINTER_ROLE (AccessControl)
- **Pausable**: Ambos contratos pueden pausarse en emergencia

## Stack tecnologico

| Componente | Tecnologia |
|------------|-----------|
| LMS | Moodle 4.3 (Docker) |
| Contratos | Solidity 0.8.28, OpenZeppelin 5.x, Hardhat 2.28 |
| Backend | FastAPI, SQLAlchemy async, web3.py, PostgreSQL 16 |
| Plugin | PHP (Moodle plugin API) |
| Base de datos | MariaDB (Moodle) + PostgreSQL (Backend) |
| Blockchain | Hardhat local node (desarrollo) |

## Tests

| Componente | Tests | Framework |
|------------|-------|-----------|
| Contratos | 19 | Hardhat + Chai |
| Backend | 23 | pytest + httpx |
| E2E | 8 | Python (stdlib) |
| **Total** | **50** | |

## Estado del proyecto

| Fase | Descripcion | Estado |
|------|-------------|--------|
| 1 | Entorno de desarrollo (Docker) | Completa |
| 2 | Contratos inteligentes (Solidity) | Completa |
| 3 | Backend FastAPI (Python) | Completa |
| 4 | Plugin de Moodle (PHP) | Completa |
| 5 | Prueba de flujo completo (E2E) | Completa |
| 6 | Documentacion final | Completa |

## Licencia

Proyecto academico - Universidad Tecnologica de Bolivar, 2026.
=======
# Meritcoin
>>>>>>> 84214f7b62dd8b58eaf58c9138017ad5e363f29c
