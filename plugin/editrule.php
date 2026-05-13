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

/**
 * Página para crear o editar una regla de emisión de monedas.
 *
 * CÓMO FUNCIONA:
 * ─────────────────────────────────────────────────────────────────────────────
 * Recibe los parámetros:
 *   - courseid (int, obligatorio)  ID del curso.
 *   - id       (int, opcional)     ID de la regla a editar; 0 o ausente = crear.
 *
 * Flujo:
 *   1. Verifica permisos: solo usuarios con capability manage_rules en el curso.
 *   2. Si se recibe 'id', carga la regla existente de local_meritcoin_rules.
 *   3. Instancia rule_form con el courseid y la regla (o null para alta).
 *   4. Si el usuario cancela: redirige a manage.php.
 *   5. Si el formulario es válido: guarda (insert o update) y redirige a manage.php.
 *   6. Si no: muestra el formulario con el error correspondiente.
 *
 * Permisos requeridos:
 *   - local/meritcoin:manage_rules sobre el contexto del curso.
 *
 * @package    local_meritcoin
 * @copyright  2026 Universidad Tecnológica de Bolívar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_meritcoin\form\rule_form;
use local_meritcoin\rules_service;

// ── Parámetros de entrada ────────────────────────────────────────────────────
$courseid = required_param('courseid', PARAM_INT);
$ruleid   = optional_param('id', 0, PARAM_INT);

// ── Contexto y curso ─────────────────────────────────────────────────────────
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// ── Configurar PAGE — obligatoriamente PRIMERO ────────────────────────────────
$pageurl = new moodle_url('/local/meritcoin/editrule.php', ['courseid' => $courseid, 'id' => $ruleid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_meritcoin'));

if ($ruleid > 0) {
    $PAGE->set_heading(get_string('editrule', 'local_meritcoin'));
    $PAGE->navbar->add(get_string('manage_rules', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/manage.php', ['courseid' => $courseid]));
    $PAGE->navbar->add(get_string('editrule', 'local_meritcoin'));
} else {
    $PAGE->set_heading(get_string('newrule', 'local_meritcoin'));
    $PAGE->navbar->add(get_string('manage_rules', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/manage.php', ['courseid' => $courseid]));
    $PAGE->navbar->add(get_string('newrule', 'local_meritcoin'));
}

// ── Login y capability — después de set_course ───────────────────────────────
require_login($course);
require_capability('local/meritcoin:manage_rules', $context);

// ── Cargar regla existente (edición) ─────────────────────────────────────────
$rule = null;
if ($ruleid > 0) {
    $rule = $DB->get_record('local_meritcoin_rules', ['id' => $ruleid, 'courseid' => $courseid], '*', MUST_EXIST);
}

// ── URL de retorno ────────────────────────────────────────────────────────────
$manageurl = new moodle_url('/local/meritcoin/manage.php', ['courseid' => $courseid]);

// ── Instanciar formulario ─────────────────────────────────────────────────────
$defaultcoinsymbol = rules_service::get_coin_symbol_for_course($courseid);

$form = new rule_form(
    $pageurl,
    [
        'courseid'          => $courseid,
        'rule'              => $rule,
        'defaultcoinsymbol' => $defaultcoinsymbol,
    ]
);

// ── Cancelar ─────────────────────────────────────────────────────────────────
if ($form->is_cancelled()) {
    redirect($manageurl);
}

// ── Guardar ───────────────────────────────────────────────────────────────────
if ($formdata = $form->get_data()) {

    $scope       = clean_param($formdata->rule_scope, PARAM_ALPHANUMEXT);
    $cmid        = (int)($formdata->cmid ?? 0);
    $modtype     = clean_param($formdata->mod_type ?? '', PARAM_ALPHANUMEXT);
    $coinsamount = round((float)$formdata->coins_amount, 2);
    $coinsymbol  = clean_param($formdata->coin_symbol, PARAM_TEXT);
    $enabled     = (int)$formdata->enabled;
    $mingraderaw = trim($formdata->min_grade ?? '');
    $mingrade    = ($mingraderaw !== '' && is_numeric($mingraderaw)) ? (float)$mingraderaw : null;
    $now         = time();

    // ── Validación server-side de scope + campos dependientes ────────────────
    // Necesaria porque hideIf de Moodle solo oculta visualmente pero sigue
    // enviando todos los campos en el POST, y la validación client-side puede
    // no dispararse si JS falla o el campo oculto llega vacío.
    if (!in_array($scope, ['course', 'activity_type', 'activity'])) {
        \core\notification::error(get_string('invaliddata', 'error'));
        echo $OUTPUT->header();
        echo $OUTPUT->heading($PAGE->heading);
        $form->display();
        echo $OUTPUT->footer();
        exit;
    }

    if ($scope === 'activity' && $cmid <= 0) {
        \core\notification::error(get_string('required') . ': ' . get_string('activity'));
        echo $OUTPUT->header();
        echo $OUTPUT->heading($PAGE->heading);
        $form->display();
        echo $OUTPUT->footer();
        exit;
    }

    if ($scope === 'activity_type' && empty($modtype)) {
        \core\notification::error(get_string('required') . ': ' . get_string('rule_mod_type', 'local_meritcoin'));
        echo $OUTPUT->header();
        echo $OUTPUT->heading($PAGE->heading);
        $form->display();
        echo $OUTPUT->footer();
        exit;
    }

    // ── Resolver activityname, cmid y modtype según scope ────────────────────
    $activityname = '';

    if ($scope === 'activity') {
        $modinfo      = get_fast_modinfo($courseid);
        $cm           = $modinfo->get_cm($cmid);
        $activityname = $cm->name;
        $modtype      = null;
    } else if ($scope === 'activity_type') {
        $activityname = get_string('pluginname', 'mod_' . $modtype);
        $cmid         = null;
    } else {
        // scope === 'course'
        $cmid         = null;
        $modtype      = null;
        $activityname = 'Regla general del curso';
    }

    // ── Persistir ────────────────────────────────────────────────────────────
    if ($ruleid > 0) {
        $record               = new stdClass();
        $record->id           = $ruleid;
        $record->rule_scope   = $scope;
        $record->cmid         = ($scope === 'activity') ? $cmid : null;
        $record->mod_type     = ($scope === 'activity_type') ? $modtype : null;
        $record->activityname = $activityname;
        $record->coins_amount = $coinsamount;
        $record->coin_symbol  = $coinsymbol;
        $record->min_grade    = $mingrade;
        $record->enabled      = $enabled;
        $record->timemodified = $now;

        $DB->update_record('local_meritcoin_rules', $record);
        \core\notification::success(get_string('rule_updated', 'local_meritcoin'));

    } else {
        // Buscar regla duplicada según scope para hacer upsert
        if ($scope === 'activity') {
            $existing = $DB->get_record('local_meritcoin_rules', [
                'courseid'   => $courseid,
                'rule_scope' => 'activity',
                'cmid'       => $cmid,
            ]);
        } else if ($scope === 'activity_type') {
            $existing = $DB->get_record('local_meritcoin_rules', [
                'courseid'   => $courseid,
                'rule_scope' => 'activity_type',
                'mod_type'   => $modtype,
            ]);
        } else {
            $existing = $DB->get_record_sql(
                "SELECT * FROM {local_meritcoin_rules}
                  WHERE courseid = :courseid
                    AND cmid IS NULL
                    AND rule_scope = 'course'",
                ['courseid' => $courseid]
            );
        }

        if ($existing) {
            $existing->rule_scope   = $scope;
            $existing->activityname = $activityname;
            $existing->coins_amount = $coinsamount;
            $existing->coin_symbol  = $coinsymbol;
            $existing->min_grade    = $mingrade;
            $existing->cmid         = ($scope === 'activity') ? $cmid : null;
            $existing->mod_type     = ($scope === 'activity_type') ? $modtype : null;
            $existing->enabled      = $enabled;
            $existing->timemodified = $now;
            $DB->update_record('local_meritcoin_rules', $existing);
            \core\notification::warning(get_string('rule_duplicate_updated', 'local_meritcoin'));
        } else {
            $record               = new stdClass();
            $record->courseid     = $courseid;
            $record->rule_scope   = $scope;
            $record->cmid         = ($scope === 'activity') ? $cmid : null;
            $record->mod_type     = ($scope === 'activity_type') ? $modtype : null;
            $record->activityname = $activityname;
            $record->coins_amount = $coinsamount;
            $record->coin_symbol  = $coinsymbol;
            $record->min_grade    = $mingrade;
            $record->enabled      = $enabled;
            $record->timecreated  = $now;
            $record->timemodified = $now;
            $DB->insert_record('local_meritcoin_rules', $record);
            \core\notification::success(get_string('rule_created', 'local_meritcoin'));
        }
    }

    redirect($manageurl);
}

// ── Renderizar ────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading($PAGE->heading);

$form->display();

echo $OUTPUT->footer();