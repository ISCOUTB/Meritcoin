<?php
// This file is part of Moodle - http://moodle.org/
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
 * CÓMO FUNCIONA (v0.2.0):
 * ─────────────────────────────────────────────────────────────────────────────
 * Escucha dos eventos de Moodle:
 *
 *   1. course_completed → emite monedas por completar el curso completo.
 *   2. user_graded      → emite monedas por cada ACTIVIDAD INDIVIDUAL calificada
 *                         (tareas, quizzes, etc.) Y por la calificación final.
 *
 * Para calcular cuántas monedas dar, consulta la tabla local_meritcoin_rules:
 *   - Si hay regla para ese curso+actividad → usa esa regla.
 *   - Si hay regla para ese curso (sin actividad) → usa la regla del curso.
 *   - Si no hay ninguna regla → fórmula por defecto (nota / 10).
 *
 * El símbolo de la moneda se lee de local_meritcoin_course_config.
 * Si el curso no tiene configuración propia → usa 'MRT'.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Maneja el evento de curso completado.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event) {
        self::queue_event(
            $event->relateduserid,
            $event->courseid,
            'completion',
            null,
            null,   // cmid: null porque es el curso completo
            ''      // activity_name: vacío, se usará el nombre del curso
        );
    }

    /**
     * Maneja el evento de calificación registrada.
     *
     * Procesa TANTO actividades individuales (itemtype='mod') COMO la
     * calificación final del curso (itemtype='course').
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event) {
        global $DB;

        $gradeitem = $DB->get_record('grade_items', ['id' => $event->other['itemid']]);
        if (!$gradeitem) {
            return;
        }

        // Procesar 'mod' (actividades) y 'course' (calificación final).
        // Ignorar 'category', 'manual', etc.
        if (!in_array($gradeitem->itemtype, ['mod', 'course'])) {
            return;
        }

        $grade = isset($event->other['finalgrade']) ? (float)$event->other['finalgrade'] : null;

        // No encolar si no tiene calificación aún
        if ($grade === null || $grade < 0) {
            return;
        }

        // Para actividades individuales, usamos iteminstance como cmid
        $cmid          = ($gradeitem->itemtype === 'mod') ? (int)$gradeitem->iteminstance : null;
        $activity_name = $gradeitem->itemname ?? '';

        self::queue_event(
            $event->relateduserid,
            $event->courseid,
            'grade',
            $grade,
            $cmid,
            $activity_name
        );
    }

    /**
     * Encola un evento académico para envío posterior al backend.
     *
     * Pasos:
     *   1. Verificar plugin habilitado
     *   2. Obtener wallet del estudiante
     *   3. Calcular monedas según reglas
     *   4. Obtener configuración de moneda del curso
     *   5. Generar event_id único
     *   6. Insertar en la cola
     *
     * @param int        $userid        ID del usuario en Moodle.
     * @param int        $courseid      ID del curso en Moodle.
     * @param string     $type          Tipo de evento: 'completion' o 'grade'.
     * @param float|null $grade         Calificación (solo para type=grade).
     * @param int|null   $cmid          ID del course module (null = curso completo).
     * @param string     $activity_name Nombre de la actividad.
     */
    private static function queue_event(
        int $userid,
        int $courseid,
        string $type,
        ?float $grade,
        ?int $cmid,
        string $activity_name
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

        if (empty($wallet)) {
            debugging("MeritCoin: No wallet for user {$userid}.", DEBUG_DEVELOPER);
            return;
        }

        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $wallet)) {
            debugging("MeritCoin: Invalid wallet format for user {$userid}: {$wallet}", DEBUG_DEVELOPER);
            return;
        }

        // ── 3. Calcular monedas según reglas ─────────────────────────────────
        $coins = self::calculate_coins($courseid, $cmid, $type, $grade);

        // No encolar si la nota no alcanzó el mínimo (coins = 0 y hay nota)
        if ($type === 'grade' && $coins <= 0) {
            debugging("MeritCoin: Grade {$grade} did not meet minimum for coins.", DEBUG_DEVELOPER);
            return;
        }

        // ── 4. Configuración de moneda del curso ─────────────────────────────
        $course_config = $DB->get_record('local_meritcoin_course_config', ['courseid' => $courseid]);
        $coin_symbol   = $course_config ? $course_config->coin_symbol : 'MRT';
        $coin_name     = $course_config ? $course_config->coin_name   : 'MeritCoin';

        // ── 5. Datos del curso y nombre de actividad ─────────────────────────
        $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]) ?? "Course #{$courseid}";
        if (empty($activity_name)) {
            $activity_name = $coursename;
        }

        // ── 6. Generar event_id único ────────────────────────────────────────
        $now     = time();
        $cm_part = $cmid ?? 'course';
        $eventid = "evt-moodle-{$userid}-{$courseid}-{$cm_part}-{$type}-{$now}";

        if ($DB->record_exists('local_meritcoin_queue', ['event_id' => $eventid])) {
            return;
        }

        // ── 7. Construir payload JSON ────────────────────────────────────────
        $payload = [
            'event_id'       => $eventid,
            'student_wallet' => $wallet,
            'student_id'     => "STU-{$userid}",
            'course_id'      => "COURSE-{$courseid}",
            'course_name'    => $coursename,
            'activity_id'    => $cmid ? "CM-{$cmid}" : null,
            'activity_name'  => $activity_name,
            'event_type'     => $type,
            'grade'          => $grade,
            'coins_amount'   => $coins,
            'coin_symbol'    => $coin_symbol,
            'coin_name'      => $coin_name,
            'timestamp'      => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];

        // ── 8. Insertar en la cola ───────────────────────────────────────────
        $record                 = new \stdClass();
        $record->event_id       = $eventid;
        $record->userid         = $userid;
        $record->courseid       = $courseid;
        $record->cmid           = $cmid;
        $record->activity_name  = $activity_name;
        $record->event_type     = $type;
        $record->grade          = $grade;
        $record->coins_amount   = $coins;
        $record->student_wallet = $wallet;
        $record->payload        = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $record->status         = 'pending';
        $record->attempts       = 0;
        $record->last_error     = null;
        $record->timecreated    = $now;
        $record->timemodified   = $now;

        $DB->insert_record('local_meritcoin_queue', $record);
    }

    /**
     * Calcula cuántas monedas otorgar según las reglas configuradas.
     *
     * Prioridad de búsqueda:
     *   1. Regla específica para este curso + actividad (cmid)
     *   2. Regla general del curso (cmid = NULL)
     *   3. Fórmula por defecto
     *
     * Fórmulas:
     *   - coins_fixed → valor fijo independiente de la nota
     *   - coins_pct   → grade * coins_pct (ej: 85 * 0.5 = 42.5 monedas)
     *   - defecto grade → grade / 10
     *   - defecto completion → 50 monedas
     *
     * @param int        $courseid ID del curso.
     * @param int|null   $cmid     ID del course module (null = curso completo).
     * @param string     $type     'completion' o 'grade'.
     * @param float|null $grade    Calificación.
     * @return float Monedas a otorgar (0 si no cumple mínimo).
     */
    private static function calculate_coins(int $courseid, ?int $cmid, string $type, ?float $grade): float {
        global $DB;

        $rule = null;

        // Buscar regla específica para esta actividad
        if ($cmid !== null) {
            $rule = $DB->get_record('local_meritcoin_rules', [
                'courseid' => $courseid,
                'cmid'     => $cmid,
            ]);
        }

        // Si no hay regla de actividad, buscar regla de curso
        if (!$rule) {
            $rule = $DB->get_record_sql(
                "SELECT * FROM {local_meritcoin_rules}
                  WHERE courseid = :courseid AND cmid IS NULL",
                ['courseid' => $courseid]
            );
        }

        // Si hay regla: aplicarla
        if ($rule) {
            // Verificar nota mínima
            if ($type === 'grade' && $grade !== null && $grade < (float)$rule->min_grade) {
                return 0.0;
            }

            if (!empty($rule->coins_fixed)) {
                return (float)$rule->coins_fixed;
            }

            if (!empty($rule->coins_pct) && $grade !== null) {
                return round($grade * (float)$rule->coins_pct, 2);
            }
        }

        // Sin regla: fórmula por defecto
        if ($type === 'completion') {
            return 50.0;  // 50 monedas por completar el curso
        }

        // Para grade: 1 moneda cada 10 puntos (mínimo 0)
        return $grade !== null ? round($grade / 10, 2) : 0.0;
    }
}
