# MeritCoin — Sistema de Recompensas Académicas Digitales

Sistema híbrido off-chain/on-chain que integra tokens de recompensa (ERC-20)
e insignias digitales verificables (ERC-1155) con la plataforma Moodle.

Desarrollado como proyecto académico en la **Universidad Tecnológica de Bolívar**.

---

## Arquitectura

```
+------------------+      HMAC/POST       +------------------+
|                  |  ----------------->  |                  |
|   Moodle (LMS)   |                      |  FastAPI Backend  |
|  Plugin PHP v0.3 |  <-----------------  |   (off-chain)    |
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
                                              | ERC-1155    |      | ERC-20      |
                                              | MeritBadges |      | MeritCoin   |
                                              +-------------+      +-------------+
```

---

## Flujo de funcionamiento

1. Un estudiante completa una actividad o recibe una calificación en Moodle
2. El **observer** del plugin captura el evento y resuelve las monedas según la regla configurada por el profesor en `local_meritcoin_rules`
3. El evento se encola en `local_meritcoin_queue` (estado `pending` o `pending_wallet` si el estudiante aún no tiene wallet)
4. Una **tarea programada** envía el evento al backend FastAPI firmado con HMAC-SHA256
5. El backend genera metadatos **Open Badges v2 (OBv2)**, simula pin en IPFS y llama a los contratos: `mintBadge` (ERC-1155) y `mint` MRT (ERC-20)
6. El resultado queda registrado en `local_meritcoin_earnings` (ganancias por curso) y en PostgreSQL (audit_log) para trazabilidad completa

---

## Estructura del repositorio

```
meritcoin/
├── contracts/                    # Solidity + Hardhat (ERC-1155 y ERC-20)
│   ├── contracts/                # MeritBadges1155.sol, MeritCoinERC20.sol
│   ├── test/                     # 19 tests con Hardhat + Chai
│   └── scripts/deploy.js
├── backend/                      # FastAPI (procesamiento off-chain)
│   ├── app/
│   │   ├── api/                  # Endpoints: events, students
│   │   ├── core/                 # Config, DB, seguridad HMAC
│   │   ├── models/               # Pydantic + SQLAlchemy
│   │   ├── services/             # Blockchain, badges, tokens, audit
│   │   └── main.py
│   └── tests/                    # 23 tests con pytest
├── plugin/                       # Plugin Moodle local_meritcoin (PHP)
│   ├── classes/
│   │   ├── api_client.php        # Cliente HTTP hacia FastAPI
│   │   ├── observer.php          # Captura eventos Moodle
│   │   ├── rules_service.php     # Lógica de reglas y saldo por curso
│   │   ├── form/
│   │   │   └── rule_form.php     # Formulario Moodle para crear/editar reglas
│   │   └── task/
│   │       └── send_events_task.php  # Tarea programada de envío
│   ├── db/
│   │   ├── install.xml           # Schema completo (5 tablas)
│   │   ├── upgrade.php           # Migraciones hasta v0.3.0 (2026042801)
│   │   ├── access.php            # Capabilities: manage, viewqueue, manage_rules, view_report
│   │   ├── events.php            # Eventos escuchados
│   │   └── tasks.php             # Registro de tarea programada
│   ├── lang/
│   │   ├── en/local_meritcoin.php
│   │   └── es/local_meritcoin.php
│   ├── dashboard.php             # Dashboard del estudiante
│   ├── manage.php                # Gestión de reglas por curso (profesor)
│   ├── editrule.php              # Crear / editar una regla
│   ├── lib.php                   # Hooks de navegación global y de curso
│   ├── settings.php              # Configuración de administrador
│   └── version.php               # Versión del plugin (2026042801)
├── scripts/
│   ├── test_e2e.py               # 8 pruebas E2E automatizadas
│   ├── test_curl.py              # Generador de comandos curl
│   └── GUIA_FASE5.md
├── docker-compose.yml            # Moodle + MariaDB + PostgreSQL
├── .env.example
└── README.md
```

---

## Esquema de base de datos (v0.3.0)

| Tabla | Propósito |
|-------|-----------|
| `local_meritcoin_queue` | Cola de eventos pendientes de envío al backend |
| `local_meritcoin_rules` | Reglas de monedas por curso/actividad (configuradas por el profesor) |
| `local_meritcoin_earnings` | Ledger de monedas ganadas por curso (saldo disponible) |
| `local_meritcoin_spend` | Ledger de monedas gastadas en el mercado de recompensas |
| `local_meritcoin_course_config` | Configuración de moneda por curso (nombre, símbolo, contrato ERC-20) |

El saldo gastable de un estudiante en un curso se calcula como:
`earned (earnings) − spent (spend)`, de forma independiente por curso.

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

---

## Inicio rápido

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

Primera vez tarda ~3 minutos (instalación de Moodle).
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
npx hardhat node --hostname 0.0.0.0
```

**Terminal 2:**
```bash
cd contracts
npx hardhat run scripts/deploy.js --network localhost
# Copiar las direcciones de contratos mostradas
```

### 5. Configurar variables de entorno

Editar el `.env` en la raíz del proyecto:
```env
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@localhost:5432/meritcoin_db
HMAC_SECRET=cambia-este-secreto-en-produccion
BLOCKCHAIN_RPC_URL=http://host.docker.internal:8545
DEPLOYER_PRIVATE_KEY=0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
BADGE_CONTRACT_ADDRESS=<direccion del paso 4>
MRT_CONTRACT_ADDRESS=<direccion del paso 4>
DEBUG=true
```

> ⚠️ El backend corre dentro de Docker. Usar `host.docker.internal` (no `127.0.0.1`) para apuntar a servicios en tu máquina local como el nodo Hardhat.

### 6. Levantar el backend

```bash
docker compose up -d
# O si ya está corriendo, reiniciar para tomar el .env actualizado:
docker compose restart backend
```

Verificar que el backend esté sano:
```bash
curl http://localhost:8000/health
# Debe mostrar: "blockchain_connected": true
```

### 7. Configurar plugin en Moodle

1. Moodle detecta el plugin automáticamente al reiniciar
2. Ir a: **Administración del sitio → Plugins → Plugins locales → MeritCoin**
3. Configurar:
   - URL Backend: `http://host.docker.internal:8000`
   - Secreto HMAC: el mismo valor de `HMAC_SECRET`
   - Campo wallet: `wallet`
4. Crear campo de perfil de usuario (tipo texto, nombre corto: `wallet`)

### 8. Configurar reglas por curso

1. Ir a cualquier curso → menú lateral → **Gestión de reglas MeritCoin**
2. Crear una regla por actividad o para el curso completo
3. El observer usará esa regla para calcular automáticamente las monedas al capturar el evento

### 9. Ejecutar tests E2E

```bash
python scripts/test_e2e.py
# Resultado esperado: 8/8 pruebas pasaron
```

---

## API del backend

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/health` | Estado del servicio y conexión blockchain |
| POST | `/events/ingest` | Recibir evento académico (requiere HMAC) |
| GET | `/students/{wallet}/badges` | Listar insignias de un estudiante |
| GET | `/students/{wallet}/balance` | Consultar saldo MRT global |
| GET | `/students/{wallet}/summary` | Saldo MRT + badges (usado por el dashboard) |

---

## Contratos inteligentes

| Contrato | Estándar | Descripción |
|----------|----------|-------------|
| `MeritBadges1155` | ERC-1155 | Insignias digitales con metadatos OBv2 |
| `MeritCoinERC20` | ERC-20 | Token MRT de recompensa |

Ambos usan exclusivamente OpenZeppelin 5.x (sin librerías de pago).
Incluyen `AccessControl` (ISSUER_ROLE, MINTER_ROLE) y `Pausable` para emergencias.

---

## Reglas de recompensa

Las monedas se calculan en el plugin (no en el backend) a partir de las reglas
configuradas por el profesor en cada curso.

| Tipo de regla | Configuración | Comportamiento |
|---|---|---|
| **Por actividad** | `rule_scope = activity`, `cmid` específico | Se aplica solo al completar esa actividad |
| **Por curso** | `rule_scope = course`, `cmid = NULL` | Se aplica al completar el curso entero |

El valor de monedas es un monto fijo (`coins_amount`) definido por el profesor.
Las reglas se pueden habilitar o deshabilitar sin borrarlas.

---

## Seguridad

- **HMAC-SHA256**: toda comunicación Moodle → FastAPI está firmada
- **Sin datos personales en blockchain**: solo wallets e IDs ofuscados
- **Idempotencia**: eventos duplicados son rechazados por `event_id` único (índice en BD)
- **Roles Moodle**: capabilities por contexto de curso, no globales
- **Contratos**: `ISSUER_ROLE`, `MINTER_ROLE` y `Pausable`
- **sesskey**: todas las acciones de escritura en el plugin usan `require_sesskey()`

---

## Stack tecnológico

| Componente | Tecnología |
|------------|-----------|
| LMS | Moodle 4.3 (Docker) |
| Contratos | Solidity 0.8.28, OpenZeppelin 5.x, Hardhat 2.28 |
| Backend | FastAPI, SQLAlchemy async, web3.py, PostgreSQL 16 |
| Plugin | PHP 8.x (Moodle Plugin API) |
| Base de datos | MariaDB (Moodle) + PostgreSQL (Backend) |
| Blockchain | Hardhat local node (desarrollo) |

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

| Fase | Descripción | Estado |
|------|-------------|--------|
| 1 | Entorno de desarrollo (Docker) | ✅ Completa |
| 2 | Contratos inteligentes (Solidity) | ✅ Completa |
| 3 | Backend FastAPI (Python) | ✅ Completa |
| 4 | Plugin de Moodle — core (observer, task, queue) | ✅ Completa |
| 5 | Prueba de flujo completo (E2E) | ✅ Completa |
| 6 | Gestión de reglas por curso (manage.php, editrule.php, rules_service) | ✅ Completa |
| 7 | Ledger de ganancias y gasto por curso (earnings, spend) | ✅ Completa |
| 8 | Dashboard del estudiante por curso | 🔄 En progreso |
| 9 | Mercado de recompensas (canje de monedas) | 📋 Pendiente |
| 10 | Despliegue en SAVIO + ajuste visual al tema de la universidad | 📋 Pendiente |

---

## Licencia

Proyecto académico — Universidad Tecnológica de Bolívar, 2026.
