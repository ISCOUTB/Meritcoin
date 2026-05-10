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

namespace local_meritcoin;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for local_meritcoin.
 *
 * Escucha el evento user_graded y encola los eventos de calificación
 * que cumplen las reglas del curso para recibir MRT.
 *
 * Prioridad de reglas:
 *   a) Regla específica de actividad (cmid exacto)
 *   b) Regla por tipo de módulo (assign, forum, quiz...)
 *   c) Regla general del curso
 *
 * Si la regla tiene min_grade, valida la nota antes de encolar.
 * La idempotencia es estricta: si ya se encoló un evento para
 * userid+courseid+cmid+type, no se vuelve a encolar aunque la nota cambie.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Maneja el evento de calificación registrada.
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event) {
        global $DB;

        $gradeitem = $DB->get_record('grade_items', ['id' => $event->other['itemid'] ?? 0]);
        if (!$gradeitem) {
            return;
        }

        // Solo actividades reales (no el ítem de curso).
        if ($gradeitem->itemtype !== 'mod') {
            return;
        }

        $grade = isset($event->other['finalgrade']) ? (float)$event->other['finalgrade'] : null;

        if ($grade === null || $grade < 0) {
            return;
        }

        $cmid         = null;
        $modtype      = null;
        $activityname = $gradeitem->itemname ?? '';

        // Resolver cmid y mod_type para actividades reales.
        if (!empty($gradeitem->itemmodule) && !empty($gradeitem->iteminstance)) {
            $modtype  = $gradeitem->itemmodule; // e.g. 'assign', 'forum', 'quiz'
            $moduleid = $DB->get_field('modules', 'id', ['name' => $gradeitem->itemmodule]);

            if ($moduleid) {
                $cm = $DB->get_record('course_modules', [
                    'course'   => $event->courseid,
                    'module'   => $moduleid,
                    'instance' => $gradeitem->iteminstance,
                ], 'id');

                if ($cm) {
                    $cmid = (int)$cm->id;
                }
            }
        }

        self::queue_event(
            $event->relateduserid,
            $event->courseid,
            'grade',
            $grade,
            $cmid,
            $modtype,
            $activityname
        );
    }

    /**
     * Encola un evento académico para envío posterior al backend.
     *
     * @param int         $userid       ID del usuario en Moodle.
     * @param int         $courseid     ID del curso en Moodle.
     * @param string      $type         Tipo de evento: 'grade'.
     * @param float|null  $grade        Calificación.
     * @param int|null    $cmid         ID del course module.
     * @param string|null $modtype      Tipo de módulo (assign, forum, quiz...).
     * @param string      $activityname Nombre de la actividad.
     */
    private static function queue_event(
        int $userid,
        int $courseid,
        string $type,
        ?float $grade,
        ?int $cmid,
        ?string $modtype,
        string $activityname
    ) {
        global $DB;

        // ── 1. Plugin habilitado ─────────────────────────────────────────────
        if (!get_config('local_meritcoin', 'enabled')) {
            return;
        }

        // ── 2. Wallet del estudiante ─────────────────────────────────────────
        $walletfield = get_config('local_meritcoin', 'wallet_field') ?: 'wallet';

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $walletfield]);
        if (!$fieldid) {
            debugging("MeritCoin: Profile field '{$walletfield}' not found.", DEBUG_DEVELOPER);
            return;
        }

        $wallet = $DB->get_field('user_info_data', 'data', [
            'userid'  => $userid,
            'fieldid' => $fieldid,
        ]);

        $status = 'pending';

        if (empty($wallet)) {
            $wallet = null;
            $status = 'pending_wallet';
        } else if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $wallet)) {
            debugging("MeritCoin: Invalid wallet format for user {$userid}: {$wallet}", DEBUG_DEVELOPER);
            $wallet = null;
            $status = 'pending_wallet';
        }

        // ── 3. Calcular monedas según reglas (con prioridad y min_grade) ─────
        $coins = rules_service::get_coins_for_event($courseid, $cmid, $type, $modtype, $grade);

        if ($coins <= 0) {
            debugging(
                "MeritCoin: No coins for course {$courseid}, cmid " . ($cmid ?? 'null') .
                ", modtype " . ($modtype ?? 'null') . ".",
                DEBUG_DEVELOPER
            );
            return;
        }

        // ── 4. Verificar límite total de MRT por estudiante por curso ────────
        $limit = (int)(get_config('local_meritcoin', 'student_course_limit') ?: 0);
        if ($limit > 0) {
            $sql = "SELECT COALESCE(SUM(coins_amount), 0)
                      FROM {local_meritcoin_queue}
                     WHERE userid = :uid AND courseid = :cid AND event_type = 'grade'";
            $already = (float)$DB->get_field_sql($sql, ['uid' => $userid, 'cid' => $courseid]);
            if ($already + $coins > $limit) {
                debugging("MeritCoin: Student {$userid} reached MRT limit ({$already}/{$limit}) in course {$courseid}.", DEBUG_DEVELOPER);
                return;
            }
        }

        // ── 5. Configuración de moneda del curso ─────────────────────────────
        $coinsymbol = rules_service::get_coin_symbol_for_course($courseid);
        $coinname   = rules_service::get_coin_name_for_course($courseid);

        // ── 6. Nombre de actividad y curso ───────────────────────────────────
        $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]) ?? "Course #{$courseid}";
        if (empty($activityname)) {
            $activityname = $coursename;
        }

        // ── 7. Generar event_id único (idempotencia estricta por actividad) ───
        // Un mismo userid+courseid+cmid+type siempre produce el mismo event_id.
        // Si la nota cambia pero ya se ganó MRT antes, no se genera otro evento.
        $now    = time();
        $cmpart = $cmid ?? 'course';
        $eventid = 'evt-' . md5("moodle-{$userid}-{$courseid}-{$cmpart}-{$type}");

        if ($DB->record_exists('local_meritcoin_queue', ['event_id' => $eventid])) {
            return;
        }

        // ── 8. Construir payload JSON ────────────────────────────────────────
        $payload = [
            'event_id'       => $eventid,
            'student_wallet' => $wallet,
            'student_id'     => "STU-{$userid}",
            'course_id'      => "COURSE-{$courseid}",
            'course_name'    => $coursename,
            'activity_id'    => $cmid ? "CM-{$cmid}" : null,
            'activity_name'  => $activityname,
            'event_type'     => $type,
            'grade'          => $grade,
            'coins_amount'   => $coins,
            'coin_symbol'    => $coinsymbol,
            'coin_name'      => $coinname,
            'timestamp'      => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];

        // ── 9. Insertar en la cola ───────────────────────────────────────────
        $record                = new \stdClass();
        $record->event_id      = $eventid;
        $record->userid        = $userid;
        $record->courseid      = $courseid;
        $record->cmid          = $cmid;
        $record->activity_name = $activityname;
        $record->event_type    = $type;
        $record->grade         = $grade;
        $record->coins_amount  = $coins;
        $record->student_wallet = $wallet;
        $record->payload       = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $record->status        = $status;
        $record->attempts      = 0;
        $record->last_error    = null;
        $record->timecreated   = $now;
        $record->timemodified  = $now;

        $DB->insert_record('local_meritcoin_queue', $record);
    }
}