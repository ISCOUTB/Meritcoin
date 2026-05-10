# Plugin MeritCoin para Moodle (`local_meritcoin`)

Plugin local de Moodle que captura eventos de calificación, calcula las
monedas MRT correspondientes según las reglas del curso, y los envía al
backend FastAPI firmados con HMAC-SHA256.

## Datos del plugin

| Campo     | Valor                          |
|-----------|--------------------------------|
| Tipo      | Plugin local (local_meritcoin) |
| Versión   | 0.3.3 (2026051001)             |
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
│   └── task/
│       ├── send_events_task.php          # Tarea programada: envío de eventos
│       └── process_redemptions_task.php  # Tarea programada: canjes del marketplace
├── db/
│   ├── install.xml   # Tablas de la BD
│   ├── upgrade.php   # Migraciones de BD
│   ├── events.php    # Suscripción a eventos de Moodle
│   ├── tasks.php     # Registro de tareas programadas
│   └── access.php    # Capacidades y permisos
├── lang/
│   ├── en/local_meritcoin.php   # Strings en inglés
│   └── es/local_meritcoin.php   # Strings en español
├── settings.php   # Página de configuración admin
├── lib.php        # Funciones auxiliares y hooks de navegación
└── version.php    # Metadatos del plugin
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
   Inserta registro en local_meritcoin_queue
   (status = pending | pending_wallet si no tiene wallet)
                    │
                    ▼
   send_events_task.php (cada 60 segundos via cron)
                    │
                    ├─ Reactiva pending_wallet si el estudiante ya registró wallet
                    │
                    ▼
   api_client.php envía al backend con firma HMAC-SHA256
                    │
                    ├─ OK    → status = sent, registra en local_meritcoin_earnings
                    └─ Error → attempts++; si attempts >= 5 → status = failed
```

## Eventos capturados

| Evento Moodle              | Tipo enviado | Condición                                 |
|----------------------------|--------------|-------------------------------------------|
| `\core\event\user_graded` | `grade`      | Se registra calificación en una actividad |

> `course_completed` fue eliminado intencionalmente. MeritCoin solo premia
> calificaciones de actividades, no la finalización de cursos.

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

**O URL directa:**
```
http://localhost:8080/admin/settings.php?section=local_meritcoin
```

| Campo                           | Valor recomendado (desarrollo)             |
|---------------------------------|--------------------------------------------|
| Habilitado                      | ✓ Sí                                       |
| URL del backend                 | `http://host.docker.internal:8000`         |
| Secreto HMAC                    | debe coincidir con `HMAC_SECRET` en `.env` |
| Campo wallet                    | `wallet`                                   |
| Límite MRT por estudiante/curso | `0` (sin límite) o el valor deseado        |

### Paso 4: Crear campo de perfil "wallet"

1. Ir a: Administración del sitio > Usuarios > Campos de perfil de usuario
2. Clic en **Crear un nuevo campo de perfil** → tipo **Entrada de texto**
3. Configurar:
   - **Nombre corto:** `wallet`
   - **Nombre:** `Dirección Ethereum (Wallet)`
   - **Visible para:** Todos
4. Guardar cambios

Cada estudiante puede agregar su wallet Ethereum (`0x...`) desde su perfil.

## URL del backend según escenario

| Escenario                              | URL                               |
|----------------------------------------|-----------------------------------|
| Moodle en Docker, backend en Windows   | `http://host.docker.internal:8000` |
| Ambos en Docker (misma red)            | `http://meritcoin-backend:8000`   |
| Ambos en la misma máquina sin Docker   | `http://localhost:8000`           |

## Reglas de recompensa

Las reglas determinan cuántos MRT gana un estudiante por cada actividad.
Se configuran desde el menú del curso (profesor) o desde la administración.

**Prioridad de evaluación:**
1. Regla de actividad específica (por `cmid`)
2. Regla por tipo de módulo (p. ej. todos los `assign`)
3. Regla general del curso

Si una regla tiene `min_grade`, el estudiante solo recibe MRT si supera
ese umbral. La idempotencia es estricta: un estudiante recibe MRT por una
actividad **una sola vez**, aunque la nota sea corregida posteriormente.
Si la primera nota no superaba `min_grade` y luego se corrige, sí recibirá
MRT en la corrección, ya que el evento nunca fue encolado antes.

## Tareas programadas

| Tarea                       | Frecuencia   | Función                                   |
|-----------------------------|--------------|-------------------------------------------|
| `send_events_task`          | Cada minuto  | Envía eventos `pending` al backend        |
| `process_redemptions_task`  | Cada minuto  | Procesa canjes `pending` del marketplace  |

### Ejecutar manualmente (útil en pruebas)

```bash
# Ejecutar todas las tareas del cron
docker exec -it meritcoin-moodle-1 php //bitnami/moodle/admin/cli/cron.php

# Ejecutar solo send_events_task
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute='\local_meritcoin\task\send_events_task'

# Ejecutar solo process_redemptions_task
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/scheduled_task.php \
  --execute='\local_meritcoin\task\process_redemptions_task'
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

### Ver canjes pendientes

```sql
SELECT id, userid, rewardid, coins_spent, status, attempts, last_error
FROM mdl_local_meritcoin_redemptions
WHERE status != 'confirmed'
ORDER BY timecreated DESC;
```

### Verificar que el plugin está activo

```
http://localhost:8080/admin/settings.php?section=local_meritcoin
```

### Logs de Moodle

Las tareas programadas escriben al output del cron con el prefijo `MeritCoin:`.
Los errores de desarrollo se registran con `debugging(...)`, visibles en Moodle
cuando el modo depuración está activado en:

Administración del sitio > Desarrollo > Depuración → nivel **DEVELOPER**
