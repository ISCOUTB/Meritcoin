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
 * Library functions for local_meritcoin.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

// ═══════════════════════════════════════════════════════════════════════════════
// NAVEGACIÓN — Moodle 4.x
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Agrega nodo MeritCoin en la navegación global.
 * Solo para usuarios logueados que NO sean profesores/managers.
 *
 * @param global_navigation $nav
 */
function local_meritcoin_extend_navigation(global_navigation $nav) {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Ocultar a profesores/managers en cualquier curso
    $courses = enrol_get_users_courses($USER->id, true);
    foreach ($courses as $course) {
        $ctx = context_course::instance($course->id);
        if (has_capability('local/meritcoin:managerewards', $ctx) ||
            has_capability('local/meritcoin:manage_rules', $ctx)) {
            return;
        }
    }

    $node = navigation_node::create(
        get_string('pluginname', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/dashboard.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_meritcoin_primary',
        new pix_icon('i/badge', get_string('pluginname', 'local_meritcoin'))
    );

    $nav->add_node($node);
}

/**
 * Agrega el enlace a MeritCoin en la configuración del perfil del usuario.
 * Solo para estudiantes (no profesores/managers).
 *
 * @param navigation_node $nav
 * @param context         $context
 */
function local_meritcoin_extend_navigation_user_settings($nav, $context) {
    global $USER;

    if (!($context instanceof context_user)) {
        return;
    }

    if ($context->userid !== $USER->id || !isloggedin() || isguestuser()) {
        return;
    }

    $nav->add(
        get_string('mymeritcoin', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/dashboard.php'),
        navigation_node::TYPE_SETTING,
        null,
        'local_meritcoin_profile',
        new pix_icon('i/badge', '')
    );
}

/**
 * Agrega "Gestión de reglas MeritCoin" al menú de administración del curso.
 *
 * @param settings_navigation $settingsnav
 * @param context             $context
 */
function local_meritcoin_extend_settings_navigation(settings_navigation $settingsnav, context $context) {

    if (!($context instanceof context_course)) {
        return;
    }

    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (!has_capability('local/meritcoin:manage_rules', $context)) {
        return;
    }

    $courseadmin = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);
    if (!$courseadmin) {
        return;
    }

    $courseadmin->add(
        get_string('manage_rules', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/manage.php', ['courseid' => $context->instanceid]),
        navigation_node::TYPE_SETTING,
        null,
        'local_meritcoin_manage_rules',
        new pix_icon('i/settings', get_string('manage_rules', 'local_meritcoin'))
    );
}

/**
 * Extiende el menú de navegación dentro de un curso.
 * - Estudiantes ven: "Mercado de Recompensas"
 * - Profesores/managers ven: "Gestionar Recompensas"
 */
function local_meritcoin_extend_navigation_course($nav, $course, $context) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $ismanager = has_capability('local/meritcoin:managerewards', $context);

    // Marketplace: solo estudiantes
    if (!$ismanager && has_capability('local/meritcoin:viewmarketplace', $context)) {
        $nav->add(
            get_string('marketplacetitle', 'local_meritcoin'),
            new moodle_url('/local/meritcoin/marketplace.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_meritcoin_marketplace_' . $course->id,
            new pix_icon('i/star-rating', get_string('marketplacetitle', 'local_meritcoin'))
        );
    }

    // Gestionar recompensas: solo profesores/managers
    if ($ismanager) {
        $nav->add(
            get_string('rewardstitle', 'local_meritcoin'),
            new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_meritcoin_rewards_' . $course->id,
            new pix_icon('i/settings', get_string('rewardstitle', 'local_meritcoin'))
        );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// WALLET
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Obtiene la dirección de wallet Ethereum del usuario desde su perfil.
 *
 * @param int $userid
 * @return string|null
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
        $url     = rtrim($backendurl, '/') . '/students/' . urlencode($wallet) . '/summary';
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method'  => 'GET',
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        $errno    = ($response === false) ? 1 : 0;

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

/**
 * Hook de renderizado — inyecta enlace MeritCoin en el user menu de Boost.
 */
function local_meritcoin_render_navbar_output(\renderer_base $renderer) {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    $courses = enrol_get_users_courses($USER->id, true);
    foreach ($courses as $course) {
        $ctx = context_course::instance($course->id);
        if (has_capability('local/meritcoin:managerewards', $ctx) ||
            has_capability('local/meritcoin:manage_rules', $ctx)) {
            return '';
        }
    }

    $url = new moodle_url('/local/meritcoin/dashboard.php');

    return '<a href="' . $url->out() . '" 
                class="nav-link" 
                style="display:flex; align-items:center; padding: 0 8px; color: inherit;"
                title="' . get_string('mymeritcoin', 'local_meritcoin') . '">
                <i class="icon fa fa-certificate fa-fw" 
                   style="font-size:1.2rem; margin:0;" 
                   aria-hidden="true"></i>
                <span style="margin-left:4px; font-size:0.9rem;">
                    ' . get_string('mymeritcoin', 'local_meritcoin') . '
                </span>
            </a>';
}