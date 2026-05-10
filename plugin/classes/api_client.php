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
 * HTTP client for communicating with the MeritCoin FastAPI backend.
 *
 * CÓMO FUNCIONA (explicación para no-expertos en Moodle):
 * ─────────────────────────────────────────────────────────
 * Esta clase se encarga de enviar los eventos al backend FastAPI.
 * Cada petición lleva una firma HMAC-SHA256 en el header para que el
 * backend pueda verificar que realmente viene de Moodle (y no de un
 * atacante). El proceso es:
 *
 *   1. Tomar el payload JSON del evento
 *   2. Calcular HMAC-SHA256 usando el secreto compartido
 *   3. Enviar el JSON al endpoint POST /events/ingest con el header
 *      X-HMAC-Signature
 *   4. Verificar la respuesta del backend
 *
 * Usa la función curl de Moodle (no PHP curl directo) para respetar
 * la configuración de proxy del sitio.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {

    /** @var string URL base del backend. */
    private string $baseurl;

    /** @var string Secreto HMAC compartido. */
    private string $hmacsecret;

    /**
     * Constructor.
     *
     * Lee la configuración del plugin automáticamente.
     * No necesitas pasar parámetros manualmente.
     */
    public function __construct() {
        $this->baseurl    = rtrim((string)(get_config('local_meritcoin', 'api_url') ?: ''), '/');
        $this->hmacsecret = (string)(get_config('local_meritcoin', 'hmac_secret') ?: '');
    }

    /**
     * Envía un evento al backend FastAPI.
     *
     * @param string $jsonpayload El payload JSON del evento (ya serializado).
     * @return object Objeto con propiedades:
     *   - success (bool): true si el backend respondió correctamente (2xx o 409).
     *   - status_code (int): Código HTTP de respuesta.
     *   - body (string): Cuerpo de la respuesta.
     *   - error (string): Mensaje de error si hubo uno.
     */
    public function send_event(string $jsonpayload): object {
        $result              = new \stdClass();
        $result->success     = false;
        $result->status_code = 0;
        $result->body        = '';
        $result->error       = '';

        // Validar JSON.
        json_decode($jsonpayload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result->error = 'Invalid JSON payload: ' . json_last_error_msg();
            return $result;
        }

        if (empty($this->baseurl)) {
            $result->error = 'API URL is not configured in plugin settings.';
            return $result;
        }

        if (empty($this->hmacsecret)) {
            $result->error = 'HMAC secret is not configured in plugin settings.';
            return $result;
        }

        // ── Calcular firma HMAC-SHA256 ──────────────────────────────────
        $signature = hash_hmac('sha256', $jsonpayload, $this->hmacsecret);

        // ── Enviar con curl de Moodle ───────────────────────────────────
        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
            'X-HMAC-Signature: ' . $signature,
        ]);

        $response = $curl->post($this->baseurl . '/events/ingest', $jsonpayload, [
            'CURLOPT_TIMEOUT'        => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        // ── Procesar respuesta ──────────────────────────────────────────
        $info                = $curl->get_info();
        $result->status_code = (int)($info['http_code'] ?? 0);
        $result->body        = is_string($response) ? $response : '';

        $curlerror = $curl->get_errno();
        if ($curlerror) {
            $result->error = "cURL error {$curlerror}: " . $curl->error;
            return $result;
        }

        // 2xx = éxito, 409 = duplicado idempotente (también éxito)
        if (($result->status_code >= 200 && $result->status_code < 300)
            || $result->status_code === 409) {
            $result->success = true;
        } else {
            $decoded = json_decode($result->body, true);
            if (is_array($decoded)) {
                $detail        = $decoded['detail'] ?? $decoded['message'] ?? null;
                $result->error = $detail
                    ? "HTTP {$result->status_code}: {$detail}"
                    : "HTTP {$result->status_code}: {$result->body}";
            } else {
                $result->error = "HTTP {$result->status_code}: {$result->body}";
            }
        }

        return $result;
    }

    /**
     * Obtiene el resumen de un estudiante desde el backend.
     *
     * @param string $wallet Dirección Ethereum del estudiante (0x...).
     * @return array|null Array con mrt_balance y badges, o null si falla.
     */
    public function get_student_summary(string $wallet): ?array {
        if (empty($this->baseurl)) {
            return null;
        }

        $curl = new \curl();
        $curl->setHeader([
            'Accept: application/json',
            'X-HMAC-Signature: ' . hash_hmac('sha256', $wallet, $this->hmacsecret),
        ]);

        $url      = $this->baseurl . '/students/' . urlencode($wallet) . '/summary';
        $response = $curl->get($url, [], [
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        $info     = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);

        if ($curl->get_errno() || $httpcode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}