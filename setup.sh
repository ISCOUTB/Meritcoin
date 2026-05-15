#!/usr/bin/env bash
# MeritCoin — Bootstrap completo tras git clone
# Uso: ./setup.sh
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()  { echo -e "${GREEN}✓ $*${NC}"; }
info(){ echo -e "${CYAN}→ $*${NC}"; }
warn(){ echo -e "${YELLOW}⚠ $*${NC}"; }
err() { echo -e "${RED}✗ $*${NC}"; exit 1; }

ROOT="$(cd "$(dirname "$0")" && pwd)"

# 1. .env backend
info "Paso 1/5: Preparando backend/.env..."
[ ! -f "$ROOT/backend/.env" ] && cp "$ROOT/.env.example" "$ROOT/backend/.env" && warn "Creado desde .env.example"
ok "backend/.env listo"

# 2. Levantar Besu
info "Paso 2/5: Levantando Besu..."
cd "$ROOT/besu/QBFT-Network"
docker compose up -d
for i in $(seq 1 30); do
  curl -sf -X POST http://localhost:8545 -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' > /dev/null 2>&1 && ok "Besu listo" && break
  [ $i -eq 30 ] && err "Besu no respondió"
  sleep 1
done

# 3. Levantar Postgres + IPFS
info "Paso 3/5: Levantando Postgres e IPFS..."
cd "$ROOT"
docker compose up -d postgres ipfs
for i in $(seq 1 20); do
  docker exec meritcoin-postgres pg_isready -U meritcoin > /dev/null 2>&1 && ok "Postgres listo" && break
  [ $i -eq 20 ] && err "Postgres no respondió"
  sleep 1
done

# 4. Desplegar contratos
info "Paso 4/5: Desplegando contratos..."
cd "$ROOT/contracts"
[ ! -d node_modules ] && npm install --silent

# Usa BESU_PRIVATE_KEY_1 como deployer (es quien tiene DEFAULT_ADMIN_ROLE)
DEPLOYER_KEY=$(grep '^BESU_PRIVATE_KEY_1=' .env | cut -d'=' -f2)
[ -z "$DEPLOYER_KEY" ] && err "BESU_PRIVATE_KEY_1 no encontrada en contracts/.env"
grep -q '^DEPLOYER_PRIVATE_KEY=' .env && \
  sed -i "s|^DEPLOYER_PRIVATE_KEY=.*|DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY|" .env || \
  echo "DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY" >> .env

DEPLOY_OUT=$(npx hardhat run scripts/deploy.js --network besu 2>&1)
echo "$DEPLOY_OUT"

BADGE_ADDR=$(echo "$DEPLOY_OUT" | grep '^BADGE_CONTRACT_ADDRESS=' | cut -d'=' -f2)
MRT_ADDR=$(echo "$DEPLOY_OUT"   | grep '^MRT_CONTRACT_ADDRESS='   | cut -d'=' -f2)
[ -z "$BADGE_ADDR" ] || [ -z "$MRT_ADDR" ] && err "No se leyeron direcciones del deploy"
ok "Contratos: BADGE=$BADGE_ADDR  MRT=$MRT_ADDR"

# 5. Actualizar backend/.env y levantar
info "Paso 5/5: Actualizando backend y levantando..."
cd "$ROOT"
sed -i "s|^DEPLOYER_PRIVATE_KEY=.*|DEPLOYER_PRIVATE_KEY=$DEPLOYER_KEY|" backend/.env
sed -i "s|^BADGE_CONTRACT_ADDRESS=.*|BADGE_CONTRACT_ADDRESS=$BADGE_ADDR|" backend/.env
sed -i "s|^MRT_CONTRACT_ADDRESS=.*|MRT_CONTRACT_ADDRESS=$MRT_ADDR|" backend/.env
docker compose up -d backend

for i in $(seq 1 30); do
  curl -sf http://localhost:8000/health > /dev/null 2>&1 && ok "Backend listo" && break
  [ $i -eq 30 ] && warn "Backend tardando — revisa: docker logs meritcoin-backend"
  sleep 2
done

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  MeritCoin listo ✓${NC}"
echo -e "${GREEN}============================================${NC}"
echo "  Moodle:   http://localhost:8080"
echo "  Backend:  http://localhost:8000/docs"
echo "  MRT:      $MRT_ADDR"
echo "  BADGE:    $BADGE_ADDR"
