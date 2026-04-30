<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_meritcoin\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Formulario para crear/editar reglas de emisión de monedas.
 *
 * Soporta tres scopes:
 *   - course        → aplica a todo el curso.
 *   - activity_type → aplica a todos los módulos de un tipo (assign, forum, quiz…).
 *   - activity      → aplica a una actividad específica (cmid).
 *
 * Todos los scopes admiten un min_grade opcional.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_form extends \moodleform {

    public function definition() {
        $mform    = $this->_form;
        $courseid = (int)($this->_customdata['courseid'] ?? 0);
        $rule     = $this->_customdata['rule'] ?? null;
        $defaultcoinsymbol = $this->_customdata['defaultcoinsymbol'] ?? 'MRT';

        // ── Campos ocultos ───────────────────────────────────────────────
        $mform->addElement('hidden', 'id', $rule->id ?? 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        // ── Encabezado ───────────────────────────────────────────────────
        $mform->addElement('header', 'rulehdr', get_string('pluginname', 'local_meritcoin'));

        // ── Tipo de regla ────────────────────────────────────────────────
        $scopeoptions = [
            'course'        => get_string('rule_scope_course', 'local_meritcoin'),
            'activity_type' => get_string('rule_scope_activity_type', 'local_meritcoin'),
            'activity'      => get_string('rule_scope_activity', 'local_meritcoin'),
        ];
        $mform->addElement('select', 'rule_scope', get_string('rule_scope', 'local_meritcoin'), $scopeoptions);
        $mform->setType('rule_scope', PARAM_ALPHANUMEXT);

        // ── Tipo de módulo (solo para activity_type) ─────────────────────
        $modtypeoptions = $this->get_mod_type_options($courseid);
        $mform->addElement('select', 'mod_type', get_string('rule_mod_type', 'local_meritcoin'), $modtypeoptions);
        $mform->setType('mod_type', PARAM_ALPHANUMEXT);
        $mform->hideIf('mod_type', 'rule_scope', 'neq', 'activity_type');

        // ── Actividad específica (solo para activity) ─────────────────────
        $activityoptions = $this->get_course_module_options($courseid);
        $mform->addElement('select', 'cmid', get_string('activity'), $activityoptions);
        $mform->setType('cmid', PARAM_INT);
        $mform->hideIf('cmid', 'rule_scope', 'neq', 'activity');

        // ── Nombre visible ────────────────────────────────────────────────
        $mform->addElement('text', 'activityname', get_string('activity_name', 'local_meritcoin'), ['size' => 48]);
        $mform->setType('activityname', PARAM_TEXT);
        $mform->hideIf('activityname', 'rule_scope', 'eq', 'course');
        $mform->hideIf('activityname', 'rule_scope', 'eq', 'activity_type');

        // ── Monedas a otorgar ─────────────────────────────────────────────
        $mform->addElement('text', 'coins_amount', get_string('coins_amount', 'local_meritcoin'), ['size' => 10]);
        $mform->setType('coins_amount', PARAM_FLOAT);
        $mform->addRule('coins_amount', null, 'required', null, 'client');

        // ── Símbolo de la moneda ──────────────────────────────────────────
        $mform->addElement('text', 'coin_symbol', get_string('coin_symbol', 'local_meritcoin'), ['size' => 12]);
        $mform->setType('coin_symbol', PARAM_TEXT);
        $mform->addRule('coin_symbol', null, 'required', null, 'client');

        // ── Nota mínima (opcional, todos los scopes) ──────────────────────
        $mform->addElement('text', 'min_grade', get_string('rule_min_grade', 'local_meritcoin'), ['size' => 8, 'placeholder' => get_string('rule_min_grade_placeholder', 'local_meritcoin')]);
        $mform->setType('min_grade', PARAM_RAW); // se valida manualmente para permitir vacío
        $mform->addHelpButton('min_grade', 'rule_min_grade', 'local_meritcoin');

        // ── Estado ────────────────────────────────────────────────────────
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_meritcoin'), get_string('rule_enabled_desc', 'local_meritcoin'));
        $mform->setDefault('enabled', 1);

        // ── Valores por defecto ───────────────────────────────────────────
        $mingradeval = '';
        if ($rule !== null && $rule->min_grade !== null && $rule->min_grade !== '') {
            $mingradeval = format_float((float)$rule->min_grade, 2, false);
        }

        $defaults = [
            'id'           => $rule->id ?? 0,
            'courseid'     => $courseid,
            'rule_scope'   => $rule->rule_scope ?? 'activity',
            'mod_type'     => $rule->mod_type ?? '',
            'cmid'         => $rule->cmid ?? 0,
            'activityname' => $rule->activityname ?? '',
            'coins_amount' => isset($rule->coins_amount) ? format_float((float)$rule->coins_amount, 2, false) : '1.00',
            'coin_symbol'  => $rule->coin_symbol ?? $defaultcoinsymbol,
            'min_grade'    => $mingradeval,
            'enabled'      => isset($rule->enabled) ? (int)$rule->enabled : 1,
        ];
        $this->set_data($defaults);

        // ── Botones ───────────────────────────────────────────────────────
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $scope        = $data['rule_scope'] ?? '';
        $cmid         = isset($data['cmid']) ? (int)$data['cmid'] : 0;
        $modtype      = trim($data['mod_type'] ?? '');
        $coinsamount  = isset($data['coins_amount']) ? (float)$data['coins_amount'] : 0;
        $coinsymbol   = trim($data['coin_symbol'] ?? '');
        $activityname = trim($data['activityname'] ?? '');
        $mingrade     = trim($data['min_grade'] ?? '');

        if (!in_array($scope, ['course', 'activity_type', 'activity'])) {
            $errors['rule_scope'] = get_string('invaliddata', 'error');
        }

        if ($scope === 'activity' && $cmid <= 0) {
            $errors['cmid'] = get_string('required');
        }

        if ($scope === 'activity' && $activityname === '') {
            $errors['activityname'] = get_string('required');
        }

        if ($scope === 'activity_type' && $modtype === '') {
            $errors['mod_type'] = get_string('required');
        }

        if ($coinsamount <= 0) {
            $errors['coins_amount'] = get_string('error_positive_coins', 'local_meritcoin');
        }

        if ($coinsymbol === '') {
            $errors['coin_symbol'] = get_string('required');
        } else if (\core_text::strlen($coinsymbol) > 20) {
            $errors['coin_symbol'] = get_string('maxlengthwarning', '', 20);
        }

        if ($mingrade !== '') {
            if (!is_numeric($mingrade)) {
                $errors['min_grade'] = get_string('error_invalid_grade', 'local_meritcoin');
            } else if ((float)$mingrade < 0) {
                $errors['min_grade'] = get_string('error_positive_grade', 'local_meritcoin');
            }
        }

        return $errors;
    }

    /**
     * Construye opciones de tipos de módulo presentes en el curso.
     */
    private function get_mod_type_options(int $courseid): array {
        global $DB;

        $options = ['' => get_string('rule_select_mod_type', 'local_meritcoin')];

        if ($courseid <= 0) {
            return $options;
        }

        $sql = "SELECT DISTINCT m.name
                  FROM {modules} m
                  JOIN {course_modules} cm ON cm.module = m.id
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
                 ORDER BY m.name ASC";

        $mods = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        foreach ($mods as $mod) {
            $label = get_string('pluginname', 'mod_' . $mod->name);
            $options[$mod->name] = $label;
        }

        return $options;
    }

    /**
     * Construye las opciones del selector de actividades del curso.
     */
    private function get_course_module_options(int $courseid): array {
        $options = [0 => get_string('selectactivity', 'local_meritcoin')];

        if ($courseid <= 0) {
            return $options;
        }

        $modinfo = get_fast_modinfo($courseid);

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || $cm->deletioninprogress) {
                continue;
            }
            $label = '[' . $cm->modname . '] ' . $cm->name;
            $options[(int)$cm->id] = $label;
        }

        asort($options);

        $first = [0 => get_string('selectactivity', 'local_meritcoin')];
        unset($options[0]);

        return $first + $options;
    }
}