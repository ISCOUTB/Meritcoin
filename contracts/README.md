# Contratos MeritCoin - Solidity + Hardhat

Dos contratos inteligentes que manejan las insignias digitales (ERC-1155) y los
tokens de recompensa (ERC-20). Solo usan OpenZeppelin 5.x, sin librerias de pago.

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

| Componente | Version |
|------------|---------|
| Solidity | 0.8.28 |
| Hardhat | 2.28.6 |
| OpenZeppelin | 5.6.1 |
| EVM | cancun |
| Optimizer | 200 runs |

## MeritBadges1155 (ERC-1155)

Insignias digitales academicas verificables.

### Herencia

```
ERC1155Pausable + ERC1155URIStorage + AccessControl
```

### Roles

| Rol | Puede | Asignado a |
|-----|-------|------------|
| DEFAULT_ADMIN_ROLE | Pausar/despausar, gestionar roles | Deployer |
| ISSUER_ROLE | Emitir insignias (mintBadge) | Deployer (y backend) |

### Funciones principales

#### `mintBadge(address to, uint256 id, string metaURI)`
- Solo ISSUER_ROLE puede llamarla
- Verifica idempotencia: si (to, id) ya fue emitida, revierte con `BadgeAlreadyMinted`
- Acuna 1 token, asigna la URI de metadatos OBv2
- Emite evento `BadgeMinted(to, id, uri)`

#### `isMinted(address to, uint256 id) -> bool`
- Consulta si una insignia ya fue emitida a un wallet

#### `pause()` / `unpause()`
- Solo DEFAULT_ADMIN_ROLE
- Bloquea/desbloquea todas las transferencias

### Idempotencia

Usa un mapping `_minted` con clave `keccak256(abi.encodePacked(to, id))`.
Si el par (wallet, badgeId) ya existe, la transaccion revierte. Esto evita
duplicados si el backend reintenta un evento.

## MeritCoinERC20 (ERC-20)

Token de recompensa academica MeritCoin (MRT).

### Herencia

```
ERC20Pausable + AccessControl
```

### Detalles del token

| Campo | Valor |
|-------|-------|
| Nombre | MeritCoin |
| Simbolo | MRT |
| Decimales | 18 (por defecto) |
| Supply cap | Sin limite (el backend controla la emision) |

### Roles

| Rol | Puede | Asignado a |
|-----|-------|------------|
| DEFAULT_ADMIN_ROLE | Pausar/despausar, gestionar roles | Deployer |
| MINTER_ROLE | Acunar tokens (mint) | Deployer (y backend) |

### Funciones principales

#### `mint(address to, uint256 amount)`
- Solo MINTER_ROLE puede llamarla
- `amount` en wei (18 decimales). Ej: 100 MRT = 100 * 10^18 wei
- Emite evento `TokensMinted(to, amount)`

#### `pause()` / `unpause()`
- Solo DEFAULT_ADMIN_ROLE

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

Resultado esperado: 19/19 passing

### 3. Levantar nodo local

**Terminal 1:**
```bash
npx hardhat node
```

Esto levanta un nodo JSON-RPC en `http://127.0.0.1:8545` con 20 cuentas
preconfiguradas. La cuenta #0 es el deployer:

```
Account #0: 0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266
Private Key: 0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
```

### 4. Desplegar contratos

**Terminal 2:**
```bash
npx hardhat run scripts/deploy.js --network localhost
```

La salida muestra las direcciones de los contratos desplegados. Copiar ambas
para configurar el `.env` del backend.

## Tests (19)

### MeritBadges1155.test.js

- Despliega con roles correctos
- mintBadge emite token + evento + URI
- Rechaza mintBadge sin ISSUER_ROLE
- Rechaza mint duplicado (BadgeAlreadyMinted)
- isMinted retorna true/false
- Pausa bloquea mint
- Despausar permite mint

### MeritCoin.test.js

- Nombre y simbolo correctos (MeritCoin / MRT)
- mint acuna tokens + evento
- Rechaza mint sin MINTER_ROLE
- Pausa bloquea transferencias
- Despausar permite transferencias

```bash
npx hardhat test --verbose
```

## Configuracion (hardhat.config.js)

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
  },
};
```

## Seguridad

- Solo OpenZeppelin 5.x (auditada, sin costo)
- AccessControl para permisos granulares (no Ownable)
- Pausable para emergencias
- Idempotencia en ERC-1155 para prevenir duplicados
- Sin datos personales en la blockchain (solo wallets e IDs numericos)
