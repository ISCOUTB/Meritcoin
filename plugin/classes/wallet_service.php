<?php
// This file is part of Moodle - http://moodle.org/
// GNU GPL v3 or later - http://www.gnu.org/copyleft/gpl.html

namespace local_meritcoin;

defined('MOODLE_INTERNAL') || die();

/**
 * Servicio de wallets custodiales para local_meritcoin.
 *
 * Gestiona:
 *  - Provisionado automático de wallets via backend
 *  - Caché local en mdl_local_meritcoin_wallets
 *  - Verificación de si un curso es piloto y si el estudiante pertenece al grupo piloto
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wallet_service {

    /**
     * Obtiene o provisiona la wallet de un estudiante para un curso piloto.
     *
     * Flujo:
     *  1. Verifica que el curso sea piloto y el estudiante esté en el grupo (si aplica).
     *  2. Busca wallet en caché local (mdl_local_meritcoin_wallets).
     *  3. Si no existe, llama al backend para provisionarla y la guarda en caché.
     *
     * @param int $userid   ID del usuario Moodle.
     * @param int $courseid ID del curso Moodle.
     * @return string|null  Dirección de wallet o null si el curso no es piloto.
     */
    public static function get_or_provision(int $userid, int $courseid): ?string {
        global $DB;

        // ── 1. ¿Es curso piloto? ─────────────────────────────────────────────
        $pilot = $DB->get_record('local_meritcoin_pilot_courses', [
            'courseid'      => $courseid,
            'pilot_enabled' => 1,
        ]);
        if (!$pilot) {
            return null;
        }

        // ── 2. ¿Está en el grupo piloto? (si se configuró uno) ───────────────
        if (!empty($pilot->groupid)) {
            if (!groups_is_member($pilot->groupid, $userid)) {
                return null;
            }
        }

        // ── 3. Buscar en caché local ─────────────────────────────────────────
        $cached = $DB->get_record('local_meritcoin_wallets', ['userid' => $userid]);
        if ($cached && !empty($cached->wallet_address)) {
            return $cached->wallet_address;
        }

        // ── 4. Provisionar en el backend ─────────────────────────────────────
        $expires_at = self::resolve_expires_at($pilot, $courseid);
        $wallet     = self::call_provision($userid, $courseid, $expires_at);

        if (!$wallet) {
            debugging("MeritCoin: No se pudo provisionar wallet para user {$userid}", DEBUG_DEVELOPER);
            return null;
        }

        // ── 5. Guardar en caché local ────────────────────────────────────────
        $now = time();
        if ($cached) {
            $cached->wallet_address   = $wallet;
            $cached->status           = 'active';
            $cached->provisioned_at   = $now;
            $DB->update_record('local_meritcoin_wallets', $cached);
        } else {
            $record                   = new \stdClass();
            $record->userid           = $userid;
            $record->wallet_address   = $wallet;
            $record->status           = 'active';
            $record->provisioned_at   = $now;
            $DB->insert_record('local_meritcoin_wallets', $record);
        }

        return $wallet;
    }

    /**
     * Resuelve la fecha de expiración: usa el override del admin o mdl_course.enddate.
     *
     * @param \stdClass $pilot    Registro de mdl_local_meritcoin_pilot_courses.
     * @param int       $courseid ID del curso.
     * @return string   Fecha en formato ISO 8601 UTC.
     */
    private static function resolve_expires_at(\stdClass $pilot, int $courseid): string {
        global $DB;

        // Override manual del admin tiene prioridad.
        if (!empty($pilot->expires_at)) {
            return gmdate('Y-m-d\TH:i:s\Z', (int)$pilot->expires_at);
        }

        // Fallback a mdl_course.enddate.
        $enddate = $DB->get_field('course', 'enddate', ['id' => $courseid]);
        if ($enddate && $enddate > time()) {
            return gmdate('Y-m-d\TH:i:s\Z', (int)$enddate);
        }

        // Si el curso no tiene fecha de fin, usar 6 meses por defecto.
        return gmdate('Y-m-d\TH:i:s\Z', strtotime('+6 months'));
    }

    /**
     * Llama a POST /wallets/provision en el backend.
     *
     * @param int    $userid     ID del usuario.
     * @param int    $courseid   ID del curso.
     * @param string $expires_at ISO 8601.
     * @return string|null Dirección de wallet o null si falla.
     */
    private static function call_provision(int $userid, int $courseid, string $expires_at): ?string {
        $client = new api_client();
        $result = $client->post('/wallets/provision', [
            'student_id' => "STU-{$userid}",
            'course_id'  => "COURSE-{$courseid}",
            'expires_at' => $expires_at,
        ]);

        if (!$result || empty($result['wallet_address'])) {
            return null;
        }
        return $result['wallet_address'];
    }
}