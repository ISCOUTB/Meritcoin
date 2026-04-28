<?php
// This file is part of Moodle - [http://moodle.org/](http://moodle.org/)
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_meritcoin;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for local_meritcoin.
 *
 * CÓMO FUNCIONA (v0.3.0):
 * ─────────────────────────────────────────────────────────────────────────────
 * Escucha dos eventos de Moodle:
 *
 *   1. course_completed → registra el logro del curso completo.
 *      Por defecto NO suma monedas gastables para el mercado del curso.
 *
 *   2. user_graded      → registra monedas por cada ACTIVIDAD INDIVIDUAL
 *                         calificada (tareas, quizzes, etc.) y también puede
 *                         procesar la calificación final del curso.
 *
 * Para calcular cuántas monedas dar, consulta la tabla local_meritcoin_rules:
 *   - Si hay regla para ese curso+actividad → usa esa regla.
 *   - Si hay regla para ese curso (sin actividad) → usa la regla del curso.
 *   - Si no hay ninguna regla → devuelve 0 para no emitir monedas “por defecto”.
 *
 * El símbolo y nombre de la moneda se leen de local_meritcoin_course_config.
 * Si el curso no tiene configuración propia → usa 'MRT' / 'MeritCoin'.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    [http://www.gnu.org/copyleft/gpl.html](http://www.gnu.org/copyleft/gpl.html) GNU GPL v3 or later
 */
class observer {

    /**
     * Maneja el evento de curso completado.
     *
     * Se sigue encolando porque puede ser útil para auditoría, badges o backend,
     * pero por defecto no genera saldo gastable del mercado.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event) {
        self::queue_event(
            $event->relateduserid,
            $event->courseid,
            'completion',
            null,
            null,   // cmid: null porque es el curso completo.
            ''      // activity_name: vacío, se usará el nombre del curso.
        );
    }

    /**
     * Maneja el evento de calificación registrada.
     *
     * Procesa tanto actividades individuales (itemtype='mod') como la
     * calificación final del curso (itemtype='course').
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event) {
        global $DB;

        $gradeitem = $DB->get_record('grade_items', ['id' => $event->other['itemid'] ?? 0]);
        if (!$gradeitem) {
            return;
        }

        // Procesar 'mod' (actividades) y 'course' (calificación final).
        // Ignorar 'category', 'manual', etc.
        if (!in_array($gradeitem->itemtype, ['mod', 'course'])) {
            return;
        }

        $grade = isset($event->other['finalgrade']) ? (float)$event->other['finalgrade'] : null;

        // No encolar si todavía no existe una calificación válida.
        if ($grade === null || $grade < 0) {
            return;
        }

        $cmid = null;
        $activityname = $gradeitem->itemname ?? '';

        // Para actividades reales, resolver el course module para guardar cmid.
        if ($gradeitem->itemtype === 'mod' && !empty($gradeitem->itemmodule) && !empty($gradeitem->iteminstance)) {
            $moduleid = $DB->get_field('modules', 'id', ['name' => $gradeitem->itemmodule]);

            if ($moduleid) {
                $cm = $DB->get_record('course_modules', [
                    'course' => $event->courseid,
                    'module' => $moduleid,
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
            $activityname
        );
    }

    /**
     * Encola un evento académico para envío posterior al backend.
     *
     * Pasos:
     *   1. Verificar plugin habilitado.
     *   2. Obtener wallet del estudiante.
     *   3. Calcular monedas según reglas simples por curso/actividad.
     *   4. Obtener configuración de moneda del curso.
     *   5. Generar event_id único.
     *   6. Insertar en la cola.
     *
     * @param int        $userid       ID del usuario en Moodle.
     * @param int        $courseid     ID del curso en Moodle.
     * @param string     $type         Tipo de evento: 'completion' o 'grade'.
     * @param float|null $grade        Calificación (solo para type=grade).
     * @param int|null   $cmid         ID del course module (null = curso completo).
     * @param string     $activityname Nombre de la actividad.
     */
    private static function queue_event(
        int $userid,
        int $courseid,
        string $type,
        ?float $grade,
        ?int $cmid,
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
            'userid' => $userid,
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

        // ── 3. Calcular monedas según reglas ─────────────────────────────────
        // En v0.3.0 la fuente principal es la regla simple definida por el profesor.
        // Completion se registra, pero por defecto no suma monedas gastables.
        $coins = rules_service::get_coins_for_event($courseid, $cmid, $type);

        // Para grades sin regla activa o con valor 0, no encolar monedas “vacías”.
        // Así evitamos crear eventos inútiles para el mercado del curso.
        if ($type === 'grade' && $coins <= 0) {
            debugging(
                "MeritCoin: No active coin rule for course {$courseid}, cmid " . ($cmid ?? 'null') . ".",
                DEBUG_DEVELOPER
            );
            return;
        }

        // ── 4. Configuración de moneda del curso ─────────────────────────────
        $coinsymbol = rules_service::get_coin_symbol_for_course($courseid);
        $coinname = rules_service::get_coin_name_for_course($courseid);

        // ── 5. Datos del curso y nombre de actividad ─────────────────────────
        $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]) ?? "Course #{$courseid}";
        if (empty($activityname)) {
            $activityname = $coursename;
        }

        // ── 6. Generar event_id único ────────────────────────────────────────
        $now = time();
        $cmpart = $cmid ?? 'course';
        $eventid = uniqid("evt-moodle-{$userid}-{$courseid}-{$cmpart}-{$type}-", true);

        if ($DB->record_exists('local_meritcoin_queue', ['event_id' => $eventid])) {
            return;
        }

        // ── 7. Construir payload JSON ────────────────────────────────────────
        $payload = [
            'event_id' => $eventid,
            'student_wallet' => $wallet,
            'student_id' => "STU-{$userid}",
            'course_id' => "COURSE-{$courseid}",
            'course_name' => $coursename,
            'activity_id' => $cmid ? "CM-{$cmid}" : null,
            'activity_name' => $activityname,
            'event_type' => $type,
            'grade' => $grade,
            'coins_amount' => $coins,
            'coin_symbol' => $coinsymbol,
            'coin_name' => $coinname,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];

        // ── 8. Insertar en la cola ───────────────────────────────────────────
        $record = new \stdClass();
        $record->event_id = $eventid;
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->cmid = $cmid;
        $record->activity_name = $activityname;
        $record->event_type = $type;
        $record->grade = $grade;
        $record->coins_amount = $coins;
        $record->student_wallet = $wallet;
        $record->payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $record->status = $status;
        $record->attempts = 0;
        $record->last_error = null;
        $record->timecreated = $now;
        $record->timemodified = $now;

        $DB->insert_record('local_meritcoin_queue', $record);
    }
}