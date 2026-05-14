# Documentación de Arquitectura — MeritCoin
## Formato ARC42 — Versión 0.7.0

**Proyecto:** MeritCoin — Sistema de Recompensas Académicas Digitales  
**Institución:** Universidad Tecnológica de Bolívar  
**Fecha:** Mayo 2026  
**Estado:** Completado 
**Rama principal de desarrollo:** `main`

---

# 1. Introducción y Objetivos

## 1.1 Descripción del sistema

MeritCoin es un sistema de incentivos académicos que integra la plataforma LMS
Moodle con tecnología blockchain. Permite que los profesores configuren reglas de
recompensa por actividad, tipo de actividad o curso, y que los estudiantes acumulen
tokens digitales (MRT) e insignias verificables on-chain al completar logros académicos.

El sistema opera de forma híbrida: la lógica de negocio y configuración vive off-chain
(Moodle + PostgreSQL), mientras que la emisión de tokens e insignias queda registrada
permanentemente on-chain en una red EVM privada basada en **Hyperledger Besu** (4 nodos,
consenso QBFT). Los metadatos de las insignias (Open Badges v2) se almacenan en un
**nodo IPFS local (Kubo)** integrado en el stack Docker.

El plugin incluye un subsistema completo de insignias (`local_meritcoin_badges`) con
páginas de verificación pública (`badge_verify.php`) y generación de certificados PDF
(`badge_pdf.php`), ambas standalone sin layout Moodle.

A partir de v0.5.1 el sistema soporta **wallets custodiales automáticas** para cursos
piloto, eliminando el requisito de que el estudiante registre su wallet manualmente.

## 1.2 Objetivos de calidad

| Prioridad | Atributo | Descripción |
|---|---|---|
| 1 | **Integridad** | Cada evento académico produce exactamente un registro on-chain. Duplicados rechazados por `event_id` único (MD5 determinístico de userid+cmid+grade). |
| 2 | **Trazabilidad** | Todo evento queda en cola Moodle, audit_log PostgreSQL y blockchain. Tres capas de auditoría independientes. |
| 3 | **Seguridad** | Comunicación Moodle → Backend firmada con HMAC-SHA256. Contratos con `AccessControl` y roles explícitos. Wallets custodiales cifradas con Fernet (AES-128-CBC). |
| 4 | **Extensibilidad** | Despliegue en SAVIO con cambios únicamente en capa de presentación y variables de entorno. |
| 5 | **Operabilidad** | El backend conecta a cualquier nodo EVM compatible cambiando solo `BLOCKCHAIN_RPC_URL`. IPFS configurable via `IPFS_API_URL`. |
| 6 | **Usabilidad visual** | Dashboard, modal de insignias, verificación y PDF funcionan en modo claro y oscuro, con fallbacks ante imágenes rotas. |

## 1.3 Partes interesadas

| Rol | Interés principal |
|---|---|
| **Estudiante** | Acumular y consultar MRT e insignias; descargar certificados PDF; compartir enlace de verificación pública |
| **Profesor** | Configurar reglas de recompensa sin conocimiento de blockchain; gestionar recompensas canjeables; ver informe de transacciones |
| **Administrador Moodle** | Instalar y configurar el plugin; ver panel global de KPIs; gestionar cursos piloto y wallets custodiales |
| **Desarrollador** | Mantener y extender el sistema; desplegar en SAVIO |
| **Verificador externo** | Acceder a `badge_verify.php` sin cuenta Moodle para confirmar autenticidad de una insignia |

---

# 2. Restricciones de Arquitectura

## 2.1 Restricciones técnicas

| Restricción | Razón |
|---|---|
| El plugin sigue la **Moodle Plugin API** estándar | Compatibilidad con Moodle 4.x y con SAVIO |
| Los contratos usan solo **OpenZeppelin 5.x** (sin librerías de pago) | Licencia MIT, auditadas, sin dependencias propietarias |
| La red blockchain es **Hyperledger Besu** (4 nodos QBFT) en su propio Docker Compose | Stack independiente del compose principal para aislamiento de la red |
| Los metadatos OBv2 se suben a un **nodo IPFS Kubo local** | Evita dependencia de servicios externos; reproducible en desarrollo |
| No se almacenan datos personales on-chain | Privacidad: solo wallets e IDs ofuscados en blockchain |
| El plugin usa `file_get_contents` (no cURL) para HTTP | El contenedor Bitnami Moodle tiene cURL deshabilitado por defecto |
| Los contratos se gestionan con **pnpm** (no npm) | Mejor aislamiento de dependencias, almacén centralizado, sin CVEs de supply-chain de npm |
| El nodo Besu corre en un compose **independiente** del compose principal | Permite levantar la red antes del resto del stack y reiniciarla sin afectar Moodle |
| Las páginas `badge_verify.php` y `badge_pdf.php` son **standalone** | Acceso público sin autenticación; no dependen del tema activo |
| Los toggles interactivos en páginas standalone usan **JavaScript puro** | Compatibilidad con Bootstrap 4 y 5 |

## 2.2 Restricciones organizacionales

- El proyecto es académico; la infraestructura target es **SAVIO** (Moodle institucional UTB).
- Las claves privadas usadas en desarrollo nunca deben usarse en producción.
- El volumen del plugin en `docker-compose.yml` debe estar descomentado para que los cambios locales se reflejen en el contenedor.

---

# 3. Contexto del Sistema

## 3.1 Contexto de negocio

```text
+-------------------+        Configura reglas          +--------------------+
|    Profesor        | --------------------------------> |  Plugin MeritCoin  |
+-------------------+        (manage.php/editrule.php)  |  (Moodle)          |
                                                         +--------+-----------+
+-------------------+        Logro completado                     |
|    Estudiante      | -----(dispara evento)---------->           |
+-------------------+                                             |
       |                                                          v
       |  (Dashboard)                             Evento encolado + monedas resueltas
       |  (Marketplace)                                           |
       |  (badge_verify / badge_pdf)                              v
       |                                             +------------+------------+
       |                                             |   Backend FastAPI       |
       |                                             |   (off-chain processor) |
       |                                             +------+--------+---------+
       |                                                    |        |        |
       |                                       +-----------+    +---+---+  +-+----------+
       |                                       v                v       v               v
       |                            +----------+------+  +------+  +---+--+  +-----+-----+
       |                            | Blockchain (EVM) |  | IPFS |  | PostgreSQL (audit) |
       |                            | Besu QBFT 4 nodos|  | Kubo |  |                    |
       |                            +------------------+  +------+  +--------------------+
       |                                       |
       |                            +----------+----------+
       |                            v                     v
       |                     +-------------+      +-------------+
       |                     | ERC-1155    |      | ERC-20      |
       |                     | MeritBadges |      | MeritCoin   |
       |                     +-------------+      +-------------+
       |
       v
+------+---------------+
| badge_verify.php      |  ← Verificador público (sin login)
| badge_pdf.php         |  ← Certificado PDF standalone
+-----------------------+
```

## 3.2 Contexto técnico

| Canal | Protocolo | Descripción |
|---|---|---|
| Moodle → Backend | HTTP POST + HMAC-SHA256 (`file_get_contents`) | Envío de eventos académicos firmados |
| Backend → Blockchain | JSON-RPC (web3.py) vía `host.docker.internal:8545` | Llamadas a `mintBadge` y `mint` |
| Backend → IPFS (Kubo) | HTTP API vía `http://meritcoin-ipfs:5001` | Upload de metadatos OBv2 y pin |
| Backend → PostgreSQL | asyncpg (SQLAlchemy async) | Persistencia del audit_log |
| Plugin → MariaDB | Moodle DBAL | Persistencia de queue, rules, earnings, spend, rewards, redemptions, badges, wallets |
| Plugin → Backend (wallets) | HTTP POST + HMAC (`file_get_contents`) | Provisionado y expiración de wallets custodiales |
| Estudiante → Moodle | HTTPS (navegador) | Dashboard, marketplace, historial |
| Verificador → `badge_verify.php` | HTTPS (sin autenticación) | Verificación pública de insignias |
| Estudiante → `badge_pdf.php` | HTTPS (sin autenticación) | Descarga de certificado |

---

# 4. Estrategia de Solución

La solución separa cuatro capas de responsabilidad:

1. **Capa de configuración y captura (Moodle + Plugin PHP):** El profesor define reglas
   con tres niveles de granularidad. El observer captura eventos, filtra por `itemtype = mod`,
   aplica reglas con prioridad jerárquica, verifica el límite MRT por curso y encola. Para
   cursos piloto, `wallet_service` provisiona wallets custodiales automáticamente. Esta capa
   no tiene dependencias directas de la blockchain.

2. **Capa de procesamiento off-chain (FastAPI + PostgreSQL + IPFS):** Recibe eventos firmados,
   verifica HMAC, garantiza idempotencia, genera metadatos OBv2, los sube al nodo Kubo local
   y orquesta las llamadas a Besu. También gestiona el ciclo de vida de wallets custodiales
   (provisionado, expiración de semestre).

3. **Capa de registro permanente (Contratos Solidity + EVM Besu):** Emite badges ERC-1155
   y tokens ERC-20. Fuente de verdad final e inmutable. El balance real del ERC-20 es
   consultado por el marketplace para validar canjes.

4. **Capa de presentación pública (páginas standalone):** `badge_verify.php` y `badge_pdf.php`
   operan sin layout Moodle y sin autenticación. Los estilos son autónomos y no dependen del
   tema activo de Moodle.

La decisión de calcular `coins_amount` en el plugin (no en el backend) mantiene el backend
agnóstico a las reglas del LMS, facilitando integración futura con sistemas distintos de Moodle.

---

# 5. Vista de Bloques

## 5.1 Nivel 1 — Sistema completo

```text
+---------------------------------------------------------------+
|                      SISTEMA MERITCOIN                        |
|                                                               |
|  +------------------+     +----------------------------+      |
|  |  Moodle (LMS)    |     |  Backend FastAPI           |      |
|  |  + Plugin PHP    +---->+  + PostgreSQL (audit)      |      |
|  |  + MariaDB       |     |  + IPFS Kubo (metadatos)   |      |
|  +------------------+     +----------+-----------------+      |
|          |                            |                       |
|          |                 +----------+----------+            |
|   Páginas públicas         | Besu QBFT (4 nodos) |            |
|   badge_verify.php         | ERC-1155 + ERC-20   |            |
|   badge_pdf.php            +---------------------+            |
+---------------------------------------------------------------+
```

## 5.2 Nivel 2 — Plugin Moodle (caja blanca)

| Componente | Responsabilidad |
|---|---|
| `observer.php` | Escucha `user_graded`; filtra `itemtype=mod`; genera `event_id` MD5; verifica límite MRT; detecta curso piloto y llama `wallet_service` |
| `rules_service.php` | Prioridad: `activity` > `activity_type` > `course`; aplica `min_grade` |
| `wallet_service.php` | Llama `POST /wallets/provision`; guarda en `local_meritcoin_wallets`; reactiva eventos `pending_wallet` |
| `send_events_task.php` | Envía eventos `pending` al backend cada minuto; reactiva `pending_wallet` cuando la wallet ya está disponible |
| `process_redemptions_task.php` | Procesa canjes `pending` del marketplace cada minuto |
| `expire_courses_task.php` | Detecta cursos piloto vencidos y llama `POST /wallets/expire-course` (cron 2 AM) |
| `api_client.php` | Encapsula HTTP con firma HMAC-SHA256 usando `file_get_contents` |
| `manage.php` + `editrule.php` | UI del profesor para CRUD de reglas |
| `dashboard.php` | Saldo MRT, historial, grid de insignias; fallback `onerror` para imágenes; colores forzados en modal para dark mode |
| `marketplace.php` | Consulta y canje de recompensas; valida `earnings - spend` por curso + balance real del contrato |
| `admin_pilot_courses.php` | Panel admin para configurar cursos piloto y fechas de cierre de semestre |
| `badge_verify.php` | Verificación pública standalone por hash; toggle hash con JS puro |
| `badge_pdf.php` | Certificado PDF A4 standalone; firma cursiva del emisor; `window.print()` |
| `lib.php` | Hooks de navegación por rol |
| `settings.php` | Config admin: URL backend, HMAC secret, límite MRT |

## 5.3 Nivel 2 — Backend FastAPI (caja blanca)

| Componente | Responsabilidad |
|---|---|
| `api/events.py` | `POST /events/ingest`: valida HMAC, delega a `events_service` |
| `api/students.py` | `/balance`, `/badges`, `/summary` |
| `api/wallets.py` | `POST /wallets/provision`, `POST /wallets/expire-course` |
| `services/events_service.py` | Orquesta: idempotencia → IPFS → badges → tokens → audit |
| `services/blockchain.py` | web3.py wrapper: conecta a Besu, llama `mintBadge`, `mint`, `burn` |
| `services/ipfs_service.py` | Sube metadatos OBv2 al nodo Kubo local (`/api/v0/add`); retorna CID real |
| `services/badges_service.py` | Genera metadatos Open Badges v2; coordina con `ipfs_service` |
| `services/tokens_service.py` | Calcula y llama `mint` de tokens ERC-20 |
| `services/wallet_service.py` | Genera wallet custodial; cifra clave privada con Fernet; registra en BD |
| `services/audit_service.py` | Registra resultado final en PostgreSQL |
| `core/config.py` | Lee variables de entorno vía pydantic-settings |
| `core/security.py` | Verifica firma HMAC-SHA256 |

## 5.4 Nivel 2 — Contratos Solidity (caja blanca)

| Contrato | Estándar | Funciones clave |
|---|---|---|
| `MeritBadges1155.sol` | ERC-1155 | `mintBadge(address, tokenId, uri)` — insignia única por logro con idempotencia |
| `MeritCoinERC20.sol` | ERC-20 | `mint(address, amount)`, `burn(address, amount)` — acuña o quema MRT |

Ambos heredan `AccessControl` (`ISSUER_ROLE`, `MINTER_ROLE`, `BURNER_ROLE`) y
`Pausable` de OpenZeppelin 5.x. Se gestionan con **pnpm**.

---

# 6. Vista de Ejecución

## 6.1 Escenario principal — Evento de calificación

```text
Moodle    Observer    rules_service    Queue(MariaDB)    Task       Backend         Besu/IPFS
  |           |              |                |           |            |                |
  |--user_graded------------>|                |           |            |                |
  |           |--itemtype=mod?                |           |            |                |
  |           |--resolve_rules(curso,cmid,tipo,grade)     |            |                |
  |           |<----------coins_amount--------|           |            |                |
  |           |--check_MRT_limit (si excede → descarta)   |            |                |
  |           |--¿curso piloto?               |           |            |                |
  |           |  Sí→wallet_service→POST /wallets/provision→            |                |
  |           |     guarda wallet en local_meritcoin_wallets           |                |
  |           |--insert(event_id, coins, pending)-------->|            |                |
  |           |                               |           |            |                |
  |  (scheduler cada 1 min)                   |<--poll----|            |                |
  |           |                               |---events->|            |                |
  |           |                               |           |--POST /events/ingest+HMAC-->|
  |           |                               |           |            |--verify HMAC   |
  |           |                               |           |            |--ipfs upload-->|
  |           |                               |           |            |<--CID real-----|
  |           |                               |           |            |--mintBadge()-->|
  |           |                               |           |            |--mint(MRT)---->|
  |           |                               |           |            |<--txHash-------|
  |           |                               |           |            |--audit_log     |
  |           |                               |           |<--200 OK---|                |
  |           |                               |--update(sent)--------->|                |
  |           |                               |--insert(earnings)----->|                |
```

## 6.2 Escenario — Canje en el marketplace

```text
Estudiante   marketplace.php    api_client.php     Backend           Besu
    |               |                  |              |                |
    |--ver mercado->|                  |              |                |
    |               |--GET /summary(wallet)---------->|                |
    |               |                  |              |--balanceOf()-->|
    |               |                  |              |<--balance------|
    |               |<--balance + badges              |                |
    |               |--calcular saldo: earnings - spend por curso      |
    |               |--mostrar recompensas canjeables                  |
    |--canjear----->|                  |              |                |
    |               |--validar saldo y stock          |                |
    |               |--insert(redemption, spend)      |                |
    |               |--OK confirmación al estudiante  |                |
    |  (process_redemptions_task cada 1 min)          |                |
    |               |--POST /tokens/spend+HMAC------->|                |
    |               |                  |              |--burn(MRT)---->|
    |               |                  |              |<--txHash-------|
    |               |                  |              |--audit_log     |
    |               |                  |<--200 OK-----|                |
    |               |--update(redemption: confirmed)  |                |
```

## 6.3 Escenario — Provisionado de wallet custodial (curso piloto)

```text
Observer     wallet_service     Backend (wallets)      BD MariaDB
    |               |                   |                    |
    |--curso piloto detectado           |                    |
    |--encola con status=pending_wallet |                    |
    |-->wallet_service::provision()     |                    |
    |               |--POST /wallets/provision+HMAC------->  |
    |               |                   |--genera keypair    |
    |               |                   |--cifra con Fernet  |
    |               |                   |--guarda en BD PG   |
    |               |<--wallet_address--|                    |
    |               |--insert(wallets: userid, address)----> |
    |               |--UPDATE queue: pending_wallet→pending->|
```

## 6.4 Escenario — Idempotencia (evento duplicado)

Si el backend recibe un `event_id` ya existente en `audit_log`, retorna `200 OK`
con `"Evento ya fue procesado anteriormente"` sin volver a llamar a Besu ni a IPFS.
El plugin marca el evento como `sent` igualmente.

## 6.5 Escenario — Cierre de semestre (curso piloto)

```text
expire_courses_task (cron 2 AM)
    |
    v
Detecta cursos piloto con expires_at <= now o course.enddate <= now
    |
    v
POST /wallets/expire-course → backend guarda snapshot MRT, cierra enrollments
    |
    v
Marca pilot_enabled = 0 en local_meritcoin_pilot_courses
```

---

# 7. Vista de Despliegue

## 7.1 Entorno de desarrollo (actual)

```text
Máquina host (Windows/Mac/Linux)
│
├── Stack Besu (besu/QBFT-Network/docker-compose.yml) — independiente
│   ├── besu-node-1  (RPC: 8545, P2P: 30303) — bootnode
│   ├── besu-node-2  (RPC: 8546, P2P: 30304)
│   ├── besu-node-3  (RPC: 8547, P2P: 30305)
│   └── besu-node-4  (RPC: 8548, P2P: 30306)
│   Consenso: QBFT (4 nodos validadores)
│
└── Stack principal (docker-compose.yml)
    ├── meritcoin-backend    (FastAPI, puerto 8000)
    │   └── BLOCKCHAIN_RPC_URL=http://host.docker.internal:8545
    ├── meritcoin-moodle     (Moodle 4.3, puertos 8080/8443)
    │   └── Volumen: ./plugin → /bitnami/moodle/local/meritcoin
    ├── meritcoin-postgres   (PostgreSQL 16, puerto 5432)
    ├── meritcoin-mariadb    (MariaDB 10.11, puerto 3306)
    └── meritcoin-ipfs       (Kubo, API: 5001, Gateway: 8081)
        └── IPFS_API_URL=http://meritcoin-ipfs:5001
```

**Comunicación cross-stack:** el backend accede a Besu via `host.docker.internal:8545`.
En Linux añadir `extra_hosts: ["host.docker.internal:host-gateway"]` al servicio backend.

**Nota crítica sobre el volumen del plugin:** la línea
`- ./plugin:/bitnami/moodle/local/meritcoin` en `docker-compose.yml`
debe estar descomentada. Si se comenta y se reinicia el servicio, el plugin
desaparece de Moodle. Para restaurarlo:

```bash
# Descomentar la línea en docker-compose.yml, luego:
docker compose up -d --force-recreate moodle
```

## 7.2 Entorno objetivo — SAVIO (producción)

```text
Servidor UTB
├── SAVIO (Moodle institucional)
│   └── Plugin local_meritcoin instalado como release estable
│
├── Backend FastAPI
│   └── BLOCKCHAIN_RPC_URL → nodo Besu institucional
│   └── IPFS_API_URL → nodo IPFS institucional o Pinata
│
└── Red Hyperledger Besu
    └── Génesis y configuración basadas en /besu/QBFT-Network
```

---

# 8. Conceptos Transversales

## 8.1 Seguridad

- **HMAC-SHA256:** cada petición del plugin al backend incluye firma con `HMAC_SECRET` compartido. El backend rechaza con `401` firmas inválidas.
- **Roles en contratos:** `ISSUER_ROLE` → `mintBadge`; `MINTER_ROLE` → `mint`; `BURNER_ROLE` → `burn`. Solo la cuenta deployer tiene estos roles.
- **Pausable:** ambos contratos pausables ante incidentes sin redespliegue.
- **sesskey:** todas las acciones de escritura del plugin usan `require_sesskey()`.
- **Sin datos personales on-chain:** solo wallets e IDs ofuscados.
- **Wallets custodiales cifradas:** claves privadas cifradas con Fernet (AES-128-CBC) usando `WALLET_ENCRYPTION_KEY`. El backend no arranca si esta variable no está definida.
- **pnpm:** gestor de paquetes con almacén centralizado, sin CVEs de supply-chain de npm.

## 8.2 Idempotencia

`event_id` en `audit_log` tiene índice único. Duplicado → `200` sin reintentar blockchain. El observer genera `event_id = MD5(userid+cmid+grade)` para evitar duplicados desde el origen.

## 8.3 Trazabilidad en tres capas

| Capa | Almacén | Qué registra |
|---|---|---|
| Plugin (Moodle) | `local_meritcoin_queue` | Estado: `pending` / `pending_wallet` → `sent` / `failed` |
| Plugin (Moodle) | `local_meritcoin_earnings` | Ledger de MRT ganados por usuario y curso |
| Plugin (Moodle) | `local_meritcoin_spend` | Ledger de MRT gastados por usuario y curso |
| Plugin (Moodle) | `local_meritcoin_wallets` | Caché de wallets custodiales (espejo del backend) |
| Backend (off-chain) | PostgreSQL `audit_log` | event_id, wallet, txHash badge, txHash MRT, CID IPFS real, timestamp |
| Blockchain (on-chain) | EVM Besu | Transacciones inmutables de `mintBadge` y `mint` |

## 8.4 Metadatos Open Badges v2 (OBv2) en IPFS

Cada insignia emitida lleva metadatos OBv2 subidos al nodo Kubo local:
- `name`, `description`, `image` del curso/actividad
- `recipient` (wallet del estudiante, ofuscada con SHA-256)
- `issuedOn` (timestamp del evento)
- `verification` (tipo blockchain + dirección del contrato)

El CID retornado por Kubo se almacena en `audit_log` y se referencia en la URI
del token ERC-1155. El gateway local (`http://localhost:8081/ipfs/{CID}`) sirve
los metadatos durante el desarrollo.

## 8.5 Ledger de saldo por curso

```text
saldo_disponible = SUM(local_meritcoin_earnings.coins_earned WHERE userid, courseid)
                 - SUM(local_meritcoin_spend.coins_spent WHERE userid, courseid)
```

El marketplace valida además que el balance real del contrato ERC-20 sea suficiente
antes de aprobar el canje.

## 8.6 Sistema de reglas jerárquico

| Prioridad | Scope | Descripción |
|---|---|---|
| 1 (mayor) | `activity` | Actividad específica por `cmid` |
| 2 | `activity_type` | Todos los módulos de un tipo (`assign`, `quiz`, `forum`…) |
| 3 (menor) | `course` | Regla general del curso |

Cada regla puede tener `min_grade`. Si la nota es inferior al umbral, el evento se descarta.

## 8.7 Límite de MRT por estudiante por curso

El observer verifica que el total histórico de MRT recibidos por el estudiante en el
curso no supere el límite configurado (por defecto **16 MRT**). El consumo en el
marketplace no libera cupo — el límite evalúa el total recibido, no el saldo actual.

## 8.8 Wallets custodiales (cursos piloto)

| Evento | Acción |
|---|---|
| Primera calificación del semestre | `wallet_service` provisiona wallet via `POST /wallets/provision` |
| Cursos anteriores del mismo estudiante | Reutiliza la misma wallet (1 estudiante = 1 wallet permanente) |
| Fin de semestre (cron 2 AM) | `expire_courses_task` llama `POST /wallets/expire-course` |
| Rematrícula siguiente semestre | Nuevo enrollment con saldo 0; wallet y badges se conservan |

Si el curso **no es piloto**, el observer lee la wallet del campo de perfil `wallet`.
Ambos modos coexisten en el mismo Moodle.

## 8.9 Sistema de insignias y verificación pública

1. **Emisión:** backend llama `mintBadge` y sube metadatos a IPFS; plugin inserta en `local_meritcoin_badges` con `verify_hash` único.
2. **Dashboard:** grid `flex-wrap` con tarjetas de 150px; `onerror` handler → ícono FontAwesome si la URL falla.
3. **Modal:** colores explícitos con `!important` para legibilidad en dark mode del tema.
4. **Verificación pública:** `badge_verify.php?hash=<hash>` sin autenticación; hash técnico colapsable con JS puro (compatible BS4/BS5).
5. **Certificado PDF:** `badge_pdf.php?hash=<hash>` — HTML A4 con firma cursiva, franja de color por tipo, `window.print()`.

## 8.10 Compatibilidad Bootstrap 4/5

- No usar `data-bs-toggle` ni `data-toggle` en páginas standalone.
- Forzar colores en el modal con `!important` para evitar conflictos con dark mode.
- Usar `addEventListener` y manipulación directa de DOM.

---

# 9. Decisiones de Arquitectura (ADR)

## ADR-001: Cálculo de monedas en el plugin, no en el backend
**Decisión:** El plugin resuelve reglas y envía `coins_amount` ya calculado.  
**(+)** Backend agnóstico al LMS. **(-)** Backend confía en el valor enviado.

## ADR-002: Cola asíncrona Moodle → Backend
**Decisión:** Observer encola en MariaDB; tarea programada procesa cada minuto.  
**(+)** Sin latencia blockchain para el usuario. **(-)** Retardo máximo de 1 minuto.

## ADR-003: Doble base de datos (MariaDB + PostgreSQL)
**Decisión:** MariaDB para el plugin Moodle; PostgreSQL para el audit del backend.  
**(+)** Separación de responsabilidades. **(-)** Dos motores en el stack.

## ADR-004: Nodo IPFS local (Kubo) en el stack Docker
**Decisión:** Integrar Kubo como servicio `meritcoin-ipfs` en `docker-compose.yml`.
Reemplaza el CID simulado (`QmSimulated...`) de versiones anteriores.  
**(+)** Metadatos OBv2 reales y accesibles durante desarrollo. **(+)** Sin dependencia de servicios externos.  
**(-)** Añade un contenedor más al stack; en producción evaluar Pinata para redundancia.

## ADR-005: `file_get_contents` en lugar de cURL
**Decisión:** Todas las llamadas HTTP del plugin usan `file_get_contents` + `stream_context_create`.  
**(+)** Compatible con Bitnami Moodle sin configuración adicional. **(-)** Timeout menos granular.

## ADR-006: Saldo del marketplace = ledger local + validación del contrato
**Decisión:** `earnings - spend` por curso + consulta de balance real ERC-20.  
**(+)** Saldo independiente por curso. **(-)** Llamada extra al backend en cada carga del marketplace.

## ADR-007: `event_id` determinístico (MD5) para idempotencia desde el origen
**Decisión:** `event_id = MD5(userid + cmid + grade)`.  
**(+)** Idempotencia desde el origen + protección doble en el backend.  
**(-)** Misma nota en la misma actividad → segundo evento descartado (aceptado como trade-off).

## ADR-008: Besu como stack independiente (4 nodos QBFT)
**Decisión:** La red Besu corre en su propio `docker-compose.yml` bajo `besu/QBFT-Network/`.  
**(+)** La red puede reiniciarse sin afectar Moodle ni el backend. **(+)** 4 nodos garantizan consenso QBFT.  
**(-)** Requiere levantar dos stacks en orden; el backend accede a Besu via `host.docker.internal`.

## ADR-009: Páginas standalone para verificación y PDF
**Decisión:** `badge_verify.php` y `badge_pdf.php` sin `$OUTPUT->header()`.  
**(+)** Acceso público sin cuenta Moodle. **(-)** Estilos no heredan del tema; actualización de marca requiere editar CSS inline.

## ADR-010: JavaScript puro para interactividad del plugin
**Decisión:** `addEventListener` + DOM directo, sin depender de atributos HTML de Bootstrap.  
**(+)** Compatible con cualquier tema Moodle (BS4/BS5). **(-)** Más JS por mantener.

## ADR-011: pnpm como gestor de paquetes para contratos
**Decisión:** Reemplazar npm por pnpm en el directorio `contracts/`.  
**(+)** Almacén centralizado de paquetes; mejor aislamiento de dependencias; evita CVEs de supply-chain asociados al registro de npm.  
**(-)** Requiere que los colaboradores tengan pnpm instalado (`npm install -g pnpm`).

## ADR-012: Wallets custodiales para cursos piloto
**Decisión:** El backend genera y cifra (Fernet/AES-128-CBC) las claves privadas de wallets
custodiales. El plugin las cachea en `local_meritcoin_wallets`.  
**(+)** Elimina fricción para el estudiante en cursos piloto. **(-)** El backend custodia claves privadas; requiere HSM o multisig en producción.

---

# 10. Esquema de Base de Datos

## 10.1 MariaDB — Plugin Moodle (v0.5.1+)

| Tabla | Propósito |
|---|---|
| `local_meritcoin_queue` | Cola de eventos: `pending`, `pending_wallet`, `sent`, `failed` |
| `local_meritcoin_rules` | Reglas de recompensa por curso/actividad |
| `local_meritcoin_earnings` | Ledger de MRT ganados por curso |
| `local_meritcoin_spend` | Ledger de MRT gastados por curso |
| `local_meritcoin_course_config` | Config de moneda por curso |
| `local_meritcoin_rewards` | Recompensas canjeables por curso |
| `local_meritcoin_redemptions` | Historial de canjes |
| `local_meritcoin_badges` | Insignias emitidas con `verify_hash` y CID IPFS |
| `local_meritcoin_badge_types` | Tipos de insignia (color e ícono) |
| `local_meritcoin_pilot_courses` | Configuración de cursos piloto (v0.5.1) |
| `local_meritcoin_wallets` | Caché de wallets custodiales (v0.5.1) |

## 10.2 PostgreSQL — Backend

| Tabla | Propósito |
|---|---|
| `events` | Audit log: `event_id` único, wallet, txHash, CID IPFS real, timestamp |
| `audit_log` | Log detallado de operaciones del backend |
| `wallets` | Wallets custodiales: dirección, clave cifrada, curso, estado |

---

# 11. Riesgos y Deuda Técnica

| ID | Tipo | Descripción | Impacto | Plan de mitigación |
|---|---|---|---|---|
| R-01 | **Riesgo** | Nodo Kubo local no tiene redundancia; si falla, los CIDs no son accesibles | Medio | Añadir Pinata como pin secundario antes del despliegue en SAVIO |
| R-02 | **Riesgo** | Clave privada del deployer en `.env`; si se filtra, un atacante puede mintear arbitrariamente | Alto | HSM o Gnosis Safe multisig en producción |
| R-03 | **Deuda técnica** | Tests de backend no cubren todos los flujos de wallets custodiales | Medio | Ampliar suite pytest en siguiente iteración |
| R-04 | **Riesgo** | Configuración Besu en desarrollo puede diferir de la institucional | Medio | Probar en staging con la configuración definitiva antes de SAVIO |
| R-05 | **Deuda técnica** | `file_get_contents` no permite timeout granular | Bajo | Evaluar habilitar cURL en Bitnami o migrar a Guzzle |
| R-06 | **Deuda técnica** | No existe mecanismo de retry con backoff para eventos `failed` | Bajo | Implementar backoff exponencial en `send_events_task.php` |
| R-07 | **Deuda técnica** | Ajustes visuales finales para SAVIO no están completos | Medio | Completar en fase de despliegue institucional |
| R-08 | **Riesgo** | El volumen del plugin puede quedar comentado accidentalmente | Bajo | Documentado en README; considerar healthcheck que valide el mount |
| R-09 | **Deuda técnica** | La key `teacher_weekly_limit` describe límite por estudiante/curso, no por profesor; solo se corrigieron los labels en UI | Bajo | Renombrar en migración futura de `upgrade.php` |
| R-10 | **Deuda técnica** | CSS inline en páginas standalone; cualquier cambio de marca requiere editar dos archivos | Bajo | Extraer a `styles/public.css` compartido |
| R-11 | **Deuda técnica** | Dark mode puede afectar otros componentes no cubiertos por los `!important` de v0.6.0 | Medio | Auditar todos los componentes antes de despliegue en SAVIO |
| R-12 | **Riesgo** | Wallets custodiales cifradas en backend; pérdida de `WALLET_ENCRYPTION_KEY` = pérdida de acceso a todas las wallets custodiales | Alto | Backup seguro de la clave; considerar KMS en producción |

---

# 12. Estado del Proyecto

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
| 9 | Insignias personalizadas con verificación pública y PDF | ✅ Completa |
| 10 | Integración Hyperledger Besu (QBFT, 4 nodos) | ✅ Completa |
| 11 | MVP final + IPFS local (Kubo) + wallets custodiales | ✅ Completa |

---

*Documento actualizado Mayo 2026 — MeritCoin v0.7.0 — Universidad Tecnológica de Bolívar*