<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

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
            get_string('my_meritcoin', 'local_meritcoin'),
            new moodle_url('/local/meritcoin/dashboard.php'),
            navigation_node::TYPE_SETTING,
            null,
            'local_meritcoin_profile',
            new pix_icon('i/badge', '')
        );
    }
}

function local_meritcoin_get_user_wallet(int $userid): ?string {
    global $DB;

    $fieldshortname = get_config('local_meritcoin', 'wallet_field') ?: 'wallet';
    $field = $DB->get_record('user_info_field', ['shortname' => $fieldshortname]);

    if (!$field) {
        return null;
    }

    $data = $DB->get_record('user_info_data', [
        'userid' => $userid,
        'fieldid' => $field->id
    ]);

    return ($data && !empty($data->data)) ? trim($data->data) : null;
}

function local_meritcoin_get_user_stats(int $userid): array {
    global $DB;

    $stats = [
        'total_events' => 0,
        'sent_events' => 0,
        'pending_events' => 0,
        'failed_events' => 0,
        'completions' => 0,
        'grades' => [],
        'avg_grade' => null,
    ];

    $events = $DB->get_records('local_meritcoin_queue', ['userid' => $userid]);

    foreach ($events as $event) {
        $stats['total_events']++;

        switch ($event->status) {
            case 'sent':
                $stats['sent_events']++;
                break;
            case 'pending':
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
        $stats['avg_grade'] = round(array_sum($stats['grades']) / count($stats['grades']), 1);
    }

    return $stats;
}

function local_meritcoin_get_backend_student_data(int $userid, ?string $wallet): array {
    $result = [
        'mrt_balance' => null,
        'badges' => [],
        'backend_available' => false,
        'error' => null,
    ];

    $enabled = get_config('local_meritcoin', 'enabled');
    $backendurl = get_config('local_meritcoin', 'api_url');

    if (!$enabled || empty($backendurl) || empty($wallet)) {
        $result['error'] = 'no_config';
        return $result;
    }

    try {
        $curl = new curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        $url = rtrim($backendurl, '/') . '/students/' . urlencode($wallet) . '/summary';
        $response = $curl->get($url);
        $errno = $curl->get_errno();

        if ($errno === 0 && !empty($response)) {
            $data = json_decode($response, true);

            if (is_array($data)) {
                $result['mrt_balance'] = $data['mrt_balance'] ?? 0;
                $result['badges'] = $data['badges'] ?? [];
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

function local_meritcoin_status_badge(string $status): string {
    $map = [
        'sent' => ['bg-success', 'fa-check-circle', 'status_sent'],
        'pending' => ['bg-warning text-dark', 'fa-clock', 'status_pending'],
        'failed' => ['bg-danger', 'fa-times-circle', 'status_failed'],
    ];

    $item = $map[$status] ?? ['bg-secondary', 'fa-question', 'status_unknown'];
    $label = get_string($item[2], 'local_meritcoin');

    return $label;
}
