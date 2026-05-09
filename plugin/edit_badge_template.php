<?php
// This file is part of Moodle - http://moodle.org/
//
// @package   local_meritcoin
// @copyright 2026 Universidad Tecnológica de Bolívar
// @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/**
 * Crear o editar una plantilla de insignia.
 * URL: /local/meritcoin/edit_badge_template.php?courseid=X[&id=Y]
 */

require_once(__DIR__ . '/../../config.php');

use local_meritcoin\form\badge_template_form;

$courseid = optional_param('courseid', 0, PARAM_INT);
$tid      = optional_param('id', 0, PARAM_INT);

$sysctx  = context_system::instance();
$isadmin = has_capability('moodle/site:config', $sysctx);

// ── Contexto y permisos ───────────────────────────────────────────────────────
if ($courseid > 0) {
    $course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($courseid);
    require_login($course);
    require_capability('local/meritcoin:managerewards', $context);
} else {
    if (!$isadmin) {
        redirect(new moodle_url('/'));
    }
    $context = $sysctx;
    require_login();
}

// ── Cargar plantilla existente ────────────────────────────────────────────────
$template = null;
if ($tid > 0) {
    $template = $DB->get_record('local_meritcoin_badge_templates', ['id' => $tid], '*', MUST_EXIST);
    // Solo el creador o admin puede editar
    if ($template->createdby !== $USER->id && !$isadmin) {
        throw new moodle_exception('nopermissions', 'error', '', 'edit badge template');
    }
}

// ── Configurar PAGE ───────────────────────────────────────────────────────────
$pageurl = new moodle_url('/local/meritcoin/edit_badge_template.php',
    array_merge($courseid > 0 ? ['courseid' => $courseid] : [], $tid > 0 ? ['id' => $tid] : []));

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout($courseid > 0 ? 'incourse' : 'admin');

$heading = $tid > 0
    ? get_string('template_edit', 'local_meritcoin')
    : get_string('template_new', 'local_meritcoin');

$PAGE->set_title($heading);
$PAGE->set_heading($heading);

if ($courseid > 0) {
    $PAGE->set_course($course);
    $PAGE->navbar->add(get_string('badge_templates_title', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/badge_templates.php', ['courseid' => $courseid]));
    $PAGE->navbar->add($heading);
}

// ── Cargar tipos de insignia ──────────────────────────────────────────────────
$types_raw = $DB->get_records('local_meritcoin_badge_types', null, 'is_system DESC, name ASC');
$type_options = [];
foreach ($types_raw as $t) {
    $type_options[$t->id] = $t->name . ($t->is_system ? ' ★' : '');
}

// ── URL de retorno ────────────────────────────────────────────────────────────
$backurl = new moodle_url('/local/meritcoin/badge_templates.php',
    $courseid > 0 ? ['courseid' => $courseid] : []);

// ── Instanciar formulario ─────────────────────────────────────────────────────
$form = new badge_template_form($pageurl, [
    'template'     => $template,
    'type_options' => $type_options,
    'courseid'     => $courseid,
    'isadmin'      => $isadmin,
]);

// ── Cancelar ─────────────────────────────────────────────────────────────────
if ($form->is_cancelled()) {
    redirect($backurl);
}

// ── Guardar ───────────────────────────────────────────────────────────────────
if ($data = $form->get_data()) {
    $now = time();

    $record               = new stdClass();
    $record->name         = clean_param($data->name, PARAM_TEXT);
    $record->description  = clean_param($data->description ?? '', PARAM_TEXT);
    $record->criteria     = clean_param($data->criteria ?? '', PARAM_TEXT);
    $record->type_id      = (int)$data->type_id;
    $record->image_url    = clean_param($data->image_url ?? '', PARAM_URL);
    $record->scope        = ($isadmin && !empty($data->scope)) ? clean_param($data->scope, PARAM_ALPHA) : 'course';
    $record->courseid     = ($record->scope === 'global') ? null : ($courseid ?: null);
    $record->timemodified = $now;

    if ($tid > 0) {
        $record->id = $tid;
        $DB->update_record('local_meritcoin_badge_templates', $record);
        \core\notification::success(get_string('template_updated', 'local_meritcoin'));
    } else {
        $record->createdby   = $USER->id;
        $record->timecreated = $now;
        $DB->insert_record('local_meritcoin_badge_templates', $record);
        \core\notification::success(get_string('template_created', 'local_meritcoin'));
    }

    redirect($backurl);
}

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
$form->display();
echo $OUTPUT->footer();