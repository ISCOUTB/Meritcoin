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
├── docker-compose.yml            # Moodle + MariaDB + PostgreSQL + Hardhat
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

### 3. Instalar dependencias de contratos

```bash
cd contracts
npm install
npx hardhat test          # 19/19 tests
```

### 4. Levantar nodo Hardhat y desplegar contratos

**Terminal 1** — deja esta terminal abierta todo el tiempo:
```bash
cd contracts
npx hardhat node
```

**Terminal 2:**
```bash
cd contracts
npx hardhat run scripts/deploy.js --network localhost
```

Verás algo así — copia ambas direcciones:
```
MeritCoin ERC20 deployed to:    0x8A791620dd6260079BF849Dc5567aDC3F2FdC318
MeritBadge ERC1155 deployed to: 0x2279B7A0a67DB372996a5FaB50D91eAA73d2eBe6
```

### 5. Configurar variables de entorno

Editar `backend/.env` con las direcciones del paso anterior:
```env
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@meritcoin-postgres:5432/meritcoin_db
HMAC_SECRET=cambia-este-secreto-en-produccion
BLOCKCHAIN_RPC_URL=http://host.docker.internal:8545
DEPLOYER_PRIVATE_KEY=0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
MRT_CONTRACT_ADDRESS=0x8A791620dd6260079BF849Dc5567aDC3F2FdC318
BADGE_CONTRACT_ADDRESS=0x2279B7A0a67DB372996a5FaB50D91eAA73d2eBe6
DEBUG=true
```

> ⚠️ El backend corre dentro de Docker. Usar `host.docker.internal` (no `127.0.0.1`) para apuntar al nodo Hardhat que corre en tu máquina local.

### 6. Recrear el backend con las nuevas variables

Un simple `restart` no toma los cambios del `.env`. Hay que recrear el contenedor:

```bash
docker compose up -d --force-recreate backend
```

Verificar que el backend está conectado al nodo Hardhat:
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

Deben aparecer como `running`: `meritcoin-moodle`, `meritcoin-mariadb`, `meritcoin-postgres`, `meritcoin-backend`.

Verificar que el nodo Hardhat está activo:
```bash
curl http://localhost:8545 -X POST -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
# Debe responder con: {"result":"0x8"} (o cualquier número de bloque)
```

Si el nodo no responde, levántalo en una terminal separada:
```bash
cd contracts
npx hardhat node
```

Y vuelve a deployar los contratos y actualizar `backend/.env` con las nuevas direcciones (ver pasos 4 y 5).

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
   # Debe listar: version.php, lib.php, dashboard.php, etc.
   ```
4. Entra a Moodle como administrador: http://localhost:8080 (admin / Admin1234!)
5. Ve a **Administración del sitio → Notificaciones** — Moodle detectará el plugin y pedirá actualizar la base de datos. Haz clic en **Continuar**.

### Paso 3 — Configurar el plugin

1. Ve a **Administración del sitio → Plugins → Plugins locales → MeritCoin**
2. Configura:
   - **URL Backend**: `http://meritcoin-backend:8000`
   - **Secreto HMAC**: el mismo valor de `HMAC_SECRET` en `backend/.env`
3. Guarda los cambios.

### Paso 4 — Crear el campo de wallet en el perfil de usuario

Este campo permite asignar una wallet de Hardhat a cada estudiante.

1. Ve a **Administración del sitio → Usuarios → Campos de perfil de usuario**
2. Crea un campo de tipo **Texto**:
   - Nombre corto: `wallet`
   - Nombre: `Wallet Ethereum`
3. Guarda.

### Paso 5 — Crear un estudiante de prueba con wallet real

Hardhat genera 20 wallets predeterminadas con 10,000 ETH cada una. Usa la primera:

```
Dirección:     0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266
Clave privada: 0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
```

1. Ve a **Administración del sitio → Usuarios → Agregar usuario**
2. Crea un usuario (ej. `estudiante1` / `Estudiante1234!`)
3. En el perfil del usuario, busca el campo **Wallet Ethereum** y pega la dirección anterior
4. Guarda.

> ⚠️ **Importante**: si usas una wallet inventada (ej. `0x1234...`), los tokens se mintearán
> a una dirección que no existe en el nodo Hardhat y el balance siempre será 0.

### Paso 6 — Crear un curso y configurar una regla de recompensa

1. Crea un curso en Moodle (ej. "Matemáticas") y matricula al estudiante de prueba
2. Agrega al menos una actividad con **finalización de actividad** habilitada
3. En el menú lateral del curso ve a **MeritCoin → Gestión de reglas**
4. Crea una regla:
   - Tipo: **Por calificación** o **Por completar actividad**
   - Actividad: selecciona la que creaste
   - Monedas: ej. `10`
5. Guarda la regla.

### Paso 7 — Generar un evento desde Moodle

1. Entra a Moodle como el estudiante de prueba
2. Completa la actividad del curso (entrega la tarea, completa el quiz, etc.)
3. Verifica que el evento fue encolado:
   ```bash
   docker exec meritcoin-mariadb mysql -u bn_moodle -pmoodle_pass bitnami_moodle \
     -e "SELECT userid, status, coins_amount FROM mdl_local_meritcoin_queue ORDER BY id DESC LIMIT 3;"
   ```

### Paso 8 — Procesar la cola (enviar al backend)

La tarea programada se ejecuta automáticamente cada minuto. Para forzarla manualmente:

```bash
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute=\\local_meritcoin\\task\\send_events_task
```

Verifica que el evento llegó al backend y se mintearon los tokens:
```bash
docker exec meritcoin-postgres psql -U meritcoin -d meritcoin_db \
  -c "SELECT student_wallet, coins_amount, processed_at FROM events ORDER BY processed_at DESC LIMIT 5;"
```

### Paso 9 — Verificar el balance en el dashboard

Desde la API:
```bash
curl -s http://localhost:8000/students/0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266/summary
# Debe mostrar mrt_balance > 0 y la insignia ganada
```

Desde Moodle: entra como el estudiante y ve a **MeritCoin → Mi Dashboard**.
Debe mostrar el balance real del contrato y el badge ganado.

### Paso 10 — Probar el mercado de recompensas

1. Como profesor/admin, ve al curso → **MeritCoin → Recompensas**
2. Crea una recompensa con un precio en MRT menor o igual al balance del estudiante
3. Entra como el estudiante al curso → **MeritCoin → Mercado**
4. El estudiante solo podrá canjear recompensas con las monedas ganadas **en ese mismo curso**

---

### Limpiar datos para repetir pruebas desde cero

**Limpiar BD del backend (PostgreSQL):**
```bash
docker exec meritcoin-postgres psql -U meritcoin -d meritcoin_db \
  -c "TRUNCATE TABLE audit_log, events RESTART IDENTITY CASCADE;"
```

**Limpiar cola y canjes en Moodle (MariaDB):**
```bash
docker exec meritcoin-mariadb mysql -u bn_moodle -pmoodle_pass bitnami_moodle -e \
  "DELETE FROM mdl_local_meritcoin_queue; DELETE FROM mdl_local_meritcoin_redemptions;"
```

**Reiniciar el nodo Hardhat (limpia el estado del contrato):**
```bash
# Ctrl+C en la terminal del nodo, luego:
cd contracts && npx hardhat node
# En otra terminal:
cd contracts && npx hardhat run scripts/deploy.js --network localhost
# Actualizar las nuevas direcciones en backend/.env y recrear el backend:
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
| 8 | Dashboard del estudiante + Mercado de recompensas | ✅ Completa |
| 9 | Insignias personalizadas (imagen, nombre y descripción configurables por curso) | 🔄 En progreso |
| 10 | Despliegue en SAVIO + ajuste visual al tema de la universidad | 📋 Pendiente |

---

## Licencia

Proyecto académico — Universidad Tecnológica de Bolívar, 2026.
