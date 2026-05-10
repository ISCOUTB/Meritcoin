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
|  Plugin PHP v0.5 |  <-----------------  |   (off-chain)    |
|                  |      JSON Response   |                  |
+------------------+                      +--------+---------+
                                                   |
                                    +--------------+---------------+
                                    v              v               v
                             +-----------+  +-----------+  +------------+
                             | PostgreSQL |  |   IPFS    |  | Blockchain |
                             |  (audit)   |  | (simulado)|  |  (Besu)    |
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
3. Se verifica que el estudiante no haya superado el **límite de MRT por curso** (configurable, por defecto 16) — si lo supera, el evento es descartado
4. El evento se encola en `local_meritcoin_queue` (estado `pending` o `pending_wallet` si el estudiante aún no tiene wallet)
5. Una **tarea programada** envía el evento al backend FastAPI firmado con HMAC-SHA256
6. El backend genera metadatos **Open Badges v2 (OBv2)**, simula pin en IPFS y llama a los contratos: `mintBadge` (ERC-1155) y `mint` MRT (ERC-20) en **Besu**
7. El resultado queda registrado en `local_meritcoin_earnings` (ganancias por curso) y en PostgreSQL (audit_log) para trazabilidad completa

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
│   │   ├── api/                  # Endpoints: events, students, tokens, badges
│   │   ├── core/                 # Config, DB, seguridad HMAC
│   │   ├── models/               # Pydantic + SQLAlchemy
│   │   ├── services/             # Blockchain, badges, tokens, audit
│   │   └── main.py
│   └── tests/                    # 23 tests con pytest
├── besu/                         # Configuración de red privada Hyperledger Besu
│   ├── genesis.json              # Génesis de la red EVM privada
│   └── config/                   # Configuración de nodos Besu
├── plugin/                       # Plugin Moodle local_meritcoin (PHP)
│   ├── classes/
│   │   ├── api_client.php        # Cliente HTTP hacia FastAPI
│   │   ├── observer.php          # Captura eventos Moodle + límite MRT por estudiante
│   │   ├── rules_service.php     # Lógica de reglas y saldo por curso
│   │   ├── form/
│   │   │   └── rule_form.php     # Formulario Moodle para crear/editar reglas
│   │   └── task/
│   │       └── send_events_task.php  # Tarea programada de envío
│   ├── db/
│   │   ├── install.xml           # Schema completo (7 tablas)
│   │   ├── upgrade.php           # Migraciones hasta v0.5.0
│   │   ├── access.php            # Capabilities: manage, viewqueue, manage_rules, view_report
│   │   ├── events.php            # Eventos escuchados
│   │   └── tasks.php             # Registro de tarea programada
│   ├── lang/
│   │   ├── en/local_meritcoin.php
│   │   └── es/local_meritcoin.php
│   ├── dashboard.php             # Dashboard del estudiante
│   ├── manage.php                # Gestión de reglas por curso (profesor)
│   ├── editrule.php              # Crear / editar una regla
│   ├── rewards.php               # Gestión de recompensas del curso (profesor)
│   ├── marketplace.php           # Mercado de recompensas (estudiante)
│   ├── teacher_transactions.php  # Informe del profesor por curso
│   ├── admin_marketplace.php     # Panel global del administrador
│   ├── lib.php                   # Hooks de navegación global y de curso
│   ├── settings.php              # Configuración de administrador
│   └── version.php               # Versión del plugin (2026050904)
├── scripts/
│   ├── test_e2e.py               # 8 pruebas E2E automatizadas
│   ├── test_curl.py              # Generador de comandos curl
│   └── GUIA_FASE5.md
├── docker-compose.yml            # Moodle + MariaDB + PostgreSQL + Besu
├── .env.example
└── README.md
```

---

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
- **Java** 21+ (requerido por Hyperledger Besu)

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

### 3. Instalar dependencias de contratos

```bash
cd contracts
npm install
npx hardhat test          # 19/19 tests
```

### 4. Desplegar contratos en Besu

Asegúrate de que el servicio Besu esté levantado:

```bash
docker compose up -d besu
```

Compila, prueba y despliega los contratos:

```bash
cd contracts
npm install
npx hardhat test          # 19/19 tests
npx hardhat run scripts/deploy.js --network besu
# Copiar las direcciones de contratos mostradas
```

Verás algo así — copia ambas direcciones:
```
MeritCoin ERC20 deployed to:    0x...
MeritBadge ERC1155 deployed to: 0x...
```

### 5. Configurar variables de entorno

Editar `backend/.env` con las direcciones del paso anterior:
```env
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@meritcoin-postgres:5432/meritcoin_db
HMAC_SECRET=cambia-este-secreto-en-produccion
BLOCKCHAIN_RPC_URL=http://meritcoin-besu:8545
DEPLOYER_PRIVATE_KEY=<clave-privada-del-emisor>
MRT_CONTRACT_ADDRESS=0x...
BADGE_CONTRACT_ADDRESS=0x...
DEBUG=true
```

> El backend corre dentro de Docker. Cuando Besu también corre dentro de Docker Compose, se recomienda usar el nombre del servicio `meritcoin-besu` como host del RPC.

### 6. Recrear el backend con las nuevas variables

Un simple `restart` no toma los cambios del `.env`. Hay que recrear el contenedor:

```bash
docker compose up -d --force-recreate backend
```

Verificar que el backend está conectado al nodo Besu:
```bash
curl http://localhost:8000/health
# Debe mostrar: "blockchain_connected": true
```

---

## Tutorial de pruebas completo (end-to-end desde Moodle)

Este tutorial explica cómo probar el flujo completo del sistema desde Moodle hasta
ver los tokens reflejados en el dashboard y el mercado de recompensas.

### Paso 1 — Verificar que todos los servicios están corriendo

```bash
docker compose ps
```

Deben aparecer como `running`: `meritcoin-moodle`, `meritcoin-mariadb`, `meritcoin-postgres`, `meritcoin-backend` y `meritcoin-besu`.

Verificar que el nodo Besu está activo:
```bash
curl http://localhost:8545 -X POST -H "Content-Type: application/json"   --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
```

Si Besu no responde:
```bash
docker compose up -d besu
```

### Paso 2 — Instalar el plugin en Moodle

1. Asegúrate de que esta línea en `docker-compose.yml` **no tenga `#`**:
   ```yaml
   - ./plugin:/bitnami/moodle/local/meritcoin
   ```
2. Recrea el contenedor de Moodle:
   ```bash
   docker compose up -d --force-recreate moodle
   ```
3. Verifica que el plugin está montado:
   ```bash
   docker exec meritcoin-moodle ls /bitnami/moodle/local/meritcoin
   ```
4. Entra a Moodle como administrador: http://localhost:8080 (admin / Admin1234!)
5. Ve a **Administración del sitio → Notificaciones** y completa la instalación/actualización del plugin.

### Paso 3 — Configurar el plugin

1. Ve a **Administración del sitio → Plugins → Plugins locales → MeritCoin**
2. Configura:
   - **URL Backend**: `http://meritcoin-backend:8000`
   - **Secreto HMAC**: el mismo valor de `HMAC_SECRET` en `backend/.env`
   - **Student MRT limit per course**: máximo de MRT que un estudiante puede recibir por curso (por defecto 16)
3. Guarda los cambios.

### Paso 4 — Crear el campo de wallet en el perfil de usuario

1. Ve a **Administración del sitio → Usuarios → Campos de perfil de usuario**
2. Crea un campo de tipo **Texto**:
   - Nombre corto: `wallet`
   - Nombre: `Wallet Ethereum`
3. Guarda.

### Paso 5 — Crear un estudiante de prueba con wallet válida de Besu

1. Crea un usuario de prueba en Moodle.
2. Asigna en el campo **Wallet Ethereum** una dirección válida de la red Besu que estés usando para pruebas.
3. Asegúrate de que esa wallet exista en tu red y pueda recibir tokens.

> Si usas una wallet inválida o fuera de la red configurada, los tokens se mintearán a una dirección que no podrás consultar correctamente desde tu entorno de pruebas.

### Paso 6 — Crear un curso y configurar una regla de recompensa

1. Crea un curso en Moodle y matricula al estudiante de prueba.
2. Agrega al menos una actividad con **finalización de actividad** habilitada.
3. En el menú lateral del curso ve a **MeritCoin → Gestión de reglas**.
4. Crea una regla:
   - Tipo: **Por actividad**, **por tipo de actividad** o **por curso**
   - Monedas: por ejemplo `5`
5. Guarda la regla.

### Paso 7 — Generar un evento desde Moodle

1. Entra a Moodle como el estudiante de prueba.
2. Completa la actividad del curso.
3. Verifica que el evento fue encolado:
   ```bash
   docker exec meritcoin-mariadb mysql -u bn_moodle -pmoodle_pass bitnami_moodle      -e "SELECT userid, status, coins_amount FROM mdl_local_meritcoin_queue ORDER BY id DESC LIMIT 3;"
   ```

### Paso 8 — Procesar la cola (enviar al backend)

La tarea programada se ejecuta automáticamente cada minuto. Para forzarla manualmente:

```bash
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/scheduled_task.php   --execute=\local_meritcoin\task\send_events_task
```

Verifica que el evento llegó al backend:
```bash
docker exec meritcoin-postgres psql -U meritcoin -d meritcoin_db   -c "SELECT event_id, student_wallet, coins_amount, processed_at FROM events ORDER BY processed_at DESC LIMIT 5;"
```

### Paso 9 — Verificar el balance en el dashboard

Desde la API:
```bash
curl -s http://localhost:8000/students/<WALLET>/summary
```

Desde Moodle: entra como el estudiante y ve a **MeritCoin → Mi Dashboard**.
Debe mostrar el balance real del contrato y la insignia ganada.

### Paso 10 — Probar el mercado de recompensas

1. Como profesor/admin, ve al curso → **MeritCoin → Recompensas**.
2. Crea una recompensa con un precio en MRT menor o igual al saldo del estudiante.
3. Entra como el estudiante al curso → **MeritCoin → Mercado**.
4. El estudiante solo podrá canjear recompensas con las monedas ganadas **en ese mismo curso**.

---

### Limpiar datos para repetir pruebas desde cero

**Limpiar BD del backend (PostgreSQL):**
```bash
docker exec meritcoin-postgres psql -U meritcoin -d meritcoin_db   -c "TRUNCATE TABLE audit_log, events RESTART IDENTITY CASCADE;"
```

**Limpiar cola y canjes en Moodle (MariaDB):**
```bash
docker exec meritcoin-mariadb mysql -u bn_moodle -pmoodle_pass bitnami_moodle -e   "DELETE FROM mdl_local_meritcoin_queue; DELETE FROM mdl_local_meritcoin_redemptions;"
```

**Re-desplegar contratos y recrear backend:**
```bash
cd contracts
npx hardhat run scripts/deploy.js --network besu
# Actualizar las nuevas direcciones en backend/.env
cd ..
docker compose up -d --force-recreate backend
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
| POST | `/tokens/spend` | Quemar MRT al canjear en el marketplace |

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

- **HMAC-SHA256**: toda comunicación Moodle → FastAPI está firmada
- **Sin datos personales en blockchain**: solo wallets e IDs ofuscados
- **Idempotencia**: eventos duplicados son rechazados por `event_id` único (MD5 determinístico de userid+cmid+grade)
- **Límite MRT por estudiante**: el observer descarta eventos que exceden el tope configurado por curso
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
| Blockchain | Hyperledger Besu (red privada EVM) |

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
| 8 | Dashboard del estudiante + Mercado de recompensas | ✅ Completa |
| 9 | Insignias personalizadas (imagen, nombre y descripción configurables por curso) | ✅ Completa |
| 10 | Integración Hyperledger Besu (red privada EVM) | ✅ Completa |
| 11 | Despliegue en SAVIO + ajuste visual al tema de la universidad | 🔄 En progreso |

---

## Licencia

Proyecto académico — Universidad Tecnológica de Bolívar, 2026.
