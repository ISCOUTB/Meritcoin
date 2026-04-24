<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Capabilities for local_meritcoin.
 *
 * CÓMO FUNCIONA (explicación para no-expertos en Moodle):
 * ─────────────────────────────────────────────────────────
 * Las "capabilities" son permisos que controlan quién puede hacer qué.
 * Aquí definimos dos permisos:
 *   1. manage: Para administradores que configuran el plugin.
 *   2. viewqueue: Para ver el estado de la cola de eventos.
 *
 * Por defecto, solo los managers y administradores tienen estos permisos.
 * Puedes cambiarlos en:
 *   Moodle → Administración del sitio → Usuarios → Permisos → Definir roles
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Permiso para gestionar la configuración del plugin.
    'local/meritcoin:manage' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Permiso para ver la cola de eventos pendientes.
    'local/meritcoin:viewqueue' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
