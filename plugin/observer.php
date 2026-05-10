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
        $event_id = 'evt-completion-' . $userid . '-' . $courseid;

        // Verificar que no existe ya en la cola
        if ($DB->record_exists('local_meritcoin_queue', ['event_id' => $event_id])) {
            debugging('[MeritCoin] Evento duplicado ignorado: ' . $event_id, DEBUG_DEVELOPER);
            return;
        }

        // Construir payload JSON para el backend
        $payload = json_encode([
            'event_id'   => $event_id,
            'event_type' => 'completion',
            'student_id' => (string)$userid,
            'course_id' => (string)$courseid,
            'student_wallet' => $wallet ?? '',
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

    /**
     * Verificar si el profesor ha excedido su límite semanal
     */
    private static function check_teacher_weekly_limit($teacherid, $courseid, $amount) {
        global $DB;

        $limit = get_config('local_meritcoin', 'teacher_weekly_limit') ?: 16;

        // Calcular inicio de semana (lunes 00:00)
        $now = time();
        $week_start = strtotime('monday this week', $now);
        if ($week_start > $now) {
            $week_start = strtotime('monday last week', $now);
        }

        // Sumar MRT otorgados esta semana por este profesor EN ESTE CURSO
        $sql = "SELECT COALESCE(SUM(grade), 0) as total
                FROM {local_meritcoin_queue}
                WHERE courseid = :courseid
                AND timecreated >= :week_start
                AND event_type = 'grade'";

        $params = [
            'courseid' => $courseid,
            'week_start' => $week_start
        ];

        $used = $DB->get_field_sql($sql, $params);

        if ($used + $amount > $limit) {
            throw new \moodle_exception('student_limit_exceeded', 'local_meritcoin', '', 
                (object)['used' => $used, 'limit' => $limit]);
        }
    }

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

        // Calcular amount (MRT a otorgar) basado en la calificación
        $amount = self::calculate_mrt_amount($finalgrade, $grade_item->grademax);

        // Verificar límite semanal del profesor (grader)
        $graderid = $grade_obj->usermodified ?? $event->userid;
        try {
            self::check_teacher_weekly_limit($graderid, $courseid, $amount);
        } catch (\moodle_exception $e) {
            // Registrar el error pero no encolar
            debugging('[MeritCoin] Límite semanal excedido para profesor ID=' . $graderid, DEBUG_DEVELOPER);
            return;
        }

        $wallet   = local_meritcoin_get_user_wallet($userid);
        
        // event_id SIN timecreated para evitar duplicados
        $event_id = 'evt-grade-' . $userid . '-' . $courseid . '-' . $event->other['itemid'];

        if ($DB->record_exists('local_meritcoin_queue', ['event_id' => $event_id])) {
            debugging('[MeritCoin] Evento duplicado ignorado: ' . $event_id, DEBUG_DEVELOPER);
            return;
        }

        $payload = json_encode([
            'event_id'   => $event_id,
            'event_type' => 'grade',
            'student_id' => (string)$userid,
            'course_id' => (string)$courseid,
            'student_wallet' => $wallet ?? '',
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

    /**
     * Calcular cantidad de MRT a otorgar basado en la calificación
     */
    private static function calculate_mrt_amount($grade, $maxgrade): int {
        // Por ahora: 1 MRT por cada punto de calificación
        // Puedes ajustar la fórmula según necesites
        return (int)ceil($grade / $maxgrade);
    }
}
