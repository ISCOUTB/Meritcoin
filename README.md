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
│   ├── alembic/                  # Migraciones de base de datos    
│   ├── tests/                    # 23 tests con pytest
│   ├── requirements.txt
│   ├── pytest.ini
│   └── Dockerfile
├── besu/                         # Red privada Hyperledger Besu (QBFT, 4 nodos)
│   └── QBFT-Network/
│       ├── docker-compose.yml    # 4 nodos Besu en red QBFT
│       ├── genesis.json          # Bloque génesis de la red EVM privada
│       ├── qbftConfigFile.json   # Configuración del consenso QBFT
│       └── networkFiles/         # Claves y datos de cada nodo
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
│   ├── styles/
│   │   └── dashboard.css             # Estilos del dashboard del estudiante
│   ├── admin_marketplace.php         # Panel global del admin: todos los canjes
│   ├── admin_pilot_courses.php       # Panel admin: gestión de cursos piloto
│   ├── award_badge.php               # Interfaz para otorgar insignias manualmente a estudiantes
│   ├── badge_award.php               # Vista de insignias otorgadas en un curso
│   ├── badge_pdf.php                 # Generación de certificado PDF de una insignia
│   ├── badge_templates.php           # Gestión de plantillas de insignias por curso
│   ├── badge_types.php               # Gestión de tipos/categorías de insignias
│   ├── badge_verify.php              # Verificación pública de insignias (Open Badges v2)
│   ├── dashboard.php                 # Dashboard del estudiante (saldo MRT + insignias)
│   ├── edit_badge_template.php       # Crear/editar plantilla de insignia
│   ├── editrule.php                  # Crear / editar una regla de recompensa
│   ├── lib.php                       # Funciones auxiliares y hooks de navegación
│   ├── manage.php                    # Gestión de reglas por curso (profesor)
│   ├── marketplace.php               # Mercado de recompensas (estudiante)
│   ├── rewards.php                   # Gestión de recompensas del curso (profesor)
│   ├── settings.php                  # Página de configuración admin
│   ├── tasks.php                     # Registro auxiliar de tareas (raíz del plugin)
│   ├── teacher_transactions.php      # Informe del profesor: transacciones por curso
│   └── version.php                   # Metadatos del plugin
├── scripts/
│   ├── test_e2e.py               # 8 pruebas E2E automatizadas
│   ├── test_curl.py              # Generador de comandos curl
│   └── GUIA_FASE5.md
├── docker-compose.yml            # Moodle + MariaDB + PostgreSQL + Backend
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
- **Java** 21+ (requerido por Hyperledger Besu si se corre fuera de Docker)

---

## Inicio rápido

### 0. Levantar la red Besu (QBFT — 4 nodos)

La blockchain privada corre **de forma independiente** al docker-compose principal.
Debe estar activa antes de desplegar los contratos y de arrancar el backend.

```bash
cd besu/QBFT-Network
docker compose up -d
```

Esto levanta 4 nodos Hyperledger Besu con consenso QBFT:

| Nodo | RPC HTTP | P2P |
|------|----------|-----|
| besu-node-1 | http://localhost:8545 | 30303 |
| besu-node-2 | http://localhost:8546 | 30304 |
| besu-node-3 | http://localhost:8547 | 30305 |
| besu-node-4 | http://localhost:8548 | 30306 |

El nodo **1** es el bootnode y el punto de entrada principal de la red.
Los nodos 2, 3 y 4 se conectan automáticamente usando el enode del nodo 1.

Verifica que la red está produciendo bloques:

```bash
curl -s http://localhost:8545 -X POST -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
# Debe devolver un número de bloque en hex, p.ej. "0x5"
```

> **⚠️ Importante:** Para que el backend conecte con Besu desde dentro de Docker Compose,
> la variable `BLOCKCHAIN_RPC_URL` debe apuntar a `http://host.docker.internal:8545`
> (ya configurado así en `.env.example`).

---

### 1. Clonar y configurar variables de entorno

```bash
git clone <url-del-repo>
cd meritcoin
cp .env.example .env
```

Edita `.env` y ajusta al menos estos valores antes de continuar:

```env
# Genera la clave Fernet ejecutando:
# python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
WALLET_ENCRYPTION_KEY=tu-clave-fernet-aqui   # ⚠️ OBLIGATORIO — el backend no arranca sin esto

HMAC_SECRET=cambia-este-secreto-en-produccion
DEPLOYER_PRIVATE_KEY=0xYOUR_PRIVATE_KEY_HERE  # Clave privada de la cuenta deployer en Besu
```

> `WALLET_ENCRYPTION_KEY` cifra las wallets custodiales de los estudiantes.
> Sin esta clave el backend se niega a iniciar.

---

### 2. Levantar servicios principales con Docker

```bash
docker compose up -d
```

Primera vez tarda ~3-5 minutos (instalación automática de Moodle).

| Servicio | URL / Puerto |
|----------|-------------|
| Moodle | http://localhost:8080 (admin / Admin1234!) |
| Backend FastAPI | http://localhost:8000 |
| PostgreSQL | localhost:5432 |

---

### 3. Instalar dependencias y probar los contratos

```bash
cd contracts
npm install
npx hardhat test          # 19/19 tests deben pasar
```

---

### 4. Desplegar contratos en la red Besu

Asegúrate de que los 4 nodos Besu están corriendo (paso 0) antes de continuar.

```bash
cd contracts
npx hardhat run scripts/deploy.js --network besu
```

Verás una salida similar a:

```
MeritCoin ERC20 deployed to:    0xABC123...
MeritBadge ERC1155 deployed to: 0xDEF456...
```

**Copia ambas direcciones** — las necesitarás en el siguiente paso.

---

### 5. Configurar el backend con las direcciones de los contratos

Crea o edita `backend/.env` con los valores del deploy:

```env
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@meritcoin-postgres:5432/meritcoin_db
HMAC_SECRET=cambia-este-secreto-en-produccion
BLOCKCHAIN_RPC_URL=http://host.docker.internal:8545
DEPLOYER_PRIVATE_KEY=<clave-privada-del-emisor>
MRT_CONTRACT_ADDRESS=0xABC123...       # dirección del ERC-20
BADGE_CONTRACT_ADDRESS=0xDEF456...     # dirección del ERC-1155
WALLET_ENCRYPTION_KEY=tu-clave-fernet-aqui
DEBUG=true
```

> **Nota sobre RPC URL:** El backend corre dentro de Docker Compose. Para acceder a los
> nodos Besu que corren en su propio `docker-compose` independiente, se usa
> `host.docker.internal:8545`. En Linux puede ser necesario agregar
> `--add-host=host.docker.internal:host-gateway` al servicio del backend.

---

### 6. Recrear el backend con las nuevas variables

Un simple `restart` no recarga el `.env`. Hay que recrear el contenedor:

```bash
docker compose up -d --force-recreate backend
```

Verifica que el backend está activo y conectado a Besu:

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
# Servicios principales
docker compose ps

# Nodos Besu (desde su propio directorio)
cd besu/QBFT-Network && docker compose ps && cd ../..
```

Deben aparecer como `running`:
- Stack principal: `meritcoin-moodle`, `meritcoin-mariadb`, `meritcoin-postgres`, `meritcoin-backend`
- Stack Besu: `besu-node-1`, `besu-node-2`, `besu-node-3`, `besu-node-4`

Verifica que la red Besu está produciendo bloques:

```bash
curl -s http://localhost:8545 -X POST -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
```

Si algún nodo Besu no responde:

```bash
cd besu/QBFT-Network
docker compose up -d
```

### Paso 2 — Instalar el plugin en Moodle

1. Abre `docker-compose.yml` (el principal, en la raíz) y **descomenta** esta línea bajo el servicio `moodle`:
   ```yaml
   - ./plugin:/bitnami/moodle/local/meritcoin
   ```
   > ⚠️ Si es la primera vez que levantas Moodle, primero deja que complete la instalación inicial **sin** esta línea montada, y solo entonces descoméntala.

2. Recrea el contenedor de Moodle:
   ```bash
   docker compose up -d --force-recreate moodle
   ```

3. Verifica que el plugin está montado correctamente:
   ```bash
   docker exec meritcoin-moodle ls /bitnami/moodle/local/meritcoin
   ```

4. Entra a Moodle como administrador: http://localhost:8080 (admin / Admin1234!)

5. Ve a **Administración del sitio → Notificaciones** y completa la instalación/actualización del plugin.

### Paso 3 — Configurar el plugin

1. Ve a **Administración del sitio → Plugins → Plugins locales → MeritCoin**
2. Configura los siguientes campos:
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

   Puedes obtener las cuentas preconfiguradas en el génesis consultando el nodo:
   ```bash
   curl -s http://localhost:8545 -X POST -H "Content-Type: application/json" \
     --data '{"jsonrpc":"2.0","method":"eth_accounts","params":[],"id":1}'
   ```

3. Asegúrate de que esa wallet tiene saldo en la red (debe aparecer en `eth_accounts` o tener ETH preacuñado en el génesis).

> Si usas una wallet inválida o fuera de la red configurada, el mint de tokens fallará y el evento quedará en estado de error en el backend.

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
   docker exec meritcoin-mariadb mysql -u bn_moodle -pmoodle_pass bitnami_moodle \
     -e "SELECT userid, status, coins_amount FROM mdl_local_meritcoin_queue ORDER BY id DESC LIMIT 3;"
   ```

### Paso 8 — Procesar la cola (enviar al backend)

La tarea programada se ejecuta automáticamente cada minuto. Para forzarla manualmente:

```bash
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute=\local_meritcoin\task\send_events_task
```

Verifica que el evento llegó al backend y fue procesado:

```bash
docker exec meritcoin-postgres psql -U meritcoin -d meritcoin_db \
  -c "SELECT event_id, student_wallet, coins_amount, processed_at FROM events ORDER BY processed_at DESC LIMIT 5;"
```

### Paso 9 — Verificar el balance en el dashboard

Desde la API:

```bash
curl -s http://localhost:8000/students/<WALLET>/summary
```

Desde Moodle: entra como el estudiante y ve a **MeritCoin → Mi Dashboard**.
Debe mostrar el balance real del contrato ERC-20 y la insignia ERC-1155 ganada.

### Paso 10 — Probar el mercado de recompensas

1. Como profesor/admin, ve al curso → **MeritCoin → Recompensas**.
2. Crea una recompensa con un precio en MRT menor o igual al saldo del estudiante.
3. Entra como el estudiante al curso → **MeritCoin → Mercado**.
4. El estudiante solo podrá canjear recompensas con las monedas ganadas **en ese mismo curso**.

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

**Re-desplegar contratos y recrear backend:**

```bash
cd contracts
npx hardhat run scripts/deploy.js --network besu
# Actualizar las nuevas direcciones en backend/.env
cd ..
docker compose up -d --force-recreate backend
```

**Reiniciar la red Besu desde cero (⚠️ elimina todos los bloques y estado):**

```bash
cd besu/QBFT-Network
docker compose down -v
docker compose up -d
```

> Después de reiniciar Besu debes re-desplegar los contratos, ya que las direcciones anteriores dejarán de existir.

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
- **Wallets custodiales cifradas**: las claves privadas de los estudiantes se cifran con Fernet (AES-128-CBC) usando `WALLET_ENCRYPTION_KEY`

---

## Stack tecnológico

| Componente | Tecnología |
|------------|-----------|
| LMS | Moodle 4.3 (Docker, imagen bitnamilegacy) |
| Contratos | Solidity 0.8.28, OpenZeppelin 5.x, Hardhat 2.28 |
| Backend | FastAPI, SQLAlchemy async, web3.py, PostgreSQL 16 |
| Plugin | PHP 8.x (Moodle Plugin API) |
| Base de datos | MariaDB 10.11 (Moodle) + PostgreSQL 16 (Backend) |
| Blockchain | Hyperledger Besu (red privada QBFT, 4 nodos) |

---

## Estado de las pruebas

| Componente            | Tests | Framework           | Estado        |
|-----------------------|-------|---------------------|---------------|
| Contratos Solidity    | 19    | Hardhat + Chai      | ✅ Estables   |
| Backend FastAPI       | 24    | pytest + httpx      | ✅ Estables   |
| E2E flujo completo    | 18     | Python (stdlib)     | ✅ Estables   |
| **Total**             | **61**|                     |               |

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
| 10 | Integración Hyperledger Besu (red privada QBFT, 4 nodos) | ✅ Completa |
| 11 | Finalizacion del MVP + ajuste visual al tema de la universidad | ✅ Completa |

---

## Mejoras futuras y escalamiento en SAVIO

Aunque MeritCoin ya funciona como un MVP desplegado en Docker para entornos de prueba, la visión del proyecto es integrarse de forma nativa con **SAVIO**, la plataforma institucional de la Universidad Tecnológica de Bolívar. Esta integración implicará endurecer la seguridad, mejorar la observabilidad y optimizar el rendimiento para entornos de alta concurrencia.

En una siguiente fase, cuando el piloto se valide y el plugin deje de ser solo un MVP, se contemplan las siguientes líneas de trabajo:

- **Despliegue productivo en SAVIO**: empaquetar el plugin como release estable, seguir el ciclo de QA de la universidad y coordinar la instalación en la instancia oficial de Moodle/SAVIO.
- **Hardening de seguridad**: rotación de claves, gestión centralizada de secretos, monitoreo de eventos anómalos y revisión periódica de permisos y roles en Moodle y en los contratos.
- **Escalabilidad de la infraestructura**: separación de ambientes (dev/stage/prod), uso de orquestadores (p.ej. Kubernetes) en lugar de un único docker-compose y afinamiento de recursos para soportar múltiples cursos y semestres concurrentes.
- **Observabilidad y monitoreo**: paneles de métricas (Prometheus/Grafana o equivalente), logging estructurado y alertas sobre fallos en cola de eventos, backend o red Besu.
- **Mejoras de UX para estudiantes y profesores**: refinamiento del dashboard MeritCoin, reportes más detallados por curso y soporte a nuevos tipos de reglas y recompensas.
- **Extensión de la capa on-chain**: posibilidad de interoperar con otras redes EVM de prueba o sidechains institucionales, manteniendo siempre la privacidad de los datos académicos.

---

## Licencia

Proyecto académico — Universidad Tecnológica de Bolívar, 2026.
