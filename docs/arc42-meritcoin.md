# Documento de Arquitectura del Software — MeritCoin

**Formato:** ARC42 — Versión 1.0
**Proyecto:** MeritCoin (MRT) — Sistema de Recompensas Académicas Digitales
**Institución:** Universidad Tecnológica de Bolívar (UTB)
**Autores:** Alfonso David Grateron Cabarcas, Alejandro Sanchez Diaz
**Fecha:** Mayo de 2026
**Estado del sistema:** MVP funcional, validado de extremo a extremo en entorno Docker con red privada Hyperledger Besu (QBFT, 4 nodos).
**Rama principal de desarrollo:** `main`

---

## 1. Introducción y Objetivos

### 1.1 Propósito

MeritCoin es una plataforma híbrida *off-chain / on-chain* que integra el entorno virtual de aprendizaje **Moodle 4.3** con una red privada **Ethereum Virtual Machine (EVM)** basada en **Hyperledger Besu**, con el fin de reconocer logros académicos de los estudiantes mediante:

1. Un **token fungible ERC-20** denominado **MeritCoin (MRT)**, usado como moneda de recompensa académica.
2. Una **insignia digital no fungible ERC-1155** denominada **MeritBadge**, asociada a logros concretos y verificable públicamente.

El sistema implementa un puente confiable entre los procesos académicos tradicionales (calificaciones, completación de actividades) y un registro inmutable on-chain, con auditoría en tres capas (cola Moodle, log PostgreSQL del backend, blockchain).

### 1.2 Alcance

El alcance del MVP incluye:

- Captura automática de eventos académicos en Moodle (calificación de actividades).
- Configuración por parte del profesor de **reglas de recompensa** jerárquicas: por actividad, por tipo de módulo o por curso.
- Emisión automática de insignias (ERC-1155) y tokens MRT (ERC-20) al cumplirse las reglas, sobre la red privada Besu.
- **Wallets custodiales** generadas y administradas por el backend, con cifrado simétrico de las claves privadas.
- **Dashboard del estudiante** con saldo, historial e insignias obtenidas.
- **Marketplace de recompensas** por curso, con quema (burn) de MRT al canjear.
- **Verificación pública** de insignias por hash y **certificado PDF** descargable, sin requerir autenticación.
- Trazabilidad y auditoría completas de los flujos.

Quedan **fuera del alcance** del MVP actual: integración nativa con SAVIO —plataforma institucional de la Universidad Tecnológica de Bolívar—, el pinning real en IPFS mediante servicios como Pinata o web3.storage para garantizar la persistencia permanente de los metadatos de insignias, el hardening de seguridad con rotación de claves y gestión centralizada de secretos, la separación de ambientes (dev/stage/prod) con orquestación en Kubernetes, y la incorporación de observabilidad mediante paneles Prometheus/Grafana con alertas sobre fallos en cola, backend o red Besu.

### 1.3 Problema que resuelve

Los reconocimientos académicos tradicionales (notas numéricas, certificados en PDF, registros internos del LMS) presentan limitaciones que MeritCoin aborda:

| Problema | Forma en que MeritCoin lo aborda |
|----------|----------------------------------|
| Las calificaciones del LMS no son fácilmente verificables por terceros sin acceso a la plataforma. | Cada insignia queda registrada on-chain con un `tokenId` único y una página pública de verificación por hash. |
| Los certificados PDF son fácilmente falsificables. | Cada certificado PDF está vinculado a un `verify_hash` único y a una transacción on-chain trazable. |
| Los esquemas de gamificación tradicionales viven solo dentro del LMS y se pierden al cerrar el semestre. | El saldo MRT y las insignias persisten en la blockchain institucional independientemente del ciclo académico. |
| Los profesores no tienen control granular sobre qué actividades premiar. | Sistema de reglas jerárquicas configurable por curso, tipo de módulo o actividad. |
| La emisión manual de insignias no escala. | Emisión automática orquestada por el plugin + backend al cumplirse las reglas. |

### 1.4 Objetivos del proyecto

**Objetivo general.** Diseñar, implementar y validar un sistema híbrido que registre logros académicos como activos digitales verificables, integrado de manera no intrusiva con la plataforma Moodle de la UTB y con una red EVM privada institucional.

**Objetivos específicos.**

1. Implementar **contratos inteligentes auditables** que cumplan los estándares ERC-20 y ERC-1155, con control de acceso por roles y capacidad de pausa.
2. Construir un **backend off-chain** que orqueste la verificación HMAC, idempotencia, emisión on-chain y persistencia de auditoría.
3. Desarrollar un **plugin local de Moodle** que capture eventos, gestione reglas, mantenga ledgers de ganancias y gastos por curso, y exponga UIs para estudiantes, profesores y administradores.
4. Garantizar **trazabilidad extremo a extremo** mediante tres capas independientes de registro (Moodle, PostgreSQL, blockchain).
5. Demostrar **viabilidad técnica** mediante pruebas E2E sobre red Besu privada con consenso QBFT (4 nodos).
6. Dejar bases claras para una futura **integración con SAVIO**, la instancia institucional de Moodle de la UTB.

### 1.5 Objetivos de calidad

| Prioridad | Atributo | Descripción y métrica asociada |
|-----------|----------|-------------------------------|
| 1 | **Integridad** | Cada evento académico debe producir exactamente una emisión on-chain. Idempotencia garantizada por `event_id` determinístico (MD5 de `userid+courseid+cmid+type`). |
| 2 | **Trazabilidad** | Todo evento queda registrado en tres capas independientes: cola Moodle, audit log PostgreSQL y blockchain. |
| 3 | **Seguridad** | Comunicación Moodle → Backend firmada con HMAC-SHA256. Wallets custodiales cifradas con Fernet (AES-128-CBC). Roles `ISSUER_ROLE`, `MINTER_ROLE` y `BURNER_ROLE` en los contratos. Contratos `Pausable`. |
| 4 | **Privacidad** | Ningún dato personal viaja a la blockchain. Solo wallets e IDs ofuscados (`STU-{id}`, `COURSE-{id}`). Cumplimiento de la **Ley 1581 de 2012** (Colombia). |
| 5 | **Auditabilidad** | Cada operación de mint/burn queda registrada con `txHash`, `event_id` y timestamp; los registros son reproducibles offline. |
| 6 | **Operabilidad** | Cambio de nodo EVM mediante una sola variable (`BLOCKCHAIN_RPC_URL`). Detección automática del cliente vía `web3_clientVersion`. |
| 7 | **Extensibilidad** | Cálculo de monedas centralizado en el plugin → el backend es agnóstico al LMS; se podría reemplazar Moodle por otro LMS conservando contratos y backend. |
| 8 | **Mantenibilidad** | Separación estricta en capas. Cobertura de pruebas: 19 unitarias Solidity (Hardhat + Chai), 24 unitarias Python (pytest + httpx), 18 E2E. Total 61 pruebas verdes. |
| 9 | **Usabilidad visual** | Dashboard y modal de insignias compatibles con Bootstrap 4 y 5, con manejo explícito de modo claro/oscuro y fallback `onerror` para imágenes rotas. |

---

## 2. Definiciones y Términos

| Término | Definición operacional dentro de MeritCoin |
|---------|-------------------------------------------|
| **MRT** | MeritCoin Token. Token fungible ERC-20 con 18 decimales, símbolo `MRT`. |
| **MeritBadge** | Insignia digital ERC-1155 emitida por logro, con `tokenId` único por par `(wallet, badgeId)`. |
| **Off-chain** | Componentes que no viven en la blockchain: plugin Moodle, backend FastAPI, MariaDB, PostgreSQL. |
| **On-chain** | Estado persistente en la blockchain Besu: balances ERC-20, propietarios de ERC-1155, URIs de metadatos. |
| **HMAC** | Hash-based Message Authentication Code. En MeritCoin, HMAC-SHA256 con `HMAC_SECRET` compartido entre plugin y backend. |
| **OBv2** | Open Badges v2. Estándar abierto del IMS Global para insignias digitales verificables. |
| **CID** | Content Identifier de IPFS. En el MVP actual es simulado (`QmSimulated...`). |
| **QBFT** | Quorum Byzantine Fault Tolerance. Algoritmo de consenso de Hyperledger Besu para redes permisionadas. |
| **Wallet custodial** | Cuenta Ethereum cuya clave privada es generada y custodiada por el backend (cifrada con Fernet), no por el usuario. |
| **Event ID** | Identificador determinístico de cada evento académico: `evt-md5("moodle-{userid}-{courseid}-{cmid}-{type}")`. Base de la idempotencia. |
| **Earnings / Spend** | Tablas en MariaDB con el ledger off-chain de monedas ganadas y gastadas por curso. |
| **Saldo gastable por curso** | `SUM(earnings.coins_earned) − SUM(spend.coins_spent)` por `(userid, courseid)`. |
| **Pilot course** | Curso marcado explícitamente como piloto MeritCoin, donde se generan wallets custodiales automáticamente. |
| **Revocación** | Operación administrativa que invalida una emisión manual (`BadgeAward.revoked = true`); el certificado PDF deja de generarse. |
| **Verify hash** | Hash único asociado a una insignia local del plugin, usado por `badge_verify.php` para mostrar la insignia sin autenticación. |
| **SAVIO** | Instancia institucional de Moodle de la UTB; entorno objetivo de despliegue futuro. |
| **ADR** | Architecture Decision Record. Registro fechado de una decisión arquitectónica relevante. |

---

## 3. Descripción General del Sistema

MeritCoin se compone de cuatro grandes bloques desplegados en contenedores Docker:

1. **Capa de captura (Moodle + Plugin `local_meritcoin`).** Captura eventos académicos (`user_graded`), aplica reglas configuradas por el profesor, calcula `coins_amount` y encola el evento firmado.
2. **Capa de procesamiento off-chain (FastAPI + PostgreSQL).** Recibe eventos firmados con HMAC, garantiza idempotencia, gestiona wallets custodiales, orquesta llamadas a los contratos vía `web3.py` y registra auditoría.
3. **Capa de registro on-chain (Contratos Solidity + Besu).** Red privada Hyperledger Besu de 4 nodos en consenso QBFT que ejecuta `MeritBadges1155` y `MeritCoinERC20`.
4. **Capa de presentación pública (páginas standalone PHP).** `badge_verify.php` y `badge_pdf.php` permiten que cualquier persona verifique una insignia o descargue su certificado sin autenticación en Moodle.

El sistema opera como una extensión natural del LMS: el profesor configura las reglas dentro de Moodle, los estudiantes ven sus saldos e insignias en su dashboard de Moodle, y la blockchain queda totalmente oculta tras una capa de orquestación.

### 3.1 Diagrama de Contexto del Sistema

```
  +----------------+  +----------------+  +-------------------+  +--------------------+
  |  Estudiante    |  |   Profesor     |  |  Admin Moodle     |  | Verificador externo|
  | Completa acts. |  | Configura      |  | Configura plugin, |  | Consulta hash,     |
  | ve dashboard,  |  | reglas, crea   |  | ve KPIs globales  |  | descarga PDF       |
  | canjea MRT     |  | recompensas    |  |                   |  |                    |
  +-------+--------+  +-------+--------+  +---------+---------+  +---------+----------+
          |                   |                      |                      |
          +----------+--------+                      |           +----------+
                     |                               |           |
                     v                               v           v
  +=========================================================================+
  |                         SISTEMA MERITCOIN                               |
  |                                                                         |
  |  +-------------------------------------------------------------------+  |
  |  | Moodle 4.3  +  Plugin local_meritcoin                             |  |
  |  | (captura eventos académicos, reglas, marketplace, dashboards)     |  |
  |  +----------------------------------+--------------------------------+  |
  |                                     |                                   |
  |                           HTTPS + HMAC-SHA256                           |
  |                                     |                                   |
  |                                     v                                   |
  |  +----------------------------------+--------------------------------+  |
  |  | Backend FastAPI                                                   |  |
  |  | (orquestación, idempotencia, auditoría, wallets custodiales)      |  |
  |  +----------------------------------+--------------------------------+  |
  |                                     |                                   |
  |                     JSON-RPC web3.py  /  consultas balanceOf            |
  |                                     |                                   |
  |                                     v                                   |
  |  +----------------------------------+--------------------------------+  |
  |  | Red Hyperledger Besu  QBFT  (4 nodos validadores)                 |  |
  |  | Contratos: MeritCoinERC20  +  MeritBadges1155                     |  |
  |  +-------------------------------------------------------------------+  |
  +=========================================================================+
```

### 3.2 Capacidades funcionales principales

- Emisión automática de tokens MRT al cumplir reglas académicas.
- Emisión automática y manual de insignias ERC-1155.
- Gestión de wallets custodiales por estudiante.
- Marketplace de recompensas con quema controlada de MRT.
- Verificación pública de insignias por hash.
- Generación de certificados PDF imprimibles A4 con firma estilizada.
- Panel administrativo con KPIs y filtrado por curso/estudiante.

---

## 4. Stakeholders

| Stakeholder | Interés / Responsabilidad | Nivel de influencia |
|-------------|---------------------------|---------------------|
| **Estudiante** | Acumula y consulta MRT, insignias y certificados. Canjea recompensas en su curso. | Usuario final principal. |
| **Profesor / Editor de curso** | Configura reglas de recompensa, define recompensas canjeables, consulta reporte de transacciones del curso. | Define la economía local de cada curso. |
| **Administrador Moodle** | Instala/configura el plugin, gestiona credenciales del backend, supervisa KPIs globales, decide límite MRT por estudiante/curso. | Custodio operacional del sistema. |
| **Verificador externo** (empleador, otra institución) | Verifica autenticidad de una insignia mediante su hash o el certificado PDF. | Consumidor del valor de la insignia. |
| **Equipo de desarrollo / mantenimiento** | Evoluciona el plugin, el backend y los contratos. Despliega actualizaciones. | Responsable técnico del MVP. |
| **Equipo de TI institucional (UTB)** | Operará la integración con SAVIO y la red Besu institucional en producción. | Responsable del entorno productivo objetivo. |
| **Director / Jurados del proyecto de grado** | Evalúan la calidad arquitectónica y la viabilidad. | Influencia académica. |
| **Auditor institucional** | Revisa trazabilidad e integridad de las emisiones para cumplimiento normativo (Ley 1581). | Garante del cumplimiento. |

---

## 5. Requerimientos

Los requerimientos están consolidados a partir de la primera versión del SAD del proyecto, junto con la implementación actual del repositorio. Cada requerimiento incluye su **estado de implementación** (Implementado / Parcial / Planificado).

### 5.1 Requerimientos Funcionales (RF)

| ID | Requerimiento | Estado |
|----|---------------|--------|
| RF-01 | Capturar eventos académicos de Moodle (`user_graded`) sobre actividades reales (`itemtype = mod`). | Implementado (`observer.php`). |
| RF-02 | Permitir al profesor definir reglas de recompensa con tres alcances: actividad, tipo de actividad y curso. | Implementado (`rules_service.php`, `manage.php`, `editrule.php`). |
| RF-03 | Calcular el monto MRT asociado a cada evento aplicando una prioridad jerárquica de reglas (actividad > tipo > curso). | Implementado. |
| RF-04 | Permitir definir una nota mínima (`min_grade`) en las reglas; los eventos por debajo se descartan. | Implementado. |
| RF-05 | Imponer un **límite total de MRT por estudiante y curso** (por defecto 16). | Implementado (`student_course_limit`). |
| RF-06 | Encolar eventos firmados con HMAC y enviarlos asíncronamente al backend (cada minuto). | Implementado (`send_events_task.php`). |
| RF-07 | Validar firma HMAC-SHA256 en el backend y rechazar peticiones inválidas (HTTP 401). | Implementado (`core/security.py`). |
| RF-08 | Garantizar idempotencia: un mismo `event_id` se procesa una sola vez. | Implementado (`audit_service.reserve_event`). |
| RF-09 | Emitir tokens MRT mediante `mint(address, amount)` en el contrato ERC-20. | Implementado (`services/blockchain.py`). |
| RF-10 | Emitir insignias mediante `mintBadge(address, tokenId, uri)` en el contrato ERC-1155. | Implementado. |
| RF-11 | Quemar tokens MRT mediante `burnFrom(address, amount)` al canjear en el marketplace. | Implementado (`/tokens/spend`). |
| RF-12 | Provisionar wallets custodiales automáticamente para estudiantes en cursos piloto. | Implementado (`wallet_service.py`). |
| RF-13 | Cifrar las claves privadas custodiales en reposo con Fernet (AES-128-CBC). | Implementado (`core/wallet_crypto.py`). |
| RF-14 | Mantener ledgers off-chain de monedas ganadas (`earnings`) y gastadas (`spend`) por curso. | Implementado. |
| RF-15 | Exponer dashboard del estudiante: saldo real ERC-20, historial e insignias. | Implementado (`dashboard.php`, `/students/{wallet}/summary`). |
| RF-16 | Permitir al profesor crear recompensas y al estudiante canjearlas dentro del marketplace de su curso. | Implementado. |
| RF-17 | Validar al canjear que `saldo_gastable_curso ≥ precio` Y `balance_on_chain ≥ precio`. | Implementado. |
| RF-18 | Permitir emisión manual de insignias con plantillas reutilizables y habilidades asociadas. | Implementado (`/badges/award`). |
| RF-19 | Permitir la revocación de insignias manuales por administradores o emisores autorizados. | Implementado. |
| RF-20 | Verificar públicamente una insignia mediante `badge_verify.php?hash=...` sin autenticación. | Implementado. |
| RF-21 | Generar y descargar certificado PDF imprimible en A4 mediante `badge_pdf.php`. | Implementado. |
| RF-22 | Generar certificado PDF en el backend para insignias manuales (`/badges/award/{id}/certificate`). | Implementado. |
| RF-23 | Permitir al administrador cerrar todos los enrollments de un curso al final del semestre, conservando snapshot de MRT. | Implementado (`/wallets/expire-course`). |
| RF-24 | Soportar reintentos de eventos `failed` (manual mediante CLI de Moodle). | Parcial; reintento automático con backoff planificado. |
| RF-25 | Soporte para meta-transacciones EIP-712 / relayer / paymaster. | Planificado (en SAD; no implementado en MVP). |
| RF-26 | Pinning real de metadatos OBv2 en nodo IPFS. | Planificado (actualmente simulado). |

### 5.2 Requerimientos del Sistema (RS)

| ID | Requerimiento | Implementación |
|----|---------------|----------------|
| RS-01 | El sistema debe operar sobre Docker Compose; cada componente en su propio contenedor. | `docker-compose.yml` orquesta Moodle, MariaDB, Postgres, Backend; Besu corre en su propio compose. |
| RS-02 | La red blockchain debe ser permisionada y privada en el entorno institucional. | Besu QBFT con 4 nodos validadores. |
| RS-03 | El backend debe poder reiniciarse sin pérdida de información. | PostgreSQL en volumen persistente; estado off-chain en BD; balances reales en blockchain. |
| RS-04 | El backend debe ser tolerante a la caída temporal del nodo blockchain al arrancar. | Implementado en `services/blockchain.py` (lazy connect, health check). |
| RS-05 | Los contratos deben ser pausables ante incidentes. | `Pausable` de OpenZeppelin con `DEFAULT_ADMIN_ROLE`. |
| RS-06 | El sistema debe permitir cambiar el nodo EVM cambiando solo `BLOCKCHAIN_RPC_URL`. | Configuración por entorno. |
| RS-07 | El plugin debe ser compatible con Moodle 4.3+. | XMLDB compatible con Moodle Plugin API estándar. |
| RS-08 | El plugin debe ser compatible con temas que usen Bootstrap 4 o 5. | Toggles en JS puro; modal con colores explícitos. |
| RS-09 | El plugin no debe depender de cURL del servidor. | Uso de `file_get_contents` con `stream_context_create`. |
| RS-10 | Las contraseñas y secretos no deben quedar en el código. | `.env` y `WALLET_ENCRYPTION_KEY` obligatorios en arranque. |

### 5.3 Requerimientos de Usuario (RU)

| ID | Como… | Quiero… | Para… |
|----|-------|---------|-------|
| RU-01 | Estudiante | Ver mi saldo MRT actual en mi dashboard de Moodle | Saber cuántas recompensas puedo canjear. |
| RU-02 | Estudiante | Ver mis insignias obtenidas con su descripción | Acreditar mis logros académicos. |
| RU-03 | Estudiante | Descargar un certificado PDF de cada insignia | Compartirlo con terceros (empleadores, otras instituciones). |
| RU-04 | Estudiante | Canjear monedas por recompensas del curso | Recibir beneficios académicos tangibles. |
| RU-05 | Profesor | Definir cuántos MRT vale cada actividad o tipo de actividad de mi curso | Adaptar la economía al diseño pedagógico. |
| RU-06 | Profesor | Crear recompensas canjeables por mis estudiantes | Reforzar la motivación. |
| RU-07 | Profesor | Ver un reporte con todas las transacciones de mi curso | Auditar la economía y detectar anomalías. |
| RU-08 | Administrador | Configurar la URL del backend y el secreto HMAC | Vincular Moodle con el backend institucional. |
| RU-09 | Administrador | Ver KPIs globales (eventos procesados, MRT emitidos, canjes) | Monitorear el sistema. |
| RU-10 | Verificador externo | Confirmar la autenticidad de una insignia mediante un enlace público | Validar credenciales del estudiante sin acceso a Moodle. |

### 5.4 Casos de uso esenciales

Los casos de uso esenciales del sistema se describen en la sección **8. Casos de Uso**, donde se incluyen además diagramas Mermaid.

---

## 6. Consideraciones No Funcionales

| Categoría | Consideración | Estado en el MVP |
|-----------|---------------|------------------|
| **Rendimiento** | Latencia entre evento académico y emisión on-chain. | Hasta ~1 minuto (intervalo de la tarea programada) + tiempo de minado QBFT (~2 s por bloque). |
| **Rendimiento** | Tamaño máximo de la cola de eventos. | Sin límite explícito; índice único sobre `event_id` evita duplicados. |
| **Seguridad** | Firma HMAC-SHA256 obligatoria en `/events/ingest`. | Implementado. |
| **Seguridad** | Cifrado de claves privadas custodiales con Fernet (AES-128-CBC). | Implementado; `WALLET_ENCRYPTION_KEY` obligatoria en arranque. |
| **Seguridad** | Control de acceso por roles en contratos (`ISSUER_ROLE`, `MINTER_ROLE`, `BURNER_ROLE`, `DEFAULT_ADMIN_ROLE`). | Implementado. |
| **Seguridad** | Idempotencia estricta: `event_id` determinístico + índice único en BD. | Implementado en doble capa (plugin + backend). |
| **Seguridad** | Protección CSRF en escrituras del plugin (`require_sesskey()`). | Implementado. |
| **Seguridad** | Capacidad de pausa de los contratos ante incidentes. | Implementado (`Pausable`). |
| **Privacidad** | Ningún dato personal viaja a la blockchain (`STU-{id}`, `COURSE-{id}` como identificadores ofuscados). | Implementado. |
| **Privacidad** | Cumplimiento de la Ley 1581/2012 (Colombia) de protección de datos. | Implementado por diseño. |
| **Escalabilidad** | Backend asíncrono basado en FastAPI + SQLAlchemy async. | Implementado. |
| **Escalabilidad** | Procesamiento desacoplado mediante cola en MariaDB. | Implementado; permite reintentos. |
| **Escalabilidad** | Red Besu QBFT escalable horizontalmente (4 nodos validadores en el MVP). | Implementado. |
| **Disponibilidad** | El backend arranca aunque Besu no esté disponible y reporta su estado en `/health`. | Implementado. |
| **Disponibilidad** | El plugin puede operar en `pending_wallet` mientras el estudiante no tenga wallet. | Implementado. |
| **Mantenibilidad** | Capas separadas y bajas dependencias entre componentes (plugin ↔ backend ↔ contratos). | Implementado. |
| **Mantenibilidad** | Contratos auditables y versionables, basados en OpenZeppelin 5.x. | Implementado. |
| **Mantenibilidad** | 61 pruebas automatizadas verdes (19 contratos + 24 backend + 18 E2E). | Implementado. |
| **Auditabilidad** | Trazabilidad en tres capas (cola Moodle, `audit_log` PostgreSQL, blockchain). | Implementado. |
| **Auditabilidad** | `txHash` registrado para cada emisión MRT y badge. | Implementado. |
| **Usabilidad** | Dashboard responsive con grid `flex-wrap`, fallback `onerror` para imágenes rotas. | Implementado. |
| **Usabilidad** | Compatibilidad con Bootstrap 4 y 5 (toggles en JS puro). | Implementado. |
| **Usabilidad** | Manejo explícito del dark mode del sistema operativo en componentes críticos (modal de insignias). | Implementado. |
| **Interoperabilidad** | Insignias compatibles con el estándar Open Badges v2 (OBv2). | Implementado a nivel de metadatos generados. |
| **Operabilidad** | Cambio de nodo EVM mediante una sola variable de entorno. | Implementado. |

---

## 7. Arquitectura del Sistema

### 7.1 Estilo arquitectónico

MeritCoin sigue un estilo arquitectónico **híbrido off-chain / on-chain**, con tres dominios físicos y lógicos claramente separados:

1. **Dominio LMS (Moodle + Plugin).** Capa de captura y presentación, dependiente del LMS institucional.
2. **Dominio Off-chain (Backend + PostgreSQL).** Capa de orquestación, persistencia operativa y auditoría.
3. **Dominio On-chain (Besu + Contratos).** Capa de registro inmutable y verificación pública.

La comunicación entre dominios se realiza mediante interfaces explícitas: HTTP+HMAC entre LMS y backend, JSON-RPC entre backend y blockchain. Esta separación cumple los principios de **bajo acoplamiento**, **alta cohesión** y **separación de preocupaciones** declarados en el SAD original.

### 7.2 Diagrama ejecutivo — ¿qué hace MeritCoin?

Este diagrama está pensado para lectores no técnicos. Resume el sistema como una cadena de confianza: una actividad académica ocurre en SAVIO/Moodle, MeritCoin la convierte en una recompensa digital, y la red blockchain institucional conserva la evidencia verificable.

```
  +---------------------------+        +--------------------------------+
  | Estudiante realiza una    |        | Profesor define reglas:        |
  | actividad en SAVIO/Moodle |        | qué actividad premia y         |
  +-------------+-------------+        | cuántos MRT entrega            |
                |                      +----------------+---------------+
                +------------------+-------------------+
                                   |
                                   v
                       +-----------+------------+
                       | ¿La nota o actividad   |
                       | cumple la regla?       |
                       +-----------+------------+
              NO                  |                 SÍ
   +----------+                   |                 +----------+
   v                              |                            v
+--+------------------+           |           +---------------+----------+
| No se emite         |           |           | MeritCoin calcula la     |
| recompensa          |           |           | recompensa y evita       |
+---------------------+           |           | duplicados               |
                                  |           +---------------+----------+
                                  |                           |
                                  |                           v
                                  |           +---------------+----------+
                                  |           | Backend registra         |
                                  |           | auditoría y firma        |
                                  |           | la operación             |
                                  |           +---------------+----------+
                                  |                           |
                                  |                           v
                                  |           +---------------+----------+
                                  |           | Red privada Besu (UTB)  |
                                  |           +--------+--------+--------+
                                  |                    |        |
                                  |          +---------+        +---------+
                                  |          v                            v
                                  |  +-------+----------+   +------------+-------+
                                  |  | Contrato ERC-20: |   | Contrato ERC-1155: |
                                  |  | entrega MRT      |   | emite insignia     |
                                  |  +-------+----------+   +------+-------------+
                                  |          |                      |
                                  |          +----------+-----------+
                                  |                     |
                       +----------+    +----------------+------------------+
                       |               |                                   |
                       |               v                                   v
                       |  +------------+-----------+    +-----------------+-----+
                       |  | Estudiante ve saldo,   |    | Verificador externo   |
                       |  | insignias y recompensas|    | consulta certificado  |
                       |  +------------------------+    | o hash público        |
                       |                               +------------------------+
                       +-- (cuando no cumple regla, termina aquí)
```

### 7.3 Diagrama técnico de contenedores

El siguiente diagrama complementa la vista ejecutiva con los contenedores reales del MVP. Su objetivo es mostrar dónde vive cada responsabilidad técnica, sin perder la separación por dominios.

```
  +================================================+
  | DOMINIO LMS: SAVIO / Moodle                    |
  |                                                |
  |  +-----------------------+                     |
  |  | Moodle 4.3            |                     |
  |  | (interfaz de          +--+                  |
  |  |  estudiantes y        |  |                  |
  |  |  profesores)          |  |                  |
  |  +-----------------------+  |                  |
  |            |                |                  |
  |  +---------+-------------+  |                  |
  |  | Plugin MeritCoin      |  |                  |
  |  | (captura eventos,     |  |                  |
  |  |  reglas, marketplace) |  |                  |
  |  +---------+-------------+  |                  |
  |            |                |                  |
  |  +---------+----------+     |                  |
  |  | Base de datos      |<----+                  |
  |  | Moodle (MariaDB)   |                        |
  |  +--------------------+                        |
  +===========+====================================+
              |
              |  Evento académico firmado
              |  HTTPS + HMAC-SHA256
              v
  +================================================+
  | DOMINIO OFF-CHAIN: Orquestación                |
  |                                                |
  |  +----------------------------+                |
  |  | API MeritCoin (FastAPI)    |                |
  |  | (valida HMAC, idempotencia,|                |
  |  |  emite on-chain)           |                |
  |  +------------+---------------+                |
  |               |                                |
  |  +------------+----------+                     |
  |  | Auditoría y wallets   |                     |
  |  | (PostgreSQL)          |                     |
  |  +-----------------------+                     |
  +===============+================================+
                  |
                  |  Transacción blockchain
                  |  JSON-RPC
                  v
  +================================================+
  | DOMINIO ON-CHAIN: Red privada UTB              |
  |                                                |
  |  +----------------------------+                |
  |  | 4 validadores Besu         |                |
  |  | (consenso QBFT)            |                |
  |  +------------+---------------+                |
  |               |                                |
  |  +------------+----------------------------+   |
  |  | Contratos inteligentes (Solidity)       |   |
  |  | MeritCoinERC20  +  MeritBadges1155      |   |
  |  +-----------------------------------------+   |
  +================================================+
```

Para una entrega impresa o una sustentación con diapositivas, estos diagramas Mermaid pueden recrearse visualmente en **diagrams.net** usando las mismas cajas y flechas. Esa herramienta es gratuita, funciona en navegador y permite exportar a PNG, SVG o PDF con apariencia más editorial que un diagrama renderizado desde Markdown.

### 7.4 Diagrama de Componentes — Plugin Moodle

Este diagrama agrupa el plugin por **lo que hace cada parte**, no por archivos. La parte interna (motor del plugin) reacciona a los eventos de Moodle, decide la recompensa y envía la información al backend. La parte visible (pantallas) es lo que ven estudiantes, profesores y administradores. En el medio están los registros locales del plugin.

```
  +============================+   +=============================+   +=========================+
  | MOTOR DEL PLUGIN (interno) |   | REGISTROS LOCALES           |   | PANTALLAS (UI)          |
  |                            |   |                             |   |                         |
  | +------------------------+ |   | +-------------------------+ |   | +---------------------+ |
  | | Captura de eventos     | |   | | Cola de eventos         | |   | | Dashboard           | |
  | | Moodle (observer.php)  +------>| (MariaDB)               | |   | | del estudiante      | |
  | +----------+-------------+ |   | +-------------------------+ |   | +---------------------+ |
  |            |               |   |                             |   |                         |
  | +----------+-------------+ |   | +-------------------------+ |   | +---------------------+ |
  | | Motor de reglas        | |   | | Ledger por curso        | |   | | Marketplace         | |
  | | y saldos por curso     +------>| (ganado y gastado)      |<----| | del curso           | |
  | | (rules_service.php)    | |   | +-------------------------+ |   | +---------------------+ |
  | +----------+-------------+ |   |                             |   |                         |
  |            |               |   | +-------------------------+ |   | +---------------------+ |
  | +----------+-------------+ |   | | Reglas y recompensas    |<----| | Gestión de reglas   | |
  | | Envío periódico        | |   | | (configuración)         |<----| | (profesor)          | |
  | | al backend             +--+  | +-------------------------+ |   | +---------------------+ |
  | | (send_events_task.php) | |   |                             |   |                         |
  | +------------------------+ |   | +-------------------------+ |   | +---------------------+ |
  |                            |   | | Insignias del           |<----| | Verificación pública| |
  +============================+   | | estudiante (local)      | |   | | y certificado PDF   | |
                                   | +-------------------------+ |   | +---------------------+ |
                                   +=============================+   |                         |
                                                                     | +---------------------+ |
              +--------------------------------------------------+   | | Panel del           | |
              |                Backend FastAPI                   |<--| | administrador       | |
              |   (recibe eventos firmados, responde saldos)     |   | +---------------------+ |
              +--------------------------------------------------+   +========================+
```

> Las correspondencias archivo ↔ componente (observer.php, dashboard.php, etc.) están detalladas en la tabla **7.9 Componentes por responsabilidad**.

### 7.5 Diagrama de Componentes — Backend FastAPI

Este diagrama presenta el backend como **tres capas de responsabilidad**, no como un árbol de archivos. La puerta de entrada recibe peticiones desde el plugin Moodle y desde el dashboard. La cocina del backend orquesta la lógica (eventos, insignias, wallets, blockchain). El piso de servicios comunes se ocupa de la seguridad y de hablar con la base de datos.

```
  +------------------+
  |  Plugin Moodle   |
  +--------+---------+
           |  HTTP + HMAC
           |
           v
  +================================================+
  | PUERTA DE ENTRADA  (API REST — FastAPI)        |
  |                                                |
  |  +----------------+  +--------------------+    |
  |  | /events/ingest |  | /students/{wallet} |    |
  |  | Eventos        |  | Saldo e insignias  |    |
  |  | académicos     |  +--------------------+    |
  |  +----------------+                            |
  |                                                |
  |  +----------------+  +--------------------+    |
  |  | /tokens/spend  |  | /wallets/          |    |
  |  | Canje de       |  | Wallets            |    |
  |  | recompensas    |  | custodiales        |    |
  |  +----------------+  +--------------------+    |
  |                                                |
  |  +-------------------------------------------+ |
  |  | /badges/  Insignias manuales y certificados||
  |  +-------------------------------------------+ |
  +===============+================================+
                  |
                  v
  +================================================+
  | LÓGICA DEL BACKEND                             |
  |                                                |
  |  +------------------+  +-------------------+   |
  |  | Procesar evento  |  | Calcular y quemar |   |
  |  | (idempotencia y  |  | MRT del canje     |   |
  |  |  auditoría)      |  | (burnFrom)        |   |
  |  +------------------+  +-------------------+   |
  |                                                |
  |  +------------------+  +-------------------+   |
  |  | Provisionar y    |  | Emitir insignias  |   |
  |  | expirar wallets  |  | manuales y generar|   |
  |  | custodiales      |  | certificado PDF   |   |
  |  +------------------+  +-------------------+   |
  +===============+================================+
                  |
                  v
  +================================================+
  | SERVICIOS COMUNES                              |
  |                                                |
  |  +------------------+  +-------------------+   |
  |  | Verificación     |  | Cifrado Fernet    |   |
  |  | HMAC-SHA256      |  | de claves privadas|   |
  |  +------------------+  +-------------------+   |
  |                                                |
  |  +------------------+  +-------------------+   |
  |  | Cliente          |  | Acceso a          |   |
  |  | blockchain       |  | PostgreSQL (async)|   |
  |  | web3.py          |  |                   |   |
  |  +--------+---------+  +---------+---------+   |
  +============+============================+======+
               |                           |
               | JSON-RPC                  | TCP
               v                           v
  +------------+---------+     +-----------+----------+
  | Red Besu             |     | PostgreSQL           |
  | (contratos ERC-20    |     | (auditoría y wallets)|
  |  y ERC-1155)         |     +----------------------+
  +----------------------+
```

> El mapeo a archivos concretos (`events.py`, `blockchain.py`, etc.) se mantiene en la tabla **7.9 Componentes por responsabilidad**.

### 7.6 Flujo principal — Emisión de MRT por evento académico

Para lectores no técnicos, el flujo puede entenderse como un proceso de cinco pasos: el profesor define la regla, el estudiante obtiene el logro, Moodle detecta el evento, MeritCoin calcula y valida la recompensa, y la red Besu registra la emisión.

```
  +--------------------+       +-----------------------------+
  | Profesor configura |       | Estudiante completa         |
  | reglas de          |       | actividad académica         |
  | recompensa         |       +-------------+---------------+
  +--------+-----------+                     |
           |                                 v
           |                    +------------+-------------+
           +-------------------->  Moodle detecta nota o   |
                                |  completación            |
                                +------------+-------------+
                                             |
                                             v
                                +------------+-------------+
                                | MeritCoin evalúa regla   |
                                | y límite por curso       |
                                +------+----------+--------+
                          NO cumple   |            |   SÍ cumple
                               +------+            +-------+
                               v                           v
                    +----------+----------+   +-----------+----------+
                    | No se entrega MRT   |   | Evento queda en cola |
                    | (descartado)        |   | para envío seguro    |
                    +---------------------+   +-----------+----------+
                                                          |
                                                          v
                                              +-----------+----------+
                                              | Backend verifica     |
                                              | firma HMAC y evita   |
                                              | duplicados           |
                                              +-----------+----------+
                                                          |
                                                          v
                                              +-----------+----------+
                                              | Blockchain Besu      |
                                              | mint(wallet, MRT)    |
                                              +-----------+----------+
                                                          |
                                                          v
                                              +-----------+----------+
                                              | Saldo MRT e insignia |
                                              | visibles al          |
                                              | estudiante           |
                                              +----------------------+
```

El siguiente diagrama detalla la secuencia técnica real del MVP.

```
  PARTICIPANTES:
  [Estudiante]  [Moodle]  [observer.php]  [rules_service]  [Cola MariaDB]
  [send_events_task]  [Backend API]  [events_service]  [Besu ERC-20]  [PostgreSQL]

  ─────────────────────────────────────────────────────────────────────────────────────
   1. Estudiante  ──────────────────────────────>  Moodle
                  Completa actividad / recibe nota

   2. Moodle  ───────────────────────────────────>  observer.php
                  Dispara evento user_graded

   3. observer.php  [filtra: itemtype=mod, grade≥0]

   4. observer.php  ───────────────────────────>  rules_service
                    get_coins_for_event(courseid, cmid, modtype, grade)

   5. rules_service  ──────────────────────────>  observer.php
                     coins_amount (según prioridad de regla)

   6. observer.php  [verifica límite MRT acumulado por curso]
   7. observer.php  [event_id = md5(userid + courseid + cmid + type)]

   8. observer.php  ───────────────────────────>  Cola MariaDB
                    INSERT (event_id, payload, status=pending)

  ─── Cada 1 minuto (Task API de Moodle) ──────────────────────────────────────────────

   9. send_events_task  ──────────────────────>  Cola MariaDB
                        SELECT WHERE status='pending'

  10. send_events_task  ──────────────────────>  Backend API
                        POST /events/ingest + HMAC-SHA256

  11. Backend API  [verify_hmac()]

  12. Backend API  ────────────────────────────>  events_service
                   process_event(event)

  13. events_service  ─────────────────────────>  PostgreSQL
                      reserve_event(event_id)  ← idempotencia

      ┌── SI event_id ya existe ─────────────────────────────────────────────────────┐
      │  PostgreSQL  ──────────────────────────>  events_service                     │
      │              duplicate                                                       │
      │  events_service  ──────────────────────>  Backend API                        │
      │                  200 OK "duplicate"                                          │
      └──────────────────────────────────────────────────────────────────────────────┘

      ┌── SI evento nuevo ───────────────────────────────────────────────────────────┐
      │  events_service  ─────────────────────>  Besu ERC-20                         │
      │                  mint(student_wallet, coins_amount × 1e18)                   │
      │  Besu ERC-20  ─────────────────────────>  events_service                     │
      │               tx_hash                                                        │
      │  events_service  ─────────────────────>  PostgreSQL                          │
      │                  record_audit(event_id, tx_mrt, mrt_amount)                  │
      │  events_service  ─────────────────────>  PostgreSQL                          │
      │                  mark_event_processed                                        │
      │  events_service  ─────────────────────>  Backend API                         │
      │                  200 OK + tx_hash                                            │
      └──────────────────────────────────────────────────────────────────────────────┘

  14. Backend API  ────────────────────────────>  send_events_task
                   200 OK

  15. send_events_task  ──────────────────────>  Cola MariaDB
                        UPDATE status='sent'
                        INSERT earnings(+coins)
  ─────────────────────────────────────────────────────────────────────────────────────
```

### 7.7 Flujo de canje en el marketplace

En palabras simples: el estudiante entra al marketplace de su curso, MeritCoin verifica que tenga monedas suficientes ganadas **en ese curso** (no en otro), confirma el balance real en blockchain y, si todo cuadra, quema esas monedas a cambio de la recompensa.

```
  +-----------------------------+
  | Estudiante elige una        |
  | recompensa del marketplace  |
  +-------------+---------------+
                |
                v
  +-------------+---------------+
  | ¿Saldo del curso            |
  | (earnings - spend)          |
  | es mayor o igual al precio? |
  +------+----------+-----------+
     NO  |                | SÍ
         v                v
  +------+-------+  +------+---------------------+
  | Canje        |  | ¿Balance real en           |
  | rechazado:   |  | blockchain ≥ precio?       |
  | saldo        |  +------+----------+----------+
  | insuficiente |     NO  |          | SÍ
  +--------------+         v          v
                    +------+---+  +----+-----------------------+
                    | Canje    |  | Se queman las monedas      |
                    | rechazado|  | en Besu (burnFrom)         |
                    +----------+  +----+-----------------------+
                                       |
                                       v
                               +-------+-----------------------+
                               | Recompensa entregada y        |
                               | registrada en ledger          |
                               +---------------------------- --+
```

El siguiente diagrama detalla la secuencia técnica del canje.

```
  PARTICIPANTES:
  [Estudiante]  [marketplace.php]  [rules_service]  [Backend API]  [Besu ERC-20]

  ─────────────────────────────────────────────────────────────────────────────────────
   1. Estudiante  ─────────────────────────>  marketplace.php
                  Abre marketplace del curso

   2. marketplace.php  ────────────────────>  Backend API
                       GET /students/{wallet}/summary

   3. Backend API  ────────────────────────>  Besu ERC-20
                   balanceOf(wallet)

   4. Besu ERC-20  ────────────────────────>  Backend API
                   balance_real_on_chain

   5. Backend API  ────────────────────────>  marketplace.php
                   { mrt_balance, badges }

   6. marketplace.php  ────────────────────>  rules_service
                       get_available_balance_by_course(userid, courseid)

   7. rules_service  ──────────────────────>  marketplace.php
                     saldo_curso = earnings - spend

   8. marketplace.php  ────────────────────>  Estudiante
                       lista de recompensas + saldos

  ─── Acción: el estudiante elige canjear una recompensa ──────────────────────────────

   9. Estudiante  ─────────────────────────>  marketplace.php
                  Canjear recompensa X

  10. marketplace.php  ────────────────────>  rules_service
                       can_redeem_in_course(userid, courseid, precio)

      ┌── SI saldo_curso < precio ───────────────────────────────────────────────────┐
      │  rules_service  ──────────────────>  marketplace.php  →  Saldo insuficiente  │
      └──────────────────────────────────────────────────────────────────────────────┘

      ┌── SI saldo_curso >= precio ──────────────────────────────────────────────────┐
      │  marketplace.php  ────────────────>  Backend API                             │
      │                   POST /tokens/spend { wallet, amount, reward_id }           │
      │  Backend API  ─────────────────────>  Besu ERC-20                            │
      │               burnFrom(wallet, amount × 1e18)                                │
      │  Besu ERC-20  ─────────────────────>  Backend API                            │
      │               tx_hash                                                        │
      │  Backend API  ─────────────────────>  marketplace.php                        │
      │               { tx_hash }                                                    │
      │  marketplace.php  ────────────────>  rules_service                           │
      │                   record_spend(userid, courseid, reward, amount)             │
      │  marketplace.php  ────────────────>  Estudiante                              │
      │                   Canje confirmado                                           │
      └──────────────────────────────────────────────────────────────────────────────┘
  ─────────────────────────────────────────────────────────────────────────────────────
```

### 7.8 Formulación del algoritmo de recompensas y canje

MeritCoin no requiere una ecuación económica de intercambio entre mercaderes, porque el MRT no se comporta como una moneda de mercado con precio variable, oferta, demanda o conversión a dinero fiat. En este proyecto, la ecuación correcta es una **función determinística de recompensa académica**: dado un evento académico y un conjunto de reglas configuradas por el profesor, el sistema calcula cuántos MRT se entregan, actualiza el saldo por curso y autoriza o rechaza un canje.

#### Cálculo de MRT por evento académico

Sea un evento académico:

$
e = (u, c, a, m, g, t)
$

donde:

- $u$: estudiante.
- $c$: curso.
- $a$: actividad específica.
- $m$: tipo de módulo Moodle, por ejemplo tarea, cuestionario o foro.
- $g$: calificación o resultado del estudiante.
- $t$: tipo de evento académico.

Sea $R_c$ el conjunto de reglas activas del curso $c$. Cada regla $r \in R_c$ tiene una prioridad, una condición de aplicación y una cantidad de monedas $v(r)$. La prioridad aplicada por el MVP es:

$
\text{actividad específica} > \text{tipo de módulo} > \text{curso}
$

La regla efectiva se define como:

$$
r^{\ast}(e) = \arg\max_{r \in R_c} \left( prioridad(r) \right)
\quad \text{sujeto a que } r \text{ aplique sobre } (a,m,g)
$$

La recompensa bruta del evento es:

$$
MRT_{bruto}(e) =
\begin{cases}
v(r^{\ast}(e)), & \text{si existe una regla aplicable y el evento no fue procesado antes} \\
0, & \text{si no hay regla aplicable o el evento es duplicado}
\end{cases}
$$

Para respetar el límite máximo de monedas por estudiante y curso, la recompensa efectiva es:

$$
MRT(e) = \min \left( MRT_{bruto}(e),\ L_c - G_{u,c} \right)
$$

donde:

- $L_c$: límite máximo de MRT permitido para el estudiante dentro del curso.
- $G_{u,c}$: total acumulado de MRT ya ganado por el estudiante $u$ en el curso $c$.

Si $L_c - G_{u,c} \le 0$, el sistema registra el evento pero no emite nuevas monedas.

#### Saldo disponible por curso

El saldo disponible para canje en un curso se calcula como:

$$
S_{u,c} = \sum_{e \in E_{u,c}} MRT(e) - \sum_{x \in X_{u,c}} precio(x)
$$

donde:

- $E_{u,c}$: eventos válidos del estudiante $u$ en el curso $c$.
- $X_{u,c}$: recompensas canjeadas por el estudiante en el curso.
- $precio(x)$: costo en MRT de la recompensa $x$.

#### Condición de autorización de canje

Un canje de recompensa $x$ se autoriza solo si se cumplen dos condiciones:

$$
S_{u,c} \ge precio(x)
\quad \land \quad
B_{chain}(wallet_u) \ge precio(x)
$$

donde $B_{chain}(wallet_u)$ es el balance real ERC-20 consultado en la blockchain. Si ambas condiciones se cumplen, el sistema ejecuta:

$$
B_{chain}'(wallet_u) = B_{chain}(wallet_u) - precio(x)
$$

operacionalmente implementado como una transacción `burnFrom(wallet_u, precio(x))` sobre el contrato ERC-20.

#### Tiempo estimado de confirmación

Para justificar el comportamiento temporal del algoritmo en la red Besu privada, puede expresarse el tiempo total de emisión como:

$$
T_{total} = T_{cola} + T_{api} + T_{tx} + T_{sync}
$$

donde:

- $T_{cola}$: espera hasta que la tarea programada de Moodle envía el evento; en el MVP puede ser hasta aproximadamente un minuto.
- $T_{api}$: tiempo de validación HMAC, idempotencia y persistencia en backend.
- $T_{tx}$: tiempo de inclusión y finalidad de la transacción en Besu; en la red configurada depende del `blockperiodseconds`.
- $T_{sync}$: tiempo hasta que Moodle actualiza la UI local con el resultado.

Con esto, MeritCoin sí posee una formulación algorítmica propia, pero su foco no es maximizar utilidad económica, sino garantizar **equidad académica, idempotencia, trazabilidad y validez de canje**.

### 7.9 Componentes por responsabilidad

| Componente | Rol | Tecnología |
|------------|-----|-----------|
| `observer.php` | Captura `user_graded`, resuelve reglas, encola eventos. | PHP 8.x — Moodle Event API. |
| `rules_service.php` | Resuelve reglas (prioridad actividad > tipo > curso), calcula saldos por curso. | PHP 8.x. |
| `wallet_service.php` (plugin) | Lectura local de wallet del estudiante o aprovisionamiento contra el backend. | PHP 8.x. |
| `send_events_task.php` | Tarea programada Moodle que envía eventos pendientes (cada minuto). | Moodle Task API. |
| `api_client.php` | Encapsula HTTP+HMAC via `file_get_contents`. | PHP 8.x. |
| `dashboard.php` | UI estudiante: saldo, historial, grid de insignias. | PHP + JS puro + Bootstrap. |
| `marketplace.php` / `rewards.php` | Mercado de recompensas (estudiante / profesor). | PHP + JS puro. |
| `badge_verify.php` / `badge_pdf.php` | Páginas públicas standalone para verificación e impresión. | PHP + CSS inline + JS puro. |
| `backend/app/api/*.py` | Routers REST: events, students, badges, tokens, wallets. | FastAPI. |
| `backend/app/services/blockchain.py` | Wrapper `web3.py` + middleware POA + lock para tx secuenciales. | web3.py 6.x + Web3 Provider HTTP. |
| `backend/app/services/wallet_service.py` | Provisión y expiración de wallets custodiales por curso. | SQLAlchemy async + `eth_account`. |
| `backend/app/services/certificate.py` | Generación de PDF para insignias manuales. | ReportLab / equivalente. |
| `MeritCoinERC20.sol` | Token ERC-20 con `mint`, `burnFrom`, `pause`. | Solidity 0.8.28 + OpenZeppelin 5.x. |
| `MeritBadges1155.sol` | Insignia ERC-1155 con `mintBadge` idempotente, `pause`. | Solidity 0.8.28 + OpenZeppelin 5.x. |
| Red Besu | 4 nodos validadores QBFT con `blockperiodseconds=2`. | Hyperledger Besu (`hyperledger/besu:latest`). |

### 7.10 Conceptos transversales

#### Seguridad

- **HMAC-SHA256** sobre el body crudo en cada petición Moodle → Backend.
- **Roles de contrato** explícitos (`ISSUER_ROLE`, `MINTER_ROLE`, `BURNER_ROLE`).
- **Pausable** ante incidentes.
- **Wallets custodiales cifradas** con Fernet (clave Fernet obligatoria en arranque del backend).
- **CSRF en plugin** mediante `require_sesskey()` en toda escritura.
- **Sin datos personales en blockchain**; solo IDs ofuscados.

#### Idempotencia

- `event_id` determinístico `md5("moodle-{userid}-{courseid}-{cmid}-{type}")` calculado en el plugin.
- Índice único en `local_meritcoin_queue.event_id` (MariaDB).
- Reserva previa en `events` (PostgreSQL) antes de cualquier llamada on-chain.
- Doble verificación on-chain: `MeritBadges1155._minted[keccak256(to, id)]` previene doble emisión de la misma insignia al mismo wallet.

#### Trazabilidad en tres capas

| Capa | Almacén | Qué registra |
|------|---------|--------------|
| Plugin Moodle | `local_meritcoin_queue` | Estado de cada evento: pending / pending_wallet / sent / failed. |
| Plugin Moodle | `local_meritcoin_earnings` / `_spend` | Ledger por curso de monedas ganadas y gastadas. |
| Plugin Moodle | `local_meritcoin_badges` | Insignias emitidas, hash de verificación. |
| Backend | `events` (PG) | Registro de cada evento procesado (idempotencia). |
| Backend | `audit_log` (PG) | `tx_badge`, `tx_mrt`, `cid_ipfs`, `mrt_amount`. |
| Blockchain | EVM Besu | Transacciones inmutables `mint`, `mintBadge`, `burnFrom`. |

#### Configuración

- Todas las variables sensibles viven en `.env` (HMAC secret, clave privada deployer, Fernet, URLs de contratos).
- `pydantic-settings` carga la configuración del backend.
- Las direcciones de contrato no están embebidas: se inyectan tras `npx hardhat run scripts/deploy.js --network besu`.

---

## 8. Casos de Uso

### 8.1 Catálogo

| ID | Caso de uso | Actor primario | Disparador |
|----|-------------|----------------|-----------|
| CU-01 | Configurar regla de recompensa | Profesor | Acceso al menú MeritCoin → Gestión de reglas. |
| CU-02 | Ganar MRT por completar actividad | Estudiante | Evento `user_graded`. |
| CU-03 | Consultar dashboard MeritCoin | Estudiante | Acceso al menú Mi Dashboard. |
| CU-04 | Crear recompensa canjeable | Profesor | Acceso al menú Recompensas del curso. |
| CU-05 | Canjear recompensa | Estudiante | Selección de recompensa en marketplace. |
| CU-06 | Emitir insignia manualmente | Profesor / Admin | Acceso a `award_badge.php`. |
| CU-07 | Verificar insignia públicamente | Verificador externo | Recepción de enlace `badge_verify.php?hash=...`. |
| CU-08 | Descargar certificado PDF | Estudiante / Verificador | Botón "Descargar PDF". |
| CU-09 | Configurar plugin (admin) | Administrador Moodle | Acceso a `settings.php`. |
| CU-10 | Cerrar enrollments al fin de semestre | Administrador / scheduler | Fin del semestre académico. |
| CU-11 | Revisar reporte de transacciones del curso | Profesor | Acceso a `teacher_transactions.php`. |
| CU-12 | Revisar KPIs globales | Administrador | Acceso a `admin_marketplace.php`. |

### 8.2 Diagrama de Casos de Uso

```
  ACTORES                     SISTEMA MERITCOIN — CASOS DE USO
  ─────────                   ─────────────────────────────────────────────────────────

  +------------+              +-----------------------------------------------+
  | Estudiante |─────────────>| CU-02  Ganar MRT por completar actividad      |
  +------------+    │         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    ├────────>| CU-03  Consultar dashboard MeritCoin          |
                    │         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    ├────────>| CU-05  Canjear recompensa del marketplace     |
                    │         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    └────────>| CU-08  Descargar certificado PDF              |
                              +-----------------------------------------------+

  +----------+                +-----------------------------------------------+
  | Profesor |───────────────>| CU-01  Configurar regla de recompensa         |
  +----------+      │         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    ├────────>| CU-04  Crear recompensa canjeable             |
                    │         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    ├────────>| CU-06  Emitir insignia manualmente            |
                    │         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    └────────>| CU-11  Revisar reporte de transacciones       |
                              +-----------------------------------------------+

  +----------------+          +-----------------------------------------------+
  | Admin Moodle   |─────────>| CU-06  Emitir insignia manualmente            |
  +----------------+│         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    ├────────>| CU-09  Configurar plugin                      |
                    │         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    ├────────>| CU-10  Cerrar enrollments al fin de semestre  |
                    │         +-----------------------------------------------+
                    │         +-----------------------------------------------+
                    └────────>| CU-12  Revisar KPIs globales                  |
                              +-----------------------------------------------+

  +---------------------+     +-----------------------------------------------+
  | Verificador externo |────>| CU-07  Verificar insignia públicamente        |
  +---------------------+ │   +-----------------------------------------------+
                          │   +-----------------------------------------------+
                          └──>| CU-08  Descargar certificado PDF              |
                              +-----------------------------------------------+

  +-----------+              +-----------------------------------------------+
  | Scheduler |─────────────>| CU-02  Ganar MRT (envío periódico de eventos) |
  +-----------+    │         +-----------------------------------------------+
                   │         +-----------------------------------------------+
                   └────────>| CU-10  Cerrar enrollments (fin de semestre)   |
                             +-----------------------------------------------+
```

### 8.3 Caso de uso detallado: CU-02 — Ganar MRT por completar actividad

| Campo | Valor |
|-------|-------|
| **ID** | CU-02 |
| **Actor primario** | Estudiante |
| **Actores secundarios** | Scheduler de Moodle, Backend, Red Besu |
| **Precondiciones** | El curso tiene al menos una regla habilitada. El estudiante tiene wallet (manual o custodial). El plugin está habilitado. |
| **Flujo principal** | 1. El estudiante entrega o se le califica una actividad. 2. Moodle dispara `user_graded`. 3. `observer.php` filtra `itemtype=mod`. 4. `rules_service` resuelve la regla aplicable y calcula `coins_amount`. 5. Se verifica el límite acumulado por curso. 6. Se calcula el `event_id` determinístico. 7. Se inserta en `local_meritcoin_queue` con estado `pending`. 8. La tarea programada envía el evento al backend con HMAC. 9. El backend valida HMAC, reserva el `event_id` y llama `mint`. 10. El backend registra `audit_log`. 11. La tarea marca el evento como `sent` y aumenta `earnings`. |
| **Flujos alternativos** | A1: El estudiante no tiene wallet → estado `pending_wallet`, no se envía hasta que el campo se complete. A2: La regla tiene `min_grade` y la nota es inferior → evento no se encola. A3: El estudiante ya alcanzó su límite MRT del curso → evento no se encola. A4: Backend recibe `event_id` duplicado → responde `200 duplicate` sin llamar on-chain. A5: La transacción on-chain falla → estado `failed`, rollback en BD. |
| **Postcondiciones** | El balance ERC-20 del estudiante se incrementa en `coins_amount`. El `audit_log` registra el `tx_hash`. |

### 8.4 Caso de uso detallado: CU-05 — Canjear recompensa

| Campo | Valor |
|-------|-------|
| **ID** | CU-05 |
| **Actor primario** | Estudiante |
| **Precondiciones** | El estudiante tiene saldo gastable en el curso ≥ precio. La recompensa está habilitada y con stock. El balance on-chain ≥ precio. |
| **Flujo principal** | 1. El estudiante abre `marketplace.php` del curso. 2. El plugin consulta `/students/{wallet}/summary` para obtener el balance real. 3. El plugin calcula `saldo_curso = earnings - spend`. 4. El estudiante elige una recompensa. 5. `rules_service.can_redeem_in_course` valida el saldo. 6. Se llama `POST /tokens/spend` al backend. 7. El backend ejecuta `burnFrom` sobre el contrato ERC-20. 8. El plugin registra el canje en `local_meritcoin_spend` y `local_meritcoin_redemptions`. 9. Se confirma al estudiante. |
| **Postcondiciones** | El balance ERC-20 disminuye en `precio`. El saldo gastable del curso se reduce. La recompensa queda asociada al estudiante. |

### 8.5 Caso de uso detallado: CU-07 — Verificar insignia públicamente

| Campo | Valor |
|-------|-------|
| **ID** | CU-07 |
| **Actor primario** | Verificador externo (sin cuenta Moodle) |
| **Precondiciones** | La URL contiene un `hash` válido. La insignia no está revocada. |
| **Flujo principal** | 1. El verificador abre `https://.../local/meritcoin/badge_verify.php?hash=<hash>`. 2. El plugin consulta `local_meritcoin_badges` por `verify_hash`. 3. Se renderiza una página standalone con datos de la insignia, sello institucional y timestamp. 4. El verificador puede expandir el hash técnico y/o ir a `badge_pdf.php` para descargar el certificado. |
| **Postcondiciones** | El verificador confirma la autenticidad sin necesidad de credenciales. |

---

## 9. Modelo de Datos

### 9.1 Modelo conceptual

A alto nivel, MeritCoin gira en torno a seis grupos de información: el estudiante y su wallet, el curso y sus reglas, los eventos académicos que se procesan, el ledger de monedas ganadas y gastadas, las insignias emitidas y la auditoría on-chain/off-chain. El diagrama siguiente lo muestra sin entrar a nivel de tablas concretas.

```
  +----------------------+          +----------------------+
  | Estudiante           |          | Curso                |
  | + wallet custodial   +--------->| con sus reglas       |
  +----------+-----------+ participa+----------+-----------+
             |            en                   |
             |                                 | define
             |                                 v
             |                      +----------+------------+
             |                      | Evento académico      |
             |                      | (actividad calificada)|
             |                      +-----+--------+--------+
             |                            |        |
             |                    alimenta|        |emite
             |                            v        v
             |              +------------+-+  +---+-------------+
             |              | Ledger del   |  | Monedas MRT     |
             |              | curso        |  | (ERC-20)        |
             |              | (ganado /    |  +---------+-------+
             |              |  gastado)    |            |
             |              +---+----------+            |
             |                  |                       |  emite
             |        autoriza  |              +--------+--------+
             |        canje de  +------------->| Insignia        |
             |                                 | (ERC-1155)      |
             |                                 +---------+-------+
             |                                           |
             +-------------------+   +------------------+
                                 |   |
                                 v   v
                         +-------+---+------+
                         | Auditoría        |
                         | tx_hash          |
                         | + audit_log      |
                         +------------------+
```

El siguiente diagrama entidad-relación detalla las entidades reales del sistema (tablas en MariaDB y PostgreSQL). Es un complemento técnico del esquema conceptual anterior.

```
  ┌──────────────────────────────────────────────────────────────────────────────────┐
  │  ENTIDADES EN MARIADB (Plugin Moodle)                                            │
  └──────────────────────────────────────────────────────────────────────────────────┘

  +------------------+         +------------------+         +--------------------+
  | USUARIO          |         | CURSO            |         | RULE               |
  |------------------|         |------------------|         |--------------------|
  | moodle_userid PK |  se     | moodle_courseid  |  define | id PK              |
  | wallet_address   | inscr.  | PK               +-------->| courseid FK        |
  | profile_wallet   +-------->| fullname         |         | cmid               |
  | _field           |         |                  |         | rule_scope         |
  +------+------+----+         +--------+---------+         | mod_type           |
         |      |                       |                   | min_grade          |
    tiene|  gen.|              ofrece   |                   | coins_amount       |
         |  ev. |                       |                   | enabled            |
         v      |              +--------v---------+         +--------------------+
  +------+----+ |              | REWARD           |
  | WALLET    | |              |------------------|
  | REGISTRY  | |              | id PK            |
  |-----------|  -->           | courseid FK      |
  | id PK     |      +-------->| name             |
  | student_id|      |         | price            |
  | wallet_   |  +---+------+  | stock            |
  | address   |  | EVENT    |  | enabled          |
  | private_  |  | REGISTRY |  +------------------+
  | key_enc   |  |----------|
  | status    |  | event_id |    aplica  +------------------+
  +-----------+  | PK       +----------->| AUDIT_LOG        |
                 | userid FK|            |------------------|
  +----------+   | courseid |            | event_id PK      |
  | EARNINGS |   | FK       |            | tx_badge         |
  |----------|   | cmid     |   produce  | tx_mrt           |
  | id PK    |   | event_   +----------->| mrt_amount       |
  | userid FK|   | type     |            | cid_ipfs         |
  | courseid |   | grade    |            | created_at       |
  | FK       |   | coins_   |            +------------------+
  | coins_   |   | amount   |
  | earned   |   | wallet   |
  +----------+   | status   |
                 +----------+
  +----------+
  | SPEND    |         +------------------+     +-------------------+
  |----------|         | BADGE_TEMPLATE   |     | BADGE_AWARD       |
  | id PK    |         |------------------|     |-------------------|
  | userid FK|         | id PK            |     | id PK             |
  | courseid |  instan.|  name            +---->| template_id FK    |
  | FK       |  cia    | image_url        |     | student_id        |
  | reward_  |         | criteria         |     | student_wallet    |
  | code     |         | is_active        |     | course_id         |
  | coins_   |         +------------------+     | revoked           |
  | spent    |                                  | tx_hash           |
  | status   |         +------------------+     | issued_at         |
  +----------+         | BADGE            |     +-------------------+
                       |------------------|
  +------------------+ | id PK            |
  | COURSE_ENROLLMENT| | userid FK        |
  |------------------| | courseid FK      |
  | id PK            | | badge_name       |
  | student_id       | | badge_type       |
  | course_id        | | image_url        |
  | wallet_address   | | verify_hash      |
  | status           | | UNIQUE           |
  | mrt_snapshot     | | timecreated      |
  | expires_at       | +------------------+
  +------------------+

  ┌──────────────────────────────────────────────────────────────────────────────────┐
  │  ENTIDADES EN POSTGRESQL (Backend)                                              │
  └──────────────────────────────────────────────────────────────────────────────────┘

  +----------------+    +-------------------+    +-----------------+
  | events         |    | audit_log         |    | wallet_registry |
  | (idempotencia) |    | (tx_hash por ev.) |    | (wallets custo.)|
  +----------------+    +-------------------+    +-----------------+

  +--------------------+    +------------------+    +-----------+
  | course_enrollment  |    | badge_templates  |    | skills    |
  | (expires_at,       |    | (insignias man.) |    |           |
  |  mrt_snapshot)     |    +------------------+    +-----------+
  +--------------------+

  +------------------+
  | badge_awards     |
  | (revoked,        |
  |  chain_status)   |
  +------------------+
```

### 9.2 Esquema en MariaDB (Plugin Moodle)

Tablas creadas por `db/install.xml` v2026051001:

| Tabla | Propósito |
|-------|-----------|
| `local_meritcoin_queue` | Cola de eventos pendientes. Índice único en `event_id`. |
| `local_meritcoin_rules` | Reglas de recompensa por `(courseid, cmid, rule_scope, mod_type)`. |
| `local_meritcoin_earnings` | Ledger de monedas ganadas por curso. |
| `local_meritcoin_spend` | Ledger de monedas gastadas en el marketplace por curso. |
| `local_meritcoin_course_config` | Configuración por curso (`coin_name`, `coin_symbol`, contrato override). |
| `local_meritcoin_rewards` | Recompensas creadas por el profesor (precio, stock, habilitada). |
| `local_meritcoin_redemptions` | Historial de canjes. |
| `local_meritcoin_badges` | Insignias emitidas (caché local), con `verify_hash` único. |
| `local_meritcoin_badge_types` | Tipos de insignia (color, ícono). |
| `local_meritcoin_badge_templates` | Plantillas reutilizables por curso. |
| `local_meritcoin_pilot_courses` | Cursos marcados como piloto (wallet custodial automática). |
| `local_meritcoin_wallets` | Caché local de wallets custodiales devueltas por el backend. |

### 9.3 Esquema en PostgreSQL (Backend)

| Tabla | Propósito |
|-------|-----------|
| `events` | Registro de cada evento académico recibido (idempotencia por `event_id`). |
| `audit_log` | `tx_badge`, `tx_mrt`, `cid_ipfs`, `mrt_amount` para cada `event_id`. |
| `wallet_registry` | Wallet custodial permanente por estudiante (`private_key_enc` con Fernet). |
| `course_enrollment` | Inscripción del estudiante a un curso piloto, con `expires_at` y `mrt_snapshot` al cierre. |
| `badge_templates` | Plantillas para insignias manuales (badge templates del flujo `/badges/*`). |
| `badge_awards` | Emisiones manuales de insignias (`revoked`, `tx_hash`, `chain_status`). |
| `skills` | Habilidades asociables a plantillas de insignia. |

### 9.4 Datos en la blockchain

| Concepto | Almacenamiento on-chain |
|---------|-------------------------|
| Balance MRT | Mapping interno del contrato ERC-20. |
| Propietarios de badges | Mapping interno del contrato ERC-1155 + URI por `tokenId`. |
| Eventos `TokensMinted`, `TokensBurned`, `BadgeMinted` | Logs estándar EVM, consultables por filtro. |
| Roles `MINTER_ROLE`, `ISSUER_ROLE`, `BURNER_ROLE` | Storage del contrato (`AccessControl`). |

**No se almacena ningún dato personal en la blockchain.** Solo direcciones de wallet y `tokenId`s.

### 9.5 Política de retención y privacidad

- Las claves privadas custodiales nunca se exponen por API (modelo `WalletResponse` no incluye `private_key_enc`).
- Los datos académicos se conservan mientras dure el ciclo académico del curso; el snapshot MRT al cierre permite cerrar la economía del curso sin perder histórico.
- La separación entre Moodle (datos personales) y Backend (identificadores ofuscados) facilita el cumplimiento de la **Ley 1581/2012**.

---

## 10. Diseño de Interfaces

### 10.1 Interfaces de Usuario (UI)

| Interfaz | Tipo | Tecnología | Audiencia | Notas de diseño |
|----------|------|-----------|-----------|-----------------|
| Dashboard del estudiante (`dashboard.php`) | Web embebida en Moodle | PHP + Bootstrap + JS puro | Estudiante | Grid `flex-wrap` 150 px; fallback `onerror` para imágenes; modal con colores explícitos `!important` (resistente a dark mode). |
| Marketplace del estudiante (`marketplace.php`) | Web embebida | PHP + Bootstrap | Estudiante | Muestra solo recompensas del curso actual; valida saldo `earnings - spend`. |
| Gestión de reglas (`manage.php` / `editrule.php`) | Web embebida | PHP + Moodle Form API | Profesor | Dropdown dinámico de módulos del curso; selector de scope `activity / activity_type / course`. |
| Gestión de recompensas (`rewards.php`) | Web embebida | PHP + Bootstrap | Profesor | CRUD de recompensas con precio en MRT y stock. |
| Reporte de transacciones del curso (`teacher_transactions.php`) | Web embebida | PHP | Profesor | Filtros por estudiante; muestra otorgados vs canjes. |
| Panel global (`admin_marketplace.php`) | Web embebida | PHP | Admin Moodle | KPIs globales, pestaña "Todas las transacciones" filtrable. |
| Configuración del plugin (`settings.php`) | Web embebida | PHP (Moodle Admin Settings API) | Admin Moodle | URL backend, HMAC secret, límite MRT por curso. |
| Página de verificación pública (`badge_verify.php`) | Web standalone (sin layout Moodle) | PHP + CSS inline + JS puro | Verificador externo | Acceso por hash, sin login; toggle del hash técnico en JS puro; carga FontAwesome desde CDN. |
| Certificado PDF (`badge_pdf.php`) | HTML A4 imprimible | PHP + CSS inline + `window.print()` | Estudiante / Verificador | `@media print` oculta los botones; firma estilizada con Playfair Display italic. |

### 10.2 Interfaces de Servicio (REST)

#### 10.2.1 Endpoints del Backend FastAPI

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| `GET` | `/health` | — | Estado del servicio y conexión al nodo Besu. |
| `POST` | `/events/ingest` | HMAC | Recibe un evento académico firmado y dispara `mint`. |
| `GET` | `/students/{wallet}/badges` | — | Insignias emitidas por flujo automático. |
| `GET` | `/students/{wallet}/balance` | — | Saldo MRT real (consulta blockchain). |
| `GET` | `/students/{wallet}/summary` | — | Saldo + insignias (consumido por el dashboard del plugin). |
| `POST` | `/tokens/spend` | — | Quema MRT al canjear (`burnFrom`). |
| `POST` | `/wallets/provision` | — | Provisiona wallet custodial para un estudiante en un curso piloto. |
| `GET` | `/wallets/{student_id}` | — | Consulta wallet (sin `private_key`). |
| `POST` | `/wallets/expire-course` | — | Cierra todos los enrollments de un curso al fin de semestre. |
| `PATCH` | `/wallets/enrollments/{student_id}/{course_id}` | — | Actualiza `expires_at` de un enrollment. |
| `POST` | `/skills` / `GET /skills` | — | CRUD de habilidades. |
| `POST` | `/badges/templates` / `GET / PATCH / DELETE` | — | CRUD de plantillas de insignia. |
| `POST` | `/badges/award` | — | Otorga insignia manualmente (flujo `BadgeAward`). |
| `GET` | `/badges/student/{student_id}` | — | Insignias manuales de un estudiante. |
| `DELETE` | `/badges/award/{award_id}` | — | Revoca una insignia manual. |
| `GET` | `/verify/{award_id}` | público | Datos de verificación pública. |
| `GET` | `/badges/award/{award_id}/certificate` | público | Descarga el PDF del certificado. |

> El esquema completo está disponible en `/docs` (FastAPI OpenAPI / Swagger UI).

#### 10.2.2 Contrato HMAC para `/events/ingest`

- Header: `X-Signature: <hmac_sha256_hex(HMAC_SECRET, body_crudo)>`
- Cualquier desviación en el body (incluido orden de campos, espacios, encoding) invalida la firma.
- El backend devuelve `401 Unauthorized` ante firma inválida.

#### 10.2.3 Payload de `/events/ingest`

```json
{
  "event_id":       "evt-<md5>",
  "student_wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
  "student_id":     "STU-3",
  "course_id":      "COURSE-5",
  "course_name":    "Introducción a Blockchain",
  "activity_id":    "CM-42",
  "activity_name":  "Quiz Semana 3",
  "event_type":     "grade",
  "grade":          85.0,
  "coins_amount":   1.0,
  "coin_symbol":    "MRT",
  "coin_name":      "MeritCoin",
  "timestamp":      "2026-03-10T21:00:00Z"
}
```

### 10.3 Interfaces blockchain

| Función | Contrato | Roles requeridos | Idempotencia |
|---------|----------|-------------------|--------------|
| `mint(address, uint256)` | `MeritCoinERC20` | `MINTER_ROLE` | Garantizada off-chain por `event_id`. |
| `burnFrom(address, uint256)` | `MeritCoinERC20` | `BURNER_ROLE` | Lado del canje. |
| `mintBadge(address, uint256, string)` | `MeritBadges1155` | `ISSUER_ROLE` | On-chain: `BadgeAlreadyMinted` revierte ante doble emisión `(to, id)`. |
| `pause()` / `unpause()` | Ambos | `DEFAULT_ADMIN_ROLE` | — |

---

## 11. Entorno de Despliegue

### 11.1 Vista de Despliegue (MVP local)

```
  +=========================================================================+
  | MÁQUINA HOST CON DOCKER                                                 |
  |                                                                         |
  |  +------------------------------------+                                 |
  |  | docker-compose.yml principal       |                                 |
  |  |                                    |                                 |
  |  |  +-------------------+  :8080      |                                 |
  |  |  | meritcoin-moodle  +------+      |                                 |
  |  |  +-------------------+      |      |                                 |
  |  |                             | HTTP + HMAC                            |
  |  |  +-------------------+      |      |                                 |
  |  |  | meritcoin-mariadb | :3306|      |                                 |
  |  |  | (usa Moodle)  <---+------+      |                                 |
  |  |  +-------------------+      |      |                                 |
  |  |                             v      |                                 |
  |  |  +-------------------+  :8000      |                                 |
  |  |  | meritcoin-backend +--+   |      |                                 |
  |  |  | (FastAPI)         |  |   |      |                                 |
  |  |  +-------------------+  |   |      |                                 |
  |  |                         |   |      |                                 |
  |  |  +-------------------+  |   |      |                                 |
  |  |  | meritcoin-postgres|<-+   |      |                                 |
  |  |  | (auditoría)  :5432|      |      |                                 |
  |  |  +-------------------+      |      |                                 |
  |  +------------------------------------+                                 |
  |                                |                                        |
  |                  host.docker.internal:8545                              |
  |                                |                                        |
  |  +-----------------------------v----------------------------------+     |
  |  | besu/QBFT-Network/docker-compose.yml                           |     |
  |  |                                                                |     |
  |  |  +------------------+      +------------------+                |     |
  |  |  | besu-node-1      |<---->| besu-node-2      |                |     |
  |  |  | :8545 RPC        |      | :8546            |                |     |
  |  |  | :30303 P2P       |      | :30304 P2P       |                |     |
  |  |  +------------------+      +------------------+                |     |
  |  |         ^                          ^                           |     |
  |  |         |                          |                           |     |
  |  |  +------+------+           +-------+----------+                |     |
  |  |  | besu-node-4 |<--------->| besu-node-3      |                |     |
  |  |  | :8548       |           | :8547            |                |     |
  |  |  +-------------+           +------------------+                |     |
  |  +----------------------------------------------------------------+     |
  |                                                                         |
  |  +----------------------------+                                         |
  |  | Hardhat CLI                |                                         |
  |  | compile + deploy           +----> (deploy a besu-node-1)             |
  |  | (herramienta externa)      |                                         |
  |  +----------------------------+                                         |
  +=========================================================================+
```

### 11.2 Nodos lógicos y físicos

| Nodo | Imagen / proceso | Puertos | Volumen |
|------|------------------|---------|---------|
| `meritcoin-mariadb` | `bitnamilegacy/mariadb:10.11.9` | 3306 | `mariadb_data` |
| `meritcoin-moodle` | `bitnamilegacy/moodle:4.3.8` | 8080, 8443 | `moodle_data`, `moodledata`, `./plugin:/bitnami/moodle/local/meritcoin` |
| `meritcoin-postgres` | `postgres:16-alpine` | 5432 | `postgres_data` |
| `meritcoin-backend` | Build local (`./backend`) — FastAPI + uvicorn | 8000 | `./backend:/app` |
| `besu-node-1..4` | `hyperledger/besu:latest` | 8545–8548 RPC, 30303–30306 P2P | `Node-X/data` |

### 11.3 Red Hyperledger Besu — Parámetros

| Parámetro | Valor |
|-----------|-------|
| Consenso | QBFT (Byzantine Fault Tolerant) |
| `chainId` | 1337 |
| `blockperiodseconds` | 2 |
| `epochlength` | 30000 |
| `requesttimeoutseconds` | 4 |
| Hardforks | Berlin, London, Paris, Shanghai, Cancun (todos en bloque 0) |
| Validadores iniciales | 4 nodos (declarados en `extraData` del génesis) |
| Cuentas pre-fondeadas | `0x41c9e41a5ac82966819a59229753ef1ad6d98cf9`, `0xFE3B557E8Fb62b89F4916B721be55cEb828dBd73` |
| API RPC habilitadas | ETH, NET, QBFT, WEB3, TXPOOL |

### 11.4 Variables de entorno críticas

| Variable | Componente | Obligatoria | Descripción |
|----------|-----------|-------------|-------------|
| `WALLET_ENCRYPTION_KEY` | Backend | Sí | Clave Fernet para cifrar claves privadas custodiales. |
| `HMAC_SECRET` | Backend + Plugin | Sí | Secreto compartido HMAC. |
| `DEPLOYER_PRIVATE_KEY` | Backend | Sí | Clave que firma las tx de mint/burn. |
| `BLOCKCHAIN_RPC_URL` | Backend | Sí | URL del nodo Besu (`http://host.docker.internal:8545` desde Docker en Linux). |
| `MRT_CONTRACT_ADDRESS` | Backend | Sí (post-deploy) | Dirección del contrato ERC-20. |
| `BADGE_CONTRACT_ADDRESS` | Backend | Sí (post-deploy) | Dirección del contrato ERC-1155. |
| `DATABASE_URL` | Backend | Sí | DSN async de PostgreSQL. |
| `student_course_limit` | Plugin (config) | No | Límite MRT por estudiante/curso (default 16). |

### 11.5 Comunicación entre dominios

```
  +---------------------------+         +---------------------------+
  | Moodle                    |         | Backend FastAPI           |
  | red: meritcoin-net        |         | red: meritcoin-net        |
  | (192.168.x.x)             |         | meritcoin-backend:8000    |
  +----------+----------------+         +------+------+-------------+
             |                                  |      |
             |  HTTPS + HMAC                    |      |
             |  meritcoin-backend:8000          |      |
             +--------------------------------->|      |
                                                |      |  TCP
                                                |      |  meritcoin-postgres:5432
                                                |      v
                                                |  +---+----------+
                                                |  | PostgreSQL   |
                                                |  | (auditoría y |
                                                |  |  wallets)    |
                                                |  +--------------+
                                                |
                                                |  JSON-RPC
                                                |  host.docker.internal:8545
                                                v
                                      +---------+----------+
                                      | Red Besu           |
                                      | red: besu-network  |
                                      | (contratos ERC-20  |
                                      |  y ERC-1155)       |
                                      +--------------------+
  +---------------------------+
  | MariaDB                   |
  | meritcoin-mariadb:3306    |
  | (usada por Moodle via TCP)|
  +---------------------------+
  ^
  |  TCP   meritcoin-mariadb:3306
  |
  +--- (Moodle)
```

### 11.6 Entorno objetivo — SAVIO (post-MVP)

En una prueba real sobre SAVIO, la responsabilidad de operar los nodos no debería recaer sobre profesores o estudiantes. Aunque Besu recomienda una red con varios validadores para evitar un único punto de falla, esos validadores pueden ser **nodos lógicos separados** administrados por la misma área de TI, siempre que estén desplegados en máquinas virtuales, hosts, zonas o políticas de respaldo distintas.

La propuesta institucional más realista para UTB es:

- Plugin desplegado en la instancia SAVIO institucional.
- Backend FastAPI desplegado en infraestructura institucional con TLS, variables secretas protegidas y monitoreo.
- Red Besu privada institucional con cuatro validadores, operados técnicamente por TI pero con responsabilidades funcionales asignadas a áreas distintas.
- Reemplazo del CID IPFS simulado por pin real a nodo IPFS institucional o a un proveedor de pinning.
- Monitoreo con Prometheus + Grafana, logs estructurados y procedimiento de pausa de contratos ante incidente.

#### Representación de nodos en una implementación SAVIO

| Nodo Besu | Representación institucional propuesta | Responsable técnico | Rol funcional |
|-----------|----------------------------------------|---------------------|---------------|
| Validador 1 | Infraestructura principal TI | Dirección / área de TI | Nodo RPC primario para backend y despliegues controlados. |
| Validador 2 | Infraestructura de continuidad o respaldo TI | Dirección / área de TI | Alta disponibilidad y tolerancia a falla del nodo primario. |
| Validador 3 | Servicio SAVIO / LMS institucional | Equipo que administra Moodle/SAVIO, apoyado por TI | Alinea la red con el sistema académico que origina los eventos. |
| Validador 4 | Auditoría académica, registro académico o programa piloto, operado por TI | TI como custodio técnico; área académica como custodio funcional | Nodo de independencia institucional para trazabilidad y verificación. |

Si la universidad no desea distribuir operación entre varias dependencias, la alternativa aceptable para el piloto es mantener los cuatro validadores bajo TI, pero desplegados en cuatro instancias separadas. En ese caso, la separación no sería organizacional sino operativa: distintos contenedores/VM, respaldos, llaves de nodo y políticas de recuperación.

```
  +=========================================================================+
  | UNIVERSIDAD TECNOLÓGICA DE BOLÍVAR                                      |
  |                                                                         |
  |  +------------------+       +----------------------------------+        |
  |  | Estudiantes y    |       | SAVIO / Moodle                   |        |
  |  | Profesores       +------>| (origen de eventos académicos)   |        |
  |  +------------------+       +----------------+-----------------+        |
  |                                              |                          |
  |                                   eventos firmados                      |
  |                                   HTTPS + HMAC                          |
  |                                              |                          |
  |                                              v                          |
  |                             +----------------+-----------------+        |
  |                             | Backend MeritCoin                |        |
  |                             | (orquestación y auditoría)       |        |
  |                             +--------+----------+--------------+        |
  |                                      |          |                       |
  |                         transacciones|          |lectura blockchain     |
  |                                      |          |                       |
  |                                      v          v                       |
  |  +====================================================+                 |
  |  | RED BESU PRIVADA INSTITUCIONAL                     |                 |
  |  |                                                    |                 |
  |  |  +------------------+    +------------------+      |                 |
  |  |  | Validador 1      |<-->| Validador 2      |      |                 |
  |  |  | TI - infra.      |    | TI - respaldo /  |      |                 |
  |  |  | principal        |    | continuidad      |      |                 |
  |  |  +------------------+    +------------------+      |                 |
  |  |           ^                       ^                |                 |
  |  |           |                       |                |                 |
  |  |  +--------+---------+    +--------+---------+      |                 |
  |  |  | Validador 4      |<-->| Validador 3      |      |                 |
  |  |  | Auditoría        |    | SAVIO / LMS      |      |                 |
  |  |  | académica /      |    | institucional    |      |                 |
  |  |  | programa piloto  |    |                  |      |                 |
  |  |  +------------------+    +------------------+      |                 |
  |  +====================================================+                 |
  |                                                                         |
  |  +------------------+                                                   |
  |  | Auditoría y      |------>  (consulta evidencias al Backend)          |
  |  | reportes         |                                                   |
  |  +------------------+                                                   |
  +=========================================================================+
```

#### Justificación de los cuatro nodos

Con cuatro validadores se evita que MeritCoin dependa de una sola máquina. En términos de gobierno institucional, los nodos no representan necesariamente cuatro dueños de negocio diferentes; representan cuatro puntos de validación separados para mantener continuidad, trazabilidad y resistencia ante fallas. Para el proyecto de grado, la recomendación es presentar los nodos como **responsabilidad de TI con custodios funcionales**, no como servidores que cada facultad deba administrar.

El cambio entre entorno actual y SAVIO requiere modificar variables de entorno, certificados TLS, secretos, URLs y plantillas visuales del plugin, sin redefinir contratos.

---

## 12. Decisiones de Arquitectura (ADR)

### ADR-001: Cálculo de monedas en el plugin, no en el backend

**Contexto.** Las reglas de recompensa dependen del LMS (catálogo de actividades, tipo de módulo, calificaciones).
**Decisión.** El plugin calcula `coins_amount` y lo envía como fuente de verdad al backend.
**Consecuencias.** (+) Backend agnóstico al LMS, sustituible. (+) Reglas sin redeploy del backend. (−) El backend confía en el valor declarado por el plugin.

### ADR-002: Comunicación asíncrona Moodle → Backend

**Decisión.** El observer encola; una tarea programada Moodle envía cada minuto.
**Consecuencias.** (+) UX inmediata para el usuario. (+) Reintentos si el backend cae. (−) Latencia hasta ~1 min entre logro y emisión on-chain.

### ADR-003: Doble base de datos (MariaDB + PostgreSQL)

**Decisión.** MariaDB para el plugin Moodle; PostgreSQL para el backend.
**Consecuencias.** (+) Cada sistema usa su motor nativo. (+) Aislamiento operativo. (−) Mayor complejidad operacional.

### ADR-004: IPFS simulado en el MVP

**Decisión.** `badges_service` produce un CID simulado (`QmSimulated...`); no se hace pin real.
**Consecuencias.** (+) Entorno de desarrollo simple. (−) Los metadatos OBv2 no son verificables públicamente fuera del backend. En SAVIO se prevé pinning real.

### ADR-005: `file_get_contents` en lugar de cURL desde el plugin

**Contexto.** La imagen Bitnami de Moodle tiene cURL deshabilitado por defecto.
**Decisión.** Usar `file_get_contents` con `stream_context_create`.
**Consecuencias.** (+) Compatible con la imagen oficial. (−) Control de timeout menos granular.

### ADR-006: Saldo del marketplace basado en ledger local + validación del contrato

**Decisión.** El saldo gastable se calcula por curso a partir de `earnings - spend`, y se valida también contra el balance ERC-20 real antes de canjear.
**Consecuencias.** (+) Saldo independiente por curso. (+) Evita aceptar canjes si el mint falló silenciosamente. (−) Una llamada extra al backend en cada carga del marketplace.

### ADR-007: `event_id` determinístico para idempotencia desde el origen

**Decisión.** `event_id = md5(userid + courseid + cmid + type)` generado en el observer; índice único en BD; reserva previa en backend.
**Consecuencias.** (+) Idempotencia en tres capas. (−) Una misma nota repetida sobre la misma actividad no emite nuevos MRT (aceptado como trade-off).

### ADR-008: Hyperledger Besu QBFT (red permisionada privada)

**Contexto.** Se requiere una red EVM compatible con OpenZeppelin que sea controlable por la UTB.
**Decisión.** Usar Besu con consenso QBFT y 4 nodos validadores en el MVP.
**Consecuencias.** (+) EVM-compatible: contratos OpenZeppelin sin modificación. (+) Red permisionada adecuada para entorno institucional. (+) Finalidad por bloque de ~2 s. (−) Requiere mantener nodos validadores. (−) Java 21+ si se ejecuta fuera de Docker.
**Alternativa considerada y descartada.** L2 pública (Polygon, Arbitrum, Optimism) mencionada en el SAD original — descartada porque introduce dependencia externa, costos de gas en stablecoin/ETH y exposición pública de patrones de uso académicos.

### ADR-009: Wallets custodiales cifradas (con opción futura non-custodial)

**Decisión.** El backend genera y custodia las claves privadas de los estudiantes en cursos piloto; las claves se cifran con Fernet usando `WALLET_ENCRYPTION_KEY`.
**Consecuencias.** (+) UX sin fricción: el estudiante no maneja claves ni gas. (+) Cumple objetivos académicos sin alfabetización cripto previa. (−) El backend se convierte en activo crítico. (−) Modelo non-custodial queda como evolución futura.

### ADR-010: Páginas standalone sin layout Moodle para verificación y PDF

**Decisión.** `badge_verify.php` y `badge_pdf.php` no llaman a `$OUTPUT->header()` ni `$OUTPUT->footer()` y traen su propio CSS inline.
**Consecuencias.** (+) Verificación y descarga accesibles sin cuenta Moodle. (+) PDF sin dependencias PHP (TCPDF/mPDF). (−) Estilos no heredan del tema; cualquier cambio de marca requiere editar dos páginas.

### ADR-011: JavaScript puro para interactividad

**Decisión.** Todos los toggles, modales y comportamientos usan `addEventListener`, sin `data-bs-toggle` ni `data-toggle`.
**Consecuencias.** (+) Compatible con BS4 y BS5. (+) Funciona aunque el tema cambie su versión de Bootstrap. (−) Más código JS que mantener.

### ADR-012: Diferimiento de Relayer / Paymaster y meta-transacciones EIP-712

**Contexto.** El SAD original contemplaba un relayer/paymaster para experiencias sin gas firmadas por el usuario.
**Decisión.** En el MVP, todas las transacciones se firman con la clave del deployer (`DEPLOYER_PRIVATE_KEY`). El uso de meta-transacciones queda como evolución posterior cuando se migre a un modelo non-custodial o se exponga a una red con costo de gas significativo.
**Consecuencias.** (+) Reduce la superficie de implementación del MVP. (−) Un solo punto de firma (mitigado en producción con HSM/multisig).

### ADR-013: Idempotencia on-chain en `MeritBadges1155`

**Decisión.** El contrato mantiene un mapping `_minted[keccak256(to, id)]` que revierte ante doble emisión a la misma wallet del mismo `badgeId`.
**Consecuencias.** (+) Tercera capa de defensa contra duplicados. (−) Limita la semántica de "varias unidades del mismo badge" — diseño deliberado.

---

## 13. Riesgos, Deuda Técnica y Restricciones

| ID | Categoría | Descripción | Impacto | Mitigación |
|----|-----------|-------------|---------|-----------|
| R-01 | Riesgo | CID IPFS simulado; metadatos OBv2 no verificables externamente. | Alto en producción | Integrar Pinata o nodo IPFS institucional antes del despliegue en SAVIO. |
| R-02 | Riesgo | `DEPLOYER_PRIVATE_KEY` en `.env`; compromiso permitiría mintear arbitrariamente. | Alto en producción | HSM o multisig Gnosis Safe en SAVIO. |
| R-03 | Deuda | Sin reintento automático con backoff para eventos `failed`. | Medio | Implementar en `send_events_task.php`. |
| R-04 | Riesgo | Génesis y validadores Besu del MVP no coinciden necesariamente con la red institucional. | Medio | Validar en staging con la configuración definitiva antes de SAVIO. |
| R-05 | Deuda | `file_get_contents` no permite timeout granular. | Bajo | Habilitar cURL en imagen Bitnami o usar Guzzle. |
| R-06 | Deuda | Volumen `./plugin:/bitnami/moodle/local/meritcoin` puede quedar comentado tras reinicios. | Bajo | Documentado en README; healthcheck del mount como mejora. |
| R-07 | Deuda | La clave `teacher_weekly_limit` realmente representa "límite por curso/estudiante" y no se reinicia semanalmente. | Bajo | Renombrar en migración futura. |
| R-08 | Deuda | Estilos de `badge_verify.php` y `badge_pdf.php` son CSS inline. | Bajo | Extraer a `styles/public.css`. |
| R-09 | Riesgo | Dark mode del SO/Moodle puede afectar componentes no protegidos. | Medio | Auditar componentes con `prefers-color-scheme: dark`. |
| R-10 | Riesgo | Cobertura de pruebas del backend no cubre todos los flujos de marketplace ni wallets custodiales con la misma profundidad. | Medio | Ampliar tests pytest. |
| R-11 | Restricción | No existen mecanismos de governance on-chain (DAO, votación). | N/A | Fuera de alcance del proyecto de grado. |
| R-12 | Restricción | El plugin requiere que el servidor PHP tenga `allow_url_fopen=On`. | Bajo | Documentado; alternativa Guzzle si la institución lo prohíbe. |
| R-13 | Supuesto pendiente | LTI 1.3 / OIDC con SSO institucional no implementado; el plugin se apoya en la autenticación nativa de Moodle. | Medio | A evaluar al integrar con SAVIO. |
| R-14 | Supuesto pendiente | El campo `wallet` se administra manualmente en perfiles no piloto; en piloto se aprovisiona custodialmente. | Bajo | Documentado. |

---

## 14. Glosario y Trazabilidad

### 14.1 Mapa entre código y secciones de este documento

| Sección | Artefactos principales en el repositorio |
|---------|-----------------------------------------|
| 5. Requerimientos | `plugin/`, `backend/app/`, `contracts/contracts/` |
| 7.2 Contenedores | `docker-compose.yml`, `besu/QBFT-Network/docker-compose.yml` |
| 7.3 Plugin | `plugin/classes/`, `plugin/db/install.xml`, `plugin/*.php` |
| 7.4 Backend | `backend/app/api/`, `backend/app/services/`, `backend/app/core/`, `backend/app/models/` |
| 7.5 Flujo de emisión | `plugin/classes/observer.php`, `backend/app/services/events_service.py`, `backend/app/services/blockchain.py` |
| 9. Modelo de datos | `plugin/db/install.xml`, `backend/app/models/*.py`, `backend/alembic/` |
| 10.2 API | `backend/app/api/*.py`, `backend/app/main.py` |
| 11. Despliegue | `docker-compose.yml`, `besu/QBFT-Network/`, `contracts/scripts/deploy.js`, `backend/Dockerfile` |
| 12. ADR | `MeritCoinERC20.sol`, `MeritBadges1155.sol`, `backend/app/services/blockchain.py`, `plugin/classes/api_client.php` |

### 14.2 Versiones

| Componente | Versión |
|-----------|---------|
| Moodle | 4.3.8 (Bitnami) |
| MariaDB | 10.11.9 |
| PostgreSQL | 16 (alpine) |
| Backend (FastAPI) | 0.5.0 |
| Plugin (`local_meritcoin`) | 2026051001 (XMLDB) |
| Solidity | 0.8.28 |
| OpenZeppelin Contracts | 5.x |
| Hardhat | 2.28 |
| Hyperledger Besu | `latest` (imagen Docker), QBFT |
| Documento ARC42 | 1.0 (mayo 2026) |

---

*Documento ARC42 — MeritCoin v1.0 — Universidad Tecnológica de Bolívar, Mayo 2026.*
