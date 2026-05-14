# Contratos MeritCoin — Solidity + Hardhat

Dos contratos inteligentes que gestionan las insignias digitales (ERC-1155)
y los tokens de recompensa (ERC-20). Solo usan OpenZeppelin 5.x, sin
librerías de pago ni dependencias externas.

## Estructura

```text
contracts/
  ├── contracts/
  │ ├── MeritBadges1155.sol # ERC-1155 — Insignias digitales académicas
  │ └── MeritCoinERC20.sol # ERC-20 — Token MRT
  ├── scripts/
  │ └── deploy.js # Despliega ambos contratos y asigna roles
  ├── test/
  │ ├── MeritBadges.test.js # 11 tests de MeritBadges1155
  │ └── MeritCoin.test.js # 8 tests de MeritCoinERC20
  ├── hardhat.config.js # Redes: localhost + besu (chainId 1337)
  └── package.json
```

## Stack

| Componente | Versión |
|---|---|
| Solidity | `0.8.28` |
| Hardhat | `2.28.6` |
| OpenZeppelin Contracts | `5.6.1` |
| EVM target | `cancun` |
| Optimizer | `200` runs |
| Node.js (recomendado) | ≥ 20 |

---

## MeritBadges1155 (ERC-1155)

Insignias digitales académicas verificables. Cada token representa una
credencial única que puede verificarse públicamente en blockchain.

### Herencia

```text
ERC1155Pausable + ERC1155URIStorage + AccessControl
```

### Roles

| Rol | Puede | Asignado a |
|---|---|---|
| `DEFAULT_ADMIN_ROLE` | Pausar/despausar, gestionar roles | Deployer |
| `ISSUER_ROLE` | Emitir insignias (`mintBadge`) | Deployer (delegado al backend) |

### Funciones principales

#### `mintBadge(address to, uint256 id, string metaURI)`
- Solo `ISSUER_ROLE`
- Verifica idempotencia: si `(to, id)` ya fue emitida, revierte con `BadgeAlreadyMinted`
- Acuña 1 token ERC-1155 y asigna la URI de metadatos Open Badges v2 (almacenada en IPFS con Kubo)
- Emite evento `BadgeMinted(to, id, uri)`
- `to` puede ser wallet manual (perfil Moodle) o wallet custodial (curso piloto)

#### `isMinted(address to, uint256 id) → bool`
- Consulta si una insignia ya fue emitida a una wallet determinada

#### `pause()` / `unpause()`
- Solo `DEFAULT_ADMIN_ROLE`; bloquea/desbloquea todas las transferencias

### Idempotencia

Usa un mapping interno `_minted` con clave `keccak256(abi.encodePacked(to, id))`.
Si el par `(wallet, badgeId)` ya existe, la transacción revierte. Esto evita
duplicados si el backend reintenta un evento fallido.

Mismo (wallet, badgeId) → BadgeAlreadyMinted ✗
Misma wallet, distinto badgeId → permitido ✓
Distinta wallet, mismo badgeId → permitido ✓

---

## MeritCoinERC20 (ERC-20)

Token de recompensa académica MeritCoin (MRT).

### Herencia

```text
ERC20Pausable + AccessControl
```

### Detalles del token

| Campo | Valor |
|---|---|
| Nombre | `MeritCoin` |
| Símbolo | `MRT` |
| Decimales | `18` (estándar ERC-20) |
| Supply cap | Sin límite fijo (el backend controla la emisión por curso y semestre) |

### Roles

| Rol | Puede | Asignado a |
|---|---|---|
| `DEFAULT_ADMIN_ROLE` | Pausar/despausar, gestionar roles | Deployer |
| `MINTER_ROLE` | Acuñar tokens (`mint`) | Deployer (delegado al backend) |
| `BURNER_ROLE` | Quemar tokens (`burn`) | Deployer (delegado al backend para canjes) |

### Funciones principales

#### `mint(address to, uint256 amount)`
- Solo `MINTER_ROLE`
- `amount` en wei (18 decimales): 5 MRT = `5 * 10**18`
- `to` es la dirección de la wallet del estudiante (manual o custodial)
- Emite evento `TokensMinted(to, amount)`

#### `burn(address from, uint256 amount)`
- Solo `BURNER_ROLE`; utilizado por el backend al confirmar canjes del marketplace
- Emite evento `TokensBurned(from, amount)`

#### `pause()` / `unpause()`
- Solo `DEFAULT_ADMIN_ROLE`

---

## Despliegue

### 1. Instalar dependencias

```bash
cd contracts
npm install
```

### 2. Ejecutar tests

```bash
npx hardhat test
```

Resultado esperado: **19/19 passing**

### 3. Desplegar en Hyperledger Besu

Asegúrate de que el nodo Besu esté corriendo:

```bash
# Desde la raíz del proyecto
docker compose up -d besu
```

Despliega los contratos:

```bash
cd contracts
npx hardhat run scripts/deploy.js --network besu
```

Salida esperada:

```text
Deploying contracts with: 0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266
MeritCoin ERC20 deployed to: 0xABC...
MeritBadge ERC1155 deployed to: 0xDEF...
MINTER_ROLE granted to deployer
BURNER_ROLE granted to deployer
ISSUER_ROLE granted to deployer
```

Copiar las direcciones en `backend/.env`:

```env
MRT_CONTRACT_ADDRESS=0xABC...
BADGE_CONTRACT_ADDRESS=0xDEF...
```

### 4. Desplegar en nodo local Hardhat (desarrollo sin Besu)

```bash
# Terminal 1 — levantar nodo Hardhat
npx hardhat node

# Terminal 2 — desplegar
npx hardhat run scripts/deploy.js --network localhost
```

El nodo local incluye 20 cuentas preconfiguradas. La cuenta #0 es el deployer:

```text
Account #0: 0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266
Private Key: 0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
```

> ⚠️ Esta clave es pública y conocida. Solo para desarrollo local.

---

## Tests (19)

### MeritBadges1155.test.js — 11 tests

- Despliega con roles correctos
- `mintBadge` emite token + evento `BadgeMinted` + URI correcta
- Rechaza `mintBadge` sin `ISSUER_ROLE`
- Rechaza mint duplicado (`BadgeAlreadyMinted`)
- `isMinted` retorna `true` después del mint
- `isMinted` retorna `false` para wallet sin badge
- Pausa bloquea `mintBadge`
- Despausar permite `mintBadge`
- Solo `DEFAULT_ADMIN_ROLE` puede pausar/despausar
- Mint a wallet custodial funciona igual que a wallet manual
- Idempotencia: misma wallet, distinto badge ID → permite

### MeritCoin.test.js — 8 tests

- Nombre y símbolo correctos (`MeritCoin` / `MRT`)
- `mint` acuña tokens y emite evento `TokensMinted`
- Rechaza `mint` sin `MINTER_ROLE`
- `burn` descuenta saldo y emite evento `TokensBurned`
- Rechaza `burn` sin `BURNER_ROLE`
- Pausa bloquea transferencias
- Despausar permite transferencias
- Saldo correcto tras mint + burn combinados

```bash
npx hardhat test --verbose
```

---

## Configuración (`hardhat.config.js`)

```javascript
module.exports = {
  solidity: {
    version: "0.8.28",
    settings: {
      evmVersion: "cancun",
      optimizer: { enabled: true, runs: 200 },
    },
  },
  networks: {
    localhost: {
      url: "http://127.0.0.1:8545",
    },
    besu: {
      url: "http://127.0.0.1:8545",
      accounts: [process.env.DEPLOYER_PRIVATE_KEY],
      chainId: 1337,
    },
  },
};
```

> La red `besu` apunta al mismo puerto 8545 que el nodo local de Hardhat,
> pero se diferencia por el `chainId` (1337 = red QBFT privada).
> Usa el mismo `deploy.js` en ambos entornos cambiando solo `--network localhost`
> por `--network besu`.
>
> La variable `DEPLOYER_PRIVATE_KEY` se lee del entorno; en desarrollo
> se puede pasar inline: `DEPLOYER_PRIVATE_KEY=0xac09... npx hardhat run ...`

---

## Relación con el backend

El backend FastAPI interactúa con estos contratos vía `web3.py`:

| Acción en backend | Función del contrato |
|---|---|
| Evento académico recibido → mint MRT | `MeritCoinERC20.mint(wallet, amount)` |
| Insignia otorgada manualmente | `MeritBadges1155.mintBadge(wallet, id, ipfsCID)` |
| Canje en marketplace | `MeritCoinERC20.burn(wallet, amount)` |
| Consulta de saldo MRT | `ERC20.balanceOf(wallet)` |
| Verificación pública de badge | `MeritBadges1155.isMinted(wallet, id)` + URI vía IPFS |

La URI de cada badge apunta al gateway IPFS público:
`{IPFS_GATEWAY_URL}/ipfs/{CID}` donde el CID fue subido por `ipfs_service.py`
antes de llamar a `mintBadge`.

---

## Seguridad

- Solo **OpenZeppelin 5.x** (auditada, sin costo)
- **`AccessControl`** para permisos granulares (en lugar de `Ownable`)
- **`Pausable`** para situaciones de emergencia (detiene todas las operaciones)
- **Idempotencia en ERC-1155** para prevenir doble emisión de insignias
- Sin datos personales en blockchain: solo direcciones de wallet e IDs numéricos
- La clave privada del deployer se gestiona exclusivamente en variables de entorno, nunca en código fuente