<?php
// classes/observer.php - Manejador de eventos Moodle para MeritCoin
namespace local_meritcoin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/meritcoin/lib.php');

class observer {

    // ─────────────────────────────────────────────────────────────────────────
    // CURSO COMPLETADO
    // ─────────────────────────────────────────────────────────────────────────

    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;

        // Solo procesar si el plugin está habilitado
        if (!get_config('local_meritcoin', 'enabled')) {
            return;
        }

        $userid   = $event->relateduserid ?: $event->userid;
        $courseid = $event->courseid;

        // Ignorar usuario invitado
        if (isguestuser($userid)) {
            return;
        }

        // Obtener wallet del estudiante
        $wallet = local_meritcoin_get_user_wallet($userid);

        // ID único idempotente para evitar duplicados
        $event_id = 'evt-completion-' . $userid . '-' . $courseid . '-' . $event->timecreated;

        // Verificar que no existe ya en la cola
        if ($DB->record_exists('local_meritcoin_queue', ['event_id' => $event_id])) {
            debugging('[MeritCoin] Evento duplicado ignorado: ' . $event_id, DEBUG_DEVELOPER);
            return;
        }

        // Construir payload JSON para el backend
        $payload = json_encode([
            'event_id'   => $event_id,
            'event_type' => 'completion',
            'student_id' => $userid,
            'course_id'  => $courseid,
            'wallet'     => $wallet ?? '',
            'timestamp'  => $event->timecreated,
            'metadata'   => [
                'course_shortname' => self::get_course_shortname($courseid),
            ],
        ]);

        $now = time();

        $record = (object)[
            'event_id'       => $event_id,
            'userid'         => $userid,
            'courseid'       => $courseid,
            'event_type'     => 'completion',
            'grade'          => null,
            'student_wallet' => $wallet ?? '',
            'payload'        => $payload,
            'status'         => 'pending',
            'attempts'       => 0,
            'last_error'     => null,
            'timecreated'    => $now,
            'timemodified'   => $now,
        ];

        try {
            $DB->insert_record('local_meritcoin_queue', $record);
            debugging('[MeritCoin] Completación encolada para userid=' . $userid . ' courseid=' . $courseid, DEBUG_DEVELOPER);
        } catch (\dml_exception $e) {
            debugging('[MeritCoin] Error al encolar completación: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CALIFICACIÓN REGISTRADA
    // ─────────────────────────────────────────────────────────────────────────

    public static function user_graded(\core\event\user_graded $event): void {
        global $DB, $CFG;

        if (!get_config('local_meritcoin', 'enabled')) {
            return;
        }

        $userid   = $event->relateduserid ?: $event->userid;
        $courseid = $event->courseid;

        if (isguestuser($userid)) {
            return;
        }

        // Obtener el objeto grade para leer el valor real
        require_once($CFG->libdir . '/gradelib.php');
        $grade_item = \grade_item::fetch(['id' => $event->other['itemid']]);

        if (!$grade_item) {
            return;
        }

        // Solo procesar calificaciones de curso (itemtype = 'course')
        // o de actividades específicas si se configura
        $min_grade = (float)(get_config('local_meritcoin', 'mingrade') ?: 0);

        $grade_obj = \grade_grade::fetch([
            'itemid' => $event->other['itemid'],
            'userid' => $userid,
        ]);

        if (!$grade_obj || $grade_obj->finalgrade === null) {
            return;
        }

        $finalgrade = (float)$grade_obj->finalgrade;

        // Solo encolar si supera la nota mínima configurada
        if ($finalgrade < $min_grade) {
            return;
        }

        $wallet   = local_meritcoin_get_user_wallet($userid);
        $event_id = 'evt-grade-' . $userid . '-' . $courseid . '-' . $event->other['itemid'] . '-' . $event->timecreated;

        if ($DB->record_exists('local_meritcoin_queue', ['event_id' => $event_id])) {
            return;
        }

        $payload = json_encode([
            'event_id'   => $event_id,
            'event_type' => 'grade',
            'student_id' => $userid,
            'course_id'  => $courseid,
            'wallet'     => $wallet ?? '',
            'grade'      => $finalgrade,
            'timestamp'  => $event->timecreated,
            'metadata'   => [
                'item_name'        => $grade_item->itemname ?? 'Course grade',
                'item_type'        => $grade_item->itemtype,
                'grademax'         => (float)$grade_item->grademax,
                'course_shortname' => self::get_course_shortname($courseid),
            ],
        ]);

        $now = time();

        $record = (object)[
            'event_id'       => $event_id,
            'userid'         => $userid,
            'courseid'       => $courseid,
            'event_type'     => 'grade',
            'grade'          => $finalgrade,
            'student_wallet' => $wallet ?? '',
            'payload'        => $payload,
            'status'         => 'pending',
            'attempts'       => 0,
            'last_error'     => null,
            'timecreated'    => $now,
            'timemodified'   => $now,
        ];

        try {
            $DB->insert_record('local_meritcoin_queue', $record);
            debugging('[MeritCoin] Calificación encolada: userid=' . $userid . ' grade=' . $finalgrade, DEBUG_DEVELOPER);
        } catch (\dml_exception $e) {
            debugging('[MeritCoin] Error al encolar calificación: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private static function get_course_shortname(int $courseid): string {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], 'shortname', IGNORE_MISSING);
        return $course ? $course->shortname : 'course_' . $courseid;
    }
}
