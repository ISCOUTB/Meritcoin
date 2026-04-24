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

namespace local_meritcoin\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: send queued events to the MeritCoin backend.
 *
 * CÓMO FUNCIONA (explicación para no-expertos en Moodle):
 * ─────────────────────────────────────────────────────────
 * Esta tarea se ejecuta automáticamente cada minuto por el cron de Moodle.
 * Hace lo siguiente:
 *
 *   1. Busca eventos con status='pending' en la tabla local_meritcoin_queue
 *   2. Para cada evento, lo envía al backend FastAPI usando api_client
 *   3. Si el backend responde OK: marca el evento como 'sent'
 *   4. Si falla: incrementa el contador de intentos y guarda el error
 *   5. Si un evento falla más de 5 veces: lo marca como 'failed'
 *
 * Puedes ejecutar esta tarea manualmente desde la terminal:
 *   docker exec meritcoin-moodle php /opt/bitnami/moodle/admin/cli/scheduled_task.php \
 *     --execute='\local_meritcoin\task\send_events_task'
 *
 * Para ver el estado de la tarea:
 *   Moodle → Administración del sitio → Servidor → Tareas programadas
 *   Busca "Send queued MeritCoin events to backend"
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_events_task extends \core\task\scheduled_task {

    /** Máximo número de intentos antes de marcar como 'failed'. */
    private const MAX_ATTEMPTS = 5;

    /** Máximo de eventos a procesar por ejecución (para no sobrecargar). */
    private const BATCH_SIZE = 50;

    /**
     * Retorna el nombre de la tarea (visible en el panel de administración).
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_send_events', 'local_meritcoin');
    }

    /**
     * Ejecuta la tarea: envía eventos pendientes al backend.
     *
     * Este método es llamado automáticamente por el cron de Moodle.
     */
    public function execute() {
        global $DB;

        // ── Verificar que el plugin esté habilitado ─────────────────────
        if (!get_config('local_meritcoin', 'enabled')) {
            mtrace('MeritCoin plugin is disabled. Skipping.');
            return;
        }

        // ── Obtener eventos pendientes ──────────────────────────────────
        // Ordenamos por timecreated para procesar los más antiguos primero.
        $pending = $DB->get_records_select(
            'local_meritcoin_queue',
            "status = :status",
            ['status' => 'pending'],
            'timecreated ASC',
            '*',
            0,
            self::BATCH_SIZE
        );

        if (empty($pending)) {
            mtrace('MeritCoin: No pending events in queue.');
            return;
        }

        mtrace('MeritCoin: Processing ' . count($pending) . ' pending event(s)...');

        // ── Crear cliente API ───────────────────────────────────────────
        $client = new \local_meritcoin\api_client();

        $sent   = 0;
        $failed = 0;

        foreach ($pending as $record) {
            mtrace("  Event {$record->event_id} (attempt " . ($record->attempts + 1) . ")...");

            // ── Enviar al backend ───────────────────────────────────────
            $result = $client->send_event($record->payload);

            $now = time();

            if ($result->success) {
                // ── Éxito: marcar como enviado ──────────────────────────
                $record->status       = 'sent';
                $record->timemodified = $now;
                $record->attempts     = $record->attempts + 1;
                $record->last_error   = null;
                $DB->update_record('local_meritcoin_queue', $record);

                mtrace("    ✓ Sent successfully.");
                $sent++;

            } else {
                // ── Fallo: incrementar intentos ─────────────────────────
                $record->attempts     = $record->attempts + 1;
                $record->last_error   = $result->error;
                $record->timemodified = $now;

                if ($record->attempts >= self::MAX_ATTEMPTS) {
                    // Demasiados intentos: marcar como fallido definitivamente.
                    $record->status = 'failed';
                    mtrace("    ✗ FAILED permanently after {$record->attempts} attempts: {$result->error}");
                } else {
                    // Aún quedan intentos: se reintentará en la próxima ejecución.
                    mtrace("    ✗ Failed (will retry): {$result->error}");
                }

                $DB->update_record('local_meritcoin_queue', $record);
                $failed++;
            }
        }

        mtrace("MeritCoin: Done. Sent={$sent}, Failed={$failed}.");
    }
}
