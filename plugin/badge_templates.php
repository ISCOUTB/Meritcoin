<?php
// This file is part of Moodle - http://moodle.org/
//
// @package   local_meritcoin
// @copyright 2026 Universidad Tecnológica de Bolívar
// @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/**
 * Gestión de plantillas de insignias.
 * Profesor: ve y gestiona sus propias plantillas (scope=course).
 * Admin:    ve y gestiona todas + puede crear plantillas globales (scope=global).
 *
 * URL: /local/meritcoin/badge_templates.php?courseid=X
 */

require_once(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$tid      = optional_param('tid', 0, PARAM_INT);

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

// ── Acciones AJAX (delete / toggle) ──────────────────────────────────────────
if ($action === 'delete' && $tid > 0 && confirm_sesskey()) {
    $tpl = $DB->get_record('local_meritcoin_badge_templates', ['id' => $tid], '*', MUST_EXIST);
    // Solo el creador o admin puede borrar
    if ($tpl->createdby === $USER->id || $isadmin) {
        // No borrar si tiene insignias emitidas
        if ($DB->count_records('local_meritcoin_badges', ['templateid' => $tid]) > 0) {
            \core\notification::error(get_string('template_has_badges', 'local_meritcoin'));
        } else {
            $DB->delete_records('local_meritcoin_badge_templates', ['id' => $tid]);
            \core\notification::success(get_string('template_deleted', 'local_meritcoin'));
        }
    }
    $redirecturl = new moodle_url('/local/meritcoin/badge_templates.php', $courseid > 0 ? ['courseid' => $courseid] : []);
    redirect($redirecturl);
}

// ── Configurar PAGE ───────────────────────────────────────────────────────────
$pageurl = new moodle_url('/local/meritcoin/badge_templates.php', $courseid > 0 ? ['courseid' => $courseid] : []);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout($courseid > 0 ? 'incourse' : 'admin');
$PAGE->set_title(get_string('badge_templates_title', 'local_meritcoin'));
$PAGE->set_heading(get_string('badge_templates_title', 'local_meritcoin'));

if ($courseid > 0) {
    $PAGE->set_course($course);
    $PAGE->navbar->add(get_string('badge_templates_title', 'local_meritcoin'));
}

// ── Obtener plantillas ────────────────────────────────────────────────────────
if ($isadmin) {
    // Admin ve todas
    $templates = $DB->get_records_sql(
        "SELECT t.*, bt.name AS type_name, bt.color AS type_color, bt.icon AS type_icon,
                u.firstname, u.lastname,
                (SELECT COUNT(*) FROM {local_meritcoin_badges} b WHERE b.templateid = t.id) AS issued_count
           FROM {local_meritcoin_badge_templates} t
           JOIN {local_meritcoin_badge_types} bt ON bt.id = t.type_id
           JOIN {user} u ON u.id = t.createdby
          ORDER BY t.timecreated DESC"
    );
} else {
    // Profesor ve solo las suyas
    $templates = $DB->get_records_sql(
        "SELECT t.*, bt.name AS type_name, bt.color AS type_color, bt.icon AS type_icon,
                u.firstname, u.lastname,
                (SELECT COUNT(*) FROM {local_meritcoin_badges} b WHERE b.templateid = t.id) AS issued_count
           FROM {local_meritcoin_badge_templates} t
           JOIN {local_meritcoin_badge_types} bt ON bt.id = t.type_id
           JOIN {user} u ON u.id = t.createdby
          WHERE t.createdby = :uid
          ORDER BY t.timecreated DESC",
        ['uid' => $USER->id]
    );
}

// ── URL de nueva plantilla ────────────────────────────────────────────────────
$newurl = new moodle_url('/local/meritcoin/edit_badge_template.php',
    $courseid > 0 ? ['courseid' => $courseid] : []);

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>

<div class="container-fluid px-4 py-3">

  <div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="mb-0">
      <i class="fa fa-layer-group text-warning me-2"></i>
      <?= get_string('badge_templates_title', 'local_meritcoin') ?>
    </h2>
    <div class="d-flex gap-2">
      <?php if ($courseid > 0): ?>
        <a href="<?= new moodle_url('/local/meritcoin/award_badge.php', ['courseid' => $courseid]) ?>"
           class="btn btn-success">
          <i class="fa fa-award me-1"></i><?= get_string('badge_award_btn', 'local_meritcoin') ?>
        </a>
      <?php endif; ?>
      <a href="<?= $newurl ?>" class="btn btn-primary">
        <i class="fa fa-plus me-1"></i><?= get_string('template_new', 'local_meritcoin') ?>
      </a>
    </div>
  </div>

  <?php if (empty($templates)): ?>
    <div class="text-center py-5">
      <i class="fa fa-folder-open fa-3x text-muted mb-3 d-block"></i>
      <p class="text-muted"><?= get_string('template_empty', 'local_meritcoin') ?></p>
      <a href="<?= $newurl ?>" class="btn btn-primary">
        <i class="fa fa-plus me-1"></i><?= get_string('template_new', 'local_meritcoin') ?>
      </a>
    </div>
  <?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
      <?php foreach ($templates as $tpl):
        $editurl   = new moodle_url('/local/meritcoin/edit_badge_template.php',
            array_merge($courseid > 0 ? ['courseid' => $courseid] : [], ['id' => $tpl->id]));
        $deleteurl = new moodle_url('/local/meritcoin/badge_templates.php',
            array_merge($courseid > 0 ? ['courseid' => $courseid] : [],
                ['action' => 'delete', 'tid' => $tpl->id, 'sesskey' => sesskey()]));
        $awardurl  = new moodle_url('/local/meritcoin/award_badge.php',
            array_merge($courseid > 0 ? ['courseid' => $courseid] : [], ['templateid' => $tpl->id]));
      ?>
        <div class="col">
          <div class="card h-100 shadow-sm">
            <!-- Franja de color del tipo -->
            <div class="card-header d-flex align-items-center gap-2 py-2"
                 style="background:<?= s($tpl->type_color) ?>20; border-left: 4px solid <?= s($tpl->type_color) ?>;">
              <i class="fa <?= s($tpl->type_icon) ?>" style="color:<?= s($tpl->type_color) ?>"></i>
              <span class="badge" style="background:<?= s($tpl->type_color) ?>;color:#fff;">
                <?= s($tpl->type_name) ?>
              </span>
              <?php if ($tpl->scope === 'global'): ?>
                <span class="badge bg-dark ms-auto">
                  <i class="fa fa-globe me-1"></i><?= get_string('template_scope_global', 'local_meritcoin') ?>
                </span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <h5 class="card-title mb-1"><?= s($tpl->name) ?></h5>
              <?php if (!empty($tpl->description)): ?>
                <p class="card-text text-muted small mb-2"><?= s($tpl->description) ?></p>
              <?php endif; ?>
              <?php if (!empty($tpl->criteria)): ?>
                <div class="small text-muted border-start border-warning ps-2 mb-2">
                  <strong><?= get_string('template_criteria', 'local_meritcoin') ?>:</strong>
                  <?= s($tpl->criteria) ?>
                </div>
              <?php endif; ?>
              <div class="d-flex align-items-center gap-3 mt-3 text-muted small">
                <span>
                  <i class="fa fa-award me-1"></i>
                  <?= $tpl->issued_count ?> <?= get_string('template_issued', 'local_meritcoin') ?>
                </span>
                <span>
                  <i class="fa fa-user me-1"></i>
                  <?= s(fullname((object)['firstname' => $tpl->firstname, 'lastname' => $tpl->lastname])) ?>
                </span>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <a href="<?= $awardurl ?>" class="btn btn-sm btn-success flex-fill">
                <i class="fa fa-award me-1"></i><?= get_string('badge_award_btn', 'local_meritcoin') ?>
              </a>
              <a href="<?= $editurl ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-edit"></i>
              </a>
              <?php if ((int)$tpl->issued_count === 0): ?>
                <a href="<?= $deleteurl ?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('<?= get_string('template_confirm_delete', 'local_meritcoin') ?>')">
                  <i class="fa fa-trash"></i>
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($courseid > 0): ?>
    <div class="mt-4">
      <a href="<?= new moodle_url('/local/meritcoin/manage.php', ['courseid' => $courseid]) ?>"
         class="btn btn-link text-muted">
        <i class="fa fa-arrow-left me-1"></i><?= get_string('backtocourse', 'local_meritcoin') ?>
      </a>
    </div>
  <?php endif; ?>

</div>

<?php echo $OUTPUT->footer(); ?>