// SPDX-License-Identifier: MIT
pragma solidity ^0.8.28;

import "@openzeppelin/contracts/token/ERC20/extensions/ERC20Pausable.sol";
import "@openzeppelin/contracts/access/AccessControl.sol";

/**
 * @title MeritCoinERC20
 * @notice Token de recompensa académica MeritCoin (MRT) — ERC-20.
 *
 * Características:
 *   - Símbolo: MRT, Nombre: MeritCoin, 18 decimales (por defecto).
 *   - Solo cuentas con MINTER_ROLE pueden crear nuevos tokens (mint).
 *   - El contrato es pausable por el admin ante emergencias.
 *   - Sin supply cap: el backend decide cuánto acuñar por evento.
 */
contract MeritCoinERC20 is ERC20Pausable, AccessControl {

    // ── Roles ────────────────────────────────────────────────────────────

    /// @notice Rol que permite acuñar tokens
    bytes32 public constant MINTER_ROLE = keccak256("MINTER_ROLE");

    /// @notice Rol que permite quemar tokens (para canjes en el marketplace)
    bytes32 public constant BURNER_ROLE = keccak256("BURNER_ROLE");

    // ── Eventos ──────────────────────────────────────────────────────────

    /// @dev Emitido al acuñar tokens
    event TokensMinted(address indexed to, uint256 amount);

    /// @dev Emitido al quemar tokens
    event TokensBurned(address indexed from, uint256 amount);

    // ── Constructor ──────────────────────────────────────────────────────

    /**
     * @param admin Dirección que recibe DEFAULT_ADMIN_ROLE, MINTER_ROLE y BURNER_ROLE.
     */
    constructor(address admin) ERC20("MeritCoin", "MRT") {
        _grantRole(DEFAULT_ADMIN_ROLE, admin);
        _grantRole(MINTER_ROLE, admin);
        _grantRole(BURNER_ROLE, admin);
    }

    // ── Mint ─────────────────────────────────────────────────────────────

    /**
     * @notice Acuña tokens MRT a un estudiante como recompensa.
     * @param to     Wallet del estudiante
     * @param amount Cantidad de MRT (en wei, 18 decimales)
     */
    function mint(address to, uint256 amount) external onlyRole(MINTER_ROLE) {
        _mint(to, amount);
        emit TokensMinted(to, amount);
    }

    // ── Pausar / Despausar ───────────────────────────────────────────────

    function pause() external onlyRole(DEFAULT_ADMIN_ROLE) {
        _pause();
    }

    function unpause() external onlyRole(DEFAULT_ADMIN_ROLE) {
        _unpause();
    }

    // ── Burn ─────────────────────────────────────────────────────────────

    /**
     * @notice Quema tokens MRT de un estudiante al canjear una recompensa.
     * @param from   Wallet del estudiante
     * @param amount Cantidad de MRT a quemar (en wei, 18 decimales)
     */
    function burnFrom(address from, uint256 amount) external onlyRole(BURNER_ROLE) {
        _burn(from, amount);
        emit TokensBurned(from, amount);
    }
}
