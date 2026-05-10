# Plugin MeritCoin para Moodle (`local_meritcoin`)

Plugin local de Moodle que captura eventos de calificación, calcula las
monedas MRT correspondientes según las reglas del curso, gestiona wallets
custodiales para cursos piloto y envía los eventos al backend FastAPI
firmados con HMAC-SHA256.

## Datos del plugin

| Campo     | Valor                          |
|-----------|--------------------------------|
| Tipo      | Plugin local (local_meritcoin) |
| Versión   | 0.5.1 (2026051001)             |
| Requiere  | Moodle 4.3+                    |
| Madurez   | MATURITY_ALPHA                 |
| Licencia  | GNU GPL v3                     |

## Estructura

```
plugin/
├── classes/
│   ├── observer.php                      # Captura eventos de Moodle
│   ├── api_client.php                    # Cliente HTTP con firma HMAC
│   ├── rules_service.php                 # Lógica de reglas y cálculo de monedas
│   ├── wallet_service.php                # Provisionado automático de wallets custodiales
│   └── task/
│       ├── send_events_task.php          # Tarea programada: envío de eventos (cada minuto)
│       ├── process_redemptions_task.php  # Tarea programada: canjes del marketplace (cada minuto)
│       └── expire_courses_task.php       # Tarea programada: cierre de semestre (cron diario 2 AM)
├── db/
│   ├── install.xml   # Tablas de la BD (9 tablas)
│   ├── upgrade.php   # Migraciones de BD (hasta v2026051001)
│   ├── events.php    # Suscripción a eventos de Moodle
│   ├── tasks.php     # Registro de tareas programadas
│   └── access.php    # Capacidades y permisos
├── lang/
│   ├── en/local_meritcoin.php   # Strings en inglés
│   └── es/local_meritcoin.php   # Strings en español
├── settings.php              # Página de configuración admin
├── admin_pilot_courses.php   # Panel admin: gestión de cursos piloto
├── lib.php                   # Funciones auxiliares y hooks de navegación
└── version.php               # Metadatos del plugin
```

## Flujo de funcionamiento

```
Estudiante recibe una calificación en una actividad
                    │
                    ▼
        observer.php captura user_graded
                    │
                    ▼
   rules_service calcula monedas MRT según reglas del curso
   (prioridad: actividad específica > tipo de módulo > curso)
                    │
                    ├─ coins = 0 → descarta el evento
                    │
                    ▼
   ¿Es curso piloto? ──── Sí ──→ wallet_service::get_or_provision()
                    │                     │
                    │ No                  ▼
                    │            POST /wallets/provision
                    │            guarda en mdl_local_meritcoin_wallets
                    │                     │
                    ▼─────────────────────┘
   Inserta registro en local_meritcoin_queue
   (status = pending | pending_wallet si no tiene wallet)
                    │
                    ▼
   send_events_task.php (cada 60 segundos via cron)
                    │
                    ├─ Reactiva pending_wallet si el estudiante ya tiene wallet
                    │
                    ▼
   api_client.php envía al backend con firma HMAC-SHA256
                    │
                    ├─ OK    → status = sent, registra en local_meritcoin_earnings
                    └─ Error → attempts++; si attempts >= 5 → status = failed
```

Al final del semestre:
```
expire_courses_task (cron 2 AM diario)
        │
        ▼
Detecta cursos piloto vencidos (expires_at <= now o course.enddate <= now)
        │
        ▼
POST /wallets/expire-course → backend cierra enrollments y guarda snapshot MRT
        │
        ▼
Marca el piloto como pilot_enabled = 0
```

## Eventos capturados

| Evento Moodle              | Tipo enviado | Condición                                 |
|----------------------------|--------------|-------------------------------------------|
| `\core\event\user_graded` | `grade`      | Se registra calificación en una actividad |

> `course_completed` fue eliminado intencionalmente. MeritCoin solo premia
> calificaciones de actividades, no la finalización de cursos.

## Sistema de wallets custodiales

A partir de v0.5.1 el plugin soporta asignación automática de wallets para
estudiantes en **cursos piloto**, sin requerir que el estudiante configure
nada manualmente.

### Configuración (admin)

1. Ve a **Administración del sitio → MeritCoin → Cursos Piloto** (`admin_pilot_courses.php`).
2. Selecciona el curso y opcionalmente:
   - **Grupo piloto**: solo los estudiantes de ese grupo recibirán wallet custodial.
   - **Fecha de cierre manual**: sobreescribe la fecha de fin del curso (`mdl_course.enddate`).
3. Guarda. A partir de ese momento, el sistema gestiona las wallets automáticamente.

### Ciclo de vida

| Evento | Acción del sistema |
|---|---|
| Primera calificación del semestre | `wallet_service` provisiona wallet via backend |
| Cursos anteriores del mismo estudiante | Reutiliza la misma wallet (1 estudiante = 1 wallet permanente) |
| Fin de semestre (cron 2 AM) | `expire_courses_task` cierra el enrollment y congela el saldo MRT |
| Rematrícula en el siguiente semestre | Nuevo enrollment con saldo 0; wallet y badges anteriores se conservan |

### Fallback a wallet manual

Si el curso **no es piloto**, el observer sigue leyendo la wallet del campo
de perfil de usuario (campo `wallet` por defecto). Ambos modos son compatibles
y pueden coexistir en el mismo Moodle.

## Tablas de la base de datos

### `local_meritcoin_queue` — cola de eventos pendientes

| Campo            | Tipo           | Descripción                                     |
|------------------|----------------|-------------------------------------------------|
| `event_id`       | varchar(255)   | ID único para idempotencia (MD5 determinístico) |
| `userid`         | int            | ID del usuario en Moodle                        |
| `courseid`       | int            | ID del curso                                    |
| `cmid`           | int\|null      | ID del course module (null = nivel de curso)    |
| `activity_name`  | varchar(255)   | Nombre de la actividad                          |
| `event_type`     | varchar(50)    | `grade`                                         |
| `grade`          | decimal(10,5)  | Calificación del estudiante                     |
| `coins_amount`   | decimal(10,2)  | MRT calculados según la regla                   |
| `student_wallet` | varchar(42)    | Wallet Ethereum; null si aún no registrada      |
| `payload`        | text           | JSON completo que se enviará al backend         |
| `status`         | varchar(20)    | `pending`, `pending_wallet`, `sent`, `failed`   |
| `attempts`       | int            | Número de intentos de envío                     |
| `last_error`     | text\|null     | Último error del backend                        |
| `timecreated`    | int            | Timestamp de creación                           |
| `timemodified`   | int            | Timestamp de última actualización               |

### `local_meritcoin_pilot_courses` — cursos piloto (NEW v0.5.1)

| Campo           | Tipo        | Descripción                                            |
|-----------------|-------------|--------------------------------------------------------|
| `courseid`      | int UNIQUE  | ID del curso Moodle                                    |
| `pilot_enabled` | int(1)      | 1 = activo, 0 = cerrado                                |
| `groupid`       | int\|null   | Grupo piloto (null = todos los estudiantes del curso)  |
| `expires_at`    | int\|null   | Timestamp de cierre manual (null = usa course.enddate) |
| `created_by`    | int         | ID del admin que configuró el piloto                   |
| `created_at`    | int         | Timestamp de creación                                  |

### `local_meritcoin_wallets` — caché de wallets custodiales (NEW v0.5.1)

| Campo             | Tipo        | Descripción                                         |
|-------------------|-------------|-----------------------------------------------------|
| `userid`          | int UNIQUE  | ID del usuario Moodle                               |
| `wallet_address`  | varchar(42) | Dirección Ethereum (espejo del backend)             |
| `status`          | varchar(20) | `active`                                            |
| `provisioned_at`  | int         | Timestamp del primer provisionado                   |

### `local_meritcoin_rules` — reglas de recompensa

| Campo          | Tipo          | Descripción                                            |
|----------------|---------------|--------------------------------------------------------|
| `courseid`     | int           | ID del curso                                           |
| `cmid`         | int\|null     | Null = regla de curso o tipo; valor = actividad exacta |
| `rule_scope`   | varchar(20)   | `activity`, `activity_type` o `course`                 |
| `mod_type`     | varchar(50)   | Tipo de módulo: `assign`, `forum`, `quiz`, etc.        |
| `coins_amount` | decimal(10,2) | MRT a otorgar                                          |
| `coin_symbol`  | varchar(20)   | Símbolo de la moneda del curso                         |
| `min_grade`    | decimal(10,5) | Nota mínima para activar la regla; null = sin umbral   |
| `enabled`      | int(1)        | 1 = activa, 0 = deshabilitada                          |

### `local_meritcoin_earnings` — ledger de ganancias por curso

Registra cada MRT otorgado tras un envío exitoso al backend. Usado para
calcular el saldo gastable del estudiante en el marketplace de cada curso.

### `local_meritcoin_redemptions` — historial de canjes

| Campo          | Tipo          | Descripción                                      |
|----------------|---------------|--------------------------------------------------|
| `userid`       | int           | Estudiante que canjea                            |
| `rewardid`     | int           | Recompensa canjeada                              |
| `coins_spent`  | decimal(10,2) | Precio al momento del canje                      |
| `tx_hash`      | varchar(66)   | Hash de la transacción; null mientras se procesa |
| `status`       | varchar(20)   | `pending`, `confirmed`, `failed`                 |
| `attempts`     | int           | Intentos de procesamiento                        |
| `last_error`   | text\|null    | Último error al procesar el canje                |

## Instalación

### Requisitos previos

1. Docker corriendo con `docker compose up -d` (Moodle + MariaDB + PostgreSQL)
2. Backend FastAPI levantado en puerto 8000
3. `WALLET_ENCRYPTION_KEY` configurada en `backend/.env`

### Paso 1: Colocar archivos del plugin

El `docker-compose.yml` ya monta la carpeta `./plugin` como volumen en:
```
/bitnami/moodle/local/meritcoin
```
El plugin se detecta automáticamente al reiniciar Moodle.

### Paso 2: Instalar en Moodle

1. Ir a `http://localhost:8080` e iniciar sesión como admin
   - Usuario: `admin`
   - Contraseña: `Admin1234!`
2. Moodle detecta el plugin nuevo y muestra la pantalla de actualización
3. Hacer clic en **Actualizar base de datos de Moodle**

### Paso 3: Configurar el plugin

**Ruta en menú:**
Administración del sitio > Plugins > Plugins locales > MeritCoin

| Campo                           | Valor recomendado (desarrollo)             |
|---------------------------------|---------------------------------------------|
| Habilitado                      | ✓ Sí                                        |
| URL del backend                 | `http://host.docker.internal:8000`          |
| Secreto HMAC                    | debe coincidir con `HMAC_SECRET` en `.env`  |
| Campo wallet                    | `wallet`                                    |
| Límite MRT por estudiante/curso | `0` (sin límite) o el valor deseado         |

### Paso 4a: Wallet manual (cursos no piloto)

1. Ir a: Administración del sitio > Usuarios > Campos de perfil de usuario
2. Crear campo de tipo **Entrada de texto** con nombre corto `wallet`.
3. Cada estudiante registra su dirección Ethereum en su perfil.

### Paso 4b: Cursos piloto (wallet custodial automática)

1. Ir a **Administración del sitio → MeritCoin → Cursos Piloto**.
2. Seleccionar el curso y configurar fecha de cierre (opcional).
3. El sistema gestiona las wallets automáticamente — el estudiante no necesita hacer nada.

## URL del backend según escenario

| Escenario                              | URL                                |
|----------------------------------------|------------------------------------|
| Moodle en Docker, backend en Windows   | `http://host.docker.internal:8000` |
| Ambos en Docker (misma red)            | `http://meritcoin-backend:8000`    |
| Ambos en la misma máquina sin Docker   | `http://localhost:8000`            |

## Reglas de recompensa

**Prioridad de evaluación:**
1. Regla de actividad específica (por `cmid`)
2. Regla por tipo de módulo (p. ej. todos los `assign`)
3. Regla general del curso

Si una regla tiene `min_grade`, el estudiante solo recibe MRT si supera
ese umbral. La idempotencia es estricta: un estudiante recibe MRT por una
actividad **una sola vez**, aunque la nota sea corregida posteriormente.

## Tareas programadas

| Tarea                       | Frecuencia    | Función                                            |
|-----------------------------|---------------|----------------------------------------------------|
| `send_events_task`          | Cada minuto   | Envía eventos `pending` al backend                 |
| `process_redemptions_task`  | Cada minuto   | Procesa canjes `pending` del marketplace           |
| `expire_courses_task`       | Diaria (2 AM) | Cierra enrollments de cursos piloto vencidos       |

### Ejecutar manualmente (útil en pruebas)

```bash
# Ejecutar todas las tareas del cron
docker exec -it meritcoin-moodle-1 php //bitnami/moodle/admin/cli/cron.php

# Ejecutar solo send_events_task
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute='\local_meritcoin\task\send_events_task'

# Ejecutar solo expire_courses_task
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute='\local_meritcoin\task\expire_courses_task'
```

> **Nota (Git Bash en Windows):** usar doble barra (`//bitnami/...`) para
> evitar que Git Bash convierta la ruta a formato Windows.

## Capacidades (permisos)

| Capacidad                         | Descripción                              | Roles por defecto        |
|-----------------------------------|------------------------------------------|--------------------------|
| `local/meritcoin:manage`          | Configurar el plugin globalmente         | Admin                    |
| `local/meritcoin:manage_rules`    | Gestionar reglas de monedas en un curso  | Teacher, Editing Teacher |
| `local/meritcoin:managerewards`   | Crear/editar recompensas del marketplace | Teacher, Editing Teacher |
| `local/meritcoin:viewmarketplace` | Ver y canjear recompensas                | Student                  |
| `local/meritcoin:awardbadges`     | Otorgar insignias a estudiantes          | Teacher, Editing Teacher |
| `local/meritcoin:viewbadges`      | Ver insignias de otros usuarios          | Manager                  |

## Depuración

### Ver la cola de eventos

```sql
SELECT event_id, userid, courseid, event_type, coins_amount, status, attempts, last_error
FROM mdl_local_meritcoin_queue
ORDER BY timecreated DESC
LIMIT 50;
```

### Ver wallets custodiales en caché

```sql
SELECT userid, wallet_address, status, FROM_UNIXTIME(provisioned_at) AS provisioned
FROM mdl_local_meritcoin_wallets
ORDER BY provisioned_at DESC;
```

### Ver cursos piloto activos

```sql
SELECT pc.id, c.fullname, pc.pilot_enabled,
       FROM_UNIXTIME(pc.expires_at) AS expires_override
FROM mdl_local_meritcoin_pilot_courses pc
JOIN mdl_course c ON c.id = pc.courseid
WHERE pc.pilot_enabled = 1;
```

### Ver canjes pendientes

```sql
SELECT id, userid, rewardid, coins_spent, status, attempts, last_error
FROM mdl_local_meritcoin_redemptions
WHERE status != 'confirmed'
ORDER BY timecreated DESC;
```

### Logs de Moodle

Las tareas programadas escriben al output del cron con el prefijo `MeritCoin:`.
Los errores de desarrollo se registran con `debugging(...)`, visibles en Moodle
cuando el modo depuración está activado en:

Administración del sitio > Desarrollo > Depuración → nivel **DEVELOPER**
