<?php
// This file is part of Moodle - http://moodle.org/
// ...licencia...

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

// ═══════════════════════════════════════════════════════════════════════════════
// NAVEGACIÓN — Moodle 4.x
// ═══════════════════════════════════════════════════════════════════════════════

function local_meritcoin_is_student_only(): bool {
    if (!isloggedin() || isguestuser()) {
        return false;
    }
    // Admins y managers globales nunca son "solo estudiantes"
    if (is_siteadmin() || has_capability('moodle/site:config', context_system::instance())) {
        return false;
    }
    // Si tiene manage_rules o managerewards en CUALQUIER curso => teacher/manager
    $courses = enrol_get_users_courses($GLOBALS['USER']->id, true);
    foreach ($courses as $course) {
        $ctx = context_course::instance($course->id);
        if (has_capability('local/meritcoin:managerewards', $ctx) ||
            has_capability('local/meritcoin:manage_rules', $ctx)) {
            return false;
        }
    }
    return true;
}

function local_meritcoin_extend_navigation(global_navigation $nav) {
    if (!local_meritcoin_is_student_only()) { return; }

    $nav->add_node(navigation_node::create(
        get_string('pluginname', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/dashboard.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_meritcoin_primary',
        new pix_icon('i/badge', get_string('pluginname', 'local_meritcoin'))
    ));
}

function local_meritcoin_extend_navigation_user_settings($nav, $context) {
    global $USER;

    if (!($context instanceof context_user)) {
        return;
    }

    if ($context->userid !== $USER->id || !local_meritcoin_is_student_only()) { return; }

    $nav->add(
        get_string('mymeritcoin', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/dashboard.php'),
        navigation_node::TYPE_SETTING,
        null,
        'local_meritcoin_profile',
        new pix_icon('i/badge', '')
    );
}

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

    // Tipos de insignia — solo admins/managers globales
    if (has_capability('local/meritcoin:manage', context_system::instance())) {
        $courseadmin->add(
            get_string('badge_types_menu', 'local_meritcoin'),
            new moodle_url('/local/meritcoin/badge_types.php'),
            navigation_node::TYPE_SETTING,
            null,
            'local_meritcoin_badge_types',
            new pix_icon('i/badge', get_string('badge_types_menu', 'local_meritcoin'))
        );
    }
}

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

    // Gestionar recompensas + insignias: solo profesores/managers
    if ($ismanager) {
        $nav->add(
            get_string('rewardstitle', 'local_meritcoin'),
            new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_meritcoin_rewards_' . $course->id,
            new pix_icon('i/settings', get_string('rewardstitle', 'local_meritcoin'))
        );

        if (has_capability('local/meritcoin:awardbadges', $context)) {
            $nav->add(
                get_string('badge_award_title', 'local_meritcoin'),
                new moodle_url('/local/meritcoin/badge_award.php', ['courseid' => $course->id]),
                navigation_node::TYPE_CUSTOM,
                null,
                'local_meritcoin_badge_award_' . $course->id,
                new pix_icon('i/badge', get_string('badge_award_title', 'local_meritcoin'))
            );
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// WALLET
// ═══════════════════════════════════════════════════════════════════════════════

function local_meritcoin_get_user_wallet(int $userid): ?string {
    global $DB;

    // Primero buscar wallet custodial (curso piloto).
    $custodial = $DB->get_field('local_meritcoin_wallets', 'wallet_address', ['userid' => $userid]);
    if (!empty($custodial)) {
        return trim($custodial);
    }

    // Fallback: wallet manual en campo de perfil.
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

function local_meritcoin_get_user_stats(int $userid): array {
    global $DB;

    $total   = $DB->count_records('local_meritcoin_queue', ['userid' => $userid]);
    $sent    = $DB->count_records('local_meritcoin_queue', ['userid' => $userid, 'status' => 'sent']);
    $failed  = $DB->count_records('local_meritcoin_queue', ['userid' => $userid, 'status' => 'failed']);
    $pending = $DB->count_records_select(
        'local_meritcoin_queue',
        "userid = :uid AND status IN ('pending','pending_wallet')",
        ['uid' => $userid]
    );
    $avg = $DB->get_field_sql(
        "SELECT AVG(grade) FROM {local_meritcoin_queue}
         WHERE userid = :uid AND event_type = 'grade' AND grade IS NOT NULL",
        ['uid' => $userid]
    );

    return [
        'total_events'   => $total,
        'sent_events'    => $sent,
        'pending_events' => $pending,
        'failed_events'  => $failed,
        'avg_grade'      => $avg !== null ? round((float)$avg, 1) : null,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// BACKEND
// ═══════════════════════════════════════════════════════════════════════════════

function local_meritcoin_get_backend_student_data(int $userid, ?string $wallet): array {
    $result = [
        'mrt_balance'       => null,
        'badges'            => [],
        'backend_available' => false,
        'error'             => null,
    ];

    if (!get_config('local_meritcoin', 'enabled') || empty($wallet)) {
        $result['error'] = 'no_config';
        return $result;
    }

    try {
        $client = new \local_meritcoin\api_client();
        $data   = $client->get_student_summary($wallet);

        if (is_array($data)) {
            $result['mrt_balance']       = $data['mrt_balance'] ?? 0;
            $result['badges']            = $data['badges'] ?? [];
            $result['backend_available'] = true;
        } else {
            $result['error'] = 'invalid_response';
        }
    } catch (\Exception $e) {
        $result['error'] = $e->getMessage();
        debugging('[local_meritcoin] Backend error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS DE UI
// ═══════════════════════════════════════════════════════════════════════════════

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

function local_meritcoin_render_navbar_output(\renderer_base $renderer) {
    if (!local_meritcoin_is_student_only()) { return ''; }

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