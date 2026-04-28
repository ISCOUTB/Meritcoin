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
 * Library functions for local_meritcoin.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

// ═══════════════════════════════════════════════════════════════════════════════
// NAVEGACIÓN
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Agrega el enlace al dashboard de MeritCoin en la navegación global.
 * Solo visible para usuarios autenticados (no invitados).
 *
 * @param global_navigation $nav
 */
function local_meritcoin_extend_navigation(global_navigation $nav) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $node = $nav->add(
        get_string('pluginname', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/dashboard.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_meritcoin_dashboard',
        new pix_icon('i/badge', get_string('pluginname', 'local_meritcoin'))
    );

    $node->showinflatnavigation = true;
}

/**
 * Agrega el enlace a MeritCoin en la configuración del perfil del usuario.
 *
 * @param navigation_node $nav
 * @param context $context
 */
function local_meritcoin_extend_navigation_user_settings($nav, $context) {
    global $USER;

    if (!($context instanceof context_user)) {
        return;
    }

    if ($context->userid !== $USER->id || !isloggedin() || isguestuser()) {
        return;
    }

    $section = $nav->find('useraccount', navigation_node::TYPE_CONTAINER);
    if ($section) {
        $section->add(
            get_string('mymeritcoin', 'local_meritcoin'),
            new moodle_url('/local/meritcoin/dashboard.php'),
            navigation_node::TYPE_SETTING,
            null,
            'local_meritcoin_profile',
            new pix_icon('i/badge', '')
        );
    }
}

/**
 * Agrega el enlace de gestión de reglas en la navegación del curso.
 *
 * Solo se muestra si el usuario tiene la capability manage_rules sobre
 * el contexto del curso. Así el enlace aparece únicamente para
 * profesores/editores y managers, no para estudiantes.
 *
 * Aparece en: Menú del curso → sección de administración del curso.
 *
 * @param navigation_node $parentnode
 * @param stdClass        $course
 * @param context_course  $context
 */
function local_meritcoin_extend_course_navigation(
    navigation_node $parentnode,
    stdClass $course,
    context_course $context
) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (!has_capability('local/meritcoin:manage_rules', $context)) {
        return;
    }

    $parentnode->add(
        get_string('manage_rules', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/manage.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_meritcoin_manage_rules',
        new pix_icon('i/settings', get_string('manage_rules', 'local_meritcoin'))
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// WALLET
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Obtiene la dirección de wallet Ethereum del usuario desde su perfil.
 *
 * @param int $userid
 * @return string|null  Wallet válida o null si no existe/no es válida.
 */
function local_meritcoin_get_user_wallet(int $userid): ?string {
    global $DB;

    $fieldshortname = get_config('local_meritcoin', 'wallet_field') ?: 'wallet';
    $field = $DB->get_record('user_info_field', ['shortname' => $fieldshortname]);

    if (!$field) {
        return null;
    }

    $data = $DB->get_record('user_info_data', [
        'userid'  => $userid,
        'fieldid' => $field->id,
    ]);

    return ($data && !empty($data->data)) ? trim($data->data) : null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ESTADÍSTICAS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Calcula estadísticas básicas del usuario a partir de la cola local.
 *
 * Nota: este método lee TODOS los eventos del usuario de la cola.
 * Para cálculos más específicos por curso usa rules_service.
 *
 * @param int $userid
 * @return array
 */
function local_meritcoin_get_user_stats(int $userid): array {
    global $DB;

    $stats = [
        'total_events'   => 0,
        'sent_events'    => 0,
        'pending_events' => 0,
        'failed_events'  => 0,
        'completions'    => 0,
        'grades'         => [],
        'avg_grade'      => null,
    ];

    $events = $DB->get_records('local_meritcoin_queue', ['userid' => $userid]);

    foreach ($events as $event) {
        $stats['total_events']++;

        switch ($event->status) {
            case 'sent':
                $stats['sent_events']++;
                break;
            case 'pending':
            case 'pending_wallet':
                $stats['pending_events']++;
                break;
            case 'failed':
                $stats['failed_events']++;
                break;
        }

        if ($event->event_type === 'completion') {
            $stats['completions']++;
        }

        if ($event->event_type === 'grade' && $event->grade !== null) {
            $stats['grades'][] = (float)$event->grade;
        }
    }

    if (!empty($stats['grades'])) {
        $stats['avg_grade'] = round(
            array_sum($stats['grades']) / count($stats['grades']),
            1
        );
    }

    return $stats;
}

// ═══════════════════════════════════════════════════════════════════════════════
// BACKEND
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Consulta el backend FastAPI para obtener el saldo MRT y los badges del estudiante.
 *
 * Devuelve datos seguros aunque el backend no esté disponible; el dashboard
 * mostrará datos locales en ese caso.
 *
 * @param int         $userid
 * @param string|null $wallet
 * @return array
 */
function local_meritcoin_get_backend_student_data(int $userid, ?string $wallet): array {
    $result = [
        'mrt_balance'       => null,
        'badges'            => [],
        'backend_available' => false,
        'error'             => null,
    ];

    $enabled    = get_config('local_meritcoin', 'enabled');
    $backendurl = get_config('local_meritcoin', 'api_url');

    if (!$enabled || empty($backendurl) || empty($wallet)) {
        $result['error'] = 'no_config';
        return $result;
    }

    try {
        $curl = new curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        $url      = rtrim($backendurl, '/') . '/students/' . urlencode($wallet) . '/summary';
        $response = $curl->get($url);
        $errno    = $curl->get_errno();

        if ($errno === 0 && !empty($response)) {
            $data = json_decode($response, true);

            if (is_array($data)) {
                $result['mrt_balance']       = $data['mrt_balance'] ?? 0;
                $result['badges']            = $data['badges'] ?? [];
                $result['backend_available'] = true;
            } else {
                $result['error'] = 'invalid_json';
            }
        } else {
            $result['error'] = 'connection_failed';
        }

    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        debugging('[local_meritcoin] Backend error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS DE UI
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Devuelve la etiqueta de texto para un estado de la cola.
 *
 * @param string $status
 * @return string
 */
function local_meritcoin_status_badge(string $status): string {
    $map = [
        'sent'           => 'statussent',
        'pending'        => 'statuspending',
        'pending_wallet' => 'statuspending',
        'failed'         => 'statusfailed',
    ];

    $key = $map[$status] ?? 'statusunknown';

    return get_string($key, 'local_meritcoin');
}