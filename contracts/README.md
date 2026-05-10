# Contratos MeritCoin — Solidity + Hardhat

Dos contratos inteligentes que manejan las insignias digitales (ERC-1155) y los
tokens de recompensa (ERC-20). Solo usan OpenZeppelin 5.x, sin librerías de pago.

## Estructura

```
contracts/
├── contracts/
│   ├── MeritBadges1155.sol    # ERC-1155 - Insignias digitales
│   └── MeritCoinERC20.sol     # ERC-20 - Token MRT
├── test/
│   ├── MeritBadges.test.js    # Tests de MeritBadges1155
│   └── MeritCoin.test.js      # Tests de MeritCoinERC20
├── scripts/
│   └── deploy.js              # Despliegue de ambos contratos
├── hardhat.config.js
└── package.json
```

## Stack

| Componente | Versión |
|------------|---------|
| Solidity | 0.8.28 |
| Hardhat | 2.28.6 |
| OpenZeppelin | 5.6.1 |
| EVM target | cancun |
| Optimizer | 200 runs |

---

## MeritBadges1155 (ERC-1155)

Insignias digitales académicas verificables. Cada token representa una
credencial única que puede verificarse públicamente en la blockchain.

### Herencia

```
ERC1155Pausable + ERC1155URIStorage + AccessControl
```

### Roles

| Rol | Puede | Asignado a |
|-----|-------|------------|
| `DEFAULT_ADMIN_ROLE` | Pausar/despausar, gestionar roles | Deployer |
| `ISSUER_ROLE` | Emitir insignias (`mintBadge`) | Deployer (delegado al backend) |

### Funciones principales

#### `mintBadge(address to, uint256 id, string metaURI)`
- Solo `ISSUER_ROLE` puede llamarla
- Verifica idempotencia: si `(to, id)` ya fue emitida, revierte con `BadgeAlreadyMinted`
- Acuña 1 token y asigna la URI de metadatos OBv2 (almacenada/simulada en IPFS)
- Emite evento `BadgeMinted(to, id, uri)`
- `to` puede ser una wallet manual (perfil Moodle) o una wallet custodial (curso piloto)

#### `isMinted(address to, uint256 id) → bool`
- Consulta si una insignia ya fue emitida a una wallet

#### `pause()` / `unpause()`
- Solo `DEFAULT_ADMIN_ROLE`; bloquea/desbloquea todas las transferencias

### Idempotencia

Usa un mapping `_minted` con clave `keccak256(abi.encodePacked(to, id))`.
Si el par (wallet, badgeId) ya existe, la transacción revierte. Esto evita
duplicados si el backend reintenta un evento fallido.

---

## MeritCoinERC20 (ERC-20)

Token de recompensa académica MeritCoin (MRT).

### Herencia

```
ERC20Pausable + AccessControl
```

### Detalles del token

| Campo | Valor |
|-------|-------|
| Nombre | MeritCoin |
| Símbolo | MRT |
| Decimales | 18 (por defecto ERC-20) |
| Supply cap | Sin límite (el backend controla la emisión por curso y semestre) |

### Roles

| Rol | Puede | Asignado a |
|-----|-------|------------|
| `DEFAULT_ADMIN_ROLE` | Pausar/despausar, gestionar roles | Deployer |
| `MINTER_ROLE` | Acuñar tokens (`mint`) | Deployer (delegado al backend) |
| `BURNER_ROLE` | Quemar tokens (`burn`) | Deployer (delegado al backend para canjes) |

### Funciones principales

#### `mint(address to, uint256 amount)`
- Solo `MINTER_ROLE`
- `amount` en wei (18 decimales). Ejemplo: 5 MRT = `5 * 10^18`
- El `to` es la dirección de la wallet del estudiante (manual o custodial)
- Emite evento `TokensMinted(to, amount)`

#### `burn(address from, uint256 amount)`
- Solo `BURNER_ROLE`; utilizado por el backend al procesar canjes del marketplace
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

### 3. Desplegar en Hyperledger Besu (producción del proyecto)

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

La salida mostrará las direcciones de ambos contratos. Copiarlas en `backend/.env`:

```
MeritCoin ERC20 deployed to:    0xABC...
MeritBadge ERC1155 deployed to: 0xDEF...
```

### 4. Desplegar en nodo local (desarrollo sin Besu)

```bash
# Terminal 1 — levantar nodo Hardhat
npx hardhat node

# Terminal 2 — desplegar
npx hardhat run scripts/deploy.js --network localhost
```

El nodo local incluye 20 cuentas preconfiguradas. La cuenta #0 es el deployer:

```
Account #0: 0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266
Private Key: 0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
```

---

## Tests (19)

### MeritBadges1155.test.js (11 tests)

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
- Idempotencia con misma wallet, distinto badge ID → permite

### MeritCoin.test.js (8 tests)

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

## Configuración (hardhat.config.js)

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
    localhost: { url: "http://127.0.0.1:8545" },
    besu: {
      url: "http://127.0.0.1:8545",
      accounts: [process.env.DEPLOYER_PRIVATE_KEY],
      chainId: 1337,
    },
  },
};
```

> La red `besu` apunta al mismo puerto 8545 que el nodo local de Hardhat,
> pero se diferencia por el `chainId` (1337 = red QBFT privada de Besu).
> Esto permite usar el mismo `deploy.js` en ambos entornos simplemente
> cambiando `--network localhost` por `--network besu`.

---

## Seguridad

- Solo OpenZeppelin 5.x (auditada, sin costo)
- `AccessControl` para permisos granulares (en lugar de `Ownable`)
- `Pausable` para situaciones de emergencia
- Idempotencia en ERC-1155 para prevenir doble emisión de insignias
- Sin datos personales en la blockchain: solo direcciones de wallet e IDs numéricos
- La clave privada del deployer se gestiona exclusivamente en variables de entorno (nunca en código fuente)
