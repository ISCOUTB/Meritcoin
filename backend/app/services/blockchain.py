"""
Wrapper de web3.py para interactuar con los contratos MeritCoin en Besu.

Contratos soportados:
  - MeritBadges1155 (ERC-1155): mint_badge()
  - MeritCoinERC20  (ERC-20):   mint_mrt(), burn_mrt(), get_mrt_balance()

La instancia singleton `blockchain` se crea al importar este módulo.
Si Besu no está disponible al arrancar, el servicio inicia igual y los
endpoints de escritura fallarán con RuntimeError hasta que la conexión
sea posible. El health check refleja el estado real de conexión.
"""

import logging
import asyncio
from typing import Optional

from web3 import Web3
from web3.middleware import ExtraDataToPOAMiddleware

from app.core.config import settings

logger = logging.getLogger(__name__)

# ── ABIs mínimos (solo las funciones que usamos) ──────────────────────────────

BADGE_ABI = [
    {
        "inputs": [
            {"internalType": "address", "name": "to",      "type": "address"},
            {"internalType": "uint256", "name": "id",      "type": "uint256"},
            {"internalType": "string",  "name": "metaURI", "type": "string"},
        ],
        "name": "mintBadge",
        "outputs": [],
        "stateMutability": "nonpayable",
        "type": "function",
    },
    {
        "inputs": [
            {"internalType": "address", "name": "to",  "type": "address"},
            {"internalType": "uint256", "name": "id",  "type": "uint256"},
        ],
        "name": "isMinted",
        "outputs": [{"internalType": "bool", "name": "", "type": "bool"}],
        "stateMutability": "view",
        "type": "function",
    },
    {
        "inputs": [
            {"internalType": "address", "name": "account", "type": "address"},
            {"internalType": "uint256", "name": "id",      "type": "uint256"},
        ],
        "name": "balanceOf",
        "outputs": [{"internalType": "uint256", "name": "", "type": "uint256"}],
        "stateMutability": "view",
        "type": "function",
    },
    {
        "inputs": [{"internalType": "uint256", "name": "tokenId", "type": "uint256"}],
        "name": "uri",
        "outputs": [{"internalType": "string", "name": "", "type": "string"}],
        "stateMutability": "view",
        "type": "function",
    },
]

MRT_ABI = [
    {
        "inputs": [
            {"internalType": "address", "name": "to",     "type": "address"},
            {"internalType": "uint256", "name": "amount", "type": "uint256"},
        ],
        "name": "mint",
        "outputs": [],
        "stateMutability": "nonpayable",
        "type": "function",
    },
    {
        "inputs": [
            {"internalType": "address", "name": "from",   "type": "address"},
            {"internalType": "uint256", "name": "amount", "type": "uint256"},
        ],
        "name": "burnFrom",
        "outputs": [],
        "stateMutability": "nonpayable",
        "type": "function",
    },
    {
        "inputs": [{"internalType": "address", "name": "account", "type": "address"}],
        "name": "balanceOf",
        "outputs": [{"internalType": "uint256", "name": "", "type": "uint256"}],
        "stateMutability": "view",
        "type": "function",
    },
]

# Timeout en segundos para wait_for_transaction_receipt.
# Besu en red privada produce bloques cada ~2s; 120s da margen amplio.
_TX_TIMEOUT = 30

# Gas máximo por transacción (fallback si estimate_gas falla).
_GAS_FALLBACK = 500_000
_MAX_RETRIES = 2
_RETRY_BASE_DELAY = 1 


class BlockchainService:
    """
    Cliente para interactuar con los contratos MeritCoin en Hyperledger Besu.

    Se instancia una sola vez como singleton al importar este módulo.
    No lanza excepción si Besu no está disponible al construirse;
    los métodos de escritura fallan en el momento de usarse.
    """

    def __init__(self) -> None:
        self.w3 = Web3(Web3.HTTPProvider(settings.blockchain_rpc_url))
        # Middleware PoA necesario para redes Besu con consenso IBFT 2.0
        self.w3.middleware_onion.inject(ExtraDataToPOAMiddleware, layer=0)

        self._account = None
        self.badges_contract = None
        self.mrt_contract = None
        self._tx_lock = asyncio.Lock()

        # La cuenta y los contratos se inicializan solo si la configuración
        # está presente. Así el backend arranca aunque falten las variables.
        if settings.deployer_private_key:
            self._account = self.w3.eth.account.from_key(settings.deployer_private_key)
        else:
            logger.warning(
                "DEPLOYER_PRIVATE_KEY no configurada — "
                "las transacciones de mint/burn no estarán disponibles"
            )

        if settings.badge_contract_address:
            self.badges_contract = self.w3.eth.contract(
                address=Web3.to_checksum_address(settings.badge_contract_address),
                abi=BADGE_ABI,
            )
        else:
            logger.warning("BADGE_CONTRACT_ADDRESS no configurada")

        if settings.mrt_contract_address:
            self.mrt_contract = self.w3.eth.contract(
                address=Web3.to_checksum_address(settings.mrt_contract_address),
                abi=MRT_ABI,
            )
        else:
            logger.warning("MRT_CONTRACT_ADDRESS no configurada")

    # ── Conexión ──────────────────────────────────────────────────────────────

    def is_connected(self) -> bool:
        """Verifica si el nodo Besu responde."""
        try:
            return self.w3.is_connected()
        except Exception:
            return False

    # ── Transacciones ─────────────────────────────────────────────────────────

    def _require_account(self) -> None:
        """Lanza RuntimeError si la clave del deployer no está configurada."""
        if not self._account:
            raise RuntimeError(
                "DEPLOYER_PRIVATE_KEY no configurada — "
                "no se pueden firmar transacciones"
            )

    async def _send_tx(self, tx_func) -> str:
        self._require_account()

        for attempt in range(1, _MAX_RETRIES + 1):
            try:
                try:
                    gas = tx_func.estimate_gas({"from": self._account.address})
                    gas = int(gas * 1.2)
                except Exception as exc:
                    logger.warning("estimate_gas falló (%s), usando fallback %d", exc, _GAS_FALLBACK)
                    gas = _GAS_FALLBACK

                # Solo bloquear al obtener nonce y enviar
                async with self._tx_lock:
                    nonce = self.w3.eth.get_transaction_count(self._account.address)
                    tx = tx_func.build_transaction({
                        "from": self._account.address,
                        "nonce": nonce,
                        "gas": gas,
                        "gasPrice": self.w3.eth.gas_price,
                    })
                    signed = self._account.sign_transaction(tx)
                    tx_hash = self.w3.eth.send_raw_transaction(signed.raw_transaction)

                # wait_for_receipt FUERA del lock — no bloquea otras txs
                receipt = await asyncio.get_event_loop().run_in_executor(
                    None,
                    lambda: self.w3.eth.wait_for_transaction_receipt(tx_hash, timeout=_TX_TIMEOUT)
                )
                return receipt.transactionHash.hex()

            except Exception as exc:
                last_exc = exc
                # Si la tx ya existe en el mempool/cadena, recuperar el hash
                if "Known transaction" in str(exc):
                    try:
                        confirmed_nonce = self.w3.eth.get_transaction_count(self._account.address, 'latest')
                        block = self.w3.eth.get_block(self.w3.eth.block_number, full_transactions=True)
                        for tx in block.transactions:
                            if tx['from'].lower() == self._account.address.lower() \
                            and tx['nonce'] == confirmed_nonce - 1:
                                logger.info("Tx ya conocida, recuperando hash: %s", tx['hash'].hex())
                                return tx['hash'].hex()
                    except Exception:
                        pass  # Si falla la recuperación, continuar con retry normal
                if attempt < _MAX_RETRIES:
                    delay = _RETRY_BASE_DELAY * (2 ** (attempt - 1))
                    await asyncio.sleep(delay)
                else:
                    logger.error("Todos los intentos fallaron: %s", exc)

        raise RuntimeError(f"Transacción falló tras {_MAX_RETRIES} intentos: {last_exc}")

    # ── Badges (ERC-1155) ─────────────────────────────────────────────────────

    async def mint_badge(self, to: str, badge_id: int, uri: str) -> str:
        """
        Emite una insignia ERC-1155 al wallet del estudiante.

        Args:
            to:       Dirección Ethereum del estudiante.
            badge_id: ID del token ERC-1155 (uint256 derivado del template UUID).
            uri:      URI de los metadatos Open Badges v2.

        Returns:
            tx_hash como string hex.
        """
        if not self.badges_contract:
            raise RuntimeError(
                "BADGE_CONTRACT_ADDRESS no configurada — "
                "no se pueden emitir insignias"
            )
        to_addr = Web3.to_checksum_address(to)
        tx_hash = await self._send_tx(
            self.badges_contract.functions.mintBadge(to_addr, badge_id, uri)
        )
        logger.info("Badge #%d emitido a %s — tx: %s", badge_id, to, tx_hash)
        return tx_hash

    def get_badge_balance(self, wallet: str, badge_id: int) -> int:
        """Retorna cuántas unidades de un badge tiene un wallet (normalmente 0 o 1)."""
        if not self.badges_contract:
            return 0
        addr = Web3.to_checksum_address(wallet)
        return self.badges_contract.functions.balanceOf(addr, badge_id).call()

    # ── MRT (ERC-20) ──────────────────────────────────────────────────────────

    async def mint_mrt(self, to: str, amount: float) -> str:
        """
        Acuña tokens MRT al wallet del estudiante.

        Args:
            to:     Dirección Ethereum del estudiante.
            amount: Cantidad en unidades enteras de MRT (se convierte a wei internamente).

        Returns:
            tx_hash como string hex.
        """
        if not self.mrt_contract:
            raise RuntimeError(
                "MRT_CONTRACT_ADDRESS no configurada — "
                "no se pueden acuñar tokens"
            )
        to_addr = Web3.to_checksum_address(to)
        # web3.py requiere str para to_wei cuando el valor viene de un float
        amount_wei = Web3.to_wei(str(amount), "ether")
        tx_hash = await self._send_tx(
            self.mrt_contract.functions.mint(to_addr, amount_wei)
        )
        logger.info("%.4f MRT acuñados a %s — tx: %s", amount, to, tx_hash)
        return tx_hash

    async def burn_mrt(self, from_addr: str, amount: float) -> str:
        """
        Quema tokens MRT de un wallet al canjear en el marketplace.

        Llama a burnFrom(address, amount) del contrato MeritCoinERC20.
        No requiere approve() previo porque el contrato usa _burn() interno
        protegido por BURNER_ROLE, que el deployer ya tiene asignado.

        Args:
            from_addr: Wallet del estudiante cuyos MRT se quemarán.
            amount:    Cantidad en unidades enteras de MRT.

        Returns:
            tx_hash como string hex.
        """
        if not self.mrt_contract:
            raise RuntimeError(
                "MRT_CONTRACT_ADDRESS no configurada — "
                "no se pueden quemar tokens"
            )
        addr = Web3.to_checksum_address(from_addr)
        amount_wei = Web3.to_wei(str(amount), "ether")
        tx_hash = await self._send_tx(
            self.mrt_contract.functions.burnFrom(addr, amount_wei)
        )
        logger.info("%.4f MRT quemados de %s — tx: %s", amount, from_addr, tx_hash)
        return tx_hash

    def get_mrt_balance(self, wallet: str) -> tuple[str, str]:
        """
        Consulta el saldo MRT de un wallet directamente desde la blockchain.

        Returns:
            Tupla (balance_mrt, balance_wei) como strings.
            Retorna ("0", "0") si el contrato no está configurado.
        """
        if not self.mrt_contract:
            return ("0", "0")
        addr = Web3.to_checksum_address(wallet)
        balance_wei = self.mrt_contract.functions.balanceOf(addr).call()
        balance_mrt = Web3.from_wei(balance_wei, "ether")
        return (str(balance_mrt), str(balance_wei))


# ── Singleton ─────────────────────────────────────────────────────────────────
# Una sola instancia compartida por toda la app.
# El constructor no lanza excepción si Besu no está disponible.
blockchain = BlockchainService()
