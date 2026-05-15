#!/usr/bin/env bash
# MeritCoin — Bootstrap completo tras git clone
# Uso: ./setup.sh
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

ok()   { echo -e "${GREEN}✓ $*${NC}"; }
info() { echo -e "${CYAN}→ $*${NC}"; }
warn() { echo -e "${YELLOW}⚠ $*${NC}"; }
err()  { echo -e "${RED}✗ $*${NC}"; exit 1; }

ROOT="$(cd "$(dirname "$0")" && pwd)"

# 1. .env backend
info "Paso 1/6: Preparando backend/.env..."
if [ ! -f "$ROOT/backend/.env" ]; then
  cp "$ROOT/.env.example" "$ROOT/backend/.env"
  warn "backend/.env creado desde .env.example"
fi
ok "backend/.env listo"

# 2. Levantar Besu
info "Paso 2/6: Levantando Besu..."
cd "$ROOT/besu/QBFT-Network"
docker compose up -d

for i in $(seq 1 30); do
  curl -sf -X POST http://localhost:8545 \
    -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
    > /dev/null 2>&1 && ok "Besu listo" && break

  [ $i -eq 30 ] && err "Besu no respondió"
  sleep 1
done

# 3. Levantar Postgres + IPFS
info "Paso 3/6: Levantando Postgres e IPFS..."
cd "$ROOT"
docker compose up -d postgres ipfs

for i in $(seq 1 20); do
  docker exec meritcoin-postgres pg_isready -U meritcoin > /dev/null 2>&1 && ok "Postgres listo" && break
  [ $i -eq 20 ] && err "Postgres no respondió"
  sleep 1
done

# 4. Desplegar contratos
info "Paso 4/6: Desplegando contratos..."
cd "$ROOT/contracts"
[ ! -d node_modules ] && npm install --silent

DEPLOYER_KEY=$(grep '^BESU_PRIVATE_KEY_1=' .env | cut -d'=' -f2)
[ -z "$DEPLOYER_KEY" ] && err "BESU_PRIVATE_KEY_1 no encontrada en contracts/.env"

if grep -q '^DEPLOYER_PRIVATE_KEY=' .env; then
  sed -i "s|^DEPLOYER_PRIVATE_KEY=.*|DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY|" .env
else
  echo "DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY" >> .env
fi

DEPLOY_OUT=$(npx hardhat run scripts/deploy.js --network besu 2>&1)
echo "$DEPLOY_OUT"

BADGE_ADDR=$(echo "$DEPLOY_OUT" | grep '^BADGE_CONTRACT_ADDRESS=' | cut -d'=' -f2)
MRT_ADDR=$(echo "$DEPLOY_OUT" | grep '^MRT_CONTRACT_ADDRESS=' | cut -d'=' -f2)
DEPLOYER_ADDR=$(echo "$DEPLOY_OUT" | grep '^DEPLOYER_ADDRESS=' | cut -d'=' -f2)

if [ -z "$BADGE_ADDR" ] || [ -z "$MRT_ADDR" ]; then
  err "No se leyeron direcciones del deploy"
fi

ok "Contratos: BADGE=$BADGE_ADDR MRT=$MRT_ADDR"

# 5. Actualizar backend/.env, levantar y migrar
info "Paso 5/6: Actualizando backend, levantando y migrando..."
cd "$ROOT"

if grep -q '^DEPLOYER_PRIVATE_KEY=' backend/.env; then
  sed -i "s|^DEPLOYER_PRIVATE_KEY=.*|DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY|" backend/.env
else
  echo "DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY" >> backend/.env
fi

if grep -q '^BADGE_CONTRACT_ADDRESS=' backend/.env; then
  sed -i "s|^BADGE_CONTRACT_ADDRESS=.*|BADGE_CONTRACT_ADDRESS=$BADGE_ADDR|" backend/.env
else
  echo "BADGE_CONTRACT_ADDRESS=$BADGE_ADDR" >> backend/.env
fi

if grep -q '^MRT_CONTRACT_ADDRESS=' backend/.env; then
  sed -i "s|^MRT_CONTRACT_ADDRESS=.*|MRT_CONTRACT_ADDRESS=$MRT_ADDR|" backend/.env
else
  echo "MRT_CONTRACT_ADDRESS=$MRT_ADDR" >> backend/.env
fi

docker compose up -d backend

for i in $(seq 1 30); do
  curl -sf http://localhost:8000/health > /dev/null 2>&1 && ok "Backend listo" && break
  [ $i -eq 30 ] && err "Backend no respondió — no se puede migrar"
  sleep 2
done

info "Ejecutando migraciones Alembic..."

ALEMBIC_CURRENT=$(docker exec meritcoin-backend bash -c "cd /app && alembic current 2>/dev/null" | grep -v '^INFO' | tr -d '[:space:]' || true)

if [ -z "$ALEMBIC_CURRENT" ]; then
  warn "BD sin revisión Alembic registrada — ejecutando stamp head..."
  docker exec meritcoin-backend bash -c "cd /app && alembic stamp head" \
    && ok "Stamp head aplicado" \
    || err "Falló alembic stamp head"
else
  if docker exec meritcoin-backend bash -c "cd /app && alembic upgrade head"; then
    ok "Migraciones aplicadas"
  else
    warn "alembic upgrade head falló; intentando sincronizar con stamp head por esquema previo..."
    docker exec meritcoin-backend bash -c "cd /app && alembic stamp head" \
      && ok "Stamp head aplicado tras conflicto de esquema" \
      || err "Falló la recuperación con alembic stamp head"
  fi
fi

ok "Migraciones listas"

# 6. Levantar MariaDB + Moodle
info "Paso 6/6: Levantando MariaDB y Moodle..."
cd "$ROOT"
docker compose up -d mariadb moodle

for i in $(seq 1 40); do
  docker exec meritcoin-mariadb mysqladmin ping -u root --silent > /dev/null 2>&1 && ok "MariaDB listo" && break
  [ $i -eq 40 ] && err "MariaDB no respondió"
  sleep 2
done

for i in $(seq 1 40); do
  curl -sf http://localhost:8080 > /dev/null 2>&1 && ok "Moodle listo" && break
  [ $i -eq 40 ] && warn "Moodle tardando — revisa: docker logs meritcoin-moodle"
  sleep 3
done

info "Instalando/actualizando plugins de Moodle..."
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/upgrade.php --non-interactive \
  && ok "Plugins de Moodle actualizados" \
  || warn "upgrade.php reportó advertencias — revisa manualmente"

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  MeritCoin listo ✓${NC}"
echo -e "${GREEN}============================================${NC}"
echo "  Moodle:   http://localhost:8080"
echo "  Backend:  http://localhost:8000/docs"
echo "  MRT:      $MRT_ADDR"
echo "  BADGE:    $BADGE_ADDR"
echo "  DEPLOYER: $DEPLOYER_ADDR"#!/usr/bin/env bash
# MeritCoin — Bootstrap completo tras git clone
# Uso: ./setup.sh
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

ok()   { echo -e "${GREEN}✓ $*${NC}"; }
info() { echo -e "${CYAN}→ $*${NC}"; }
warn() { echo -e "${YELLOW}⚠ $*${NC}"; }
err()  { echo -e "${RED}✗ $*${NC}"; exit 1; }

ROOT="$(cd "$(dirname "$0")" && pwd)"

# ── Leer variables del .env raíz ─────────────────────────────────────────────
HMAC_SECRET=$(grep    '^HMAC_SECRET='          "$ROOT/.env" 2>/dev/null | cut -d'=' -f2)
DB_USER=$(grep        '^MOODLE_DB_USER='       "$ROOT/.env" 2>/dev/null | cut -d'=' -f2)
DB_PASS=$(grep        '^MOODLE_DB_PASSWORD='   "$ROOT/.env" 2>/dev/null | cut -d'=' -f2)
DB_NAME=$(grep        '^MOODLE_DB_NAME='       "$ROOT/.env" 2>/dev/null | cut -d'=' -f2)
DB_ROOT=$(grep        '^MARIADB_ROOT_PASSWORD=' "$ROOT/.env" 2>/dev/null | cut -d'=' -f2)

DB_USER="${DB_USER:-bn_moodle}"
DB_PASS="${DB_PASS:-moodle_pass}"
DB_NAME="${DB_NAME:-bitnami_moodle}"
DB_ROOT="${DB_ROOT:-root_pass}"

[ -z "$HMAC_SECRET" ] && err "HMAC_SECRET no encontrada en .env"

# ─────────────────────────────────────────────────────────────────────────────
# PASO 1 — .env backend
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 1/7: Preparando backend/.env..."
if [ ! -f "$ROOT/backend/.env" ]; then
  cp "$ROOT/.env.example" "$ROOT/backend/.env"
  warn "backend/.env creado desde .env.example — revisa los valores"
fi
ok "backend/.env listo"

# ─────────────────────────────────────────────────────────────────────────────
# PASO 2 — Levantar Besu
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 2/7: Levantando Besu..."
cd "$ROOT/besu/QBFT-Network"
docker compose up -d

for i in $(seq 1 30); do
  curl -sf -X POST http://localhost:8545 \
    -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
    > /dev/null 2>&1 && ok "Besu listo" && break
  [ $i -eq 30 ] && err "Besu no respondió en 30s"
  sleep 1
done

# ─────────────────────────────────────────────────────────────────────────────
# PASO 3 — Levantar Postgres + IPFS + MariaDB + Moodle (sin plugin todavía)
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 3/7: Levantando Postgres, IPFS, MariaDB y Moodle..."
cd "$ROOT"

# Asegurar que el volumen del plugin está comentado en docker-compose.yml
# para que Moodle complete su instalación inicial sin interferencias
sed -i 's|^\(\s*\)- \./plugin:/bitnami/moodle/local/meritcoin|\1#- ./plugin:/bitnami/moodle/local/meritcoin|' docker-compose.yml

docker compose up -d postgres ipfs mariadb moodle

for i in $(seq 1 20); do
  docker exec meritcoin-postgres pg_isready -U "${DB_USER:-meritcoin}" > /dev/null 2>&1 && ok "Postgres listo" && break
  [ $i -eq 20 ] && err "Postgres no respondió"
  sleep 1
done

for i in $(seq 1 40); do
  docker exec meritcoin-mariadb mysqladmin ping -u root -p"$DB_ROOT" --silent > /dev/null 2>&1 && ok "MariaDB listo" && break
  [ $i -eq 40 ] && err "MariaDB no respondió"
  sleep 2
done

info "Esperando instalación inicial de Moodle (puede tardar hasta 5 min)..."
for i in $(seq 1 60); do
  curl -sf http://localhost:8080 > /dev/null 2>&1 && ok "Moodle listo" && break
  [ $i -eq 60 ] && err "Moodle no respondió en 5 min"
  sleep 5
done

# ─────────────────────────────────────────────────────────────────────────────
# PASO 4 — Desplegar contratos
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 4/7: Desplegando contratos..."
cd "$ROOT/contracts"
[ ! -d node_modules ] && npm install --silent

DEPLOYER_KEY=$(grep '^BESU_PRIVATE_KEY_1=' .env | cut -d'=' -f2)
[ -z "$DEPLOYER_KEY" ] && err "BESU_PRIVATE_KEY_1 no encontrada en contracts/.env"

if grep -q '^DEPLOYER_PRIVATE_KEY=' .env; then
  sed -i "s|^DEPLOYER_PRIVATE_KEY=.*|DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY|" .env
else
  echo "DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY" >> .env
fi

DEPLOY_OUT=$(npx hardhat run scripts/deploy.js --network besu 2>&1)
echo "$DEPLOY_OUT"

BADGE_ADDR=$(echo "$DEPLOY_OUT" | grep '^BADGE_CONTRACT_ADDRESS=' | cut -d'=' -f2)
MRT_ADDR=$(echo   "$DEPLOY_OUT" | grep '^MRT_CONTRACT_ADDRESS='   | cut -d'=' -f2)
DEPLOYER_ADDR=$(echo "$DEPLOY_OUT" | grep '^DEPLOYER_ADDRESS='    | cut -d'=' -f2)
[ -z "$BADGE_ADDR" ] || [ -z "$MRT_ADDR" ] && err "No se leyeron direcciones del deploy"
ok "Contratos: BADGE=$BADGE_ADDR  MRT=$MRT_ADDR"

# ─────────────────────────────────────────────────────────────────────────────
# PASO 5 — Actualizar backend/.env y recrear backend
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 5/7: Actualizando backend/.env y recreando contenedor..."
cd "$ROOT"

_set_env() {
  local key=$1 val=$2 file=$3
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$file"
  else
    echo "${key}=${val}" >> "$file"
  fi
}

_set_env DEPLOYER_PRIVATE_KEY   "$DEPLOYER_KEY"  backend/.env
_set_env BADGE_CONTRACT_ADDRESS "$BADGE_ADDR"    backend/.env
_set_env MRT_CONTRACT_ADDRESS   "$MRT_ADDR"      backend/.env

docker compose up -d --force-recreate backend

for i in $(seq 1 30); do
  curl -sf http://localhost:8000/health > /dev/null 2>&1 && ok "Backend listo" && break
  [ $i -eq 30 ] && err "Backend no respondió — no se puede migrar"
  sleep 2
done

# ─────────────────────────────────────────────────────────────────────────────
# PASO 6 — Migraciones Alembic (tolerante a esquemas previos)
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 6/7: Aplicando migraciones Alembic..."

ALEMBIC_CURRENT=$(docker exec meritcoin-backend bash -c \
  "cd /app && alembic current 2>/dev/null" | grep -v '^INFO' | tr -d '[:space:]' || true)

if [ -z "$ALEMBIC_CURRENT" ]; then
  warn "BD sin revisión Alembic — sincronizando con stamp head..."
  docker exec meritcoin-backend bash -c "cd /app && alembic stamp head" \
    && ok "Stamp head aplicado" || err "Falló alembic stamp head"
else
  if ! docker exec meritcoin-backend bash -c "cd /app && alembic upgrade head" 2>/dev/null; then
    warn "upgrade head falló por esquema previo — sincronizando con stamp head..."
    docker exec meritcoin-backend bash -c "cd /app && alembic stamp head" \
      && ok "Stamp head aplicado tras conflicto" || err "Falló alembic stamp head"
  else
    ok "Migraciones aplicadas"
  fi
fi
ok "Migraciones listas"

# ─────────────────────────────────────────────────────────────────────────────
# PASO 7 — Montar plugin, configurar Moodle y crear campo wallet
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 7/7: Instalando y configurando plugin MeritCoin en Moodle..."

# Descomentar el volumen del plugin y recrear Moodle
sed -i 's|^\(\s*\)#- \./plugin:/bitnami/moodle/local/meritcoin|\1- ./plugin:/bitnami/moodle/local/meritcoin|' docker-compose.yml
docker compose up -d --force-recreate moodle

info "Esperando que Moodle vuelva a estar listo tras recrear..."
for i in $(seq 1 40); do
  curl -sf http://localhost:8080 > /dev/null 2>&1 && ok "Moodle listo con plugin" && break
  [ $i -eq 40 ] && err "Moodle no respondió tras recrear"
  sleep 3
done

# Instalar tablas del plugin
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/upgrade.php --non-interactive \
  && ok "Plugin instalado/actualizado" \
  || warn "upgrade.php reportó advertencias menores"

# Configurar ajustes del plugin via CLI
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/cfg.php \
  --component=local_meritcoin --name=enabled     --set=1
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/cfg.php \
  --component=local_meritcoin --name=backend_url --set=http://meritcoin-backend:8000
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/cfg.php \
  --component=local_meritcoin --name=hmac_secret --set="$HMAC_SECRET"
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/cfg.php \
  --component=local_meritcoin --name=wallet_field --set=wallet
ok "Plugin configurado"

# Crear campo de perfil wallet si no existe
WALLET_EXISTS=$(docker exec meritcoin-mariadb mysql -u root -p"$DB_ROOT" "$DB_NAME" -sNe \
  "SELECT COUNT(*) FROM mdl_user_info_field WHERE shortname='wallet';" 2>/dev/null || echo "0")

if [ "$WALLET_EXISTS" = "0" ]; then
  docker exec meritcoin-mariadb mysql -u root -p"$DB_ROOT" "$DB_NAME" -e \
    "INSERT INTO mdl_user_info_field
       (shortname, name, datatype, categoryid, sortorder, required, locked, visible, forceunique, signup, defaultdata, param1)
     VALUES
       ('wallet','Wallet Ethereum','text',1,1,0,0,2,0,0,'',255);"
  ok "Campo de perfil 'wallet' creado"
else
  ok "Campo de perfil 'wallet' ya existe"
fi

# Deshabilitar verificación HTTPS forzada en Moodle
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/cfg.php \
  --name=sslproxy --set=0
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/cfg.php \
  --name=loginhttps --set=0
ok "Verificación HTTPS desactivada"

# Purgar caché de Moodle
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/purge_caches.php \
  && ok "Caché de Moodle purgada" || warn "No se pudo purgar caché"

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  MeritCoin listo ✓${NC}"
echo -e "${GREEN}============================================${NC}"
echo "  Moodle:   http://localhost:8080  (admin / Admin1234!)"
echo "  Backend:  http://localhost:8000/docs"
echo "  MRT:      $MRT_ADDR"
echo "  BADGE:    $BADGE_ADDR"
echo "  DEPLOYER: $DEPLOYER_ADDR"
echo ""
echo -e "${YELLOW}  Solo queda:${NC}"
echo -e "${YELLOW}  1. Asignar wallet a cada estudiante en su perfil de Moodle${NC}"
echo -e "${YELLOW}     (o activar el curso como Piloto para wallets custodiales)${NC}"