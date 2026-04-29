<?php
// Panel de administración del marketplace — vista global de recompensas y canjes.

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

require_login();
require_capability('local/meritcoin:manage', context_system::instance());

$tab      = optional_param('tab', 'rewards', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/meritcoin/admin_marketplace.php', ['tab' => $tab]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('adminmarketplacetitle', 'local_meritcoin'));
$PAGE->set_heading(get_string('adminmarketplacetitle', 'local_meritcoin'));
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

// ── Datos globales ────────────────────────────────────────────────────────────

// Total recompensas activas / inactivas
$total_active   = $DB->count_records('local_meritcoin_rewards', ['active' => 1]);
$total_inactive = $DB->count_records('local_meritcoin_rewards', ['active' => 0]);
$total_redeemed = $DB->count_records('local_meritcoin_redemptions');

// Coins totales gastados en todo el marketplace
$total_spent = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(coins_spent), 0) FROM {local_meritcoin_redemptions}"
);

// ── Tab: recompensas por curso ────────────────────────────────────────────────
$rewards = [];
if ($tab === 'rewards') {
    $sql = "SELECT r.*,
                   c.fullname AS coursename,
                   u.firstname, u.lastname,
                   (SELECT COUNT(*) FROM {local_meritcoin_redemptions} rd WHERE rd.rewardid = r.id) AS redemption_count
              FROM {local_meritcoin_rewards} r
              JOIN {course} c ON c.id = r.courseid
              JOIN {user}   u ON u.id = r.teacherid
             " . ($courseid ? "WHERE r.courseid = :courseid" : "") . "
          ORDER BY r.timecreated DESC";

    $params = $courseid ? ['courseid' => $courseid] : [];
    $rewards = $DB->get_records_sql($sql, $params);
}

// ── Tab: historial de canjes ──────────────────────────────────────────────────
$redemptions = [];
if ($tab === 'redemptions') {
    $sql = "SELECT rd.*,
                   r.name  AS reward_name,
                   r.price_mrt,
                   c.fullname AS coursename,
                   u.firstname, u.lastname, u.email
              FROM {local_meritcoin_redemptions} rd
              JOIN {local_meritcoin_rewards} r ON r.id = rd.rewardid
              JOIN {course} c ON c.id = rd.courseid
              JOIN {user}   u ON u.id = rd.userid
             " . ($courseid ? "WHERE rd.courseid = :courseid" : "") . "
          ORDER BY rd.timecreated DESC
             LIMIT 200";

    $params = $courseid ? ['courseid' => $courseid] : [];
    $redemptions = $DB->get_records_sql($sql, $params);
}

// ── Lista de cursos con recompensas (para filtro) ─────────────────────────────
$courses_with_rewards = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname
       FROM {local_meritcoin_rewards} r
       JOIN {course} c ON c.id = r.courseid
      ORDER BY c.fullname ASC"
);

echo $OUTPUT->header();
?>

<div class="mrt-dashboard container-fluid px-4 py-3">

  <!-- Encabezado -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="mb-0">
      <i class="fa fa-store text-primary me-2"></i>
      <?= get_string('adminmarketplacetitle', 'local_meritcoin') ?>
    </h4>
    <a href="<?= new moodle_url('/admin/settings.php', ['section' => 'local_meritcoin']) ?>"
       class="btn btn-sm btn-outline-secondary">
      <i class="fa fa-cog me-1"></i><?= get_string('pluginsettings', 'local_meritcoin') ?>
    </a>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card text-center py-3">
        <div class="mrt-stat-icon text-success"><i class="fa fa-gift fa-2x"></i></div>
        <div class="mrt-stat-value"><?= $total_active ?></div>
        <div class="mrt-stat-label"><?= get_string('adminrewardsactive', 'local_meritcoin') ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card text-center py-3">
        <div class="mrt-stat-icon text-secondary"><i class="fa fa-gift fa-2x"></i></div>
        <div class="mrt-stat-value"><?= $total_inactive ?></div>
        <div class="mrt-stat-label"><?= get_string('adminrewardsinactive', 'local_meritcoin') ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card text-center py-3">
        <div class="mrt-stat-icon text-primary"><i class="fa fa-exchange-alt fa-2x"></i></div>
        <div class="mrt-stat-value"><?= $total_redeemed ?></div>
        <div class="mrt-stat-label"><?= get_string('admintotalredemptions', 'local_meritcoin') ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card text-center py-3">
        <div class="mrt-stat-icon text-warning"><i class="fa fa-coins fa-2x"></i></div>
        <div class="mrt-stat-value"><?= number_format($total_spent, 2) ?></div>
        <div class="mrt-stat-label"><?= get_string('admintotalspent', 'local_meritcoin') ?></div>
      </div>
    </div>
  </div>

  <!-- Filtro por curso -->
  <form method="get" class="d-flex align-items-center gap-2 mb-3">
    <input type="hidden" name="tab" value="<?= s($tab) ?>">
    <label class="form-label mb-0 fw-semibold text-nowrap">
      <i class="fa fa-filter me-1"></i><?= get_string('filterbycourse', 'local_meritcoin') ?>
    </label>
    <select name="courseid" class="form-select form-select-sm" style="max-width:300px"
            onchange="this.form.submit()">
      <option value="0"><?= get_string('allcourses', 'local_meritcoin') ?></option>
      <?php foreach ($courses_with_rewards as $c): ?>
        <option value="<?= $c->id ?>" <?= $courseid == $c->id ? 'selected' : '' ?>>
          <?= s($c->fullname) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'rewards' ? 'active' : '' ?>"
         href="<?= new moodle_url('/local/meritcoin/admin_marketplace.php',
                    ['tab' => 'rewards', 'courseid' => $courseid]) ?>">
        <i class="fa fa-gift me-1"></i><?= get_string('tabrewards', 'local_meritcoin') ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'redemptions' ? 'active' : '' ?>"
         href="<?= new moodle_url('/local/meritcoin/admin_marketplace.php',
                    ['tab' => 'redemptions', 'courseid' => $courseid]) ?>">
        <i class="fa fa-history me-1"></i><?= get_string('tabredemptions', 'local_meritcoin') ?>
        <?php if ($total_redeemed > 0): ?>
          <span class="badge bg-primary ms-1"><?= $total_redeemed ?></span>
        <?php endif; ?>
      </a>
    </li>
  </ul>

  <!-- Tab: Recompensas -->
  <?php if ($tab === 'rewards'): ?>
    <div class="card">
      <div class="card-body p-0">
        <?php if (empty($rewards)): ?>
          <div class="mrt-empty-state text-center py-5">
            <i class="fa fa-gift fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted"><?= get_string('adminrewardsempty', 'local_meritcoin') ?></p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th><?= get_string('colcourse', 'local_meritcoin') ?></th>
                  <th><?= get_string('rewardname', 'local_meritcoin') ?></th>
                  <th><?= get_string('rewarddesc', 'local_meritcoin') ?></th>
                  <th class="text-end"><?= get_string('rewardprice', 'local_meritcoin') ?></th>
                  <th class="text-center"><?= get_string('rewardredemptions', 'local_meritcoin') ?></th>
                  <th><?= get_string('adminteacher', 'local_meritcoin') ?></th>
                  <th class="text-center"><?= get_string('colstatus', 'local_meritcoin') ?></th>
                  <th><?= get_string('coldate', 'local_meritcoin') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rewards as $r): ?>
                  <tr class="<?= $r->active ? '' : 'table-secondary text-muted' ?>">
                    <td class="small"><?= s($r->coursename) ?></td>
                    <td class="fw-semibold"><?= s($r->name) ?></td>
                    <td class="small text-muted"><?= s($r->description) ?></td>
                    <td class="text-end text-nowrap fw-bold"><?= number_format((float)$r->price_mrt, 2) ?></td>
                    <td class="text-center">
                      <span class="badge bg-info text-dark"><?= $r->redemption_count ?></span>
                    </td>
                    <td class="small"><?= s($r->firstname . ' ' . $r->lastname) ?></td>
                    <td class="text-center">
                      <?php if ($r->active): ?>
                        <span class="badge bg-success"><?= get_string('rewardactive', 'local_meritcoin') ?></span>
                      <?php else: ?>
                        <span class="badge bg-secondary"><?= get_string('rewardinactive', 'local_meritcoin') ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="small text-nowrap"><?= userdate($r->timecreated, get_string('strftimedatetimeshort', 'langconfig')) ?></td>
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
                  <th><?= get_string('colcourse', 'local_meritcoin') ?></th>
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
                    <td class="small"><?= s($rd->coursename) ?></td>
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