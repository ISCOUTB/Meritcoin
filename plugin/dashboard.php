<?php
// Dashboard del estudiante - Plugin MeritCoin
// v1.2 - Columnas corregidas: userid, courseid, timecreated, student_wallet

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

require_login();
$PAGE->set_url(new moodle_url('/local/meritcoin/dashboard.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('dashboardtitle', 'local_meritcoin'));
$PAGE->set_heading(get_string('dashboardheading', 'local_meritcoin'));
$PAGE->set_pagelayout('standard');

global $USER, $DB, $OUTPUT;

// Datos locales (siempre disponibles)
$wallet  = local_meritcoin_get_user_wallet($USER->id);
$stats   = local_meritcoin_get_user_stats($USER->id);

// ✅ Columnas correctas: userid, timecreated
$events  = $DB->get_records(
    'local_meritcoin_queue',
    ['userid' => $USER->id],
    'timecreated DESC',
    '*',
    0,
    20
);

// Datos del backend (pueden fallar silenciosamente)
$backend = local_meritcoin_get_backend_student_data($USER->id, $wallet);

$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

echo $OUTPUT->header();
?>

<div class="mrt-dashboard container-fluid px-4 py-3">

  <?php if (!$backend['backend_available']): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4" role="alert">
      <i class="fa fa-exclamation-triangle"></i>
      <span><?= get_string('backendunavailable', 'local_meritcoin') ?></span>
    </div>
  <?php endif; ?>

  <?php if (empty($wallet)): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 mb-4" role="alert">
      <i class="fa fa-info-circle"></i>
      <span>
        <?= get_string('nowalletmsg', 'local_meritcoin') ?>
        <a href="<?= new moodle_url('/user/edit.php', ['id' => $USER->id]) ?>">
          <?= get_string('editprofile', 'local_meritcoin') ?>
        </a>
      </span>
    </div>
  <?php endif; ?>

  <!-- HERO -->
  <div class="mrt-hero card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div class="mrt-hero-left d-flex align-items-center gap-3">
        <div class="mrt-coin-icon">
          <span class="mrt-coin-symbol">⬡</span>
        </div>
        <div>
          <div class="mrt-balance-label"><?= get_string('mrtbalance', 'local_meritcoin') ?></div>
          <div class="mrt-balance-value">
            <?php if ($backend['backend_available']): ?>
              <?= number_format($backend['mrt_balance'] ?? 0, 2) ?> <span class="mrt-ticker">MRT</span>
            <?php else: ?>
              <span class="mrt-balance-unknown">--</span> <span class="mrt-ticker">MRT</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="mrt-hero-right text-end">
        <div class="mrt-wallet-label"><?= get_string('walletaddress', 'local_meritcoin') ?></div>
        <?php if (!empty($wallet)): ?>
          <div class="mrt-wallet-value">
            <code class="mrt-wallet-code"><?= s(substr($wallet, 0, 6) . '...' . substr($wallet, -4)) ?></code>
            <button class="btn btn-sm btn-link mrt-copy-btn p-0 ms-1"
                    data-wallet="<?= s($wallet) ?>"
                    title="<?= get_string('copywallet', 'local_meritcoin') ?>">
              <i class="fa fa-copy"></i>
            </button>
          </div>
          <div class="mrt-wallet-full text-muted" style="font-size:0.72em;"><?= s($wallet) ?></div>
        <?php else: ?>
          <span class="badge bg-secondary"><?= get_string('no_wallet', 'local_meritcoin') ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ESTADÍSTICAS -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="mrt-stat-card card h-100">
        <div class="card-body text-center">
          <div class="mrt-stat-icon text-success"><i class="fa fa-graduation-cap fa-2x"></i></div>
          <div class="mrt-stat-number"><?= $stats['completions'] ?></div>
          <div class="mrt-stat-label"><?= get_string('statcompletions', 'local_meritcoin') ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="mrt-stat-card card h-100">
        <div class="card-body text-center">
          <div class="mrt-stat-icon text-warning"><i class="fa fa-star fa-2x"></i></div>
          <div class="mrt-stat-number"><?= $stats['avg_grade'] !== null ? $stats['avg_grade'] : '--' ?></div>
          <div class="mrt-stat-label"><?= get_string('statavggrade', 'local_meritcoin') ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="mrt-stat-card card h-100">
        <div class="card-body text-center">
          <div class="mrt-stat-icon text-primary"><i class="fa fa-paper-plane fa-2x"></i></div>
          <div class="mrt-stat-number"><?= $stats['sent_events'] ?></div>
          <div class="mrt-stat-label"><?= get_string('statsent', 'local_meritcoin') ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="mrt-stat-card card h-100">
        <div class="card-body text-center">
          <div class="mrt-stat-icon <?= $stats['failed_events'] > 0 ? 'text-danger' : 'text-secondary' ?>">
            <i class="fa fa-<?= $stats['failed_events'] > 0 ? 'exclamation-circle' : 'check-circle' ?> fa-2x"></i>
          </div>
          <div class="mrt-stat-number"><?= $stats['pending_events'] ?></div>
          <div class="mrt-stat-label"><?= get_string('statpending', 'local_meritcoin') ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- BADGES -->
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="fa fa-shield-alt text-warning"></i>
      <strong><?= get_string('badgessection', 'local_meritcoin') ?></strong>
      <?php if ($backend['backend_available']): ?>
        <span class="badge bg-warning text-dark ms-auto"><?= count($backend['badges']) ?></span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (!$backend['backend_available']): ?>
        <p class="text-muted text-center py-3">
          <i class="fa fa-plug fa-2x d-block mb-2"></i>
          <?= get_string('badgesbackendneeded', 'local_meritcoin') ?>
        </p>
      <?php elseif (empty($backend['badges'])): ?>
        <div class="mrt-empty-state text-center py-4">
          <i class="fa fa-medal fa-3x text-muted mb-3 d-block"></i>
          <p class="text-muted"><?= get_string('nobadgesyet', 'local_meritcoin') ?></p>
          <small class="text-muted"><?= get_string('nobadgeshint', 'local_meritcoin') ?></small>
        </div>
      <?php else: ?>
        <div class="mrt-badges-grid">
          <?php foreach ($backend['badges'] as $badge): ?>
            <div class="mrt-badge-item text-center">
              <div class="mrt-badge-icon">
                <?php if (!empty($badge['image_url'])): ?>
                  <img src="<?= s($badge['image_url']) ?>" alt="<?= s($badge['name']) ?>"
                       width="64" height="64" loading="lazy">
                <?php else: ?>
                  <i class="fa fa-award fa-3x text-warning"></i>
                <?php endif; ?>
              </div>
              <div class="mrt-badge-name"><?= s($badge['name'] ?? 'Badge') ?></div>
              <div class="mrt-badge-date text-muted">
                <?= !empty($badge['awarded_at'])
                    ? userdate($badge['awarded_at'], get_string('strftimedate', 'langconfig'))
                    : '' ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- HISTORIAL -->
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="fa fa-history text-primary"></i>
      <strong><?= get_string('eventshistory', 'local_meritcoin') ?></strong>
      <span class="badge bg-secondary ms-auto"><?= $stats['total_events'] ?></span>
    </div>
    <div class="card-body p-0">
      <?php if (empty($events)): ?>
        <div class="mrt-empty-state text-center py-4">
          <i class="fa fa-inbox fa-3x text-muted mb-2 d-block"></i>
          <p class="text-muted"><?= get_string('noeventsyet', 'local_meritcoin') ?></p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th><?= get_string('coltype', 'local_meritcoin') ?></th>
                <th><?= get_string('colcourse', 'local_meritcoin') ?></th>
                <th><?= get_string('colgrade', 'local_meritcoin') ?></th>
                <th><?= get_string('colstatus', 'local_meritcoin') ?></th>
                <th><?= get_string('coldate', 'local_meritcoin') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($events as $event):
                // ✅ Columna correcta: courseid (no course_id)
                $course = $DB->get_record('course', ['id' => $event->courseid], 'fullname', IGNORE_MISSING);
              ?>
                <tr>
                  <td>
                    <?php if ($event->event_type === 'completion'): ?>
                      <span class="badge bg-success">
                        <i class="fa fa-check-circle me-1"></i><?= get_string('typecompletion', 'local_meritcoin') ?>
                      </span>
                    <?php else: ?>
                      <span class="badge bg-primary">
                        <i class="fa fa-star me-1"></i><?= get_string('typegrade', 'local_meritcoin') ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="text-truncate" style="max-width:200px;"
                      title="<?= s($course ? $course->fullname : '') ?>">
                    <?= s($course ? $course->fullname : 'Curso ' . $event->courseid) ?>
                  </td>
                  <td>
                    <?= $event->grade !== null
                        ? number_format((float)$event->grade, 1)
                        : '<span class="text-muted">—</span>' ?>
                  </td>
                  <td><?= local_meritcoin_status_badge($event->status) ?></td>
                  <td class="text-nowrap">
                    <?php
                      // ✅ Columna correcta: timecreated (no created_at)
                      echo userdate($event->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($stats['total_events'] > 20): ?>
          <div class="card-footer text-muted text-center small">
            <?= get_string('showinglast20', 'local_meritcoin') ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
document.querySelectorAll('.mrt-copy-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var wallet = this.getAttribute('data-wallet');
        navigator.clipboard.writeText(wallet).then(function() {
            var icon = btn.querySelector('i');
            icon.className = 'fa fa-check text-success';
            setTimeout(function() { icon.className = 'fa fa-copy'; }, 1500);
        });
    });
});
</script>

<?php echo $OUTPUT->footer(); ?>
