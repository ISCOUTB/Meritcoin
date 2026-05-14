# Besu — Red Privada Hyperledger Besu (QBFT)

Configuración de la red blockchain privada EVM que usa MeritCoin para
acuñar tokens MRT (ERC-20) e insignias digitales (ERC-1155). Usa el
algoritmo de consenso **QBFT** (Quorum Byzantine Fault Tolerant),
adecuado para redes permisionadas de baja latencia.

## Estructura
```text
besu/
└── QBFT-Network/
  ├── genesis.json # Bloque génesis activo (chainId 1337, con alloc del deployer)
  ├── qbftConfigFile.json # Parámetros QBFT usados para generar la red inicial
  ├── docker-compose.yml # 4 nodos Besu + watchdog de salud
  ├── watchdog.sh # Script de reinicio automático si la cadena se congela
  ├── networkFiles/
  │ └── genesis.json # Génesis generado por besu operator generate-blockchain-config
  ├── Node-1/
  │ ├── data/
  │ │ └── key # Clave privada P2P del nodo 1
  │ └── config.toml # Configuración de Besu para el nodo 1
  ├── Node-2/
  │ ├── data/key
  │ └── config.toml
  ├── Node-3/
  │ ├── data/key
  │ └── config.toml
  └── Node-4/
    ├── data/key
    └── config.toml
```

> En el contexto del proyecto, el `docker-compose.yml` raíz levanta **un único nodo**
> (`meritcoin-besu`) que actúa como validador completo para desarrollo y pruebas.
> El `docker-compose.yml` de esta carpeta levanta los **4 nodos** completos y está
> pensado para staging o pruebas de consenso multi-nodo.

## Parámetros de red

| Parámetro | Valor |
|---|---|
| Chain ID | `1337` |
| Consenso | QBFT (permisionado) |
| JSON-RPC | `http://localhost:8545` |
| Puerto P2P | `30303` |
| Tiempo de bloque | ~2 segundos (`blockperiodseconds: 2`) |
| Epoch length | `30000` bloques |
| Request timeout | `4` segundos |
| Gas limit | `0x1fffffffffffff` |
| EVM mínima | Berlin (`berlinBlock: 0`) |
| Profile | `ENTERPRISE` |

## Servicios Docker

### `docker-compose.yml` — 4 nodos + watchdog

| Servicio | Puerto host | Rol |
|---|---|---|
| `besu-node-1` | `8545` (RPC), `30303` (P2P) | Nodo bootstrap (bootnode) |
| `besu-node-2` | `8546` (RPC), `30304` (P2P) | Validador, se une vía enode de Node-1 |
| `besu-node-3` | `8547` (RPC), `30305` (P2P) | Validador, se une vía enode de Node-1 |
| `besu-node-4` | `8548` (RPC), `30306` (P2P) | Validador, se une vía enode de Node-1 |
| `besu-watchdog` | — | Vigilante: reinicia nodos si la cadena se congela |

Los nodos 2–4 usan `--bootnodes` apuntando al enode de Node-1 y tienen
`--Xdns-enabled=true` para resolución de nombres dentro de la red Docker.

### Watchdog (`watchdog.sh`)

Servicio Alpine que corre en bucle cada 60 segundos. Compara el número de bloque
en dos puntos (`eth_blockNumber`). Si el bloque no avanzó o el nodo no responde,
ejecuta `docker restart besu-node-1 besu-node-2 besu-node-3 besu-node-4`
automáticamente y espera 15 segundos antes de continuar el ciclo.

```sh
RPC="${RPC:-http://besu-node-1:8545}"
# Compara B1 (t=0) con B2 (t=30s)
# Si B1 == B2 o B2 está vacío → reinicia todos los nodos
```

## Cómo funciona con el proyecto

```text
docker compose up -d besu
        │
        ▼
Node-1 arranca con genesis.json (chainId 1337)
        │
        ▼
JSON-RPC disponible en http://localhost:8545
        │
        ├── Hardhat deploy.js ──→ contratos ERC-20 y ERC-1155 desplegados
        │ direcciones → backend/.env
        │
        └── FastAPI backend ──→ BLOCKCHAIN_RPC_URL=http://meritcoin-besu:8545
mint MRT y mintBadge por cada evento académico
```

## Génesis (`genesis.json`)

- **`chainId: 1337`** — red privada, sin conflicto con mainnet ni testnets públicas
- **`alloc`** — la cuenta del deployer tiene balance ETH inicial para pagar gas
- **`qbft.validators`** — las 4 direcciones de nodo que pueden proponer bloques
- **`gasLimit: 0x1fffffffffffff`** — permite deploy de contratos OpenZeppelin complejos
- **`berlinBlock: 0`** — activa EIP-2929 desde el bloque génesis

El archivo `networkFiles/genesis.json` fue generado automáticamente con:
```bash
besu operator generate-blockchain-config \
  --config-file=qbftConfigFile.json \
  --to=networkFiles \
  --private-key-file-name=key
```

## `qbftConfigFile.json` — Configuración de generación

```json
{
  "genesis": {
    "config": {
      "chainId": 1337,
      "berlinBlock": 0,
      "qbft": {
        "blockperiodseconds": 2,
        "epochlength": 30000,
        "requesttimeoutseconds": 4
      }
    },
    "gasLimit": "0x1fffffffffffff"
  },
  "blockchain": {
    "nodes": {
      "generate": true,
      "count": 4
    }
  }
}
```

Este archivo se usa **una sola vez** para generar las claves y el génesis inicial.
No se modifica en operación normal.

## Levantar la red

### Modo desarrollo — nodo único (recomendado)

```bash
# Desde la raíz del proyecto
docker compose up -d besu

# Ver logs
docker compose logs -f besu

# Verificar que responde
curl -X POST http://localhost:8545 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
```

Respuesta esperada:
```json
{"jsonrpc":"2.0","id":1,"result":"0x0"}
```

### Modo multi-nodo — 4 validadores

```bash
cd besu/QBFT-Network
docker compose up -d

# Verificar que el consenso avanza
docker compose logs -f besu-watchdog
```

### Verificar conexión desde el backend

```bash
curl http://localhost:8000/health
# "blockchain_connected": true  →  backend conectado a Besu
```

## Cuentas preconfiguradas

| Campo | Valor |
|---|---|
| Dirección | `0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266` |
| Clave privada | `0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80` |
| Roles en contrato | `MINTER_ROLE` + `BURNER_ROLE` |
| Uso | Deploy de contratos + firma de txs desde el backend |

> ⚠️ **Esta clave es pública y conocida universalmente.** Solo para desarrollo local.
> En producción, generar una clave nueva y configurarla en `backend/.env`
> como `DEPLOYER_PRIVATE_KEY`. Nunca commitear claves de producción.

## Relación con los contratos

Los contratos `MeritBadges1155` y `MeritCoinERC20` se despliegan en esta red
con `contracts/scripts/deploy.js`. Tras el deploy, copiar las direcciones a `backend/.env`:

```env
MRT_CONTRACT_ADDRESS=0x...
BADGE_CONTRACT_ADDRESS=0x...
```

## Wallets custodiales en Besu

Las wallets custodiales son cuentas Ethereum estándar (`eth_account.create()`),
completamente compatibles con esta red sin configuración adicional:

1. `wallet_service` genera `(private_key, wallet_address)`
2. La clave privada se encripta con Fernet y se guarda en PostgreSQL
3. La dirección se registra en `wallet_registry`
4. El backend firma txs `mint` hacia esa dirección desde el deployer

Las wallets **no necesitan ETH propio** — solo reciben tokens MRT e insignias ERC-1155.

## APIs RPC habilitadas

| Namespace | Uso |
|---|---|
| `ETH` | Consultas de balance, envío de txs, lectura de contratos |
| `NET` | Información de red y peers |
| `QBFT` | Estado del consenso, lista de validadores |
| `WEB3` | Versión del cliente |
| `TXPOOL` | Estado del pool de transacciones pendientes |

## Solución de problemas

### El nodo no arranca

```bash
docker compose logs besu
# Buscar: "QBFT BFT round started"
```

Si hay error de permisos en `data/`:
```bash
chmod -R 777 besu/QBFT-Network/Node-1/data/
```

### Java no encontrado

Besu requiere Java 21+. En Docker está incluido en `hyperledger/besu`.
Para instalación local:
```bash
java -version   # debe ser 21+
```

### `eth_blockNumber` retorna error de conexión

Esperar ~10 segundos tras `docker compose up -d besu`. El nodo tarda
unos segundos en inicializar QBFT y abrir el puerto RPC.

### El watchdog reinicia los nodos en bucle

QBFT necesita al menos **3 de 4 nodos activos** para alcanzar consenso.
Causas comunes:
- Menos de 3 nodos corriendo
- Enode incorrecto en `--bootnodes` (verificar que `Node-1/data/key` corresponde)
- Permisos en `data/` (el directorio debe ser escribible por el proceso Besu)

### Reset completo de la red

```bash
docker compose down besu
docker volume rm meritcoin_besu-data
docker compose up -d besu
```

> ⚠️ Tras el reset debes **redesplegar los contratos** con Hardhat y
> actualizar `MRT_CONTRACT_ADDRESS` y `BADGE_CONTRACT_ADDRESS` en `backend/.env`.