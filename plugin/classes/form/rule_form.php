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

namespace local_meritcoin\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Formulario para crear/editar reglas de emisión de monedas por curso o actividad.
 *
 * USO ESPERADO:
 * ─────────────────────────────────────────────────────────────────────────────
 * Este formulario será utilizado desde editrule.php.
 *
 * customdata esperada:
 *   - courseid (int)                Obligatorio.
 *   - rule (\stdClass|null)         Opcional, para edición.
 *   - defaultcoinsymbol (string)    Opcional, por defecto 'MRT'.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    [http://www.gnu.org/copyleft/gpl.html](http://www.gnu.org/copyleft/gpl.html) GNU GPL v3 or later
 */
class rule_form extends \moodleform {

    /**
     * Define la estructura del formulario.
     */
    public function definition() {
        $mform = $this->_form;

        $courseid = (int)($this->_customdata['courseid'] ?? 0);
        $rule = $this->_customdata['rule'] ?? null;
        $defaultcoinsymbol = $this->_customdata['defaultcoinsymbol'] ?? 'MRT';

        // ── Datos internos ───────────────────────────────────────────────
        $mform->addElement('hidden', 'id', $rule->id ?? 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        // ── Encabezado ──────────────────────────────────────────────────
        $mform->addElement('header', 'rulehdr', get_string('pluginname', 'local_meritcoin'));

        // ── Tipo de regla ───────────────────────────────────────────────
        $scopeoptions = [
            'course' => get_string('rule_scope_course', 'local_meritcoin'),
            'activity' => get_string('rule_scope_activity', 'local_meritcoin'),
        ];
        $mform->addElement('select', 'rule_scope', get_string('rule_scope', 'local_meritcoin'), $scopeoptions);
        $mform->setType('rule_scope', PARAM_ALPHA);

        // ── Actividad del curso ─────────────────────────────────────────
        $activityoptions = $this->get_course_module_options($courseid);
        $mform->addElement('select', 'cmid', get_string('activity'), $activityoptions);
        $mform->setType('cmid', PARAM_INT);
        $mform->addHelpButton('cmid', 'activity', 'local_meritcoin');
        $mform->hideIf('cmid', 'rule_scope', 'eq', 'course');

        // ── Nombre visible de la actividad ──────────────────────────────
        // Se deja editable como respaldo simple y para permitir ajustes de UI.
        // En editrule.php también puedes recalcularlo automáticamente desde cmid.
        $mform->addElement('text', 'activityname', get_string('activity_name', 'local_meritcoin'), ['size' => 48]);
        $mform->setType('activityname', PARAM_TEXT);
        $mform->hideIf('activityname', 'rule_scope', 'eq', 'course');

        // ── Monedas a otorgar ────────────────────────────────────────────
        $mform->addElement('text', 'coins_amount', get_string('coins_amount', 'local_meritcoin'), ['size' => 10]);
        $mform->setType('coins_amount', PARAM_FLOAT);
        $mform->addRule('coins_amount', null, 'required', null, 'client');

        // ── Símbolo de la moneda ────────────────────────────────────────
        $mform->addElement('text', 'coin_symbol', get_string('coin_symbol', 'local_meritcoin'), ['size' => 12]);
        $mform->setType('coin_symbol', PARAM_TEXT);
        $mform->addRule('coin_symbol', null, 'required', null, 'client');

        // ── Estado de la regla ──────────────────────────────────────────
        $mform->addElement(
            'advcheckbox',
            'enabled',
            get_string('enabled', 'local_meritcoin'),
            get_string('rule_enabled_desc', 'local_meritcoin')
        );
        $mform->setDefault('enabled', 1);

        // ── Valores por defecto ─────────────────────────────────────────
        $defaults = [
            'id' => $rule->id ?? 0,
            'courseid' => $courseid,
            'rule_scope' => $rule->rule_scope ?? 'activity',
            'cmid' => $rule->cmid ?? 0,
            'activityname' => $rule->activityname ?? '',
            'coins_amount' => isset($rule->coins_amount) ? format_float((float)$rule->coins_amount, 2, false) : '1.00',
            'coin_symbol' => $rule->coin_symbol ?? $defaultcoinsymbol,
            'enabled' => isset($rule->enabled) ? (int)$rule->enabled : 1,
        ];
        $this->set_data($defaults);

        // ── Botones ─────────────────────────────────────────────────────
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Valida los datos del formulario.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $scope = $data['rule_scope'] ?? '';
        $cmid = isset($data['cmid']) ? (int)$data['cmid'] : 0;
        $coinsamount = isset($data['coins_amount']) ? (float)$data['coins_amount'] : 0;
        $coinsymbol = trim($data['coin_symbol'] ?? '');
        $activityname = trim($data['activityname'] ?? '');

        if (!in_array($scope, ['course', 'activity'])) {
            $errors['rule_scope'] = get_string('invaliddata', 'error');
        }

        if ($scope === 'activity' && $cmid <= 0) {
            $errors['cmid'] = get_string('required');
        }

        if ($scope === 'activity' && $activityname === '') {
            $errors['activityname'] = get_string('required');
        }

        if ($coinsamount <= 0) {
            $errors['coins_amount'] = get_string('error_positive_coins', 'local_meritcoin');
        }

        if ($coinsymbol === '') {
            $errors['coin_symbol'] = get_string('required');
        } else if (core_text::strlen($coinsymbol) > 20) {
            $errors['coin_symbol'] = get_string('maxlengthwarning', '', 20);
        }

        return $errors;
    }

    /**
     * Construye las opciones del selector de actividades del curso.
     *
     * @param int $courseid
     * @return array
     */
    private function get_course_module_options(int $courseid): array {
        $options = [
            0 => get_string('selectactivity', 'local_meritcoin'),
        ];

        if ($courseid <= 0) {
            return $options;
        }

        $modinfo = get_fast_modinfo($courseid);

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }

            if ($cm->deletioninprogress) {
                continue;
            }

            $label = '[' . $cm->modname . '] ' . $cm->name;
            $options[(int)$cm->id] = $label;
        }

        asort($options);

        // Mantener la primera opción al inicio.
        $first = [0 => get_string('selectactivity', 'local_meritcoin')];
        unset($options[0]);

        return $first + $options;
    }
}