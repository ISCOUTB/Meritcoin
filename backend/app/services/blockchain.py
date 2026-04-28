"""
Wrapper de web3.py para interactuar con los contratos MeritCoin.

Provee funciones de alto nivel:
  - mint_badge(): Emite una insignia ERC-1155
  - mint_mrt(): Acuña tokens MRT ERC-20
  - get_badge_balance(): Consulta balance de insignia
  - get_mrt_balance(): Consulta balance MRT
"""

import json
import logging
from pathlib import Path
from typing import Optional

from web3 import Web3
from web3.middleware import ExtraDataToPOAMiddleware

from app.core.config import settings

logger = logging.getLogger(__name__)

# ── ABIs mínimos (solo las funciones que usamos) ─────────────────────────

BADGE_ABI = json.loads("""[
  {
    "inputs": [
      {"internalType": "address", "name": "to", "type": "address"},
      {"internalType": "uint256", "name": "id", "type": "uint256"},
      {"internalType": "string",  "name": "metaURI", "type": "string"}
    ],
    "name": "mintBadge",
    "outputs": [],
    "stateMutability": "nonpayable",
    "type": "function"
  },
  {
    "inputs": [
      {"internalType": "address", "name": "to", "type": "address"},
      {"internalType": "uint256", "name": "id", "type": "uint256"}
    ],
    "name": "isMinted",
    "outputs": [{"internalType": "bool", "name": "", "type": "bool"}],
    "stateMutability": "view",
    "type": "function"
  },
  {
    "inputs": [
      {"internalType": "address", "name": "account", "type": "address"},
      {"internalType": "uint256", "name": "id",      "type": "uint256"}
    ],
    "name": "balanceOf",
    "outputs": [{"internalType": "uint256", "name": "", "type": "uint256"}],
    "stateMutability": "view",
    "type": "function"
  },
  {
    "inputs": [{"internalType": "uint256", "name": "tokenId", "type": "uint256"}],
    "name": "uri",
    "outputs": [{"internalType": "string", "name": "", "type": "string"}],
    "stateMutability": "view",
    "type": "function"
  }
]""")

MRT_ABI = json.loads("""[
  {
    "inputs": [
      {"internalType": "address", "name": "to",     "type": "address"},
      {"internalType": "uint256", "name": "amount",  "type": "uint256"}
    ],
    "name": "mint",
    "outputs": [],
    "stateMutability": "nonpayable",
    "type": "function"
  },
  {
    "inputs": [{"internalType": "address", "name": "account", "type": "address"}],
    "name": "balanceOf",
    "outputs": [{"internalType": "uint256", "name": "", "type": "uint256"}],
    "stateMutability": "view",
    "type": "function"
  }
]""")


class BlockchainService:
    """Cliente para interactuar con los contratos en la blockchain."""

    def __init__(self):
        self.w3 = Web3(Web3.HTTPProvider(settings.blockchain_rpc_url))
        # Middleware para redes PoA (Hardhat es compatible)
        self.w3.middleware_onion.inject(ExtraDataToPOAMiddleware, layer=0)

        self.account = self.w3.eth.account.from_key(settings.deployer_private_key)

        # Contratos (se inicializan si las direcciones están configuradas)
        self.badges_contract = None
        self.mrt_contract = None

        if settings.badge_contract_address:
            self.badges_contract = self.w3.eth.contract(
                address=Web3.to_checksum_address(settings.badge_contract_address),
                abi=BADGE_ABI,
            )
        if settings.mrt_contract_address:
            self.mrt_contract = self.w3.eth.contract(
                address=Web3.to_checksum_address(settings.mrt_contract_address),
                abi=MRT_ABI,
            )

    def is_connected(self) -> bool:
        """Verifica conexión al nodo blockchain."""
        try:
            return self.w3.is_connected()
        except Exception:
            return False

    def _send_tx(self, tx_func):
        """Helper: construye, firma y envía una transacción."""
        tx = tx_func.build_transaction({
            "from": self.account.address,
            "nonce": self.w3.eth.get_transaction_count(self.account.address),
            "gas": 500_000,
            "gasPrice": self.w3.eth.gas_price,
        })
        signed = self.account.sign_transaction(tx)
        tx_hash = self.w3.eth.send_raw_transaction(signed.raw_transaction)
        receipt = self.w3.eth.wait_for_transaction_receipt(tx_hash, timeout=30)
        return receipt

    def mint_badge(self, to: str, badge_id: int, uri: str) -> str:
        """
        Emite una insignia ERC-1155.
        Retorna el tx_hash como string hex.
        """
        if not self.badges_contract:
            raise RuntimeError("Contrato de badges no configurado (BADGE_CONTRACT_ADDRESS vacío)")

        to_addr = Web3.to_checksum_address(to)
        receipt = self._send_tx(
            self.badges_contract.functions.mintBadge(to_addr, badge_id, uri)
        )
        tx_hash = receipt.transactionHash.hex()
        logger.info(f"Badge #{badge_id} emitido a {to} — tx: {tx_hash}")
        return tx_hash

    def mint_mrt(self, to: str, amount_ether: float) -> str:
      """
      Acuña tokens MRT.
      amount_ether: cantidad en unidades enteras (se convierte a wei).
      Retorna el tx_hash como string hex.
      """
      if not self.mrt_contract:
          raise RuntimeError("Contrato MRT no configurado (MRT_CONTRACT_ADDRESS vacío)")

      to_addr = Web3.to_checksum_address(to)
      # web3.py requiere string o int para to_wei, no float
      amount_wei = Web3.to_wei(str(amount_ether), "ether")
      receipt = self._send_tx(
          self.mrt_contract.functions.mint(to_addr, amount_wei)
      )
      tx_hash = receipt.transactionHash.hex()
      logger.info(f"{amount_ether} MRT acuñados a {to} — tx: {tx_hash}")
      return tx_hash

    def get_badge_balance(self, wallet: str, badge_id: int) -> int:
        """Consulta cuántas unidades de un badge tiene un wallet."""
        if not self.badges_contract:
            return 0
        addr = Web3.to_checksum_address(wallet)
        return self.badges_contract.functions.balanceOf(addr, badge_id).call()

    def get_mrt_balance(self, wallet: str) -> tuple[str, str]:
        """
        Consulta saldo MRT de un wallet.
        Retorna (balance_mrt, balance_wei) como strings.
        """
        if not self.mrt_contract:
            return ("0", "0")
        addr = Web3.to_checksum_address(wallet)
        balance_wei = self.mrt_contract.functions.balanceOf(addr).call()
        balance_mrt = Web3.from_wei(balance_wei, "ether")
        return (str(balance_mrt), str(balance_wei))


# Singleton
blockchain = BlockchainService()
