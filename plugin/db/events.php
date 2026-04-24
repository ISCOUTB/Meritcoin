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
 * Event observers for local_meritcoin.
 *
 * Moodle lee este archivo para saber qué eventos observar.
 * Cada entrada asocia un evento del core con un método de nuestro observer.
 *
 * CÓMO FUNCIONA (explicación para no-expertos en Moodle):
 * ─────────────────────────────────────────────────────────
 * Moodle tiene un sistema de eventos: cuando un estudiante completa un curso
 * o recibe una calificación, Moodle "dispara" un evento. Nosotros registramos
 * aquí que queremos "escuchar" esos eventos. Cuando ocurren, Moodle llama
 * automáticamente al método que indicamos (por ejemplo, course_completed).
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // ── Evento: Curso completado ────────────────────────────────────────
    // Se dispara cuando un estudiante completa todos los requisitos de un curso.
    // En Moodle: Administración del curso → Finalización del curso debe estar
    // habilitada y configurada con criterios (actividades, calificación, etc.).
    [
        'eventname' => '\core\event\course_completed',
        'callback'  => '\local_meritcoin\observer::course_completed',
    ],

    // ── Evento: Calificación registrada ─────────────────────────────────
    // Se dispara cuando un profesor califica a un estudiante en cualquier
    // actividad o cuando la calificación final del curso se actualiza.
    // Usamos user_graded que cubre tanto calificaciones de actividades como
    // la calificación final del curso.
    [
        'eventname' => '\core\event\user_graded',
        'callback'  => '\local_meritcoin\observer::user_graded',
    ],
];
