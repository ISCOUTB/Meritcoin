# Besu — Red Privada Hyperledger Besu (QBFT)

Configuración de la red blockchain privada EVM que usa MeritCoin para
acuñar tokens MRT (ERC-20) e insignias digitales (ERC-1155). Usa el
algoritmo de consenso **QBFT** (Quorum Byzantine Fault Tolerant),
adecuado para redes permisionadas de baja latencia.

## Estructura

```
besu/
└── QBFT-Network/
    ├── genesis.json          # Bloque génesis de la red (chainId 1337)
    ├── Node-1/
    │   ├── data/
    │   │   └── key           # Clave privada del nodo 1 (P2P)
    │   └── config.toml       # Configuración de Besu para el nodo 1
    ├── Node-2/
    │   ├── data/key
    │   └── config.toml
    ├── Node-3/
    │   ├── data/key
    │   └── config.toml
    └── Node-4/
        ├── data/key
        └── config.toml
```

> En el contexto del proyecto, Docker Compose levanta **un único nodo** (Node-1)
> que actúa como nodo validador completo para desarrollo y pruebas. Los nodos
> 2–4 están preparados para despliegues multi-nodo en entornos de staging.

## Red

| Parámetro | Valor |
|-----------|-------|
| Chain ID | 1337 |
| Consenso | QBFT (permisionado) |
| JSON-RPC | `http://localhost:8545` |
| Puerto P2P | 30303 |
| EVM version | Cancun |
| Gas limit | 0x1fffffffffffff |
| Tiempo de bloque | ~2 segundos |

## Cómo funciona con el proyecto

```
docker compose up -d besu
        │
        ▼
Node-1 arranca con genesis.json (chainId 1337)
        │
        ▼
JSON-RPC disponible en http://localhost:8545
        │
        ├── Hardhat deploy.js  ──→  contratos ERC-20 y ERC-1155 desplegados
        │
        └── FastAPI backend    ──→  BLOCKCHAIN_RPC_URL=http://meritcoin-besu:8545
                                    mint MRT y mintBadge por cada evento académico
```

## Levantar la red

### Con Docker Compose (recomendado)

La red se levanta junto al resto del sistema:

```bash
# Desde la raíz del proyecto
docker compose up -d besu

# Ver logs del nodo
docker compose logs -f besu

# Verificar que el nodo responde
curl -X POST http://localhost:8545 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}'
```

Respuesta esperada:
```json
{"jsonrpc":"2.0","id":1,"result":"0x0"}
```

### Verificar la conexión desde el backend

```bash
curl http://localhost:8000/health
# "blockchain_connected": true indica que el backend ya habla con Besu
```

## Génesis (genesis.json)

El bloque génesis configura el estado inicial de la red:

- **chainId 1337** — red privada, no conflicto con mainnet ni testnets públicas
- **Allocations** — la cuenta del deployer (`0xf39Fd6e51...` en localhost, o la cuenta real en Besu) tiene balance inicial en ETH para pagar gas
- **QBFT validators** — lista de las 4 direcciones de nodo que pueden proponer bloques
- **Gas limit generoso** — permite transacciones complejas (deploy de contratos OZ) sin ajuste fino

## Cuentas preconfiguradas

En desarrollo, el deployer usa la clave privada del nodo #0 de Hardhat,
que ya tiene balance en el génesis:

| Campo | Valor |
|-------|-------|
| Dirección | `0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266` |
| Clave privada | `0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80` |
| Uso | Deployer de contratos + `MINTER_ROLE` + `BURNER_ROLE` |

> ⚠️ **Esta clave es pública y conocida.** Solo usar en entornos de desarrollo
> local. En producción, generar una clave nueva y guardarla en `backend/.env`
> como `DEPLOYER_PRIVATE_KEY`.

## Relación con los contratos

Los contratos `MeritBadges1155` y `MeritCoinERC20` se despliegan en esta red
usando el script `contracts/scripts/deploy.js`. Las direcciones resultantes
deben copiarse en `backend/.env` como:

```env
MRT_CONTRACT_ADDRESS=0x...
BADGE_CONTRACT_ADDRESS=0x...
```

El backend FastAPI usa `web3.py` con `BLOCKCHAIN_RPC_URL=http://meritcoin-besu:8545`
(nombre del servicio Docker) para firmar y enviar transacciones usando la
clave del deployer, protegida dentro del contenedor.

## Wallets custodiales en Besu

El sistema de wallets custodiales (v0.5.1) genera cuentas Ethereum estándar
(`eth_account`) que son completamente compatibles con esta red. No se requiere
ninguna configuración adicional en Besu para soportar wallets custodiales —
son simplemente cuentas EVM más como cualquier otra.

El flujo es:
1. `wallet_service` genera `(private_key, wallet_address)` con `eth_account.create()`
2. La clave privada se encripta con Fernet y se almacena en PostgreSQL
3. La dirección se registra en `wallet_registry`
4. El backend firma transacciones `mint` hacia esa dirección desde la cuenta deployer

## Solución de problemas

### El nodo no arranca

```bash
docker compose logs besu
# Buscar: "QBFT BFT round started"
```

Si aparece error de permisos en `data/`:
```bash
chmod -R 777 besu/QBFT-Network/Node-1/data/
```

### Java no encontrado

Besu requiere Java 21+. Verificar:
```bash
java -version
```

En Docker esto está incluido en la imagen `hyperledger/besu`. Si corres
Besu directamente (sin Docker), instala `temurin-21` o similar.

### `eth_blockNumber` retorna error de conexión

Esperar ~10 segundos después de `docker compose up -d besu`. El nodo
tarda unos segundos en inicializar QBFT y abrir el puerto RPC.

### Reset completo de la red

Para empezar desde el bloque génesis de nuevo (borrando todo el historial):
```bash
docker compose down besu
docker volume rm meritcoin_besu-data   # nombre puede variar
docker compose up -d besu
```

Tras el reset debes **redesplegar los contratos** y actualizar las direcciones en `.env`.
