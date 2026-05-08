<?php
// This file is part of Moodle - http://moodle.org/
//
// @package   local_meritcoin
// @copyright 2026 Universidad Tecnológica de Bolívar
// @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

namespace local_meritcoin\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Formulario para otorgar insignias a estudiantes.
 */
class award_badge_form extends \moodleform {

    public function definition() {
        $mform            = $this->_form;
        $template_options = $this->_customdata['template_options'] ?? [];
        $student_options  = $this->_customdata['student_options']  ?? [];
        $default_template = $this->_customdata['default_template']  ?? null;

        // ── Plantilla ─────────────────────────────────────────────────────────
        $mform->addElement('select', 'templateid',
            get_string('award_select_template', 'local_meritcoin'), $template_options);
        $mform->addRule('templateid', null, 'required', null, 'client');
        if ($default_template) {
            $mform->setDefault('templateid', $default_template);
        }

        // ── Estudiantes (selección múltiple) ──────────────────────────────────
        $select = $mform->addElement('select', 'userids',
            get_string('award_select_students', 'local_meritcoin'), $student_options);
        $select->setMultiple(true);
        $mform->addRule('userids', null, 'required', null, 'client');
        $mform->addHelpButton('userids', 'award_select_students', 'local_meritcoin');

        // ── Nota interna (opcional) ───────────────────────────────────────────
        $mform->addElement('textarea', 'notes',
            get_string('award_notes', 'local_meritcoin'),
            ['rows' => 2, 'cols' => 60]);
        $mform->setType('notes', PARAM_TEXT);

        // ── Botones ───────────────────────────────────────────────────────────
        $this->add_action_buttons(true, get_string('award_btn', 'local_meritcoin'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['templateid'])) {
            $errors['templateid'] = get_string('required');
        }
        if (empty($data['userids'])) {
            $errors['userids'] = get_string('required');
        }

        return $errors;
    }
}