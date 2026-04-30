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

/**
 * Página principal de gestión de reglas de monedas para un curso.
 *
 * CÓMO FUNCIONA:
 * ─────────────────────────────────────────────────────────────────────────────
 * Recibe:
 *   - courseid (int, obligatorio) ID del curso.
 *   - action  (string, opcional)  'enable', 'disable' o 'delete'.
 *   - ruleid  (int, opcional)     ID de la regla sobre la que actúa 'action'.
 *   - sesskey                     Obligatorio para acciones de escritura.
 *
 * Flujo principal:
 *   1. Verifica permisos (manage_rules sobre el contexto del curso).
 *   2. Si llega una acción (enable/disable/delete): la procesa y redirige.
 *   3. Lista todas las reglas del curso usando rules_service::get_rules_for_course().
 *   4. Renderiza la tabla con acciones inline.
 *
 * Acceso esperado:
 *   Desde el bloque de navegación del curso o un enlace en el menú del plugin.
 *   URL: /local/meritcoin/manage.php?courseid=X
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_meritcoin\rules_service;

// ── Parámetros ────────────────────────────────────────────────────────────────
$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$ruleid   = optional_param('ruleid', 0, PARAM_INT);

// ── Contexto y permisos ───────────────────────────────────────────────────────
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// ── Configurar página ANTES de require_login ─────────────────────────────────
$pageurl = new moodle_url('/local/meritcoin/manage.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('manage_rules', 'local_meritcoin'));
$PAGE->set_heading(get_string('manage_rules', 'local_meritcoin'));
$PAGE->navbar->add(get_string('manage_rules', 'local_meritcoin'), $pageurl);

// ── Login y capability (después de set_course) ────────────────────────────────
require_login($course);
require_capability('local/meritcoin:manage_rules', $context);

// ── Procesar acciones inline ──────────────────────────────────────────────────
if (!empty($action) && $ruleid > 0) {
    require_sesskey();

    $rule = $DB->get_record('local_meritcoin_rules', ['id' => $ruleid, 'courseid' => $courseid], '*', MUST_EXIST);
    $now  = time();

    switch ($action) {
        case 'enable':
            $DB->update_record('local_meritcoin_rules', (object)[
                'id'           => $ruleid,
                'enabled'      => 1,
                'timemodified' => $now,
            ]);
            \core\notification::success(get_string('rule_toggled', 'local_meritcoin'));
            break;

        case 'disable':
            $DB->update_record('local_meritcoin_rules', (object)[
                'id'           => $ruleid,
                'enabled'      => 0,
                'timemodified' => $now,
            ]);
            \core\notification::success(get_string('rule_toggled', 'local_meritcoin'));
            break;

        case 'delete':
            $DB->delete_records('local_meritcoin_rules', ['id' => $ruleid, 'courseid' => $courseid]);
            \core\notification::success(get_string('rule_deleted', 'local_meritcoin'));
            break;
    }

    redirect($pageurl);
}

// ── Cargar reglas del curso ───────────────────────────────────────────────────
$rules = rules_service::get_rules_for_course($courseid);

// ── Renderizar ────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_rules', 'local_meritcoin'));
echo html_writer::tag('p', get_string('manage_rules_desc', 'local_meritcoin'), ['class' => 'text-muted']);

// ── Botón "Nueva regla" ───────────────────────────────────────────────────────
$newruleurl = new moodle_url('/local/meritcoin/editrule.php', ['courseid' => $courseid]);
echo html_writer::div(
    $OUTPUT->single_button($newruleurl, get_string('newrule', 'local_meritcoin'), 'get'),
    'mb-3'
);

// ── Tabla de reglas ───────────────────────────────────────────────────────────
if (empty($rules)) {
    echo $OUTPUT->notification(get_string('norules', 'local_meritcoin'), 'info');

} else {
    $table             = new html_table();
    $table->id         = 'meritcoin-rules-table';
    $table->attributes = ['class' => 'generaltable fullwidth'];
    $table->head       = [
        get_string('rules_table_scope',    'local_meritcoin'),
        get_string('rules_table_activity', 'local_meritcoin'),
        get_string('rules_table_coins',    'local_meritcoin'),
        get_string('rules_table_symbol',   'local_meritcoin'),
        get_string('rules_table_status',   'local_meritcoin'),
        get_string('rules_table_actions',  'local_meritcoin'),
    ];
    $table->colclasses = ['', '', 'text-right', '', 'text-center', 'text-center'];

    foreach ($rules as $rule) {

        $scope = ($rule->rule_scope === 'course')
            ? get_string('rule_scope_course',   'local_meritcoin')
            : get_string('rule_scope_activity', 'local_meritcoin');

        $activityname = ($rule->rule_scope === 'course')
            ? html_writer::tag('em', '—')
            : format_string($rule->activityname);

        $coins = format_float((float)$rule->coins_amount, 2);

        $statusbadge = $rule->enabled
            ? html_writer::tag('span', get_string('rule_enabled',  'local_meritcoin'), ['class' => 'badge badge-success'])
            : html_writer::tag('span', get_string('rule_disabled', 'local_meritcoin'), ['class' => 'badge badge-secondary']);

        $actions = [];

        $editurl   = new moodle_url('/local/meritcoin/editrule.php', ['courseid' => $courseid, 'id' => $rule->id]);
        $actions[] = html_writer::link(
            $editurl,
            $OUTPUT->pix_icon('t/edit', get_string('editrule', 'local_meritcoin')),
            ['title' => get_string('editrule', 'local_meritcoin')]
        );

        if ($rule->enabled) {
            $toggleurl = new moodle_url($pageurl, ['action' => 'disable', 'ruleid' => $rule->id, 'sesskey' => sesskey()]);
            $actions[] = html_writer::link(
                $toggleurl,
                $OUTPUT->pix_icon('t/hide', get_string('rule_disable_action', 'local_meritcoin')),
                ['title' => get_string('rule_disable_action', 'local_meritcoin')]
            );
        } else {
            $toggleurl = new moodle_url($pageurl, ['action' => 'enable', 'ruleid' => $rule->id, 'sesskey' => sesskey()]);
            $actions[] = html_writer::link(
                $toggleurl,
                $OUTPUT->pix_icon('t/show', get_string('rule_enable_action', 'local_meritcoin')),
                ['title' => get_string('rule_enable_action', 'local_meritcoin')]
            );
        }

        $deleteurl = new moodle_url($pageurl, ['action' => 'delete', 'ruleid' => $rule->id, 'sesskey' => sesskey()]);
        $actions[] = html_writer::link(
            $deleteurl,
            $OUTPUT->pix_icon('t/delete', get_string('rule_delete_action', 'local_meritcoin')),
            [
                'title'   => get_string('rule_delete_action', 'local_meritcoin'),
                'onclick' => 'return confirm(' . json_encode(get_string('rule_delete_confirm', 'local_meritcoin')) . ');',
                'class'   => 'text-danger',
            ]
        );

        $table->data[] = new html_table_row([
            new html_table_cell($scope),
            new html_table_cell($activityname),
            new html_table_cell($coins),
            new html_table_cell(format_string($rule->coin_symbol)),
            new html_table_cell($statusbadge),
            new html_table_cell(implode(' ', $actions)),
        ]);
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();