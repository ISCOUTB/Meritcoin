<?php
// This file is part of Moodle - [http://moodle.org/](http://moodle.org/)
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
 * Definimos cuatro permisos:
 *
 *   1. manage        → Administradores que configuran el plugin a nivel global.
 *                      Contexto: sistema.
 *
 *   2. viewqueue     → Ver el estado de la cola de eventos pendientes.
 *                      Contexto: sistema.
 *
 *   3. manage_rules  → Profesores/editores que configuran las reglas de monedas
 *                      de un curso específico.
 *                      Contexto: curso.
 *
 *   4. view_report   → Ver el informe de ganancias de un curso.
 *                      Contexto: curso. Disponible para profesores y estudiantes.
 *
 * Puedes cambiar estos permisos en:
 *   Moodle → Administración del sitio → Usuarios → Permisos → Definir roles
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // ── Sistema ───────────────────────────────────────────────────────────────

    // Permiso para gestionar la configuración global del plugin.
    'local/meritcoin:manage' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Permiso para ver la cola de eventos pendientes (panel de admin).
    'local/meritcoin:viewqueue' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // ── Curso ─────────────────────────────────────────────────────────────────

    // Permiso para crear, editar y eliminar reglas de monedas de un curso.
    // Lo usan: manage.php, editrule.php, delete_rule.php.
    // Por defecto: editingteacher y manager dentro del curso.
    'local/meritcoin:manage_rules' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Permiso para ver el informe de ganancias del curso.
    // Lo usará el dashboard del estudiante y el informe del profesor.
    // Por defecto: estudiantes, profesores y managers dentro del curso.
    'local/meritcoin:view_report' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];