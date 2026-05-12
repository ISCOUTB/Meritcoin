# Documentación de Arquitectura — MeritCoin
## Formato ARC42 — Versión 0.6.0

**Proyecto:** MeritCoin — Sistema de Recompensas Académicas Digitales  
**Institución:** Universidad Tecnológica de Bolívar  
**Fecha:** Mayo 2026  
**Estado:** Completado 
**Rama principal de desarrollo:** `main`

---

# 1. Introducción y Objetivos

## 1.1 Descripción del sistema

MeritCoin es un sistema de incentivos académicos que integra la plataforma LMS Moodle con tecnología blockchain. Permite que los profesores configuren reglas de recompensa por actividad, tipo de actividad o curso, y que los estudiantes acumulen tokens digitales (MRT) e insignias verificables on-chain al completar logros académicos.

El sistema opera de forma híbrida: la lógica de negocio y configuración vive off-chain (Moodle + PostgreSQL), mientras que la emisión de tokens e insignias queda registrada permanentemente on-chain en una red EVM privada basada en **Hyperledger Besu**.

El plugin incluye un subsistema completo de insignias locales (`local_meritcoin_badges`) con páginas de verificación pública (`badge_verify.php`) y generación de certificados PDF imprimibles (`badge_pdf.php`), ambas páginas standalone sin layout Moodle.

## 1.2 Objetivos de calidad

| Prioridad | Atributo | Descripción |
|-----------|----------|-------------|
| 1 | **Integridad** | Cada evento académico debe producir exactamente un registro on-chain. Duplicados son rechazados por `event_id` único (MD5 deterministico de userid+cmid+grade). |
| 2 | **Trazabilidad** | Todo evento queda registrado en la cola Moodle, en el audit_log PostgreSQL y en la blockchain. Tres capas de auditoría independientes. |
| 3 | **Seguridad** | Toda comunicación Moodle → Backend está firmada con HMAC-SHA256. Los contratos usan `AccessControl` con roles explícitos. Todas las escrituras del plugin usan `require_sesskey()`. |
| 4 | **Extensibilidad** | El sistema debe poder desplegarse en SAVIO (instancia Moodle de la universidad) con cambios únicamente en la capa de presentación. |
| 5 | **Operabilidad** | El backend debe poder conectarse a cualquier nodo EVM compatible cambiando únicamente la variable `BLOCKCHAIN_RPC_URL`. |
| 6 | **Usabilidad visual** | La interfaz del estudiante (dashboard, modal de insignias, verificación, PDF) debe funcionar correctamente en modo claro y oscuro, con fallbacks visuales ante imágenes rotas y sin dependencias de Bootstrap versión específica. |

## 1.3 Partes interesadas (Stakeholders)

| Rol | Interés principal |
|-----|-------------------|
| **Estudiante** | Acumular y consultar monedas e insignias; descargar certificados PDF; compartir enlace de verificación pública |
| **Profesor** | Configurar reglas de recompensa por curso/actividad sin conocimiento técnico de blockchain; gestionar recompensas canjeables; ver informe de transacciones de su curso |
| **Administrador Moodle** | Instalar y configurar el plugin; ver panel global de KPIs, recompensas y canjes; gestionar credenciales del backend |
| **Desarrollador** | Mantener y extender el sistema; desplegar en SAVIO |
| **Verificador externo** | Acceder a `badge_verify.php` sin cuenta Moodle para confirmar autenticidad de una insignia mediante su hash |

---

# 2. Restricciones de Arquitectura

## 2.1 Restricciones técnicas

| Restricción | Razón |
|-------------|-------|
| El plugin debe seguir la **Moodle Plugin API** estándar | Compatibilidad con Moodle 4.x y con SAVIO |
| Los contratos usan únicamente **OpenZeppelin 5.x** (sin librerías de pago) | Licencia MIT, auditadas, sin dependencias propietarias |
| El backend corre **dentro de Docker**; el nodo blockchain corre como servicio Besu en Docker | Restricción de entorno de desarrollo y futura producción |
| No se almacenan datos personales on-chain | Privacidad: solo wallets e IDs ofuscados viajan a la blockchain |
| El `backend/.env` es la fuente de configuración del backend | `config.py` lee variables de entorno desde el `.env` del servicio Docker |
| El plugin usa `file_get_contents` (no cURL) para HTTP | El contenedor Bitnami Moodle tiene cURL deshabilitado por defecto |
| El nodo blockchain en staging/producción es **Hyperledger Besu** (red privada EVM) | Compatibilidad con infraestructura institucional UTB; Besu es EVM-compatible y permite redes permisionadas |
| El backend detecta automáticamente el cliente EVM via `web3_clientVersion` | Permite comportamiento adaptativo sin reconfiguración manual |
| Las páginas `badge_verify.php` y `badge_pdf.php` son **standalone** (sin layout Moodle) | Permiten acceso público sin autenticación; cargan FontAwesome y Google Fonts desde CDN |
| Los toggles interactivos en páginas standalone usan **JavaScript puro** (sin `data-bs-toggle`) | Compatibilidad con Bootstrap 4 y 5; evita dependencia de versión del tema Moodle |

## 2.2 Restricciones organizacionales

- El proyecto es académico; la infraestructura de producción target es **SAVIO** (Moodle institucional de la UTB).
- El ajuste visual para SAVIO debe poder hacerse sin reescribir lógica de negocio.
- Las claves privadas usadas en desarrollo nunca deben usarse en producción.
- El volumen del plugin en `docker-compose.yml` (`./plugin:/bitnami/moodle/local/meritcoin`) debe estar descomentado para que los cambios locales se reflejen en el contenedor.

---

# 3. Contexto del Sistema

## 3.1 Contexto de negocio

```text
+-------------------+        Configura reglas          +--------------------+
|    Profesor        | --------------------------------> |  Plugin MeritCoin  |
+-------------------+        (manage.php/editrule.php)  |  (Moodle)          |
                                                         +--------+-----------+
+-------------------+        Logro completado                    |
|    Estudiante      | -----(dispara evento)---------->          |
+-------------------+                                            |
       |                                                          v
       |  (Dashboard)                             Evento encolado + monedas resueltas
       |  (Marketplace)                                           |
       |  (badge_verify / badge_pdf)                             v
       |                                             +------------+------------+
       |                                             |   Backend FastAPI       |
       |                                             |   (off-chain processor) |
       |                                             +------+--------+---------+
       |                                                    |        |
       |                                       +-----------+        +-----------+
       |                                       v                                v
       |                            +----------+-------+          +-------------+------+
       |                            | Blockchain (EVM) |          | PostgreSQL (audit) |
       |                            | ERC-1155 + ERC-20|          | + IPFS simulado    |
       |                            +------------------+          +--------------------+
       |
       v
+------+---------------+
| badge_verify.php      |  ← Verificador público (sin login)
| badge_pdf.php         |  ← Certificado PDF standalone
+-----------------------+
```

## 3.2 Contexto técnico

| Canal | Protocolo | Descripción |
|-------|-----------|-------------|
| Moodle → Backend | HTTP POST + HMAC-SHA256 (file_get_contents) | Envío de eventos académicos firmados |
| Backend → Blockchain | JSON-RPC (web3.py) vía `meritcoin-besu:8545` | Llamadas a `mintBadge` y `mint` |
| Backend → PostgreSQL | asyncpg (SQLAlchemy async) | Persistencia del audit_log |
| Plugin → MariaDB | Moodle DBAL | Persistencia de queue, rules, earnings, spend, rewards, redemptions, badges |
| Estudiante → Moodle | HTTPS (navegador) | Dashboard, marketplace, historial de transacciones |
| Verificador → badge_verify.php | HTTPS (navegador, sin autenticación) | Verificación pública de insignias por hash |
| Estudiante → badge_pdf.php | HTTPS (navegador, sin autenticación Moodle) | Descarga/impresión de certificado |
| Profesor → Moodle | HTTPS (navegador) | Gestión de reglas, recompensas, informe de transacciones del curso |
| Admin → Moodle | HTTPS (navegador) | Panel global: KPIs, todas las transacciones, gestión de recompensas |

---

# 4. Estrategia de Solución

La solución separa en tres capas de responsabilidad claramente delimitadas:

1. **Capa de configuración y captura (Moodle + Plugin PHP):** El profesor define las reglas de recompensa con tres niveles de granularidad: actividad específica (`activity`), tipo de módulo (`activity_type`) y curso completo (`course`). El observer captura los eventos del LMS, filtra por `itemtype = mod` para ignorar calificaciones globales del curso, aplica las reglas con prioridad jerárquica y calcula el valor de monedas antes de encolar. Esta capa no tiene dependencias directas de la blockchain.

2. **Capa de procesamiento off-chain (FastAPI + PostgreSQL):** Recibe eventos firmados, verifica integridad HMAC, garantiza idempotencia mediante `event_id` único (MD5 deterministico), genera metadatos OBv2, simula pin IPFS y orquesta las llamadas a la blockchain. Expone endpoints de consulta para el dashboard y el marketplace.

3. **Capa de registro permanente (Contratos Solidity + EVM):** Emite badges ERC-1155 y tokens ERC-20. Es la fuente de verdad final e inmutable del sistema. El balance real del contrato ERC-20 es consultado por el marketplace para validar canjes.

4. **Capa de presentación pública (páginas standalone):** `badge_verify.php` y `badge_pdf.php` operan sin layout Moodle y sin autenticación requerida. Permiten que cualquier persona con el hash de una insignia pueda verificar su autenticidad o descargar el certificado. Los estilos son completamente autónomos (CSS inline) y no dependen del tema activo de Moodle.

La decisión de calcular `coins_amount` en el plugin (no en el backend) permite que el backend sea agnóstico a las reglas de negocio del LMS, facilitando la futura integración con otros sistemas distintos de Moodle.

---

# 5. Vista de Bloques

## 5.1 Nivel 1 — Sistema completo

```text
+-------------------------------------------------------+
|                   SISTEMA MERITCOIN                   |
|                                                       |
|  +------------------+     +----------------------+   |
|  |  Moodle (LMS)    |     |  Backend FastAPI     |   |
|  |  + Plugin PHP    +---->+  (Docker container)  |   |
|  |  + MariaDB       |     |  + PostgreSQL        |   |
|  +------------------+     +----------+-----------+   |
|          |                            |               |
|          |                 +----------+----------+    |
|   Páginas públicas         | Blockchain (Besu)   |    |
|   badge_verify.php         | ERC-1155 + ERC-20   |    |
|   badge_pdf.php            +---------------------+    |
+-------------------------------------------------------+
```

## 5.2 Nivel 2 — Plugin Moodle (caja blanca)

| Componente | Responsabilidad |
|------------|-----------------|
| `observer.php` | Escucha `mod_completed` y `grade_item_updated`; filtra `itemtype=mod`; genera `event_id` MD5 deterministico; verifica límite MRT por estudiante/curso antes de encolar |
| `rules_service.php` | Resuelve reglas con prioridad: `activity` > `activity_type` > `course`; aplica `min_grade` si está configurado |
| `send_events_task.php` | Tarea programada (Moodle Task API) que envía eventos `pending` al backend vía `file_get_contents` + HMAC |
| `api_client.php` | Encapsula la comunicación HTTP con el backend; genera firma HMAC-SHA256 |
| `manage.php` + `editrule.php` | UI del profesor para CRUD de reglas por curso |
| `rule_form.php` | Formulario Moodle (Form API) para creación/edición de reglas; incluye dropdown dinámico de módulos del curso |
| `dashboard.php` | UI del estudiante: saldo MRT real, historial de eventos, grid de insignias con modal de detalle; incluye fallback `onerror` para imágenes rotas y forzado de colores explícitos en el modal para evitar conflictos con dark mode del tema |
| `rewards.php` | UI del profesor para crear/gestionar recompensas canjeables del curso |
| `marketplace.php` | UI del estudiante para consultar y canjear recompensas; valida saldo por curso (earnings - spend) vs. saldo real del contrato |
| `teacher_transactions.php` | Vista del profesor: monedas otorgadas y canjes del curso; KPIs; filtrable por estudiante |
| `admin_marketplace.php` | Panel admin: KPIs globales, recompensas, canjes, pestaña "Todas las transacciones" filtrable por curso y estudiante |
| `badge_verify.php` | Página pública standalone (sin login): muestra datos de la insignia por hash, sello de institución, timestamp de emisión; hash de verificación colapsable vía JS puro (compatible BS4/BS5) |
| `badge_pdf.php` | Página pública standalone: certificado HTML/CSS imprimible en A4; incluye firma cursiva del emisor, franja de color por tipo de insignia, botón `window.print()`; FontAwesome cargado al final para no bloquear render |
| `lib.php` | Hooks de navegación: menú global y navegación de curso por rol (estudiante/profesor/admin) |
| `settings.php` | Configuración de administrador: URL backend, HMAC secret, límite MRT por estudiante/curso; registro de páginas externas admin |

## 5.3 Nivel 2 — Backend FastAPI (caja blanca)

| Componente | Responsabilidad |
|------------|-----------------|
| `api/events.py` | Endpoint `POST /events/ingest`: valida HMAC, delega a `events_service` |
| `api/students.py` | Endpoints de consulta: `/balance`, `/badges`, `/summary` |
| `services/events_service.py` | Orquesta el flujo: idempotencia → badges → tokens → audit |
| `services/blockchain.py` | Wrapper web3.py: conecta a `meritcoin-besu:8545`, llama `mintBadge`, `mint` y `burn` |
| `services/badges_service.py` | Genera metadatos Open Badges v2 y simula pin IPFS |
| `services/tokens_service.py` | Calcula y llama mint de tokens ERC-20 |
| `services/audit_service.py` | Registra resultado final en PostgreSQL |
| `core/config.py` | Lee variables de entorno vía pydantic-settings |
| `core/security.py` | Verifica firma HMAC-SHA256 de las peticiones entrantes |

## 5.4 Nivel 2 — Contratos Solidity (caja blanca)

| Contrato | Estándar | Funciones clave |
|----------|----------|-----------------|
| `MeritBadges1155.sol` | ERC-1155 | `mintBadge(address, tokenId, uri)` — emite una insignia única por logro |
| `MeritCoinERC20.sol` | ERC-20 | `mint(address, amount)`, `burn(address, amount)` — acuña o quema tokens MRT |

Ambos contratos heredan `AccessControl` (roles `ISSUER_ROLE`, `MINTER_ROLE`) y `Pausable` de OpenZeppelin 5.x.

---

# 6. Vista de Ejecución

## 6.1 Escenario principal — Evento de completación/calificación de actividad

```text
Moodle      Observer      rules_service    Queue(MariaDB)   Task          Backend         Blockchain
  |             |               |                |             |              |                |
  |--grade_item_updated-------->|                |             |              |                |
  |             |--itemtype=mod?|                |             |              |                |
  |             |--resolve_rules(courseid, userid, cmid, modtype, grade)      |                |
  |             |<----------coins_amount---------|             |              |                |
  |             |--check_MRT_limit(courseid, userid, total+coins)             |                |
  |             |  (si excede, descarta evento)  |             |              |                |
  |             |--insert(event_id_MD5, coins, pending)------->|              |                |
  |             |                                |             |              |                |
  |  (scheduler cada 1 min)                      |<--poll------|              |                |
  |             |                                |---events--->|              |                |
  |             |                                |             |--POST /events/ingest+HMAC---> |
  |             |                                |             |              |--verify HMAC   |
  |             |                                |             |              |--idempotency?  |
  |             |                                |             |              |--mintBadge()--->
  |             |                                |             |              |--mint(MRT)----->
  |             |                                |             |              |<--txHash--------|
  |             |                                |             |              |--audit_log     |
  |             |                                |             |<--200 OK-----|                |
  |             |                                |--update(processed)-------->|                |
  |             |                                |--insert(earnings: +coins)->|                |
```

## 6.2 Escenario — Canje en el marketplace

```text
Estudiante    marketplace.php     api_client.php    Backend              Blockchain
    |               |                   |               |                     |
    |--ver mercado->|                   |               |                     |
    |               |--GET /summary(wallet)------------>|                     |
    |               |                   |               |--balanceOf(wallet)-->
    |               |                   |               |<--balance real-------|
    |               |<--balance + badges|               |                     |
    |               |--calcular saldo disponible        |                     |
    |               |  (earnings - spend del curso)     |                     |
    |               |--mostrar recompensas canjeables-->|                     |
    |--canjear----->|                   |               |                     |
    |               |--validar saldo y stock            |                     |
    |               |--insert(redemption)               |                     |
    |               |--insert(spend: +precio)           |                     |
    |               |--OK: confirmación al estudiante   |                     |
```

## 6.3 Escenario — Visualización y descarga de insignia

```text
Estudiante        dashboard.php          badge_verify.php       badge_pdf.php
    |                   |                       |                      |
    |--ver dashboard--->|                       |                      |
    |                   |--query local_meritcoin_badges                |
    |                   |--render grid de tarjetas (flex wrap)         |
    |--click tarjeta--->|                       |                      |
    |                   |--JS: abrir modal con datos JSON              |
    |                   |  (imagen con onerror fallback al ícono)      |
    |--click verificar->|                       |                      |
    |                   |--redirect hash------->|                      |
    |                   |                       |--query BD por hash   |
    |                   |                       |--render datos + sello|
    |                   |                       |--toggle hash (JS puro)|
    |--click PDF------->|                       |                      |
    |                   |--redirect hash------------------------>|     |
    |                   |                       |                |--render certificado A4
    |                   |                       |                |--firma cursiva emisor
    |                   |                       |                |--window.print()
```

## 6.4 Escenario de idempotencia — Evento duplicado

Si el backend recibe un `event_id` que ya existe en `audit_log`, retorna `200 OK` con `"Evento ya fue procesado anteriormente"` sin volver a llamar a la blockchain. El plugin marca el evento como `processed` igualmente.

## 6.5 Escenario de wallet no registrada

Si el estudiante no tiene wallet configurada en su perfil Moodle, el observer encola el evento con estado `pending_wallet`. La tarea programada ignora estos eventos hasta que el estudiante registre su wallet.

---

# 7. Vista de Despliegue

## 7.1 Entorno de desarrollo (actual)

```text
Máquina host (Windows/Mac/Linux)
├── Docker Desktop
│   ├── meritcoin-backend     (FastAPI, puerto 8000)
│   ├── meritcoin-moodle      (Moodle 4.3, puertos 8080/8443)
│   │   └── Volumen: ./plugin → /bitnami/moodle/local/meritcoin
│   ├── meritcoin-postgres    (PostgreSQL 16, puerto 5432)
│   ├── meritcoin-mariadb     (MariaDB 10.11, puerto 3306)
│   └── meritcoin-besu        (Hyperledger Besu, puerto 8545)
│
└── Herramientas locales
    └── Hardhat CLI para compilar y desplegar contratos a la red Besu
```

**Comunicación entre servicios:** los contenedores usan `http://meritcoin-besu:8545` como `BLOCKCHAIN_RPC_URL` en `backend/.env`.

**Nota crítica sobre el volumen del plugin:** la línea `- ./plugin:/bitnami/moodle/local/meritcoin` en `docker-compose.yml` debe estar descomentada. Si se comenta y se reinicia el servicio, el plugin desaparece de Moodle. Para restaurarlo: descomentar la línea y ejecutar `docker compose up -d --force-recreate moodle`.

## 7.2 Entorno objetivo — SAVIO (producción)

```text
Servidor UTB
├── SAVIO (Moodle institucional)
│   └── Plugin local_meritcoin instalado vía zip o directorio
│
├── Backend FastAPI
│   └── Apuntando a nodo Besu institucional
│
└── Nodo Hyperledger Besu
    └── Red privada EVM de la UTB (génesis y config basadas en /besu)
```

En SAVIO, `BLOCKCHAIN_RPC_URL` apuntará al nodo Besu institucional. El resto de la arquitectura no cambia; únicamente se ajustan variables de entorno y los templates visuales del plugin.

---

# 8. Conceptos Transversales

## 8.1 Seguridad

- **HMAC-SHA256:** cada petición del plugin al backend incluye una firma calculada con `HMAC_SECRET` compartido. El backend rechaza con `401` cualquier petición con firma inválida.
- **Roles en contratos:** `ISSUER_ROLE` controla `mintBadge`; `MINTER_ROLE` controla `mint`. Solo el deployer del backend tiene estos roles asignados.
- **Pausable:** ambos contratos pueden pausarse ante incidentes sin necesidad de redespliegue.
- **sesskey:** todas las acciones de escritura del plugin requieren `require_sesskey()` de Moodle para prevenir CSRF.
- **Sin datos personales on-chain:** solo wallets e IDs ofuscados viajan a la blockchain.
- **Páginas públicas sin datos sensibles:** `badge_verify.php` y `badge_pdf.php` solo leen datos de `local_meritcoin_badges` y nunca exponen claves, wallets completas ni datos personales más allá del nombre del estudiante.

## 8.2 Idempotencia

El campo `event_id` en `audit_log` (PostgreSQL) tiene índice único. Si el backend intenta insertar un `event_id` duplicado, la BD lanza una excepción que el servicio captura y convierte en respuesta `200` sin reintentar la transacción blockchain. El observer genera un `event_id` deterministico (MD5 de userid+cmid+grade), evitando duplicados desde el origen.

## 8.3 Trazabilidad en tres capas

| Capa | Almacén | Qué registra |
|------|---------|--------------|
| Plugin (Moodle) | `local_meritcoin_queue` | Estado del evento: pending / pending_wallet → processed / failed |
| Plugin (Moodle) | `local_meritcoin_earnings` | Ledger de monedas ganadas por usuario y curso |
| Plugin (Moodle) | `local_meritcoin_spend` | Ledger de monedas gastadas en canjes por usuario y curso |
| Plugin (Moodle) | `local_meritcoin_badges` | Registro de insignias emitidas con hash de verificación, imagen y metadatos |
| Backend (off-chain) | PostgreSQL `audit_log` | event_id, wallet, txHash badge, txHash MRT, CID IPFS, timestamp |
| Blockchain (on-chain) | EVM | Transacciones inmutables de `mintBadge` y `mint` |

## 8.4 Metadatos Open Badges v2 (OBv2)

Cada insignia emitida lleva metadatos conformes al estándar OBv2:
- `name`, `description`, `image` del curso/actividad
- `recipient` (wallet del estudiante, ofuscado con hash SHA-256)
- `issuedOn` (timestamp del evento)
- `verification` (tipo blockchain + dirección del contrato)

El CID IPFS actual es simulado (`QmSimulated...`). En producción se sustituirá por un pin real a nodo IPFS o Pinata.

## 8.5 Ledger de saldo por curso

El saldo MRT gastable de un estudiante en un curso se calcula en el plugin como:

```text
saldo_disponible = SUM(local_meritcoin_earnings.coins_earned WHERE userid, courseid)
                 - SUM(local_meritcoin_spend.coins_spent WHERE userid, courseid)
```

Esta lógica es independiente por curso. El marketplace valida adicionalmente que `saldo_disponible ≥ precio_recompensa` **y** que el balance real del contrato ERC-20 sea también suficiente antes de aprobar el canje.

## 8.6 Sistema de reglas jerárquico

| Prioridad | Scope | Descripción |
|-----------|-------|-------------|
| 1 (mayor) | `activity` | Aplica a una actividad específica (cmid concreto) |
| 2 | `activity_type` | Aplica a todos los módulos de un tipo (assign, quiz, forum, etc.) |
| 3 (menor) | `course` | Aplica al completar el curso entero |

Cada regla puede tener un campo `min_grade` opcional: si el evento incluye una calificación inferior al umbral, el evento se descarta sin encolar.

## 8.7 Límite de MRT por estudiante por curso

El observer verifica que el total de MRT ya recibidos por el estudiante en ese curso no supere el límite configurado (`teacher_weekly_limit`, visible en la UI como *Student MRT limit per course*). El valor por defecto es **16 MRT por curso y estudiante**. El límite es acumulado (no semanal); el consumo en marketplace no libera cupo.

## 8.8 Sistema de insignias locales y verificación pública

Las insignias se almacenan en `local_meritcoin_badges` con un `verify_hash` único (SHA-256 o token aleatorio). El flujo completo de una insignia es:

1. **Emisión:** el backend emite la insignia on-chain (`mintBadge`) y el plugin inserta el registro en `local_meritcoin_badges`.
2. **Visualización:** el dashboard muestra las insignias en un grid de tarjetas `flex-wrap` con ancho fijo de 150px. Cada tarjeta tiene un `onerror` handler que sustituye la imagen por un ícono FontAwesome si la URL falla.
3. **Modal de detalle:** al hacer clic en la tarjeta se abre un modal Bootstrap con colores explícitos (`!important`) para garantizar legibilidad independientemente del dark mode del tema de Moodle.
4. **Verificación pública:** `badge_verify.php?hash=<hash>` es accesible sin autenticación. Muestra nombre del estudiante, curso, emisor, fecha y sello institucional. El hash técnico está colapsado por defecto y se expande con JS puro (sin `data-bs-toggle`, compatible con BS4 y BS5).
5. **Certificado PDF:** `badge_pdf.php?hash=<hash>` genera una página HTML A4 standalone con la firma cursiva del emisor (nombre en Playfair Display italic), franja de color por tipo de insignia y botón `window.print()`.

## 8.9 Compatibilidad Bootstrap 4/5 en páginas del plugin

El plugin debe funcionar tanto en temas Moodle que usan Bootstrap 4 como Bootstrap 5. Las reglas aplicadas son:

- **No usar `data-bs-toggle`** en páginas donde el layout Moodle no está garantizado → usar JS puro con `addEventListener`.
- **No usar `data-toggle`** de BS4 en páginas standalone → ídem.
- **Forzar colores en el modal** con `!important` para que el `prefers-color-scheme: dark` del SO no invierta el fondo del modal cuando el tema de Moodle no lo espera.

---

# 9. Decisiones de Arquitectura (ADR)

## ADR-001: Cálculo de monedas en el plugin, no en el backend

**Contexto:** Las reglas de recompensa son configuradas por el profesor en Moodle.

**Decisión:** El plugin resuelve las reglas y envía `coins_amount` ya calculado al backend.

**Consecuencias:**
- (+) El backend es agnóstico a las reglas de Moodle; puede integrarse con otros LMS.
- (+) Las reglas se pueden cambiar sin redeploy del backend.
- (-) El backend confía en el valor de monedas que envía el plugin; no lo valida de forma independiente.

## ADR-002: Comunicación asíncrona Moodle → Backend vía cola interna

**Contexto:** Los eventos de Moodle ocurren en tiempo real durante la sesión del usuario.

**Decisión:** El observer encola el evento en MariaDB inmediatamente y la tarea programada lo procesa de forma asíncrona cada minuto.

**Consecuencias:**
- (+) El usuario no experimenta latencia de la blockchain durante su sesión.
- (+) Si el backend no está disponible, los eventos quedan en cola para reintentar.
- (-) Existe un retardo entre el logro académico y la emisión on-chain (máx. 1 minuto).

## ADR-003: Doble base de datos (MariaDB + PostgreSQL)

**Contexto:** Moodle usa MariaDB de forma nativa; el backend necesita su propia BD.

**Decisión:** MariaDB exclusivamente para datos del plugin Moodle. PostgreSQL exclusivamente para el audit_log del backend.

**Consecuencias:**
- (+) Separación de responsabilidades; el backend puede funcionar sin acceso a MariaDB.
- (+) PostgreSQL ofrece mejor soporte para queries analíticas.
- (-) Dos motores de BD en el stack aumentan la complejidad operacional.

## ADR-004: IPFS simulado en desarrollo

**Contexto:** Integrar un nodo IPFS real añade complejidad al entorno de desarrollo.

**Decisión:** El `badges_service` genera un CID simulado (`QmSimulated...`) en lugar de hacer un pin real.

**Consecuencias:**
- (+) El entorno de desarrollo es más simple y reproducible.
- (-) Los metadatos OBv2 no son accesibles públicamente en desarrollo.

## ADR-005: file_get_contents en lugar de cURL para HTTP desde el plugin

**Contexto:** El contenedor Bitnami Moodle tiene la extensión cURL deshabilitada por defecto.

**Decisión:** Reemplazar todas las llamadas `curl_*` en `api_client.php` por `file_get_contents` con `stream_context_create`.

**Consecuencias:**
- (+) Compatible con el entorno Docker de Bitnami sin configuración adicional.
- (-) `file_get_contents` no ofrece control de timeout tan granular como cURL.

## ADR-006: Saldo del marketplace basado en ledger local + validación del contrato

**Decisión:** El saldo gastable se calcula como `earnings - spend` por curso. El marketplace también consulta el balance real del contrato ERC-20 al backend para validar fondos.

**Consecuencias:**
- (+) Saldo independiente por curso.
- (+) Se evita aceptar canjes si el minteo on-chain falló silenciosamente.
- (-) Requiere una llamada extra al backend en cada carga del marketplace.

## ADR-007: event_id deterministico (MD5) para idempotencia desde el origen

**Decisión:** El `event_id` se calcula como `MD5(userid + cmid + grade)` en el observer. Se usa `record_exists()` para descartar silenciosamente el evento si ya existe.

**Consecuencias:**
- (+) Idempotencia garantizada desde el origen + doble protección en el backend.
- (-) Si el mismo estudiante obtiene la misma nota en la misma actividad en dos momentos distintos, el segundo evento es descartado (caso aceptado como trade-off).

## ADR-008: Hyperledger Besu como nodo EVM de staging y producción

**Decisión:** Integrar Besu como nodo EVM definitivo. Hardhat se mantiene solo como herramienta de compilación y despliegue.

**Consecuencias:**
- (+) EVM-compatible: los contratos funcionan sin cambios sobre Besu.
- (+) Redes privadas y permisionadas (IBFT 2.0), adecuadas para UTB.
- (-) Requiere Java 21+ en el host; tiempo de bloque mayor que Hardhat en modo instantáneo.

## ADR-009: Páginas standalone sin layout Moodle para verificación y PDF

**Contexto:** `badge_verify.php` y `badge_pdf.php` deben ser accesibles públicamente (sin login) y deben funcionar correctamente al imprimir/guardar como PDF.

**Decisión:** Ambas páginas no llaman a `$OUTPUT->header()` ni `$OUTPUT->footer()`. Generan HTML completo autónomo con CSS inline y cargan FontAwesome desde CDN. El PDF usa `window.print()` con `@media print` que oculta los botones de acción.

**Consecuencias:**
- (+) Verificación y descarga accesible sin cuenta Moodle.
- (+) El PDF no depende de librerías PHP como TCPDF o mPDF.
- (-) Los estilos no heredan del tema Moodle activo; cualquier actualización de marca requiere editar el CSS inline de ambas páginas.

## ADR-010: JavaScript puro para interactividad en páginas del plugin

**Contexto:** El plugin debe ser compatible con temas Moodle que usen Bootstrap 4 (`data-toggle`) o Bootstrap 5 (`data-bs-toggle`). Usar atributos HTML de Bootstrap implica depender de la versión cargada por el tema.

**Decisión:** Todos los toggles, modales y comportamientos interactivos del plugin usan `addEventListener` y manipulación directa del DOM, sin depender de los atributos HTML de Bootstrap ni de jQuery (aunque se soporta jQuery como fallback para el modal donde Bootstrap está disponible).

**Consecuencias:**
- (+) Compatible con cualquier tema Moodle independiente de la versión de Bootstrap.
- (+) No hay errores silenciosos por versión incorrecta de Bootstrap.
- (-) Más código JS por mantener en el plugin.

---

# 10. Esquema de Base de Datos

## 10.1 MariaDB — Plugin Moodle (v0.6.0)

| Tabla | Columnas clave | Propósito |
|-------|---------------|-----------|
| `local_meritcoin_queue` | userid, courseid, cmid, activity_name, event_id, coins_amount, status, wallet | Cola de eventos pendientes |
| `local_meritcoin_rules` | courseid, cmid, rule_scope, mod_type, min_grade, coins_amount, enabled | Reglas de recompensa por curso |
| `local_meritcoin_earnings` | userid, courseid, coins_earned | Ledger de monedas ganadas por curso |
| `local_meritcoin_spend` | userid, courseid, coins_spent | Ledger de monedas gastadas por curso |
| `local_meritcoin_course_config` | courseid, coin_name, coin_symbol, contract_address | Config por curso |
| `local_meritcoin_rewards` | courseid, name, description, price, stock, enabled | Recompensas creadas por el profesor |
| `local_meritcoin_redemptions` | userid, courseid, rewardid, coins_spent, timecreated | Historial de canjes |
| `local_meritcoin_badges` | userid, courseid, badge_name, badge_type, image_url, description, criteria, issued_by, verify_hash, timecreated | Insignias emitidas con metadatos completos y hash de verificación único |
| `local_meritcoin_badge_types` | shortname, name, color, icon | Tipos de insignia (color e ícono por tipo) |

## 10.2 PostgreSQL — Backend (audit)

| Tabla | Columnas clave | Propósito |
|-------|---------------|-----------|
| `events` | event_id (unique), student_wallet, coins_amount, badge_tx, mrt_tx, ipfs_cid, processed_at | Audit log de eventos procesados |
| `audit_log` | event_id, action, detail, created_at | Log detallado de operaciones |

---

# 11. Riesgos y Deuda Técnica

| ID | Tipo | Descripción | Impacto | Plan de mitigación |
|----|------|-------------|---------|-------------------|
| R-01 | **Riesgo** | IPFS simulado en producción invalida la verificabilidad de las insignias OBv2 | Alto | Integrar Pinata o nodo IPFS propio antes del despliegue en SAVIO |
| R-02 | **Riesgo** | Clave privada del deployer en `.env`; si se filtra, un atacante puede mintear tokens arbitrariamente | Alto | Usar HSM o cuenta multisig con Gnosis Safe en producción |
| R-03 | **Deuda técnica** | Los tests de backend (pytest) no cubren todos los flujos nuevos con `rules_service` y marketplace | Medio | Revisar y actualizar en la siguiente iteración |
| R-04 | **Riesgo** | La configuración de Besu en desarrollo puede diferir de la institucional si no se valida el génesis y los parámetros de consenso | Medio | Probar en staging con la configuración definitiva antes de desplegar en SAVIO |
| R-05 | **Deuda técnica** | `file_get_contents` no permite timeout granular para llamadas al backend | Bajo | Evaluar habilitar cURL en el contenedor Bitnami o usar Guzzle |
| R-06 | **Deuda técnica** | No existe mecanismo de reintentos explícito para eventos `failed` en la cola | Bajo | Implementar lógica de retry con backoff en `send_events_task.php` |
| R-07 | **Deuda técnica** | Los ajustes visuales finales para SAVIO aún no están implementados por completo | Medio | Completar en la fase de despliegue institucional |
| R-08 | **Riesgo** | El volumen del plugin en docker-compose puede quedar comentado accidentalmente al reiniciar Docker | Bajo | Documentado en README; considerar healthcheck que valide el mount |
| R-09 | **Deuda técnica** | La key `teacher_weekly_limit` limita realmente al estudiante por curso, no al teacher; solo se corrigieron los labels en la UI | Bajo | Renombrar la key en una migración futura de `upgrade.php` |
| R-10 | **Deuda técnica** | Los estilos de `badge_verify.php` y `badge_pdf.php` son CSS inline; cualquier cambio de marca requiere editar ambas páginas manualmente | Bajo | Extraer a un archivo CSS compartido `styles/public.css` en una iteración futura |
| R-11 | **Deuda técnica** | El dark mode del tema Moodle puede seguir afectando otros componentes del plugin no cubiertos por los `!important` añadidos en v0.6.0 | Medio | Auditar todos los componentes con `prefers-color-scheme: dark` activo antes del despliegue en SAVIO |

---

# 12. Estado del Proyecto

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
| 9 | Insignias personalizadas con verificación pública y PDF de certificado | ✅ Completa |
| 10 | Integración Hyperledger Besu (red privada EVM, génesis y validación E2E) | ✅ Completa |
| 11 | Finalizacion del MVP | ✅ Completa |

---

*Documento actualizado en Mayo 2026 — MeritCoin v0.6.0 — Universidad Tecnológica de Bolívar*
