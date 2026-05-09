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
 * Formulario para crear / editar plantillas de insignia.
 */
class badge_template_form extends \moodleform {

    public function definition() {
        $mform        = $this->_form;
        $template     = $this->_customdata['template']    ?? null;
        $type_options = $this->_customdata['type_options'] ?? [];
        $isadmin      = $this->_customdata['isadmin']      ?? false;

        // ── Nombre ────────────────────────────────────────────────────────────
        $mform->addElement('text', 'name',
            get_string('template_name', 'local_meritcoin'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // ── Tipo de insignia ─────────────────────────────────────────────────
        $mform->addElement('select', 'type_id',
            get_string('template_type', 'local_meritcoin'), $type_options);
        $mform->addRule('type_id', null, 'required', null, 'client');

        // ── Descripción ───────────────────────────────────────────────────────
        $mform->addElement('textarea', 'description',
            get_string('template_description', 'local_meritcoin'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addHelpButton('description', 'template_description', 'local_meritcoin');

        // ── Criterios ─────────────────────────────────────────────────────────
        $mform->addElement('textarea', 'criteria',
            get_string('template_criteria', 'local_meritcoin'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('criteria', PARAM_TEXT);
        $mform->addHelpButton('criteria', 'template_criteria', 'local_meritcoin');

        // ── URL imagen (opcional) ─────────────────────────────────────────────
        $mform->addElement('text', 'image_url',
            get_string('template_image_url', 'local_meritcoin'), ['size' => 60]);
        $mform->setType('image_url', PARAM_URL);

        // ── Scope (solo admin) ────────────────────────────────────────────────
        if ($isadmin) {
            $mform->addElement('select', 'scope',
                get_string('template_scope', 'local_meritcoin'), [
                    'course' => get_string('template_scope_course', 'local_meritcoin'),
                    'global' => get_string('template_scope_global', 'local_meritcoin'),
                ]);
            $mform->setDefault('scope', 'course');
            $mform->addHelpButton('scope', 'template_scope', 'local_meritcoin');
        }

        // ── Botones ───────────────────────────────────────────────────────────
        $this->add_action_buttons(true, get_string('savechanges'));

        // ── Pre-rellenar si estamos editando ──────────────────────────────────
        if ($template !== null) {
            $mform->setDefault('name',        $template->name);
            $mform->setDefault('type_id',     $template->type_id);
            $mform->setDefault('description', $template->description ?? '');
            $mform->setDefault('criteria',    $template->criteria    ?? '');
            $mform->setDefault('image_url',   $template->image_url   ?? '');
            if ($isadmin) {
                $mform->setDefault('scope', $template->scope ?? 'course');
            }
        }
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty(trim($data['name'] ?? ''))) {
            $errors['name'] = get_string('required');
        }
        if (empty($data['type_id'])) {
            $errors['type_id'] = get_string('required');
        }

        return $errors;
    }
}