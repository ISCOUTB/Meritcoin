# FASE 5 — Guía de prueba de flujo integrado

## Arquitectura del flujo

```
Moodle (plugin)  ──HMAC──►  FastAPI Backend  ──web3──►  Hardhat Node
   │                            │                         │
   │ course_completed           │ POST /events/ingest     │ mintBadge()
   │ user_graded                │                         │ mint() MRT
   │                            │ PostgreSQL              │
   └── local_meritcoin_queue    └── events + audit_log    └── ERC-1155 + ERC-20
```

## Prerrequisitos

Necesitas **4 terminales** abiertas (CMD o PowerShell, NO Git Bash):

| Terminal | Servicio | Puerto |
|----------|----------|--------|
| 1 | Docker (Moodle + MariaDB + PostgreSQL) | 8080, 5432 |
| 2 | Hardhat node | 8545 |
| 3 | FastAPI backend | 8000 |
| 4 | Para ejecutar pruebas | — |

---

## Paso 1: Levantar Docker (si no está corriendo)

**Terminal 1:**
```bash
cd D:\Mis Documentos\Documentos\ProyectoMeritCoin\meritcoin
docker compose up -d
```

Espera ~2 minutos a que los 3 servicios estén corriendo:
```bash
docker compose ps
```
Los 3 deben mostrar status "Up" o "healthy".

---

## Paso 2: Levantar Hardhat node

**Terminal 2:**
```bash
cd D:\Mis Documentos\Documentos\ProyectoMeritCoin\meritcoin\contracts
npx hardhat node
```

Verás algo como:
```
Started HTTP and WebSocket JSON-RPC server at http://127.0.0.1:8545/
Account #0: 0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266 (10000 ETH)
...
```

**Déjalo corriendo. NO lo cierres.**

---

## Paso 3: Desplegar contratos

**Terminal 3** (temporal):
```bash
cd D:\Mis Documentos\Documentos\ProyectoMeritCoin\meritcoin\contracts
npx hardhat run scripts/deploy.js --network localhost
```

Verás algo como:
```
MeritBadges1155 desplegado en: 0x5FbDB2315678afecb367f032d93F642f64180aa3
MeritCoinERC20  desplegado en: 0xe7f1725E7734CE288F8367e1Bb143E90bb3F0512

=== Resumen de despliegue ===
BADGE_CONTRACT_ADDRESS=0x5FbDB2315678afecb367f032d93F642f64180aa3
MRT_CONTRACT_ADDRESS=0xe7f1725E7734CE288F8367e1Bb143E90bb3F0512
```

**IMPORTANTE:** Copia las dos direcciones. Las necesitas en el siguiente paso.

---

## Paso 4: Configurar y levantar el backend FastAPI

### 4a. Crear archivo .env para el backend

Crea el archivo `backend\.env` con este contenido (reemplaza las direcciones si son diferentes):

```env
DATABASE_URL=postgresql+asyncpg://meritcoin:meritcoin_pass@localhost:5432/meritcoin_db
HMAC_SECRET=cambia-este-secreto-en-produccion
BLOCKCHAIN_RPC_URL=http://127.0.0.1:8545
DEPLOYER_PRIVATE_KEY=0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
BADGE_CONTRACT_ADDRESS=0x5FbDB2315678afecb367f032d93F642f64180aa3
MRT_CONTRACT_ADDRESS=0xe7f1725E7734CE288F8367e1Bb143E90bb3F0512
DEBUG=true
```

### 4b. Levantar el backend

**Terminal 3:**
```bash
cd D:\Mis Documentos\Documentos\ProyectoMeritCoin\meritcoin\backend
uvicorn app.main:app --reload --port 8000
```

Verás:
```
INFO:     Uvicorn running on http://127.0.0.1:8000
INFO:     Tablas de BD creadas/verificadas
```

---

## Paso 5: Ejecutar test automático E2E

**Terminal 4:**
```bash
cd D:\Mis Documentos\Documentos\ProyectoMeritCoin\meritcoin
python scripts/test_e2e.py
```

Este script ejecuta 8 pruebas automáticas:
1. Health check del backend
2. Evento de COMPLETION (100 MRT)
3. Idempotencia (duplicado rechazado)
4. Evento de GRADE aprobatoria (50 MRT)
5. Evento de GRADE reprobatoria (0 MRT)
6. Consulta balance MRT (esperado: 150)
7. Consulta badges (esperado: 3)
8. Rechazo de HMAC inválido (401)

**Resultado esperado:** `8/8 pruebas pasaron`

---

## Paso 6 (opcional): Prueba manual con curl

Si quieres probar manualmente con curl:

```bash
python scripts/test_curl.py
```

Esto genera comandos curl que puedes copiar y pegar en CMD/PowerShell.

---

## Paso 7 (opcional): Prueba desde Moodle

Para probar el flujo completo desde Moodle (plugin → backend → blockchain):

### 7a. Crear un estudiante de prueba

1. Abre http://localhost:8080 (login: admin / Admin1234!)
2. Ve a **Administración del sitio → Usuarios → Agregar un usuario**
3. Llena:
   - Usuario: `estudiante1`
   - Contraseña: `Estudiante1!`
   - Nombre: `Test`
   - Apellido: `Student`
   - Email: `test@meritcoin.local`
4. En la sección **"Dirección Ethereum (Wallet)"** (el campo que creaste):
   - Pega: `0x70997970C51812dc3A010C7d01b50e0d17dc79C8`
5. Guardar

### 7b. Crear un curso de prueba

1. Ve a **Administración del sitio → Cursos → Agregar un curso**
2. Nombre completo: `Blockchain 101`
3. Nombre corto: `BC101`
4. Guardar

### 7c. Inscribir al estudiante

1. Entra al curso `Blockchain 101`
2. Ve a **Participantes** → **Inscribir usuarios**
3. Busca `estudiante1`, asigna rol "Estudiante", clic "Inscribir"

### 7d. Configurar finalización del curso

1. Dentro del curso, ve a **Configuración** → **Finalización del curso**
2. Agrega un criterio (por ejemplo: "Completar manualmente")
3. Guardar

### 7e. Completar el curso manualmente

1. Ve a **Participantes** del curso
2. Busca `estudiante1`
3. Marca la finalización manualmente

### 7f. Verificar que el evento se encoló

```bash
docker exec meritcoin-moodle php //opt/bitnami/moodle/admin/cli/scheduled_task.php --execute="\local_meritcoin\task\send_events_task"
```

O espera 1 minuto a que el cron de Moodle lo procese automáticamente.

### 7g. Verificar en el backend

Abre en el navegador:
```
http://localhost:8000/students/0x70997970C51812dc3A010C7d01b50e0d17dc79C8/balance
http://localhost:8000/students/0x70997970C51812dc3A010C7d01b50e0d17dc79C8/badges
```

---

## Solución de problemas

| Problema | Solución |
|----------|----------|
| "No se pudo conectar al backend" | ¿Está corriendo uvicorn en Terminal 3? |
| "blockchain_connected: false" | ¿Está corriendo `npx hardhat node` en Terminal 2? |
| "badge_contract: not configured" | Falta BADGE_CONTRACT_ADDRESS en backend/.env |
| "HTTP 401: Firma HMAC inválida" | HMAC_SECRET no coincide entre plugin y backend |
| "Error mintBadge" | Los contratos no están desplegados o las direcciones son incorrectas |
| Plugin no envía eventos | Verifica que esté habilitado en Moodle settings |
