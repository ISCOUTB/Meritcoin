// SPDX-License-Identifier: MIT
pragma solidity ^0.8.28;

import "@openzeppelin/contracts/token/ERC1155/extensions/ERC1155Pausable.sol";
import "@openzeppelin/contracts/token/ERC1155/extensions/ERC1155URIStorage.sol";
import "@openzeppelin/contracts/access/AccessControl.sol";

/**
 * @title MeritBadges1155
 * @notice Insignias digitales académicas verificables (ERC-1155).
 *
 * Características:
 *   - Solo cuentas con ISSUER_ROLE pueden emitir insignias (mintBadge).
 *   - Cada par (estudiante, badgeId) solo puede emitirse UNA vez (idempotencia).
 *   - Cada insignia tiene su propia URI de metadatos (OBv2 en IPFS).
 *   - El contrato es pausable por el admin ante emergencias.
 */
contract MeritBadges1155 is ERC1155Pausable, ERC1155URIStorage, AccessControl {

    /// @notice Rol que permite emitir insignias
    bytes32 public constant ISSUER_ROLE = keccak256("ISSUER_ROLE");

    /// @dev Registro de insignias ya emitidas: keccak256(to, id) => true
    mapping(bytes32 => bool) private _minted;

    /// @dev Emitido al acuñar una insignia
    event BadgeMinted(address indexed to, uint256 indexed id, string uri);

    /// @dev Error: la insignia ya fue emitida a este destinatario
    error BadgeAlreadyMinted(address to, uint256 id);

    /**
     * @param admin Dirección que recibe DEFAULT_ADMIN_ROLE e ISSUER_ROLE.
     */
    constructor(address admin) ERC1155("") {
        _grantRole(DEFAULT_ADMIN_ROLE, admin);
        _grantRole(ISSUER_ROLE, admin);
    }

    // ── Emitir insignia ─────────────────────────────────────────────────

    /**
     * @notice Emite una insignia a un estudiante.
     * @param to       Wallet del estudiante
     * @param id       Identificador único de la insignia
     * @param metaURI  URI de los metadatos OBv2 (IPFS CID o URL)
     *
     * Requisitos:
     *   - El llamante debe tener ISSUER_ROLE.
     *   - La insignia (to, id) no debe haber sido emitida antes.
     */
    function mintBadge(
        address to,
        uint256 id,
        string memory metaURI
    ) external onlyRole(ISSUER_ROLE) {
        bytes32 key = keccak256(abi.encodePacked(to, id));
        if (_minted[key]) {
            revert BadgeAlreadyMinted(to, id);
        }

        _minted[key] = true;
        _mint(to, id, 1, "");
        _setURI(id, metaURI);

        emit BadgeMinted(to, id, metaURI);
    }

    // ── Pausar / Despausar ──────────────────────────────────────────────

    function pause() external onlyRole(DEFAULT_ADMIN_ROLE) {
        _pause();
    }

    function unpause() external onlyRole(DEFAULT_ADMIN_ROLE) {
        _unpause();
    }

    // ── Consultas ───────────────────────────────────────────────────────

    /**
     * @notice Verifica si una insignia ya fue emitida a una dirección.
     */
    function isMinted(address to, uint256 id) external view returns (bool) {
        return _minted[keccak256(abi.encodePacked(to, id))];
    }

    // ── Overrides requeridos por Solidity ────────────────────────────────

    function _update(
        address from,
        address to,
        uint256[] memory ids,
        uint256[] memory values
    ) internal override(ERC1155Pausable, ERC1155) {
        super._update(from, to, ids, values);
    }

    function uri(uint256 tokenId)
        public
        view
        override(ERC1155, ERC1155URIStorage)
        returns (string memory)
    {
        return ERC1155URIStorage.uri(tokenId);
    }

    function supportsInterface(bytes4 interfaceId)
        public
        view
        override(ERC1155, AccessControl)
        returns (bool)
    {
        return super.supportsInterface(interfaceId);
    }
}
