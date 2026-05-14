<?php
// Gestión de recompensas del marketplace — vista del profesor.
// El profesor crea recompensas para un curso específico.

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

require_login();

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$rid      = optional_param('rid', 0, PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_capability('local/meritcoin:managerewards', $context);

$PAGE->set_url(new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('rewardstitle', 'local_meritcoin'));
$PAGE->set_heading($course->fullname . ' — ' . get_string('rewardstitle', 'local_meritcoin'));
$PAGE->set_pagelayout('standard');
$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

// ── Acciones POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    // Crear recompensa
    if ($action === 'create') {
        $name        = required_param('name', PARAM_TEXT);
        $description = optional_param('description', '', PARAM_TEXT);
        $price_mrt   = required_param('price_mrt', PARAM_INT);

        if (empty(trim($name)) || $price_mrt <= 0) {
            redirect(new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]),
                get_string('rewardinvaliddata', 'local_meritcoin'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $record               = new stdClass();
        $record->courseid     = $courseid;
        $record->teacherid    = $USER->id;
        $record->name         = trim($name);
        $record->description  = trim($description);
        $record->price_mrt    = $price_mrt;
        $record->active       = 1;
        $record->timecreated  = time();
        $record->timemodified = time();

        $DB->insert_record('local_meritcoin_rewards', $record);

        redirect(new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]),
            get_string('rewardcreated', 'local_meritcoin'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Activar / desactivar recompensa
    if ($action === 'toggle' && $rid > 0) {
        $reward = $DB->get_record('local_meritcoin_rewards',
            ['id' => $rid, 'courseid' => $courseid], '*', MUST_EXIST);

        $reward->active       = $reward->active ? 0 : 1;
        $reward->timemodified = time();
        $DB->update_record('local_meritcoin_rewards', $reward);

        redirect(new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]),
            get_string('rewardtoggled', 'local_meritcoin'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Eliminar recompensa (solo si nadie la ha canjeado)
    if ($action === 'delete' && $rid > 0) {
        $already_redeemed = $DB->record_exists('local_meritcoin_redemptions', ['rewardid' => $rid]);

        if ($already_redeemed) {
            redirect(new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]),
                get_string('rewardhasredemptions', 'local_meritcoin'), null, \core\output\notification::NOTIFY_WARNING);
        }

        $DB->delete_records('local_meritcoin_rewards', ['id' => $rid, 'courseid' => $courseid]);

        redirect(new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]),
            get_string('rewarddeleted', 'local_meritcoin'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// ── Datos para la vista ───────────────────────────────────────────────────────
$rewards = $DB->get_records('local_meritcoin_rewards',
    ['courseid' => $courseid], 'timecreated DESC');

// Contar canjes por recompensa
foreach ($rewards as $r) {
    $r->redemption_count = $DB->count_records('local_meritcoin_redemptions', ['rewardid' => $r->id]);
}

// Símbolo de moneda del curso
$course_config = $DB->get_record('local_meritcoin_course_config', ['courseid' => $courseid]);
$coin_symbol   = $course_config ? $course_config->coin_symbol : 'MRT';

$PAGE->requires->js(new moodle_url('/local/meritcoin/styles/meritcoin_poll.js'));
$PAGE->requires->js_init_code("MeritCoinPoll.start('rewards', 25000);");

echo $OUTPUT->header();
?>

<div class="mrt-dashboard container-fluid px-4 py-3">

  <!-- Encabezado -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0">
        <i class="fa fa-gift text-warning me-2"></i>
        <?= get_string('rewardstitle', 'local_meritcoin') ?>
      </h4>
      <small class="text-muted"><?= format_string($course->fullname) ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= new moodle_url('/local/meritcoin/teacher_transactions.php', ['courseid' => $courseid]) ?>"
         class="btn btn-sm btn-outline-primary">
        <i class="fa fa-chart-bar me-1"></i><?= get_string('teacher_transactions_title', 'local_meritcoin') ?>
      </a>
      <a href="<?= new moodle_url('/local/meritcoin/badge_award.php', ['courseid' => $courseid]) ?>"
         class="btn btn-sm btn-outline-warning">
        <i class="fa fa-medal me-1"></i><?= get_string('badge_award_title', 'local_meritcoin') ?>
      </a>
      <a href="<?= new moodle_url('/course/view.php', ['id' => $courseid]) ?>"
         class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i><?= get_string('backtocourse', 'local_meritcoin') ?>
      </a>
    </div>
  </div>

  <!-- Formulario crear recompensa -->
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="fa fa-plus-circle text-success"></i>
      <strong><?= get_string('rewardnew', 'local_meritcoin') ?></strong>
    </div>
    <div class="card-body">
      <form method="post"
            action="<?= new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]) ?>">
        <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
        <input type="hidden" name="action" value="create">

        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label fw-semibold" for="mrt-name">
              <?= get_string('rewardname', 'local_meritcoin') ?> <span class="text-danger">*</span>
            </label>
            <input type="text" id="mrt-name" name="name" class="form-control"
                   placeholder="<?= get_string('rewardnameph', 'local_meritcoin') ?>"
                   maxlength="255" required>
          </div>
          <div class="col-md-2">
              <label class="form-label fw-semibold" for="mrt-price">
                  <?= get_string('rewardprice', 'local_meritcoin') ?> <span class="text-danger">*</span>
              </label>
              <input type="number" id="mrt-price" name="price_mrt" class="form-control"
                     min="1" step="1" placeholder="1"
                     style="width:70px; font-size:0.95rem; font-weight:500;"
                     oninput="this.value = Math.abs(Math.round(this.value)) || ''"
                     required>
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold" for="mrt-desc">
              <?= get_string('rewarddesc', 'local_meritcoin') ?>
            </label>
            <input type="text" id="mrt-desc" name="description" class="form-control"
                   placeholder="<?= get_string('rewarddescph', 'local_meritcoin') ?>"
                   maxlength="500">
          </div>
        </div>

        <div class="mt-3">
          <button type="submit" class="btn btn-success">
            <i class="fa fa-plus me-1"></i><?= get_string('rewardcreatebtn', 'local_meritcoin') ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Lista de recompensas -->
  <div class="card">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="fa fa-list text-primary"></i>
      <strong><?= get_string('rewardslist', 'local_meritcoin') ?></strong>
      <span class="badge bg-secondary ms-auto"><?= count($rewards) ?></span>
    </div>
    <div class="card-body p-0">
      <?php if (empty($rewards)): ?>
        <div class="mrt-empty-state text-center py-4">
          <i class="fa fa-gift fa-3x text-muted mb-2 d-block"></i>
          <p class="text-muted"><?= get_string('rewardsempty', 'local_meritcoin') ?></p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th><?= get_string('rewardname', 'local_meritcoin') ?></th>
                <th><?= get_string('rewarddesc', 'local_meritcoin') ?></th>
                <th class="text-end"><?= get_string('rewardprice', 'local_meritcoin') ?></th>
                <th class="text-center"><?= get_string('rewardredemptions', 'local_meritcoin') ?></th>
                <th class="text-center"><?= get_string('colstatus', 'local_meritcoin') ?></th>
                <th class="text-center"><?= get_string('rewardactions', 'local_meritcoin') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rewards as $r): ?>
                <tr class="<?= $r->active ? '' : 'table-secondary text-muted' ?>">
                  <td class="fw-semibold"><?= s($r->name) ?></td>
                  <td class="text-muted small"><?= s($r->description) ?></td>
                  <td class="text-end text-nowrap">
                    <span class="fw-bold"><?= number_format((float)$r->price_mrt, 2) ?></span>
                    <small class="text-muted"><?= s($coin_symbol) ?></small>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-info text-dark"><?= $r->redemption_count ?></span>
                  </td>
                  <td class="text-center">
                    <?php if ($r->active): ?>
                      <span class="badge bg-success"><?= get_string('rewardactive', 'local_meritcoin') ?></span>
                    <?php else: ?>
                      <span class="badge bg-secondary"><?= get_string('rewardinactive', 'local_meritcoin') ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center text-nowrap">
                    <!-- Toggle activo/inactivo -->
                    <form method="post" class="d-inline"
                          action="<?= new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]) ?>">
                      <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="rid" value="<?= $r->id ?>">
                      <button type="submit" class="btn btn-sm <?= $r->active ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                              title="<?= $r->active ? get_string('rewarddeactivate', 'local_meritcoin') : get_string('rewardactivate', 'local_meritcoin') ?>">
                        <i class="fa fa-<?= $r->active ? 'pause' : 'play' ?>"></i>
                      </button>
                    </form>
                    <!-- Eliminar (solo si no hay canjes) -->
                    <?php if ($r->redemption_count === 0): ?>
                      <form method="post" class="d-inline"
                            action="<?= new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]) ?>"
                            onsubmit="return confirm('<?= get_string('rewardconfirmdelete', 'local_meritcoin') ?>')">
                        <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="rid" value="<?= $r->id ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                title="<?= get_string('rewarddelete', 'local_meritcoin') ?>">
                          <i class="fa fa-trash"></i>
                        </button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php echo $OUTPUT->footer(); ?>