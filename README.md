# MeritCoin — Sistema de Recompensas Académicas Digitales

Sistema híbrido off-chain/on-chain que integra tokens de recompensa (ERC-20)
e insignias digitales verificables (ERC-1155) con la plataforma Moodle.
Desarrollado como proyecto académico en la **Universidad Tecnológica de Bolívar**.

---

## Arquitectura

```text
+---------------------------+
|     Dapp / Moodle Plugin  |  ← equivale al "Dapp/wallet"
|  (PHP, dashboard, market) |
+------------+--------------+
             | HMAC-SHA256 / POST
             v
+============================================+
|            Interface Layer                 |
|    FastAPI REST · /events · /students      |  ← JSON-RPC equivalente
|    /tokens · /wallets · /health            |
+========+===================+===============+
         |                   |
+--------+-------+  +--------+---------+
|   Execution    |  |    Off-chain DB  |  
|   Core         |  |                  |
|                |  | PostgreSQL       |
| Event ingest   |  |  audit_log       |
| Rules engine   |  |  events          |
| HMAC verifier  |  |                  |
| Badge builder  |  | MariaDB (Moodle) |
|   (OBv2 meta)  |  |  queue / rules   |
+--------+-------+  +------------------+
         |
+--------+--------+
|  IPFS (Kubo)    |  ← almacenamiento descentralizado de metadatos OBv2
|  nodo local     |
|  modo --offline |
+--------+--------+
         |
+========+==============================+
|        Besu Core  (red privada)       |
|                                       |
|  Networking       Execution-Core      |
|  ─────────        ─────────────       |
|  Discovery        Transaction pool    |
|  RLPx             Synchronizer        |
|  ETH sub-proto    Block validator     |
|  QBFT sub-proto   └─ Tx processor     |
|  4 nodos          EVM                 |
|  (bootnode+3)     Pluggable consensus |
|                   QBFT PoA            |
|                                       |
|  Storage                              |
|  ───────                              |
|  World state (Trie Bonsai)            |
|  Blockchain                           |
+========+==============================+
         |
+--------+--------------------+
|   Smart Contracts (Solidity)|
|                             |
|  ERC-20 MeritCoinERC20      |
|   - mint / burn             |
|   - MINTER_ROLE             |
|   - BURNER_ROLE             |
|                             |
|  ERC-1155 MeritBadges1155   |
|   - mintBadge               |
|   - ISSUER_ROLE             |
|   - metadata URI → IPFS     |
+-----------------------------+
```

---

## Flujo de funcionamiento

1. Un estudiante completa una actividad o recibe una calificación en Moodle.
2. El **observer** del plugin (`observer.php`) captura el evento Moodle (`core\event\*`) y consulta `local_meritcoin_rules` para calcular las monedas MRT según el tipo de actividad y la nota mínima configurada por el profesor.
3. Se verifica que el estudiante no haya superado el **límite de MRT por curso** consultando `local_meritcoin_earnings` — si lo supera, el evento es descartado silenciosamente.
4. **Detección de wallet:**
   - **Curso normal:** el observer lee el campo de perfil `wallet` del estudiante. Si no tiene wallet registrada, el evento es descartado.
   - **Curso piloto:** si el estudiante aún no tiene wallet custodial, el evento se encola con `status = pending_wallet` y `wallet_service` llama a `POST /wallets/provision` en el backend para crearla automáticamente. Una vez provisionada, el estado pasa a `pending`.
5. El evento se encola en `local_meritcoin_queue` con `status = pending`, incluyendo `event_id` (MD5 de `userid+cmid+grade`) para garantizar idempotencia.
6. La **tarea programada** `send_events_task` (cron cada minuto) toma los eventos `pending`, firma el payload con **HMAC-SHA256** usando `HMAC_SECRET` y lo envía a `POST /events/ingest` en el backend FastAPI.
7. El backend valida la firma HMAC, verifica que el `event_id` no haya sido procesado previamente y:
   - a. Genera los metadatos de la insignia en formato **Open Badges v2 (OBv2)**.
   - b. Sube los metadatos al nodo **IPFS local (Kubo)** y obtiene el CID (`ipfs://...`).
   - c. Llama al contrato ERC-1155 (`mintBadge`) con el URI del CID como metadata de la insignia.
   - d. Llama al contrato ERC-20 (`mint`) para acreditar los MRT en la wallet del estudiante.
   - e. Ambas transacciones se ejecutan en la red privada **Hyperledger Besu (QBFT)**.
8. El backend registra el resultado en **PostgreSQL** (`audit_log`, `events`) para trazabilidad completa.
9. El plugin actualiza el evento en `local_meritcoin_queue` a `status = sent` y registra las ganancias en `local_meritcoin_earnings`, manteniendo el ledger del límite por curso.
10. El estudiante consulta su saldo MRT e insignias en tiempo real desde **MeritCoin → Mi Dashboard**, que llama a `GET /students/{wallet}/summary` en el backend.

---

## Estructura del repositorio

```text
meritcoin/
    ├── contracts/ # Solidity + Hardhat (ERC-1155 y ERC-20)
    │ ├── contracts/ # MeritBadges1155.sol, MeritCoinERC20.sol
    │ ├── test/ # 19 tests con Hardhat + Chai
    │ ├── scripts/
    │ │ └── deploy.js # Despliega ambos contratos y asigna roles
    │ ├── hardhat.config.js
    │ └── package.json
    ├── backend/ # FastAPI (procesamiento off-chain)
    │ ├── app/
    │ │ ├── api/ # Endpoints: events, students, tokens, badges, wallets
    │ │ ├── core/ # Config, DB, seguridad HMAC
    │ │ ├── models/ # Pydantic + SQLAlchemy
    │ │ ├── services/ # Blockchain, badges, tokens, audit, IPFS, wallets
    │ │ └── main.py
    │ ├── alembic/ # Migraciones de base de datos
    │ ├── tests/ # 24 tests con pytest
    │ ├── requirements.txt
    │ ├── pytest.ini
    │ └── Dockerfile
    ├── besu/ # Red privada Hyperledger Besu (QBFT, 4 nodos)
    │ └── QBFT-Network/
    │ ├── docker-compose.yml
    │ ├── genesis.json # Bloque génesis de la red EVM privada
    │ ├── qbftConfigFile.json # Configuración del consenso QBFT
    │ └── networkFiles/ # Claves y datos de cada nodo
    ├── plugin/ # Plugin Moodle local_meritcoin (PHP)
    │ ├── classes/ # observer, api_client, rules_service, wallet_service, tasks
    │ ├── db/ # install.xml, upgrade.php, events.php, tasks.php, access.php
    │ ├── lang/ # Strings en inglés y español
    │ ├── styles/ # dashboard.css
    │ └── *.php # Vistas: dashboard, manage, marketplace, badges, etc.
    ├── scripts/
    │ ├── test_e2e.py # Pruebas E2E automatizadas
    │ └── test_curl.py # Generador de comandos curl de prueba
    ├── docker-compose.yml # Moodle + MariaDB + PostgreSQL + Backend + IPFS
    ├── .env.example
    └── README.md
```

---

## Requisitos previos

Instala estas herramientas antes de comenzar:

| Herramienta | Versión mínima | Notas |
|---|---|---|
| Docker + Docker Compose | v2+ | Incluye Compose v2 integrado |
| Node.js | 20+ | Solo para compilar y desplegar contratos |
| pnpm | 9+ | Gestor de paquetes para contratos (`npm install -g pnpm`) |
| Python | 3.11+ | Para tests del backend y scripts E2E |
| Git | cualquiera | — |

> **¿Por qué pnpm?** pnpm usa un almacén de paquetes compartido y enlaces duros,
> lo que lo hace más rápido, más eficiente en disco y con mejor aislamiento de
> dependencias que npm. Además evita los CVEs de supply-chain asociados al
> registro por defecto de npm.

---

## Inicio rápido (orden obligatorio)

Sigue los pasos **exactamente en este orden**. Si los mezclas, el backend
no encontrará los contratos o el plugin no conectará con el backend.

```text
Levantar red Besu
Clonar y configurar .env
Levantar servicios Docker principales
Desplegar contratos en Besu
Actualizar .env del backend con las direcciones
Recrear el backend
Instalar y configurar el plugin en Moodle
```
---

### Paso 0 — Levantar la red Besu (QBFT, 4 nodos)

La blockchain privada corre **de forma independiente** al docker-compose principal.
Debe estar activa antes de desplegar los contratos y de arrancar el backend.

```bash
cd besu/QBFT-Network
docker compose up -d
```

Esto levanta 4 nodos Hyperledger Besu con consenso QBFT:

| Nodo | RPC HTTP | P2P |
|---|---|---|
| `besu-node-1` | http://localhost:8545 | 30303 |
| `besu-node-2` | http://localhost:8546 | 30304 |
| `besu-node-3` | http://localhost:8547 | 30305 |
| `besu-node-4` | http://localhost:8548 | 30306 |

El nodo 1 es el bootnode y el punto de entrada principal de la red.
Los nodos 2, 3 y 4 se conectan automáticamente usando el enode del nodo 1.

Verifica que la red está produciendo bloques:

```bash
curl -s http://localhost:8545 \
  -X POST -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
# Respuesta esperada: {"result":"0x5",...} — el número de bloque sube con el tiempo
```

> Si el resultado es `"0x0"` espera 10 segundos y repite. La red tarda unos
> segundos en producir el primer bloque después del arranque.

Vuelve a la raíz del proyecto:

```bash
cd ../..
```

---

### Paso 1 — Clonar y configurar variables de entorno

```bash
git clone <url-del-repo>
cd meritcoin
cp .env.example .env
```

Edita `.env` y ajusta **obligatoriamente** estos tres valores:

```env
# Genera la clave Fernet ejecutando este comando:
# python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
WALLET_ENCRYPTION_KEY=tu-clave-fernet-aqui   # ⚠️ el backend no arranca sin esto

HMAC_SECRET=cambia-este-secreto              # cualquier string largo y aleatorio

DEPLOYER_PRIVATE_KEY=0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
# ↑ Esta es la clave de la cuenta #0 preconfigurada en el génesis de Besu.
#   Solo para desarrollo local. Nunca uses esta clave en producción.
```

> `WALLET_ENCRYPTION_KEY` cifra las claves privadas de las wallets custodiales
> de los estudiantes con Fernet (AES-128-CBC). Sin esta variable el backend
> se niega a iniciar.

---

### Paso 2 — Levantar servicios Docker principales

```bash
docker compose up -d
```

La primera vez tarda **3-5 minutos** porque Moodle realiza su instalación inicial.
Puedes monitorear el progreso:

```bash
docker compose logs -f moodle
# Espera hasta ver: "Welcome to the Bitnami moodle container"
```

Servicios disponibles una vez levantados:

| Servicio | URL | Credenciales |
|---|---|---|
| Moodle | http://localhost:8080 | admin / Admin1234! |
| Backend FastAPI | http://localhost:8000 | — |
| Docs API (Swagger) | http://localhost:8000/docs | — |
| PostgreSQL | localhost:5432 | meritcoin / meritcoin_pass |
| IPFS (Kubo) API | http://localhost:5001 | — |
| IPFS Gateway | http://localhost:8081 | — |

---

### Paso 3 — Instalar dependencias y desplegar contratos en Besu

```bash
cd contracts
pnpm install
```

Ejecuta los tests para verificar que todo compila correctamente:

```bash
pnpm exec hardhat test
# Resultado esperado: 19 passing
```

Despliega los contratos en la red Besu (el nodo 1 debe estar corriendo en el paso 0):

```bash
pnpm exec hardhat run scripts/deploy.js --network besu
```

Verás una salida similar a:

```text
Deploying contracts with: 0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266
MeritCoin ERC20 deployed to: 0xABC123...
MeritBadge ERC1155 deployed to: 0xDEF456...
MINTER_ROLE granted to deployer ✓
BURNER_ROLE granted to deployer ✓
ISSUER_ROLE granted to deployer ✓
```

**Copia las dos direcciones** — las necesitas en el siguiente paso.

Vuelve a la raíz:

```bash
cd ..
```

---

### Paso 4 — Actualizar el backend con las direcciones de los contratos

Abre `backend/.env` (o el `.env` raíz si usa el mismo archivo) y añade/reemplaza:

```env
MRT_CONTRACT_ADDRESS=0xABC123...       # dirección ERC-20 del paso anterior
BADGE_CONTRACT_ADDRESS=0xDEF456...     # dirección ERC-1155 del paso anterior
BLOCKCHAIN_RPC_URL=http://host.docker.internal:8545
```

> **¿Por qué `host.docker.internal`?** El backend corre dentro de Docker Compose
> y necesita acceder a los nodos Besu que corren en su propio compose independiente.
> `host.docker.internal` resuelve al host físico desde dentro del contenedor.
>
> **Linux:** si `host.docker.internal` no resuelve, agrega esta línea al servicio
> `backend` en `docker-compose.yml`:
> ```yaml
> extra_hosts:
>   - "host.docker.internal:host-gateway"
> ```

---

### Paso 5 — Recrear el backend con las nuevas variables

Un simple `restart` no recarga el `.env`. Hay que recrear el contenedor:

```bash
docker compose up -d --force-recreate backend
```

Verifica que el backend está activo y conectado a Besu:

```bash
curl http://localhost:8000/health
```

Respuesta esperada:

```json
{
  "status": "ok",
  "blockchain_connected": true,
  "ipfs_connected": true,
  "database": "ok"
}
```

Si `blockchain_connected` es `false`, revisa que los nodos Besu del paso 0
estén corriendo y que `BLOCKCHAIN_RPC_URL` apunte a `host.docker.internal:8545`.

---

### Paso 6 — Instalar el plugin en Moodle

#### 6.1 Montar el volumen del plugin

Abre `docker-compose.yml` y **descomenta** esta línea bajo el servicio `moodle`:

```yaml
volumes:
  - ./plugin:/bitnami/moodle/local/meritcoin   # ← descomenta esta línea
```

> ⚠️ Si es la primera vez que levantaste Moodle, asegúrate de que completó
> su instalación inicial **antes** de montar este volumen. Si lo montas desde
> el inicio, Moodle puede fallar al detectar el plugin en un estado de BD incompleto.

Recrea el contenedor de Moodle:

```bash
docker compose up -d --force-recreate moodle
```

Verifica que el plugin está montado:

```bash
docker exec meritcoin-moodle ls /bitnami/moodle/local/meritcoin
# Debe listar: classes  db  lang  styles  dashboard.php  lib.php  version.php ...
```

#### 6.2 Instalar el plugin desde el panel de Moodle

1. Entra a http://localhost:8080 como **admin** (contraseña: `Admin1234!`)
2. Moodle detecta el plugin automáticamente. Ve a:
   **Administración del sitio → Notificaciones**
3. Haz clic en **Actualizar base de datos de Moodle** y completa el proceso.

#### 6.3 Configurar el plugin

Ve a: **Administración del sitio → Plugins → Plugins locales → MeritCoin**

| Campo | Valor |
|---|---|
| Habilitado | ✓ Sí |
| URL del backend | `http://meritcoin-backend:8000` |
| Secreto HMAC | el mismo valor de `HMAC_SECRET` en `.env` |
| Campo wallet | `wallet` |
| Límite MRT por estudiante/curso | `16` (o `0` para sin límite) |

> Usa `http://meritcoin-backend:8000` (nombre del servicio Docker) cuando
> **ambos** contenedores (Moodle y backend) están en el mismo Docker Compose.
> Usa `http://host.docker.internal:8000` solo si corres el backend fuera de Docker.

#### 6.4 Crear el campo de perfil de wallet (para cursos no piloto)

1. **Administración del sitio → Usuarios → Campos de perfil de usuario**
2. Agrega un campo de tipo **Entrada de texto**:
   - Nombre corto: `wallet`
   - Nombre visible: `Wallet Ethereum`
3. Guarda.

---

## Tutorial completo de prueba E2E

Una vez que todos los servicios están activos, sigue este flujo para verificar
que el sistema funciona de extremo a extremo.

### 1 — Verificar el estado de todos los servicios

```bash
# Stack principal
docker compose ps

# Red Besu
cd besu/QBFT-Network && docker compose ps && cd ../..
```

Todos deben aparecer como `running`. Confirma que Besu produce bloques:

```bash
curl -s http://localhost:8545 \
  -X POST -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
```

### 2 — Crear un estudiante de prueba

1. En Moodle: **Administración del sitio → Usuarios → Agregar usuario**
2. En el campo **Wallet Ethereum** del perfil asigna una dirección válida de la red:

```bash
# Ver cuentas preconfiguradas en el génesis de Besu
curl -s http://localhost:8545 \
  -X POST -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","method":"eth_accounts","params":[],"id":1}'
```

Usa cualquiera de las direcciones retornadas. La cuenta #0
(`0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266`) tiene ETH preacuñado en el génesis
y es la cuenta deployer — úsala solo para pruebas.

### 3 — Crear curso y configurar regla de recompensa

1. Crea un curso en Moodle y matricula al estudiante de prueba.
2. Agrega una actividad (tarea, quiz, foro, etc.).
3. En el menú lateral del curso ve a **MeritCoin → Gestión de reglas**.
4. Crea una regla:
   - Tipo de regla: **Por tipo de actividad** (por ej. `assign`)
   - Monedas: `5`
   - Nota mínima: `6.0` (opcional)
5. Guarda la regla.

### 4 — Generar el evento de calificación

1. Entra a Moodle como el **estudiante de prueba**.
2. Completa la actividad configurada.
3. Como profesor/admin, califica la actividad con una nota ≥ a `min_grade`.

Verifica que el evento fue encolado en Moodle:

```bash
docker exec meritcoin-mariadb \
  mysql -u bn_moodle -pmoodle_pass bitnami_moodle \
  -e "SELECT userid, event_type, coins_amount, status, attempts \
      FROM mdl_local_meritcoin_queue ORDER BY id DESC LIMIT 5;"
```

Debe aparecer un registro con `status = pending`.

### 5 — Procesar la cola

La tarea se ejecuta automáticamente cada minuto. Para forzarla de inmediato:

```bash
docker exec meritcoin-moodle \
  php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute='\local_meritcoin\task\send_events_task'
```

> **Git Bash en Windows:** usa doble barra al inicio:
> `php //bitnami/moodle/admin/cli/scheduled_task.php`

Verifica que el evento fue procesado por el backend:

```bash
docker exec meritcoin-postgres \
  psql -U meritcoin -d meritcoin_db \
  -c "SELECT event_id, student_wallet, coins_amount, processed_at \
      FROM events ORDER BY processed_at DESC LIMIT 5;"
```

Y que la cola de Moodle lo marcó como enviado:

```bash
docker exec meritcoin-mariadb \
  mysql -u bn_moodle -pmoodle_pass bitnami_moodle \
  -e "SELECT status, attempts FROM mdl_local_meritcoin_queue ORDER BY id DESC LIMIT 3;"
# status debe ser: sent
```

### 6 — Verificar saldo y badges del estudiante

Desde la API del backend:

```bash
# Reemplaza <WALLET> con la dirección del estudiante
curl -s http://localhost:8000/students/<WALLET>/summary | python -m json.tool
```

Desde Moodle: entra como el estudiante y ve a **MeritCoin → Mi Dashboard**.
Debe mostrar el saldo MRT real del contrato ERC-20 y las insignias ERC-1155 ganadas.

### 7 — Probar el marketplace

1. Como profesor/admin, ve al curso → **MeritCoin → Recompensas**.
2. Crea una recompensa con precio en MRT ≤ saldo actual del estudiante.
3. Entra como estudiante → **MeritCoin → Mercado**.
4. Canjea la recompensa. El plugin procesará el canje automáticamente
   vía `process_redemptions_task` (cada minuto) o manualmente:

```bash
docker exec meritcoin-moodle \
  php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute='\local_meritcoin\task\process_redemptions_task'
```

---

## Cursos piloto (wallets custodiales automáticas)

Los cursos piloto eliminan el requisito de que el estudiante registre su wallet
manualmente. El sistema la crea y gestiona de forma transparente.

### Activar un curso piloto

1. **Administración del sitio → MeritCoin → Cursos Piloto** (`admin_pilot_courses.php`)
2. Selecciona el curso.
3. Opcionalmente elige un **grupo piloto** (solo ese grupo recibirá wallet custodial)
   y una **fecha de cierre manual** (sobreescribe `course.enddate`).
4. Guarda.

A partir de ese momento:
- La primera calificación del semestre activa el provisionado automático de wallet
- El observer encola el evento con `status = pending_wallet`
- `wallet_service` llama a `POST /wallets/provision` en el backend
- Cuando la wallet está lista, el evento pasa a `pending` y se envía normalmente

### Cierre de semestre

La tarea `expire_courses_task` (cron 2 AM diario) detecta cursos piloto vencidos
y llama a `POST /wallets/expire-course` en el backend para congelar el saldo MRT.

Para forzarlo manualmente en pruebas:

```bash
docker exec meritcoin-moodle \
  php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute='\local_meritcoin\task\expire_courses_task'
```

---

## Limpiar datos y repetir pruebas desde cero

**Limpiar la BD del backend (PostgreSQL):**

```bash
docker exec meritcoin-postgres psql -U meritcoin -d meritcoin_db \
  -c "TRUNCATE TABLE audit_log, events RESTART IDENTITY CASCADE;"
```

**Limpiar cola y canjes en Moodle (MariaDB):**

```bash
docker exec meritcoin-mariadb \
  mysql -u bn_moodle -pmoodle_pass bitnami_moodle \
  -e "DELETE FROM mdl_local_meritcoin_queue;
      DELETE FROM mdl_local_meritcoin_redemptions;
      DELETE FROM mdl_local_meritcoin_earnings;"
```

**Re-desplegar contratos:**

```bash
cd contracts
pnpm exec hardhat run scripts/deploy.js --network besu
# Actualiza MRT_CONTRACT_ADDRESS y BADGE_CONTRACT_ADDRESS en .env
cd ..
docker compose up -d --force-recreate backend
```

**Reiniciar la red Besu desde cero** ⚠️ *elimina todos los bloques y estado*:

```bash
cd besu/QBFT-Network
docker compose down -v
docker compose up -d
cd ../..
```

> Después de reiniciar Besu debes re-desplegar los contratos, ya que las
> direcciones anteriores dejarán de existir en la nueva cadena.

---

## API del backend

Documentación interactiva disponible en http://localhost:8000/docs

| Método | Endpoint | Descripción |
|---|---|---|
| `GET` | `/health` | Estado del servicio, blockchain e IPFS |
| `POST` | `/events/ingest` | Recibir evento académico (requiere HMAC) |
| `GET` | `/students/{wallet}/badges` | Listar insignias de un estudiante |
| `GET` | `/students/{wallet}/balance` | Consultar saldo MRT on-chain |
| `GET` | `/students/{wallet}/summary` | Saldo MRT + badges (usado por el dashboard) |
| `POST` | `/tokens/spend` | Quemar MRT al confirmar un canje del marketplace |
| `POST` | `/wallets/provision` | Provisionar wallet custodial para un estudiante |
| `POST` | `/wallets/expire-course` | Congelar saldo al cerrar un curso piloto |

---

## Contratos inteligentes

| Contrato | Estándar | Descripción |
|---|---|---|
| `MeritBadges1155` | ERC-1155 | Insignias digitales con metadatos OBv2 en IPFS |
| `MeritCoinERC20` | ERC-20 | Token MRT de recompensa académica |

Ambos usan exclusivamente **OpenZeppelin 5.x** (sin librerías de pago).
Incluyen `AccessControl` (`ISSUER_ROLE`, `MINTER_ROLE`, `BURNER_ROLE`)
y `Pausable` para emergencias.

Ver documentación detallada en [`contracts/README.md`](./contracts/README.md).

---

## Seguridad

- **HMAC-SHA256**: toda comunicación Moodle → FastAPI está firmada con el secreto compartido
- **Sin datos personales en blockchain**: solo wallets e IDs numéricos ofuscados
- **Idempotencia**: eventos duplicados son rechazados por `event_id` único (MD5 determinístico de `userid+cmid+grade`)
- **Límite MRT por estudiante**: el observer descarta eventos que exceden el tope configurado por curso
- **Roles Moodle**: capabilities por contexto de curso, no globales
- **Contratos con AccessControl**: `ISSUER_ROLE`, `MINTER_ROLE`, `BURNER_ROLE` y `Pausable`
- **`require_sesskey()`**: todas las acciones de escritura del plugin validan la sesión
- **Wallets custodiales cifradas**: claves privadas cifradas con Fernet (AES-128-CBC) usando `WALLET_ENCRYPTION_KEY`
- **pnpm**: gestor de paquetes con almacén centralizado y mejor aislamiento de dependencias

---

## Stack tecnológico

| Componente | Tecnología |
|---|---|
| LMS | Moodle 4.3 (Docker, imagen Bitnami) |
| Contratos | Solidity 0.8.28 · OpenZeppelin 5.x · Hardhat 2.28 |
| Backend | FastAPI · SQLAlchemy async · web3.py · PostgreSQL 16 |
| IPFS | Kubo (nodo local, modo `--offline`) |
| Plugin | PHP 8.x (Moodle Plugin API v0.5.1) |
| Base de datos | MariaDB 10.11 (Moodle) + PostgreSQL 16 (Backend) |
| Blockchain | Hyperledger Besu — red privada QBFT, 4 nodos |
| Gestor de paquetes | pnpm 9+ (contratos) |

---

## Estado de las pruebas

| Componente | Tests | Framework | Estado |
|---|---|---|---|
| Contratos Solidity | 19 | Hardhat + Chai | ✅ Estables |
| Backend FastAPI | 24 | pytest + httpx | ✅ Estables |
| E2E flujo completo | 18 | Python (stdlib) | ✅ Estables |
| **Total** | **61** | | |

---

## Estado del proyecto

| Fase | Descripción | Estado |
|---|---|---|
| 1 | Entorno de desarrollo (Docker) | ✅ Completa |
| 2 | Contratos inteligentes (Solidity) | ✅ Completa |
| 3 | Backend FastAPI (Python) | ✅ Completa |
| 4 | Plugin Moodle — core (observer, task, queue) | ✅ Completa |
| 5 | Prueba de flujo completo (E2E) | ✅ Completa |
| 6 | Gestión de reglas por curso | ✅ Completa |
| 7 | Ledger de ganancias y gasto por curso | ✅ Completa |
| 8 | Dashboard del estudiante + Marketplace | ✅ Completa |
| 9 | Insignias personalizadas por curso | ✅ Completa |
| 10 | Integración Hyperledger Besu (QBFT, 4 nodos) | ✅ Completa |
| 11 | MVP final + integración IPFS local (Kubo) | ✅ Completa |

---

## Mejoras futuras y escalamiento en SAVIO

La visión del proyecto es integrarse nativamente con **SAVIO**, la plataforma
institucional de la Universidad Tecnológica de Bolívar. Las líneas de trabajo
contempladas para esa siguiente fase son:

- **Despliegue productivo en SAVIO**: empaquetar el plugin como release estable y coordinar su instalación en la instancia oficial de Moodle/SAVIO
- **Hardening de seguridad**: rotación de claves, gestión centralizada de secretos y monitoreo de eventos anómalos
- **Escalabilidad de infraestructura**: separación de ambientes (dev/stage/prod) y orquestación con Kubernetes
- **Observabilidad y monitoreo**: paneles Prometheus/Grafana, logging estructurado y alertas sobre fallos en cola, backend o red Besu
- **Mejoras de UX**: reportes más detallados por curso, nuevos tipos de reglas y recompensas
- **Extensión on-chain**: interoperabilidad con otras redes EVM institucionales, manteniendo privacidad de datos académicos

---

## Licencia

Proyecto académico — Universidad Tecnológica de Bolívar, 2026.