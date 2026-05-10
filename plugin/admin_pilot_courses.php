<?php
// This file is part of Moodle - http://moodle.org/
// GNU GPL v3 or later - http://www.gnu.org/copyleft/gpl.html

/**
 * Página de administración: gestión de cursos piloto MeritCoin.
 *
 * Permite al admin:
 *  - Marcar un curso como piloto (con grupo opcional y fecha override)
 *  - Ver la lista de cursos piloto activos
 *  - Sobreescribir la fecha de cierre de semestre
 *  - Desactivar un curso piloto
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_meritcoin_pilot_courses');

require_capability('local/meritcoin:manage', context_system::instance());

$action   = optional_param('action',   '',  PARAM_ALPHA);
$courseid = optional_param('courseid', 0,   PARAM_INT);
$pilotid  = optional_param('pilotid',  0,   PARAM_INT);

$PAGE->set_title(get_string('pilotcourses', 'local_meritcoin'));
$PAGE->set_heading(get_string('pilotcourses', 'local_meritcoin'));

// ── Acciones POST ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    if ($action === 'add' && $courseid > 0) {
        $groupid    = optional_param('groupid',    0,   PARAM_INT);
        $expires_at = optional_param('expires_at', '', PARAM_TEXT);

        $expires_ts = !empty($expires_at) ? strtotime($expires_at) : null;

        if (!$DB->record_exists('local_meritcoin_pilot_courses', ['courseid' => $courseid])) {
            $record                = new stdClass();
            $record->courseid      = $courseid;
            $record->pilot_enabled = 1;
            $record->groupid       = $groupid ?: null;
            $record->expires_at    = $expires_ts ?: null;
            $record->created_by    = $USER->id;
            $record->created_at    = time();
            $DB->insert_record('local_meritcoin_pilot_courses', $record);
            \core\notification::success(get_string('pilotadded', 'local_meritcoin'));
        } else {
            \core\notification::warning(get_string('pilotalreadyexists', 'local_meritcoin'));
        }

    } else if ($action === 'update_expires' && $pilotid > 0) {
        $expires_at = optional_param('expires_at', '', PARAM_TEXT);
        $expires_ts = !empty($expires_at) ? strtotime($expires_at) : null;

        $pilot             = $DB->get_record('local_meritcoin_pilot_courses', ['id' => $pilotid], '*', MUST_EXIST);
        $pilot->expires_at = $expires_ts;
        $DB->update_record('local_meritcoin_pilot_courses', $pilot);

        // Propagar el cambio al backend para todos los enrollments del curso.
        $client = new \local_meritcoin\api_client();
        $students = $DB->get_records('local_meritcoin_wallets', ['status' => 'active']);
        foreach ($students as $stu) {
            $client->patch("/wallets/enrollments/STU-{$stu->userid}/COURSE-{$pilot->courseid}", [
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires_ts),
            ]);
        }
        \core\notification::success(get_string('expiresatupdated', 'local_meritcoin'));

    } else if ($action === 'disable' && $pilotid > 0) {
        $DB->set_field('local_meritcoin_pilot_courses', 'pilot_enabled', 0, ['id' => $pilotid]);
        \core\notification::success(get_string('pilotdisabled', 'local_meritcoin'));
    }

    redirect($PAGE->url);
}

// ── Renderizado ────────────────────────────────────────────────────────────

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pilotcourses', 'local_meritcoin'));

// ── Formulario: añadir curso piloto ───────────────────────────────────────
$courses = $DB->get_records_menu('course', ['visible' => 1], 'fullname ASC', 'id, fullname');
unset($courses[SITEID]);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'add']);
echo html_writer::start_div('card mb-4 p-3');
echo html_writer::tag('h5', get_string('addpilotcourse', 'local_meritcoin'));

// Selector de curso
echo html_writer::tag('label', get_string('course'), ['for' => 'courseid', 'class' => 'form-label']);
echo html_writer::select($courses, 'courseid', '', ['' => get_string('choosecourse', 'local_meritcoin')], ['class' => 'form-select mb-2', 'id' => 'courseid']);

// Fecha de expiración (override)
echo html_writer::tag('label', get_string('expiresatoverride', 'local_meritcoin'), ['for' => 'expires_at', 'class' => 'form-label']);
echo html_writer::tag('small', get_string('expiresatoverridedesc', 'local_meritcoin'), ['class' => 'd-block text-muted mb-1']);
echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'expires_at', 'id' => 'expires_at', 'class' => 'form-control mb-3']);

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('addpilotcourse', 'local_meritcoin'), 'class' => 'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

// ── Tabla: cursos piloto activos ──────────────────────────────────────────
$sql = "SELECT pc.*, c.fullname AS coursename
          FROM {local_meritcoin_pilot_courses} pc
          JOIN {course} c ON c.id = pc.courseid
         ORDER BY pc.created_at DESC";
$pilots = $DB->get_records_sql($sql);

if ($pilots) {
    $table          = new html_table();
    $table->head    = [
        get_string('course'),
        get_string('status'),
        get_string('expiresatoverride', 'local_meritcoin'),
        get_string('courseenddate',     'local_meritcoin'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'table table-striped';

    foreach ($pilots as $pilot) {
        $enddate     = $DB->get_field('course', 'enddate', ['id' => $pilot->courseid]);
        $status_str  = $pilot->pilot_enabled
            ? html_writer::tag('span', get_string('active'),   ['class' => 'badge bg-success'])
            : html_writer::tag('span', get_string('disabled', 'local_meritcoin'), ['class' => 'badge bg-secondary']);

        $override_str = $pilot->expires_at
            ? userdate($pilot->expires_at, get_string('strftimedatefullshort', 'langconfig'))
            : html_writer::tag('em', get_string('usescourseenddate', 'local_meritcoin'));

        $enddate_str  = ($enddate && $enddate > 0)
            ? userdate($enddate, get_string('strftimedatefullshort', 'langconfig'))
            : html_writer::tag('em', get_string('noenddate', 'local_meritcoin'));

        // Botones de acción
        $actions = '';
        if ($pilot->pilot_enabled) {
            // Form para actualizar expires_at
            $actions .= html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'd-inline me-1']);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',     'value' => 'update_expires']);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'pilotid',    'value' => $pilot->id]);
            $actions .= html_writer::empty_tag('input', ['type' => 'date',   'name' => 'expires_at', 'class' => 'form-control form-control-sm d-inline w-auto']);
            $actions .= html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('update'), 'class' => 'btn btn-sm btn-outline-primary']);
            $actions .= html_writer::end_tag('form');

            // Botón desactivar
            $disable_url = new \moodle_url($PAGE->url, ['action' => 'disable', 'pilotid' => $pilot->id, 'sesskey' => sesskey()]);
            $actions .= html_writer::link($disable_url, get_string('disable'), [
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => "return confirm('" . get_string('confirmdisablepilot', 'local_meritcoin') . "');",
            ]);
        }

        $table->data[] = [
            $pilot->coursename,
            $status_str,
            $override_str,
            $enddate_str,
            $actions,
        ];
    }

    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nopilotcourses', 'local_meritcoin'), 'info');
}

echo $OUTPUT->footer();