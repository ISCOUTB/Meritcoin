<?php
// This file is part of Moodle - http://moodle.org/
// GNU GPL v3 or later - http://www.gnu.org/copyleft/gpl.html

namespace local_meritcoin\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tarea programada: cierra enrollments de cursos piloto vencidos.
 *
 * Se ejecuta diariamente. Para cada curso piloto activo cuya
 * fecha de expiración ya pasó, llama a POST /wallets/expire-course
 * en el backend y marca el piloto como cerrado.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expire_courses_task extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_expire_courses', 'local_meritcoin');
    }

    public function execute(): void {
        global $DB;

        $now = time();

        // Cursos piloto habilitados cuya fecha de expiración ya pasó.
        $sql = "SELECT pc.*, c.fullname AS coursename
                  FROM {local_meritcoin_pilot_courses} pc
                  JOIN {course} c ON c.id = pc.courseid
                 WHERE pc.pilot_enabled = 1
                   AND (
                       (pc.expires_at IS NOT NULL AND pc.expires_at <= :now1)
                       OR
                       (pc.expires_at IS NULL AND c.enddate > 0 AND c.enddate <= :now2)
                   )";

        $pilots = $DB->get_records_sql($sql, ['now1' => $now, 'now2' => $now]);

        if (empty($pilots)) {
            mtrace('MeritCoin expire_courses_task: no hay cursos vencidos.');
            return;
        }

        $client = new \local_meritcoin\api_client();

        foreach ($pilots as $pilot) {
            mtrace("MeritCoin: expirando curso {$pilot->courseid} ({$pilot->coursename})...");

            $result = $client->post('/wallets/expire-course', [
                'course_id' => "COURSE-{$pilot->courseid}",
            ]);

            if ($result !== null) {
                $expired = $result['expired_count'] ?? 0;
                mtrace("  → {$expired} enrollments cerrados.");

                // Marcar el piloto como cerrado para no volver a procesarlo.
                $pilot->pilot_enabled = 0;
                $DB->update_record('local_meritcoin_pilot_courses', $pilot);
            } else {
                mtrace("  ⚠ Error al llamar /wallets/expire-course para curso {$pilot->courseid}");
            }
        }
    }
}