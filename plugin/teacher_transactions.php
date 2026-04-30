<?php
// Vista del profesor: canjes de sus estudiantes por curso.

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

require_login();

$courseid  = required_param('courseid', PARAM_INT);
$studentid = optional_param('userid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$ctx    = context_course::instance($courseid);

require_capability('local/meritcoin:managerewards', $ctx);

$PAGE->set_url(new moodle_url('/local/meritcoin/teacher_transactions.php', ['courseid' => $courseid]));
$PAGE->set_context($ctx);
$PAGE->set_title(get_string('teacher_transactions_title', 'local_meritcoin'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');
$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

// ── Estudiantes del curso ────────────────────────────────────────────────────
$students = get_enrolled_users($ctx, 'local/meritcoin:earn', 0, 'u.id, u.firstname, u.lastname', 'u.lastname ASC');

// ── KPIs del curso ───────────────────────────────────────────────────────────
$kpi_coins_awarded = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(coins_amount),0) FROM {local_meritcoin_queue}
      WHERE courseid = :cid AND status = 'sent'",
    ['cid' => $courseid]
);
$kpi_redemptions = (int)$DB->count_records('local_meritcoin_redemptions', ['courseid' => $courseid]);
$kpi_coins_spent = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(coins_spent),0) FROM {local_meritcoin_redemptions} WHERE courseid = :cid",
    ['cid' => $courseid]
);

// ── Canjes (filtro opcional por estudiante) ──────────────────────────────────
$whereclause = "rd.courseid = :courseid";
$params      = ['courseid' => $courseid];

if ($studentid > 0) {
    $whereclause .= " AND rd.userid = :userid";
    $params['userid'] = $studentid;
}

$redemptions = $DB->get_records_sql(
    "SELECT rd.*, r.name AS reward_name, r.price_mrt,
            u.firstname, u.lastname, u.email
       FROM {local_meritcoin_redemptions} rd
       JOIN {local_meritcoin_rewards} r ON r.id = rd.rewardid
       JOIN {user} u ON u.id = rd.userid
      WHERE {$whereclause}
      ORDER BY rd.timecreated DESC
      LIMIT 200",
    $params
);

// ── Monedas otorgadas por actividad (cola) ────────────────────────────────────
$whereclause2 = "q.courseid = :courseid AND q.status = 'sent'";
$params2      = ['courseid' => $courseid];

if ($studentid > 0) {
    $whereclause2 .= " AND q.userid = :userid";
    $params2['userid'] = $studentid;
}

$earnings = $DB->get_records_sql(
    "SELECT q.*, u.firstname, u.lastname
       FROM {local_meritcoin_queue} q
       JOIN {user} u ON u.id = q.userid
      WHERE {$whereclause2}
      ORDER BY q.timecreated DESC
      LIMIT 200",
    $params2
);

$tab = optional_param('tab', 'earnings', PARAM_ALPHA);

echo $OUTPUT->header();
?>

<div class="mrt-dashboard container-fluid px-4 py-3">

  <!-- Header -->
  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="mb-0">
      <i class="fa fa-chart-bar text-primary me-2"></i>
      <?= get_string('teacher_transactions_title', 'local_meritcoin') ?>
      <small class="text-muted fs-6 ms-2"><?= s($course->fullname) ?></small>
    </h4>
    <a href="<?= new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]) ?>"
       class="btn btn-sm btn-outline-secondary">
      <i class="fa fa-arrow-left me-1"></i><?= get_string('backtocourse', 'local_meritcoin') ?>
    </a>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
      <div class="card text-center py-3">
        <div class="mrt-stat-icon text-success"><i class="fa fa-coins fa-2x"></i></div>
        <div class="mrt-stat-value"><?= number_format($kpi_coins_awarded, 2) ?></div>
        <div class="mrt-stat-label"><?= get_string('teacher_kpi_awarded', 'local_meritcoin') ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card text-center py-3">
        <div class="mrt-stat-icon text-primary"><i class="fa fa-exchange-alt fa-2x"></i></div>
        <div class="mrt-stat-value"><?= $kpi_redemptions ?></div>
        <div class="mrt-stat-label"><?= get_string('admintotalredemptions', 'local_meritcoin') ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card text-center py-3">
        <div class="mrt-stat-icon text-warning"><i class="fa fa-minus-circle fa-2x"></i></div>
        <div class="mrt-stat-value"><?= number_format($kpi_coins_spent, 2) ?></div>
        <div class="mrt-stat-label"><?= get_string('admintotalspent', 'local_meritcoin') ?></div>
      </div>
    </div>
  </div>

  <!-- Filtro por estudiante -->
  <form method="get" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <input type="hidden" name="courseid" value="<?= $courseid ?>">
    <input type="hidden" name="tab" value="<?= s($tab) ?>">
    <label class="form-label mb-0 fw-semibold text-nowrap">
      <i class="fa fa-user me-1"></i><?= get_string('teacher_filter_student', 'local_meritcoin') ?>
    </label>
    <select name="userid" class="form-select form-select-sm" style="max-width:280px"
            onchange="this.form.submit()">
      <option value="0"><?= get_string('teacher_all_students', 'local_meritcoin') ?></option>
      <?php foreach ($students as $s): ?>
        <option value="<?= $s->id ?>" <?= $studentid == $s->id ? 'selected' : '' ?>>
          <?= s(fullname($s)) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ($studentid > 0): ?>
      <a href="<?= new moodle_url('/local/meritcoin/teacher_transactions.php',
                    ['courseid' => $courseid, 'tab' => $tab]) ?>"
         class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-times me-1"></i><?= get_string('teacher_clear_filter', 'local_meritcoin') ?>
      </a>
    <?php endif; ?>
  </form>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'earnings' ? 'active' : '' ?>"
         href="<?= new moodle_url('/local/meritcoin/teacher_transactions.php',
                    ['courseid' => $courseid, 'tab' => 'earnings', 'userid' => $studentid]) ?>">
        <i class="fa fa-star me-1"></i><?= get_string('teacher_tab_earnings', 'local_meritcoin') ?>
        <span class="badge bg-secondary ms-1"><?= count($earnings) ?></span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'redemptions' ? 'active' : '' ?>"
         href="<?= new moodle_url('/local/meritcoin/teacher_transactions.php',
                    ['courseid' => $courseid, 'tab' => 'redemptions', 'userid' => $studentid]) ?>">
        <i class="fa fa-shopping-cart me-1"></i><?= get_string('tabredemptions', 'local_meritcoin') ?>
        <span class="badge bg-primary ms-1"><?= count($redemptions) ?></span>
      </a>
    </li>
  </ul>

  <!-- Tab: Monedas otorgadas -->
  <?php if ($tab === 'earnings'): ?>
    <div class="card">
      <div class="card-body p-0">
        <?php if (empty($earnings)): ?>
          <div class="mrt-empty-state text-center py-5">
            <i class="fa fa-star fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted"><?= get_string('teacher_no_earnings', 'local_meritcoin') ?></p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th><?= get_string('coldate', 'local_meritcoin') ?></th>
                  <th><?= get_string('admincolstudent', 'local_meritcoin') ?></th>
                  <th><?= get_string('colactivity', 'local_meritcoin') ?></th>
                  <th><?= get_string('colgrade', 'local_meritcoin') ?></th>
                  <th class="text-end"><?= get_string('colcoins', 'local_meritcoin') ?></th>
                  <th><?= get_string('colstatus', 'local_meritcoin') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($earnings as $e): ?>
                  <tr>
                    <td class="small text-nowrap"><?= userdate($e->timecreated, get_string('strftimedatetimeshort', 'langconfig')) ?></td>
                    <td class="small"><?= s($e->firstname . ' ' . $e->lastname) ?></td>
                    <td class="small"><?= s($e->activity_name ?: '—') ?></td>
                    <td><?= $e->grade !== null ? number_format((float)$e->grade, 1) : '—' ?></td>
                    <td class="text-end fw-bold text-success">+<?= number_format((float)$e->coins_amount, 2) ?></td>
                    <td><?= local_meritcoin_status_badge($e->status) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Tab: Canjes -->
  <?php if ($tab === 'redemptions'): ?>
    <div class="card">
      <div class="card-body p-0">
        <?php if (empty($redemptions)): ?>
          <div class="mrt-empty-state text-center py-5">
            <i class="fa fa-history fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted"><?= get_string('adminredemptionsempty', 'local_meritcoin') ?></p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th><?= get_string('coldate', 'local_meritcoin') ?></th>
                  <th><?= get_string('admincolstudent', 'local_meritcoin') ?></th>
                  <th><?= get_string('rewardname', 'local_meritcoin') ?></th>
                  <th class="text-end"><?= get_string('admincoinsspent', 'local_meritcoin') ?></th>
                  <th><?= get_string('admintxhash', 'local_meritcoin') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($redemptions as $rd): ?>
                  <tr>
                    <td class="small text-nowrap"><?= userdate($rd->timecreated, get_string('strftimedatetimeshort', 'langconfig')) ?></td>
                    <td class="small"><?= s($rd->firstname . ' ' . $rd->lastname) ?></td>
                    <td class="fw-semibold"><?= s($rd->reward_name) ?></td>
                    <td class="text-end fw-bold text-danger">-<?= number_format((float)$rd->coins_spent, 2) ?></td>
                    <td class="small text-muted font-monospace">
                      <?= $rd->tx_hash ? s(substr($rd->tx_hash, 0, 18) . '…') : '—' ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php echo $OUTPUT->footer(); ?>