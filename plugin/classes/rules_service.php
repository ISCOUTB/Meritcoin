<?php
// This file is part of Moodle - [http://moodle.org/](http://moodle.org/)
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
 * Service class for MeritCoin course/activity rules and course balances.
 *
 * PROPÓSITO:
 * ─────────────────────────────────────────────────────────────────────────────
 * Este servicio centraliza la lógica de negocio que antes quedaba repartida
 * entre observer, tasks y futuras pantallas del mercado:
 *
 *   1. Buscar la regla aplicable a una actividad o a un curso.
 *   2. Resolver cuántas monedas otorga una actividad.
 *   3. Calcular cuánto ha ganado un estudiante en un curso.
 *   4. Calcular cuánto ha gastado en el mercado de ese curso.
 *   5. Obtener el saldo gastable real por curso.
 *
 * La idea es mantener una wallet global del estudiante, pero controlar el
 * saldo utilizable por curso con base de datos local:
 *
 *   saldo_curso = SUM(earnings del curso) - SUM(spend del curso)
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    [http://www.gnu.org/copyleft/gpl.html](http://www.gnu.org/copyleft/gpl.html) GNU GPL v3 or later
 */
class rules_service {

    /**
     * Busca la regla aplicable a una actividad concreta dentro de un curso.
     *
     * Prioridad:
     *   1. Regla específica courseid + cmid + scope=activity.
     *   2. Regla general del curso con cmid NULL y scope=course.
     *
     * @param int $courseid ID del curso.
     * @param int|null $cmid ID del course module; null si se busca regla general.
     * @return \stdClass|null
     */
    public static function get_activity_rule(int $courseid, ?int $cmid): ?\stdClass {
        global $DB;

        if (!empty($cmid)) {
            $rule = $DB->get_record('local_meritcoin_rules', [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'rule_scope' => 'activity',
                'enabled' => 1,
            ]);

            if ($rule) {
                return $rule;
            }
        }

        $sql = "SELECT *
                  FROM {local_meritcoin_rules}
                 WHERE courseid = :courseid
                   AND cmid IS NULL
                   AND rule_scope = :scope
                   AND enabled = 1";

        return $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'scope' => 'course',
        ]) ?: null;
    }

    /**
     * Resuelve cuántas monedas deben emitirse para un evento.
     *
     * Reglas actuales:
     *   - completion → 0 monedas por defecto.
     *   - grade → usa coins_amount de la regla aplicable.
     *   - sin regla activa → 0.
     *
     * @param int $courseid ID del curso.
     * @param int|null $cmid ID del course module.
     * @param string $eventtype completion o grade.
     * @return float
     */
    public static function get_coins_for_event(int $courseid, ?int $cmid, string $eventtype): float {
        if ($eventtype === 'completion') {
            return 0.0;
        }

        $rule = self::get_activity_rule($courseid, $cmid);
        if (!$rule) {
            return 0.0;
        }

        return round((float)$rule->coins_amount, 2);
    }

    /**
     * Obtiene el símbolo de la moneda configurado para un curso.
     *
     * Si el curso no tiene configuración propia, usa MRT.
     *
     * @param int $courseid
     * @return string
     */
    public static function get_coin_symbol_for_course(int $courseid): string {
        global $DB;

        $config = $DB->get_record('local_meritcoin_course_config', ['courseid' => $courseid], 'coin_symbol');
        if ($config && !empty($config->coin_symbol)) {
            return $config->coin_symbol;
        }

        return 'MRT';
    }

    /**
     * Obtiene el nombre de la moneda configurado para un curso.
     *
     * Si el curso no tiene configuración propia, usa MeritCoin.
     *
     * @param int $courseid
     * @return string
     */
    public static function get_coin_name_for_course(int $courseid): string {
        global $DB;

        $config = $DB->get_record('local_meritcoin_course_config', ['courseid' => $courseid], 'coin_name');
        if ($config && !empty($config->coin_name)) {
            return $config->coin_name;
        }

        return 'MeritCoin';
    }

    /**
     * Suma todas las monedas ganadas por un usuario en un curso.
     *
     * @param int $userid
     * @param int $courseid
     * @return float
     */
    public static function get_total_earned_by_course(int $userid, int $courseid): float {
        global $DB;

        $sql = "SELECT COALESCE(SUM(coins_earned), 0)
                  FROM {local_meritcoin_earnings}
                 WHERE userid = :userid
                   AND courseid = :courseid";

        return round((float)$DB->get_field_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
        ]), 2);
    }

    /**
     * Suma todas las monedas gastadas por un usuario en un curso.
     *
     * Solo cuenta gastos aprobados.
     *
     * @param int $userid
     * @param int $courseid
     * @return float
     */
    public static function get_total_spent_by_course(int $userid, int $courseid): float {
        global $DB;

        $sql = "SELECT COALESCE(SUM(coins_spent), 0)
                  FROM {local_meritcoin_spend}
                 WHERE userid = :userid
                   AND courseid = :courseid
                   AND status = :status";

        return round((float)$DB->get_field_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => 'approved',
        ]), 2);
    }

    /**
     * Calcula el saldo gastable real del usuario dentro de un curso.
     *
     * Fórmula:
     *   saldo = earned - spent
     *
     * Nunca devuelve negativo.
     *
     * @param int $userid
     * @param int $courseid
     * @return float
     */
    public static function get_available_balance_by_course(int $userid, int $courseid): float {
        $earned = self::get_total_earned_by_course($userid, $courseid);
        $spent = self::get_total_spent_by_course($userid, $courseid);

        return max(0.0, round($earned - $spent, 2));
    }

    /**
     * Valida si un usuario puede canjear una recompensa en un curso.
     *
     * @param int $userid
     * @param int $courseid
     * @param float $cost
     * @return bool
     */
    public static function can_redeem_in_course(int $userid, int $courseid, float $cost): bool {
        if ($cost <= 0) {
            return false;
        }

        $available = self::get_available_balance_by_course($userid, $courseid);
        return $available >= round($cost, 2);
    }

    /**
     * Registra un gasto aprobado en el mercado del curso.
     *
     * Este método NO ejecuta pagos on-chain; solo registra el descuento
     * local necesario para controlar el saldo gastable del curso.
     *
     * @param int $userid
     * @param string|null $studentwallet
     * @param int $courseid
     * @param string $rewardcode
     * @param float $coinsspent
     * @param string $status
     * @return int ID del registro insertado.
     */
    public static function record_spend(
        int $userid,
        ?string $studentwallet,
        int $courseid,
        string $rewardcode,
        float $coinsspent,
        string $status = 'approved'
    ): int {
        global $DB;

        $record = new \stdClass();
        $record->userid = $userid;
        $record->student_wallet = $studentwallet;
        $record->courseid = $courseid;
        $record->reward_code = $rewardcode;
        $record->coins_spent = round($coinsspent, 2);
        $record->status = $status;
        $record->timecreated = time();

        return $DB->insert_record('local_meritcoin_spend', $record);
    }

    /**
     * Retorna un resumen simple del saldo del usuario para un curso.
     *
     * Útil para dashboards, endpoints AJAX o validaciones previas al canje.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function get_course_balance_summary(int $userid, int $courseid): array {
        $earned = self::get_total_earned_by_course($userid, $courseid);
        $spent = self::get_total_spent_by_course($userid, $courseid);
        $available = max(0.0, round($earned - $spent, 2));

        return [
            'userid' => $userid,
            'courseid' => $courseid,
            'earned' => $earned,
            'spent' => $spent,
            'available' => $available,
            'coin_symbol' => self::get_coin_symbol_for_course($courseid),
            'coin_name' => self::get_coin_name_for_course($courseid),
        ];
    }

    /**
     * Devuelve todas las reglas activas de un curso ordenadas para UI.
     *
     * Primero actividades específicas y luego regla general del curso.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_rules_for_course(int $courseid): array {
        global $DB;

        $sql = "SELECT *
                  FROM {local_meritcoin_rules}
                 WHERE courseid = :courseid
                 ORDER BY
                       CASE WHEN cmid IS NULL THEN 1 ELSE 0 END,
                       activityname ASC,
                       id ASC";

        return $DB->get_records_sql($sql, ['courseid' => $courseid]);
    }

    /**
     * Guarda o actualiza una regla simple por actividad.
     *
     * Si ya existe una regla para courseid + cmid + scope=activity, la actualiza.
     * Si no existe, la crea.
     *
     * @param int $courseid
     * @param int $cmid
     * @param string $activityname
     * @param float $coinsamount
     * @param string $coinsymbol
     * @param int $enabled
     * @return int ID del registro afectado.
     */
    public static function upsert_activity_rule(
        int $courseid,
        int $cmid,
        string $activityname,
        float $coinsamount,
        string $coinsymbol = 'MRT',
        int $enabled = 1
    ): int {
        global $DB;

        $existing = $DB->get_record('local_meritcoin_rules', [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'rule_scope' => 'activity',
        ]);

        $now = time();

        if ($existing) {
            $existing->activityname = $activityname;
            $existing->coins_amount = round($coinsamount, 2);
            $existing->coin_symbol = $coinsymbol;
            $existing->enabled = $enabled;
            $existing->timemodified = $now;

            $DB->update_record('local_meritcoin_rules', $existing);
            return (int)$existing->id;
        }

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->cmid = $cmid;
        $record->rule_scope = 'activity';
        $record->activityname = $activityname;
        $record->coins_amount = round($coinsamount, 2);
        $record->coin_symbol = $coinsymbol;
        $record->enabled = $enabled;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $DB->insert_record('local_meritcoin_rules', $record);
    }

    /**
     * Guarda o actualiza la regla general del curso.
     *
     * @param int $courseid
     * @param float $coinsamount
     * @param string $coinsymbol
     * @param int $enabled
     * @return int ID del registro afectado.
     */
    public static function upsert_course_rule(
        int $courseid,
        float $coinsamount,
        string $coinsymbol = 'MRT',
        int $enabled = 1
    ): int {
        global $DB;

        $sql = "SELECT *
                  FROM {local_meritcoin_rules}
                 WHERE courseid = :courseid
                   AND cmid IS NULL
                   AND rule_scope = :scope";

        $existing = $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'scope' => 'course',
        ]);

        $now = time();

        if ($existing) {
            $existing->activityname = 'Regla general del curso';
            $existing->coins_amount = round($coinsamount, 2);
            $existing->coin_symbol = $coinsymbol;
            $existing->enabled = $enabled;
            $existing->timemodified = $now;

            $DB->update_record('local_meritcoin_rules', $existing);
            return (int)$existing->id;
        }

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->cmid = null;
        $record->rule_scope = 'course';
        $record->activityname = 'Regla general del curso';
        $record->coins_amount = round($coinsamount, 2);
        $record->coin_symbol = $coinsymbol;
        $record->enabled = $enabled;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $DB->insert_record('local_meritcoin_rules', $record);
    }
}