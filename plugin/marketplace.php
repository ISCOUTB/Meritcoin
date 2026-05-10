<?php
// Marketplace del estudiante — canje de recompensas por curso.

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

require_login();

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$rid      = optional_param('rid', 0, PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_capability('local/meritcoin:viewmarketplace', $context);

$PAGE->set_url(new moodle_url('/local/meritcoin/marketplace.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('marketplacetitle', 'local_meritcoin'));
$PAGE->set_heading($course->fullname . ' — ' . get_string('marketplacetitle', 'local_meritcoin'));
$PAGE->set_pagelayout('standard');
$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

// ── Símbolo de moneda del curso ───────────────────────────────────────────────
$course_config = $DB->get_record('local_meritcoin_course_config', ['courseid' => $courseid]);
$coin_symbol   = $course_config ? $course_config->coin_symbol : 'MRT';

// ── Balance disponible del estudiante (solo monedas ganadas en ESTE curso) ────
$wallet  = local_meritcoin_get_user_wallet($USER->id);
$backend = local_meritcoin_get_backend_student_data($USER->id, $wallet);

$earned_this_course = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(coins_amount), 0)
       FROM {local_meritcoin_queue}
      WHERE userid   = :userid
        AND courseid = :courseid
        AND status   = 'sent'",
    ['userid' => $USER->id, 'courseid' => $courseid]
);

$spent_this_course = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(coins_spent), 0)
       FROM {local_meritcoin_redemptions}
      WHERE userid   = :userid
        AND courseid = :courseid",
    ['userid' => $USER->id, 'courseid' => $courseid]
);

$available = max(0, $earned_this_course - $spent_this_course);

// ── Acción POST: canjear recompensa ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'redeem' && $rid > 0) {
    require_sesskey();

    $reward = $DB->get_record('local_meritcoin_rewards',
        ['id' => $rid, 'courseid' => $courseid, 'active' => 1], '*', IGNORE_MISSING);

    // Validaciones
    if (!$reward) {
        redirect(new moodle_url('/local/meritcoin/marketplace.php', ['courseid' => $courseid]),
            get_string('marketplacerewardnotfound', 'local_meritcoin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $already = $DB->record_exists('local_meritcoin_redemptions',
        ['userid' => $USER->id, 'rewardid' => $rid]);
    if ($already) {
        redirect(new moodle_url('/local/meritcoin/marketplace.php', ['courseid' => $courseid]),
            get_string('marketplacealreadyredeemed', 'local_meritcoin'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    if ($available < (float)$reward->price_mrt) {
        redirect(new moodle_url('/local/meritcoin/marketplace.php', ['courseid' => $courseid]),
            get_string('marketplacenotenough', 'local_meritcoin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    // Registrar canje
    $record              = new stdClass();
    $record->userid      = $USER->id;
    $record->rewardid    = $reward->id;
    $record->courseid    = $courseid;
    $record->coins_spent = $reward->price_mrt;
    $record->tx_hash     = null;
    $record->timecreated = time();

    $redemption_id = $DB->insert_record('local_meritcoin_redemptions', $record);

    // ── Quemar MRT en Besu ────────────────────────────────────────────────────
    $backend_url = get_config('local_meritcoin', 'backend_url');
    if ($backend_url && $wallet) {
        $payload = json_encode([
            'student_id'     => (string)$USER->id,
            'student_wallet' => $wallet,
            'amount'         => (float)$reward->price_mrt,
            'reward_id'      => (string)$reward->id,
            'course_id'      => (string)$courseid,
        ]);

        $ch = curl_init($backend_url . '/tokens/spend');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = $response ? json_decode($response, true) : null;
        if (!empty($data['tx_hash'])) {
            $DB->set_field('local_meritcoin_redemptions', 'tx_hash',
                          $data['tx_hash'], ['id' => $redemption_id]);
        }
    }
}

// ── Datos para la vista ───────────────────────────────────────────────────────
$rewards = $DB->get_records('local_meritcoin_rewards',
    ['courseid' => $courseid, 'active' => 1], 'price_mrt ASC');

// Marcar cuáles ya fueron canjeadas por este estudiante
$redeemed_ids = $DB->get_fieldset_select(
    'local_meritcoin_redemptions',
    'rewardid',
    'userid = :userid AND courseid = :courseid',
    ['userid' => $USER->id, 'courseid' => $courseid]
);
$redeemed_ids = array_flip($redeemed_ids); // para isset() rápido

echo $OUTPUT->header();
?>

<div class="mrt-dashboard container-fluid px-4 py-3">

  <!-- Encabezado + balance -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h4 class="mb-0">
        <i class="fa fa-store text-primary me-2"></i>
        <?= get_string('marketplacetitle', 'local_meritcoin') ?>
      </h4>
      <small class="text-muted"><?= format_string($course->fullname) ?></small>
    </div>
    <div class="mrt-hero card px-4 py-2 d-flex flex-row align-items-center gap-3 mb-0">
      <div class="mrt-coin-icon"><span class="mrt-coin-symbol">⬡</span></div>
      <div>
        <div class="mrt-balance-label"><?= get_string('marketplaceavailable', 'local_meritcoin') ?></div>
        <div class="mrt-balance-value">
          <?= number_format($available, 2) ?>
          <span class="mrt-ticker"><?= s($coin_symbol) ?></span>
        </div>
      </div>
    </div>
  </div>

  <div class="mrt-info-banner d-flex align-items-center gap-2 mb-4 px-3 py-2 rounded"
     style="background:var(--bs-warning-bg-subtle, #fff8e1); border-left: 3px solid #f59e0b;">
    <i class="fa fa-clock text-warning"></i>
    <small class="text-muted"><?= get_string('marketplaceretroacwarning', 'local_meritcoin') ?></small>
  </div>

  <!-- Catálogo -->
  <?php if (empty($rewards)): ?>
    <div class="card">
      <div class="card-body mrt-empty-state text-center py-5">
        <i class="fa fa-store fa-3x text-muted mb-3 d-block"></i>
        <p class="text-muted"><?= get_string('marketplaceempty', 'local_meritcoin') ?></p>
      </div>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($rewards as $r):
        $already_redeemed = isset($redeemed_ids[$r->id]);
        $can_afford       = $available >= (float)$r->price_mrt;
        $disabled         = $already_redeemed || !$can_afford;
      ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card h-100 <?= $already_redeemed ? 'border-success' : ($can_afford ? '' : 'opacity-75') ?>">
            <div class="card-body d-flex flex-column gap-2">
              <div class="d-flex align-items-start justify-content-between gap-2">
                <h6 class="card-title mb-0 fw-bold"><?= s($r->name) ?></h6>
                <span class="badge bg-primary text-nowrap">
                  <?= number_format((float)$r->price_mrt, 2) ?> <?= s($coin_symbol) ?>
                </span>
              </div>
              <?php if (!empty($r->description)): ?>
                <p class="card-text text-muted small mb-0"><?= s($r->description) ?></p>
              <?php endif; ?>
              <div class="mt-auto pt-2">
                <?php if ($already_redeemed): ?>
                  <span class="btn btn-sm btn-success w-100 disabled">
                    <i class="fa fa-check me-1"></i><?= get_string('marketplaceredeemedbadge', 'local_meritcoin') ?>
                  </span>
                <?php elseif (!$can_afford): ?>
                  <span class="btn btn-sm btn-outline-secondary w-100 disabled">
                    <i class="fa fa-lock me-1"></i><?= get_string('marketplacenotenoughbtn', 'local_meritcoin') ?>
                  </span>
                <?php else: ?>
                  <form method="post"
                        action="<?= new moodle_url('/local/meritcoin/marketplace.php', ['courseid' => $courseid]) ?>">
                    <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                    <input type="hidden" name="action" value="redeem">
                    <input type="hidden" name="rid" value="<?= $r->id ?>">
                    <input type="hidden" data-reward-name="<?= s($r->name) ?>"
                           data-reward-price="<?= number_format((float)$r->price_mrt, 2) ?>"
                           data-coin-symbol="<?= s($coin_symbol) ?>">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                      <i class="fa fa-exchange-alt me-1"></i><?= get_string('marketplaceredeembtn', 'local_meritcoin') ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php echo $OUTPUT->footer(); ?>