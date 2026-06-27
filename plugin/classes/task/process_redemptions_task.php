<?php
namespace local_meritcoin\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

class process_redemptions_task extends \core\task\scheduled_task {

    /** Máximo de intentos antes de abandonar un canje. */
    private const MAX_ATTEMPTS = 5;

    public function get_name(): string {
        return get_string('task_process_redemptions', 'local_meritcoin');
    }

        public function execute(): void {
        global $DB;

        if (!get_config('local_meritcoin', 'enabled')) {
            mtrace('MeritCoin: Plugin deshabilitado.');
            return;
        }

        // Canjes pendientes (sin tx_hash confirmado) con intentos restantes.
        $pending = $DB->get_records_select(
            'local_meritcoin_redemptions',
            "tx_hash IS NULL AND status != 'failed'",
            [],
            'timecreated ASC',
            '*',
            0, 50
        );

        if (empty($pending)) {
            mtrace('MeritCoin: No hay canjes pendientes.');
            return;
        }

        mtrace('MeritCoin: Procesando ' . count($pending) . ' canje(s)...');

        $client    = new \local_meritcoin\api_client();
        $processed = 0;
        $failed    = 0;

        foreach ($pending as $r) {
            $wallet = local_meritcoin_get_user_wallet($r->userid);
            if (empty($wallet)) {
                mtrace('  Canje ' . $r->id . ': sin wallet para userid=' . $r->userid . ', saltando.');
                $failed++;
                continue;
            }

            $reward = $DB->get_record('local_meritcoin_rewards', ['id' => $r->rewardid]);
            if (!$reward) {
                mtrace('  Canje ' . $r->id . ': recompensa id=' . $r->rewardid . ' no encontrada.');
                $failed++;
                continue;
            }

            $payload = json_encode([
                'student_id'     => (string)$r->userid,
                'student_wallet' => $wallet,
                'amount'         => (float)$r->coins_spent,
                'reward_id'      => (string)$r->rewardid,
                'course_id'      => (string)$r->courseid,
            ], JSON_UNESCAPED_UNICODE);

            $result = $this->call_spend_endpoint($client, $payload);

            $now = time();

            if ($result->success) {
                $txhash         = $result->tx_hash ?? 'confirmed';
                $r->tx_hash     = $txhash;
                $r->status      = 'confirmed';
                $r->attempts    = $r->attempts + 1;
                $r->last_error  = null;
                $DB->update_record('local_meritcoin_redemptions', $r);
                mtrace('  Canje ' . $r->id . ' procesado — tx: ' . $txhash);
                $processed++;
            } else {
                $r->attempts   = $r->attempts + 1;
                $r->last_error = $result->error;
                if ($r->attempts >= self::MAX_ATTEMPTS) {
                    $r->status = 'failed';
                    mtrace('  Canje ' . $r->id . ' FALLIDO permanentemente tras ' . $r->attempts . ' intentos: ' . $result->error);
                } else {
                    mtrace('  Canje ' . $r->id . ' fallido (reintentará): ' . $result->error);
                }
                $DB->update_record('local_meritcoin_redemptions', $r);
                $failed++;
            }
        }

        mtrace("MeritCoin: Done. Processed={$processed}, Failed={$failed}.");
    }

    /**
     * Llama al endpoint /tokens/spend del backend.
     *
     * Devuelve un objeto con:
     *   - success (bool)
     *   - tx_hash (string|null)
     *   - error (string)
     */
    private function call_spend_endpoint(\local_meritcoin\api_client $client, string $payload): object {
        $result           = new \stdClass();
        $result->success  = false;
        $result->tx_hash  = null;
        $result->error    = '';

        // api_client expone send_event() para /events/ingest.
        // Para /tokens/spend usamos curl de Moodle directamente
        // hasta que api_client tenga un método spend_tokens().
        // TODO: mover a api_client::spend_tokens() cuando se implemente.
        $baseurl     = rtrim((string)(get_config('local_meritcoin', 'api_url') ?: ''), '/');
        $hmacsecret  = (string)(get_config('local_meritcoin', 'hmac_secret') ?: '');

        if (empty($baseurl)) {
            $result->error = 'API URL no configurada.';
            return $result;
        }

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
            'X-HMAC-Signature: ' . hash_hmac('sha256', $payload, $hmacsecret),
        ]);

        $response = $curl->post($baseurl . '/tokens/spend', $payload, [
            'CURLOPT_TIMEOUT'        => 30,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        $info     = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);

        if ($curl->get_errno()) {
            $result->error = 'cURL error ' . $curl->get_errno();
            return $result;
        }

        if (in_array($httpcode, [200, 201, 409])) {
            $data            = json_decode($response, true);
            $result->success = true;
            $result->tx_hash = is_array($data) ? ($data['tx_hash'] ?? null) : null;
        } else {
            $decoded       = json_decode($response, true);
            $detail        = is_array($decoded) ? ($decoded['detail'] ?? $decoded['message'] ?? null) : null;
            $result->error = $detail
                ? "HTTP {$httpcode}: {$detail}"
                : "HTTP {$httpcode}: " . substr($response, 0, 150);
        }

        return $result;
    }
}