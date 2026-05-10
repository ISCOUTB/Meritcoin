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
 * Scheduled tasks for local_meritcoin.
 *
 * CÓMO FUNCIONA (explicación para no-expertos en Moodle):
 * ─────────────────────────────────────────────────────────
 * Moodle tiene un sistema de "cron" (tareas programadas) que ejecuta tareas
 * en segundo plano. Aquí definimos una tarea que se ejecuta cada minuto
 * para enviar los eventos encolados al backend FastAPI.
 *
 * Para que el cron funcione, Docker ejecuta automáticamente el cron de Moodle.
 * También puedes ejecutarlo manualmente con:
 *   docker exec meritcoin-moodle php /opt/bitnami/moodle/admin/cli/cron.php
 *
 * Para ver y configurar las tareas:
 *   Moodle → Administración del sitio → Servidor → Tareas programadas
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


$tasks = [
    [
        'classname' => '\local_meritcoin\task\send_events_task',

        // Se ejecuta cada minuto por defecto.
        // Esto NO significa que bombarda al backend: solo envía si hay eventos
        // pendientes en la cola. Si la cola está vacía, termina inmediatamente.
        'blocking'  => 0,        // No bloquea otras tareas del cron.
        'minute'    => '*',      // Cada minuto.
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\local_meritcoin\task\process_redemptions_task',

        // Procesa canjes pendientes (quema tokens en blockchain).
        // Se ejecuta cada 2 minutos para evitar sobrecargar el backend.
        'blocking'  => 0,
        'minute'    => '*/2',    // Cada 2 minutos.
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],

    [
        'classname'   => '\local_meritcoin\task\expire_courses_task',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '2',       // 2 AM diariamente
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
    ],
];