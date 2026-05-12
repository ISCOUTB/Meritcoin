<?php
// This file is part of Moodle - http://moodle.org/
//
// @package   local_meritcoin
// @copyright 2026 Universidad Tecnológica de Bolívar
// @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/**
 * Otorgar una insignia a uno o varios estudiantes de un curso.
 *
 * FLUJO:
 *   1. Profesor/admin elige plantilla (o usa la preseleccionada por ?templateid=X).
 *   2. Elige uno o varios estudiantes del curso.
 *   3. Al guardar: inserta en local_meritcoin_badges con snapshot de la plantilla
 *      + genera verify_hash único.
 *   4. Redirige a badge_templates.php con mensaje de éxito.
 *
 * URL: /local/meritcoin/award_badge.php?courseid=X[&templateid=Y]
 */

require_once(__DIR__ . '/../../config.php');

use local_meritcoin\form\award_badge_form;

$courseid   = required_param('courseid', PARAM_INT);
$templateid = optional_param('templateid', 0, PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// ── Permisos ──────────────────────────────────────────────────────────────────
require_login($course);
require_capability('local/meritcoin:managerewards', $context);

$sysctx  = context_system::instance();
$isadmin = has_capability('moodle/site:config', $sysctx);

// ── Cargar plantillas disponibles ─────────────────────────────────────────────
// Profesor: sus plantillas propias (scope=course, este curso) + globales (scope=global).
// Admin:    todas las plantillas.
if ($isadmin) {
    $templates_raw = $DB->get_records_sql(
        "SELECT t.*, bt.name AS type_name, bt.color AS type_color
           FROM {local_meritcoin_badge_templates} t
           JOIN {local_meritcoin_badge_types} bt ON bt.id = t.type_id
          ORDER BY bt.name ASC, t.name ASC"
    );
} else {
    $templates_raw = $DB->get_records_sql(
        "SELECT t.*, bt.name AS type_name, bt.color AS type_color
           FROM {local_meritcoin_badge_templates} t
           JOIN {local_meritcoin_badge_types} bt ON bt.id = t.type_id
          WHERE t.scope = 'global'
             OR (t.scope = 'course' AND t.createdby = :uid AND t.courseid = :cid)
          ORDER BY bt.name ASC, t.name ASC",
        ['uid' => $USER->id, 'cid' => $courseid]
    );
}

if (empty($templates_raw)) {
    // No hay plantillas: redirigir a crear una
    redirect(
        new moodle_url('/local/meritcoin/edit_badge_template.php', ['courseid' => $courseid]),
        get_string('award_no_templates', 'local_meritcoin'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$template_options = [];
foreach ($templates_raw as $t) {
    $template_options[$t->id] = '[' . $t->type_name . '] ' . $t->name;
}

// ── Estudiantes del curso ─────────────────────────────────────────────────────
$students_raw = get_enrolled_users($context, 'local/meritcoin:earncoins');
$student_options = [];
foreach ($students_raw as $s) {
    $student_options[$s->id] = fullname($s) . ' (' . $s->email . ')';
}

if (empty($student_options)) {
    redirect(
        new moodle_url('/local/meritcoin/badge_templates.php', ['courseid' => $courseid]),
        get_string('award_no_students', 'local_meritcoin'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// ── Configurar PAGE ───────────────────────────────────────────────────────────
$pageurl = new moodle_url('/local/meritcoin/award_badge.php', [
    'courseid'   => $courseid,
    'templateid' => $templateid,
]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('award_badge_title', 'local_meritcoin'));
$PAGE->set_heading(get_string('award_badge_title', 'local_meritcoin'));
$PAGE->navbar->add(
    get_string('badge_templates_title', 'local_meritcoin'),
    new moodle_url('/local/meritcoin/badge_templates.php', ['courseid' => $courseid])
);
$PAGE->navbar->add(get_string('award_badge_title', 'local_meritcoin'));

// ── Instanciar formulario ─────────────────────────────────────────────────────
$form = new award_badge_form($pageurl, [
    'courseid'         => $courseid,
    'template_options' => $template_options,
    'student_options'  => $student_options,
    'default_template' => $templateid ?: array_key_first($template_options),
    'templates_data'   => $templates_raw,
]);

// ── Cancelar ─────────────────────────────────────────────────────────────────
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/meritcoin/badge_templates.php', ['courseid' => $courseid]));
}

// ── Guardar ───────────────────────────────────────────────────────────────────
if ($data = $form->get_data()) {
    $now       = time();
    $tpl       = $templates_raw[$data->templateid];
    $userids   = is_array($data->userids) ? $data->userids : [$data->userids];
    $issued_by = $USER->id;
    $count     = 0;

    // Obtener shortname del tipo
    $badge_type_rec = $DB->get_record('local_meritcoin_badge_types',
        ['id' => $tpl->type_id], 'shortname', MUST_EXIST);

    foreach ($userids as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) {
            continue;
        }

        // Verificar que el estudiante está en el curso
        if (!array_key_exists($uid, $student_options)) {
            continue;
        }

        // Generar hash único (SHA-256 sobre templateid + userid + timestamp + random)
        $verify_hash = hash('sha256',
            $tpl->id . '|' . $uid . '|' . $courseid . '|' . $now . '|' . random_string(16)
        );

        $record                = new stdClass();
        $record->templateid    = $tpl->id;
        $record->userid        = $uid;
        $record->courseid      = $courseid;
        $record->badge_name    = $tpl->name;
        $record->badge_type    = $badge_type_rec->shortname;
        $record->description   = $tpl->description ?? '';
        $record->issued_by     = $issued_by;
        $record->verify_hash   = $verify_hash;
        $record->coins_threshold = 0;  // Reservado para futuras reglas
        $record->timecreated   = $now;
        $record->timemodified  = $now;
        $record->award_id    = $api_response->id ?? null; 

        $DB->insert_record('local_meritcoin_badges', $record);
        $count++;
    }

    if ($count > 0) {
        \core\notification::success(
            get_string('award_success', 'local_meritcoin', $count)
        );
    } else {
        \core\notification::warning(get_string('award_none_issued', 'local_meritcoin'));
    }

    redirect(new moodle_url('/local/meritcoin/badge_templates.php', ['courseid' => $courseid]));
}

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('award_badge_title', 'local_meritcoin'));

// Preview de la plantilla seleccionada (se actualiza vía JS)
?>
<div id="template-preview" class="alert alert-info d-none mb-3">
  <div class="d-flex align-items-center gap-3">
    <span id="preview-icon" class="fa fa-award fa-2x"></span>
    <div>
      <strong id="preview-name"></strong>
      <div id="preview-type" class="small"></div>
      <div id="preview-desc" class="text-muted small mt-1"></div>
    </div>
  </div>
</div>
<?php

$form->display();

// JS: actualizar preview al cambiar plantilla
$templates_json = json_encode(array_map(function($t) {
    return [
        'id'          => $t->id,
        'name'        => $t->name,
        'type_name'   => $t->type_name,
        'type_color'  => $t->type_color,
        'description' => $t->description ?? '',
    ];
}, array_values($templates_raw)));

echo html_writer::script("
(function(){
  const templates = $templates_json;
  const tmap = {};
  templates.forEach(t => tmap[t.id] = t);

  function updatePreview(tid) {
    const t = tmap[tid];
    if (!t) return;
    document.getElementById('preview-name').textContent = t.name;
    document.getElementById('preview-type').textContent = t.type_name;
    document.getElementById('preview-type').style.color = t.type_color;
    document.getElementById('preview-desc').textContent = t.description;
    document.getElementById('template-preview').classList.remove('d-none');
  }

  const sel = document.querySelector('[name=\"templateid\"]');
  if (sel) {
    updatePreview(sel.value);
    sel.addEventListener('change', e => updatePreview(e.target.value));
  }
})();
");

echo $OUTPUT->footer();