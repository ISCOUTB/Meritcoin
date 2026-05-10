<?php
// classes/task/send_events.php - Envía eventos pendientes al backend MeritCoin
namespace local_meritcoin\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/meritcoin/lib.php');

class send_events extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_send_events', 'local_meritcoin');
    }

    public function execute(): void {
        global $DB;

        if (!get_config('local_meritcoin', 'enabled')) {
            mtrace('[MeritCoin] Plugin deshabilitado. Tarea omitida.');
            return;
        }

        $backendurl = get_config('local_meritcoin', 'api_url');
        if (empty($backendurl)) {
            mtrace('[MeritCoin] Backend URL no configurada. Tarea omitida.');
            return;
        }

        $max_attempts = (int)(get_config('local_meritcoin', 'maxattempts') ?: 3);

        // Obtener eventos pendientes (o fallidos con intentos restantes)
        $pending = $DB->get_records_select(
            'local_meritcoin_queue',
            "status = 'pending' OR (status = 'failed' AND attempts < :max)",
            ['max' => $max_attempts],
            'timecreated ASC',
            '*',
            0,
            50   // máximo 50 por ejecución
        );

        if (empty($pending)) {
            mtrace('[MeritCoin] No hay eventos pendientes.');
            return;
        }

        mtrace('[MeritCoin] Procesando ' . count($pending) . ' eventos...');

        $sent    = 0;
        $failed  = 0;

        foreach ($pending as $event) {
            $success = $this->send_event($event, $backendurl);

            $now = time();
            if ($success) {
                $DB->update_record('local_meritcoin_queue', (object)[
                    'id'           => $event->id,
                    'status'       => 'sent',
                    'attempts'     => $event->attempts + 1,
                    'last_error'   => null,
                    'timemodified' => $now,
                ]);
                $sent++;
            } else {
                $new_status = ($event->attempts + 1 >= $max_attempts) ? 'failed' : 'pending';
                $DB->update_record('local_meritcoin_queue', (object)[
                    'id'           => $event->id,
                    'status'       => $new_status,
                    'attempts'     => $event->attempts + 1,
                    'timemodified' => $now,
                ]);
                $failed++;
            }
        }

        mtrace('[MeritCoin] Completado: ' . $sent . ' enviados, ' . $failed . ' fallidos.');
    }

    private function send_event(object $event, string $backendurl): bool {
        global $DB;

        try {
            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_TIMEOUT'        => 10,
                'CURLOPT_CONNECTTIMEOUT' => 5,
                'CURLOPT_RETURNTRANSFER' => true,
            ]);

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            $hmac_secret = get_config('local_meritcoin', 'hmac_secret');
            if (!empty($hmac_secret)) {
                $body_hash = hash_hmac('sha256', $event->payload, $hmac_secret);
                $headers[] = 'X-HMAC-Signature: ' . $body_hash;
            }

            $curl->setHeader($headers);

            $url = rtrim($backendurl, '/') . '/events/ingest';
            $response = $curl->post($url, $event->payload);
            $errno    = $curl->get_errno();
            $info     = $curl->get_info();
            $httpcode = $info['http_code'] ?? 0;

            if ($errno !== 0) {
                $DB->set_field('local_meritcoin_queue', 'last_error',
                    'CURL error ' . $errno, ['id' => $event->id]);
                mtrace('[MeritCoin] CURL error en evento ' . $event->event_id . ': errno=' . $errno);
                return false;
            }

            // 200 o 201 = éxito, 409 = duplicado (también éxito, idempotente)
            if (in_array($httpcode, [200, 201, 409])) {
                mtrace('[MeritCoin] Enviado: ' . $event->event_id . ' (HTTP ' . $httpcode . ')');
                return true;
            }

            $DB->set_field('local_meritcoin_queue', 'last_error',
                'HTTP ' . $httpcode . ': ' . substr($response, 0, 200), ['id' => $event->id]);
            mtrace('[MeritCoin] Error HTTP ' . $httpcode . ' en evento ' . $event->event_id);
            return false;

        } catch (\Exception $e) {
            $DB->set_field('local_meritcoin_queue', 'last_error',
                $e->getMessage(), ['id' => $event->id]);
            mtrace('[MeritCoin] Excepción en evento ' . $event->event_id . ': ' . $e->getMessage());
            return false;
        }
    }
}
