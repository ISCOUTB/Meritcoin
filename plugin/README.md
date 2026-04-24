# Plugin MeritCoin para Moodle (local_meritcoin)

Plugin local de Moodle que captura eventos academicos (completar cursos y
calificaciones) y los envia al backend FastAPI firmados con HMAC-SHA256.

## Datos del plugin

| Campo | Valor |
|-------|-------|
| Tipo | Plugin local (local_meritcoin) |
| Version | 0.1.0 (2026031000) |
| Requiere | Moodle 4.3+ |
| Madurez | MATURITY_ALPHA |
| Licencia | GNU GPL v3 |

## Estructura

```
plugin/
├── classes/
│   ├── observer.php               # Captura eventos de Moodle
│   ├── api_client.php             # Cliente HTTP con firma HMAC
│   └── task/
│       └── send_events_task.php   # Tarea programada (cron)
├── db/
│   ├── install.xml                # Tabla local_meritcoin_queue
│   ├── upgrade.php                # Migraciones de BD
│   ├── events.php                 # Suscripcion a eventos de Moodle
│   ├── tasks.php                  # Registro de tarea programada
│   └── access.php                 # Capacidades/permisos
├── lang/
│   ├── en/local_meritcoin.php     # Strings en ingles
│   └── es/local_meritcoin.php     # Strings en espanol
├── settings.php                   # Pagina de configuracion admin
├── lib.php                        # Funciones auxiliares
└── version.php                    # Metadatos del plugin
```

## Flujo de funcionamiento

```
Estudiante completa curso o recibe nota
         │
         v
  observer.php captura el evento
         │
         v
  Inserta registro en local_meritcoin_queue
         │
         v
  send_events_task.php (cada 60 segundos)
         │
         v
  api_client.php envia al backend con HMAC
         │
         v
  Backend procesa, acuna badge + MRT
```

### Eventos capturados

| Evento Moodle | Tipo enviado | Condicion |
|---------------|-------------|-----------|
| `\core\event\course_completed` | `completion` | Estudiante completa un curso |
| `\core\event\user_graded` | `grade` | Se registra calificacion final |

### Cola de eventos (local_meritcoin_queue)

| Campo | Tipo | Descripcion |
|-------|------|-------------|
| id | bigint | Auto-increment |
| event_id | varchar(255) | ID unico para idempotencia |
| student_id | bigint | ID del usuario en Moodle |
| course_id | bigint | ID del curso |
| event_type | varchar(50) | completion o grade |
| grade | decimal | Nota (null si no aplica) |
| status | varchar(20) | pending, sent, failed |
| created_at | bigint | Timestamp de creacion |
| sent_at | bigint | Timestamp de envio (null si pending) |

## Instalacion

### Requisitos previos

1. Docker corriendo con `docker compose up -d` (Moodle + MariaDB + PostgreSQL)
2. Backend FastAPI levantado en puerto 8000

### Paso 1: Colocar archivos del plugin

El `docker-compose.yml` ya monta la carpeta `./plugin` como volumen en:
```
/bitnami/moodle/local/meritcoin
```

El plugin se detecta automaticamente al reiniciar Moodle.

### Paso 2: Instalar en Moodle

1. Ir a http://localhost:8080 e iniciar sesion como admin
   - Usuario: `admin`
   - Contrasena: `Admin1234!`
2. Moodle detecta el plugin nuevo y muestra la pantalla de actualizacion
3. Hacer clic en "Actualizar base de datos de Moodle"

### Paso 3: Configurar el plugin

Ir a la pagina de configuracion:

**Ruta en menu:**
Administracion del sitio > Plugins > Plugins locales > MeritCoin

**O URL directa:**
```
http://localhost:8080/admin/settings.php?section=local_meritcoin
```

Configurar los siguientes campos:

| Campo | Valor |
|-------|-------|
| Habilitado | Si (marcar checkbox) |
| URL del backend | `http://host.docker.internal:8000` |
| Secreto HMAC | `cambia-este-secreto-en-produccion` |
| Campo wallet | `wallet` |

### Paso 4: Crear campo de perfil "wallet"

1. Ir a: Administracion del sitio > Usuarios > Campos de perfil de usuario
2. Clic en "Crear un nuevo campo de perfil"
3. Elegir tipo "Entrada de texto"
4. Llenar:
   - Nombre corto: `wallet`
   - Nombre: `Direccion Ethereum (Wallet)`
   - Visible para: Todos
5. Guardar cambios

Cada estudiante podra editar su perfil y agregar su wallet Ethereum (0x...).

## Configuracion detallada

### URL del backend

La URL depende de donde corre Moodle y el backend:

| Escenario | URL |
|-----------|-----|
| Moodle en Docker, backend en Windows | `http://host.docker.internal:8000` |
| Ambos en Docker (misma red) | `http://meritcoin-backend:8000` |
| Ambos en la misma maquina sin Docker | `http://localhost:8000` |

### Secreto HMAC

Debe coincidir **exactamente** con la variable `HMAC_SECRET` en el `.env`
del backend. Si no coinciden, el backend rechazara las peticiones con HTTP 401.

### Tarea programada (cron)

El plugin registra una tarea programada (`send_events_task`) que se ejecuta
cada 60 segundos. Moodle la ejecuta automaticamente con su propio cron.

Para ejecutar manualmente (util en pruebas):
```bash
docker exec -it meritcoin-moodle-1 php //bitnami/moodle/admin/cli/cron.php
```

Nota: En Git Bash en Windows, usar doble barra (`//bitnami/...`) para evitar
que Git Bash convierta la ruta a formato Windows.

## Capacidades (permisos)

| Capacidad | Descripcion | Roles por defecto |
|-----------|-------------|-------------------|
| local/meritcoin:viewbadges | Ver insignias de otros | Manager |
| local/meritcoin:manageplugin | Configurar el plugin | Admin |

## Idiomas

El plugin incluye strings en ingles y espanol. Moodle selecciona
automaticamente segun el idioma configurado del sitio o del usuario.

## Depuracion

### Ver la cola de eventos

Consultar directamente la tabla en la base de datos de Moodle:

```sql
SELECT * FROM mdl_local_meritcoin_queue ORDER BY created_at DESC;
```

### Verificar que el plugin esta activo

```
http://localhost:8080/admin/settings.php?section=local_meritcoin
```

Si la pagina carga con los campos de configuracion, el plugin esta instalado
correctamente.

### Logs de Moodle

Los logs del plugin se registran con el prefijo `[local_meritcoin]` en los
logs estandar de Moodle.
