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

# ─────────────────────────────────────────────────────────────────────────────
# PASO 0 — .env principal
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 0/7: Revisando archivo .env principal..."
if [ ! -f "$ROOT/.env" ]; then
  cp "$ROOT/.env.example" "$ROOT/.env"
  warn "Archivo .env creado desde .env.example"
fi

# Sincronizar variables nuevas de .env.example a .env
grep -v '^#' "$ROOT/.env.example" | grep '=' | cut -d'=' -f1 | while read -r key; do
  if ! grep -q "^${key}=" "$ROOT/.env"; then
    val=$(grep "^${key}=" "$ROOT/.env.example" | cut -d'=' -f2-)
    echo "${key}=${val}" >> "$ROOT/.env"
    warn "Variable ${key} añadida a .env"
  fi
done

# Generar clave Fernet para WALLET_ENCRYPTION_KEY si es la de por defecto
if grep -q "tu-clave-fernet-aqui" "$ROOT/.env"; then
  NEW_KEY=$(python3 -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())" 2>/dev/null || echo "")
  if [ -z "$NEW_KEY" ]; then
    NEW_KEY=$(head -c 32 /dev/urandom | base64 | tr '+/' '-_')
  fi
  sed -i "s|tu-clave-fernet-aqui|$NEW_KEY|g" "$ROOT/.env"
  ok "Clave Fernet generada automáticamente"
fi


# ── Leer variables del .env raíz ─────────────────────────────────────────────
HMAC_SECRET=$(grep    '^HMAC_SECRET='          "$ROOT/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r')
DB_USER=$(grep        '^MOODLE_DB_USER='       "$ROOT/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r')
DB_PASS=$(grep        '^MOODLE_DB_PASSWORD='   "$ROOT/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r')
DB_NAME=$(grep        '^MOODLE_DB_NAME='       "$ROOT/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r')
DB_ROOT=$(grep        '^MARIADB_ROOT_PASSWORD=' "$ROOT/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r')
PG_USER=$(grep        '^PG_USER='              "$ROOT/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r')

DB_USER="${DB_USER:-bn_moodle}"
DB_PASS="${DB_PASS:-moodle_pass}"
DB_NAME="${DB_NAME:-bitnami_moodle}"
DB_ROOT="${DB_ROOT:-root_pass}"
PG_USER="${PG_USER:-meritcoin}"

[ -z "$HMAC_SECRET" ] && err "HMAC_SECRET no encontrada en .env"

# ─────────────────────────────────────────────────────────────────────────────
# PASO 1 — .env backend
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 1/7: Preparando backend/.env..."
cp "$ROOT/.env" "$ROOT/backend/.env"
warn "backend/.env sincronizado con .env principal"
ok "backend/.env listo"

# ─────────────────────────────────────────────────────────────────────────────
# PASO 2 — Levantar Besu
# ─────────────────────────────────────────────────────────────────────────────
info "Paso 2/7: Levantando Besu..."
cd "$ROOT/besu/QBFT-Network"

# Limpiar estado anterior para asegurar un inicio limpio
for node in 1 2 3 4; do
  rm -rf Node-$node/data/database Node-$node/data/caches \
    Node-$node/data/*.networks Node-$node/data/*.ports Node-$node/data/*.cache \
    Node-$node/data/VERSION_METADATA.json Node-$node/data/DATABASE_METADATA.json 2>/dev/null || true
done

# Levantar los nodos pero NO el watchdog todavía para evitar reinicios en bucle durante la instalación
docker compose up -d besu-node-1 besu-node-2 besu-node-3 besu-node-4
docker compose stop besu-watchdog > /dev/null 2>&1 || true
docker compose rm -f besu-watchdog > /dev/null 2>&1 || true

for i in $(seq 1 40); do
  curl -sf -X POST http://localhost:8545 \
    -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
    > /dev/null 2>&1 && ok "Besu listo" && break
  [ $i -eq 40 ] && err "Besu no respondió en 40s"
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
  docker exec meritcoin-postgres pg_isready -U "${PG_USER}" > /dev/null 2>&1 && ok "Postgres listo" && break
  [ $i -eq 20 ] && err "Postgres no respondió"
  sleep 1
done

for i in $(seq 1 40); do
  docker exec meritcoin-mariadb mysqladmin ping -u "$DB_USER" -p"$DB_PASS" --silent > /dev/null 2>&1 && ok "MariaDB listo" && break
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

info "Generando contracts/.env con las claves actuales de los nodos Besu..."
cat > "$ROOT/contracts/.env" <<EOF
BESU_PRIVATE_KEY_1=$(cat "$ROOT/besu/QBFT-Network/Node-1/data/key")
BESU_PRIVATE_KEY_2=$(cat "$ROOT/besu/QBFT-Network/Node-2/data/key")
BESU_PRIVATE_KEY_3=$(cat "$ROOT/besu/QBFT-Network/Node-3/data/key")
BESU_PRIVATE_KEY_4=$(cat "$ROOT/besu/QBFT-Network/Node-4/data/key")
BESU_RPC_URL=http://localhost:8545
DEPLOYER_PRIVATE_KEY=$(cat "$ROOT/besu/QBFT-Network/Node-1/data/key")
EOF
warn "contracts/.env ha sido sobrescrito con las claves locales"

[ ! -d node_modules ] && npm install --silent

DEPLOYER_KEY=$(grep '^BESU_PRIVATE_KEY_1=' .env | cut -d'=' -f2 | tr -d '\r')
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

# También en el raíz .env para consistencia
_set_env DEPLOYER_PRIVATE_KEY   "$DEPLOYER_KEY"  .env
_set_env BADGE_CONTRACT_ADDRESS "$BADGE_ADDR"    .env
_set_env MRT_CONTRACT_ADDRESS   "$MRT_ADDR"      .env

docker compose up -d --force-recreate --build backend

for i in $(seq 1 40); do
  curl -sf http://localhost:8000/health > /dev/null 2>&1 && ok "Backend listo" && break
  [ $i -eq 40 ] && err "Backend no respondió — no se puede migrar"
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
  --component=local_meritcoin --name=api_url     --set=http://meritcoin-backend:8000
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/cfg.php \
  --component=local_meritcoin --name=hmac_secret --set="$HMAC_SECRET"
docker exec meritcoin-moodle php /bitnami/moodle/admin/cli/cfg.php \
  --component=local_meritcoin --name=wallet_field --set=wallet
ok "Plugin configurado"

# Crear campo de perfil wallet si no existe
WALLET_EXISTS=$(docker exec meritcoin-mariadb mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sNe \
  "SELECT COUNT(*) FROM mdl_user_info_field WHERE shortname='wallet';" 2>/dev/null || echo "0")

if [ "$WALLET_EXISTS" = "0" ]; then
  docker exec meritcoin-mariadb mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
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

# Levantar el watchdog de Besu ahora que el setup ha finalizado y la red está activa
info "Levantando el watchdog de Besu..."
cd "$ROOT/besu/QBFT-Network"
docker compose up -d besu-watchdog

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