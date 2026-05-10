<?php
namespace local_meritcoin\task;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

class process_redemptions_task extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_process_redemptions', 'local_meritcoin');
    }

    public function execute(): void {
        global $DB;

        if (!get_config('local_meritcoin', 'enabled')) {
            mtrace('[MeritCoin] Plugin deshabilitado.');
            return;
        }

        $backendurl  = rtrim(get_config('local_meritcoin', 'api_url'), '/');
        $hmac_secret = get_config('local_meritcoin', 'hmac_secret');

        if (empty($backendurl)) {
            mtrace('[MeritCoin] Backend URL no configurada.');
            return;
        }

        // Canjes pendientes (sin tx_hash)
        $pending = $DB->get_records_select(
            'local_meritcoin_redemptions',
            'tx_hash IS NULL',
            [],
            'timecreated ASC',
            '*',
            0, 50
        );

        if (empty($pending)) {
            mtrace('[MeritCoin] No hay canjes pendientes.');
            return;
        }

        mtrace('[MeritCoin] Procesando ' . count($pending) . ' canjes...');

        $processed = 0;
        $failed    = 0;

        foreach ($pending as $r) {
            // Obtener wallet del estudiante
            $wallet = local_meritcoin_get_user_wallet($r->userid);
            if (empty($wallet)) {
                mtrace('[MeritCoin] Sin wallet para userid=' . $r->userid . ', saltando.');
                $failed++;
                continue;
            }

            // Obtener precio de la recompensa
            $reward = $DB->get_record('local_meritcoin_rewards', ['id' => $r->rewardid]);
            if (!$reward) {
                mtrace('[MeritCoin] Recompensa no encontrada id=' . $r->rewardid);
                $failed++;
                continue;
            }

            $payload = json_encode([
                'student_id'     => (string)$r->userid,
                'student_wallet' => $wallet,
                'amount'         => (float)$reward->price_mrt,
                'reward_id'      => (string)$r->rewardid,
                'course_id'      => (string)$r->courseid,
            ]);

            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_TIMEOUT'        => 30,
                'CURLOPT_CONNECTTIMEOUT' => 5,
                'CURLOPT_RETURNTRANSFER' => true,
            ]);

            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            if (!empty($hmac_secret)) {
                $headers[] = 'X-HMAC-Signature: ' . hash_hmac('sha256', $payload, $hmac_secret);
            }
            $curl->setHeader($headers);

            $response = $curl->post($backendurl . '/tokens/spend', $payload);
            $httpcode = ($curl->get_info())['http_code'] ?? 0;

            if (in_array($httpcode, [200, 201])) {
                $data = json_decode($response, true);
                $DB->set_field('local_meritcoin_redemptions', 'tx_hash',
                    $data['tx_hash'] ?? 'confirmed', ['id' => $r->id]);
                mtrace('[MeritCoin] Canje ' . $r->id . ' quemado — tx: ' . ($data['tx_hash'] ?? ''));
                $processed++;
            } else {
                mtrace('[MeritCoin] Error canje ' . $r->id . ' — HTTP ' . $httpcode . ': ' . substr($response, 0, 100));
                $failed++;
            }
        }

        mtrace('[MeritCoin] Completado: ' . $processed . ' procesados, ' . $failed . ' fallidos.');
    }
}