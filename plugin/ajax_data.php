<?php
// ajax_data.php — Endpoint centralizado de datos en tiempo real
define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');
require_login();

global $USER, $DB;

$page = required_param('page', PARAM_ALPHANUMEXT);

header('Content-Type: application/json');

switch ($page) {

    case 'dashboard':
        $wallet  = local_meritcoin_get_user_wallet($USER->id);
        $backend = local_meritcoin_get_backend_student_data($USER->id, $wallet);
        $stats   = local_meritcoin_get_user_stats($USER->id);
        $earned  = (float)$DB->get_field_sql(
            "SELECT COALESCE(SUM(coins_amount),0) FROM {local_meritcoin_queue}
              WHERE userid=:uid AND status='sent'", ['uid'=>$USER->id]);
        $spent   = (float)$DB->get_field_sql(
            "SELECT COALESCE(SUM(coins_spent),0) FROM {local_meritcoin_redemptions}
              WHERE userid=:uid", ['uid'=>$USER->id]);
        echo json_encode([
            'balance'           => max(0, $backend['mrt_balance'] ?? max(0, $earned - $spent)),
            'backend_available' => !empty($backend['backend_available']),
            'total_events'      => $stats['total_events'],
        ]);
        break;

    case 'marketplace':
        $courseid = required_param('courseid', PARAM_INT);
        $earned = (float)$DB->get_field_sql(
            "SELECT COALESCE(SUM(coins_amount),0) FROM {local_meritcoin_queue}
            WHERE userid=:uid AND courseid=:cid AND status='sent'",
            ['uid' => $USER->id, 'cid' => $courseid]
        );
        $spent = (float)$DB->get_field_sql(
            "SELECT COALESCE(SUM(coins_spent),0) FROM {local_meritcoin_redemptions}
            WHERE userid=:uid AND courseid=:cid",
            ['uid' => $USER->id, 'cid' => $courseid]
        );
        echo json_encode(['balance' => max(0, $earned - $spent)]);
        break;

    case 'rewards':
        $redemptions = $DB->get_records_sql(
            "SELECT * FROM {local_meritcoin_redemptions}
              WHERE userid=:uid ORDER BY timecreated DESC LIMIT 10",
            ['uid' => $USER->id]);
        echo json_encode(['redemptions' => array_values($redemptions)]);
        break;

    default:
        echo json_encode(['error' => 'unknown_page']);
        break;
}