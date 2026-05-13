<?php
// Dashboard del estudiante - Plugin MeritCoin
// v1.4 - Badges desde BD local con modal de metadatos, verificación y descarga PDF

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

require_login();

$redirectteacher = false;
$courses = enrol_get_users_courses($USER->id, true);

foreach ($courses as $course) {
    $ctx = context_course::instance($course->id);
    if (has_capability('local/meritcoin:managerewards', $ctx) ||
        has_capability('local/meritcoin:manage_rules', $ctx)) {
        $redirectteacher = true;
        break;
    }
}

if (!local_meritcoin_is_student_only()) {
    redirect(new moodle_url('/my'));
}

$PAGE->set_url(new moodle_url('/local/meritcoin/dashboard.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('dashboardtitle', 'local_meritcoin'));
$PAGE->set_heading(get_string('dashboardheading', 'local_meritcoin'));
$PAGE->set_pagelayout('standard');

global $USER, $DB, $OUTPUT;

$wallet = local_meritcoin_get_user_wallet($USER->id);
$stats  = local_meritcoin_get_user_stats($USER->id);

// Estos cálculos locales los dejamos por si quieres mostrarlos en el futuro,
// pero el balance mostrado en el HERO usará solo el backend.
$earned_local = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(coins_amount), 0)
       FROM {local_meritcoin_queue}
      WHERE userid = :userid AND status = 'sent'",
    ['userid' => $USER->id]
);
$total_spent = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(coins_spent), 0)
       FROM {local_meritcoin_redemptions}
      WHERE userid = :userid",
    ['userid' => $USER->id]
);
$real_balance = max(0, $earned_local - $total_spent);

$events = $DB->get_records_sql(
    "SELECT q.*,
            (SELECT COUNT(*)
               FROM {local_meritcoin_queue} q2
              WHERE q2.userid      = q.userid
                AND q2.cmid        = q.cmid
                AND q2.cmid       IS NOT NULL
                AND q2.timecreated <= q.timecreated) AS reeval_count
       FROM {local_meritcoin_queue} q
      WHERE q.userid = :userid
      ORDER BY q.timecreated DESC
      LIMIT 20",
    ['userid' => $USER->id]
);

// Backend: saldo real de la wallet + badges remotos (si aplica)
$backend = local_meritcoin_get_backend_student_data($USER->id, $wallet); // [cite:9]

$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

// ── Badges desde BD local con datos enriquecidos ─────────────────────────
$local_badges = $DB->get_records_sql(
    "SELECT b.*,
            bt.color        AS type_color,
            bt.icon         AS type_icon,
            c.fullname      AS course_fullname,
            u.firstname     AS issuer_firstname,
            u.lastname      AS issuer_lastname
       FROM {local_meritcoin_badges} b
  LEFT JOIN {local_meritcoin_badge_types} bt ON bt.shortname = b.badge_type
  LEFT JOIN {course}                      c  ON c.id         = b.courseid
  LEFT JOIN {user}                        u  ON u.id         = b.issued_by
      WHERE b.userid = :userid
      ORDER BY b.timecreated DESC",
    ['userid' => $USER->id]
);

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
            <?php if (!empty($backend['backend_available'])): ?>
              <?php
              // El backend ya devuelve el saldo neto de la wallet.
              $real_balance = max(0, $backend['mrt_balance'] ?? 0);
              ?>
              <?= number_format($real_balance, 2) ?> <span class="mrt-ticker">MRT</span>
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

  <!-- BADGES (BD local + modal enriquecido) -->
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="fa fa-shield-alt text-warning"></i>
      <strong><?= get_string('badgessection', 'local_meritcoin') ?></strong>
      <span class="badge bg-warning text-dark ms-auto"><?= count($local_badges) ?></span>
    </div>
    <div class="card-body">
      <?php if (empty($local_badges)): ?>
        <div class="mrt-empty-state text-center py-4">
          <i class="fa fa-medal fa-3x text-muted mb-3 d-block"></i>
          <p class="text-muted"><?= get_string('nobadgesyet', 'local_meritcoin') ?></p>
          <small class="text-muted"><?= get_string('nobadgeshint', 'local_meritcoin') ?></small>
        </div>
      <?php else: ?>
        <div class="mrt-badges-grid">
          <?php foreach ($local_badges as $lb):
            $issuer_name = trim(($lb->issuer_firstname ?? '') . ' ' . ($lb->issuer_lastname ?? ''));
            $verify_url  = (string) new moodle_url('/local/meritcoin/badge_verify.php', ['hash' => $lb->verify_hash]);
            $pdf_url     = (string) new moodle_url('/local/meritcoin/badge_pdf.php',    ['hash' => $lb->verify_hash]);

            $criteria_arr = [];
            if (!empty($lb->criteria)) {
                $criteria_arr = array_filter(array_map('trim', explode("\n", $lb->criteria)));
            }

            $badge_data = json_encode([
                'name'        => $lb->badge_name,
                'awarded_at'  => userdate($lb->timecreated, get_string('strftimedate', 'langconfig')),
                'image_url'   => $lb->image_url   ?? '',
                'description' => $lb->description ?? '',
                'skills'      => [],
                'criteria'    => array_values($criteria_arr),
                'issued_by'   => $issuer_name ?: null,
                'verify_url'  => $verify_url,
                'pdf_url'     => $pdf_url,
                'type_color'  => $lb->type_color  ?? '#f0c040',
                'type_icon'   => $lb->type_icon   ?? 'fa-award',
                'badge_type'  => $lb->badge_type  ?? '',
                'course'      => $lb->course_fullname ?? '',
            ]);
          ?>
            <div class="mrt-badge-item text-center">
              <button class="mrt-badge-trigger unstyled-btn w-100" data-badge='<?= s($badge_data) ?>'>
                <div class="mrt-badge-icon">
                  <?php if (!empty($lb->image_url)): ?>
                      <img src="<?= s($lb->image_url) ?>"
                          alt="<?= s($lb->badge_name) ?>"
                          width="52" height="52" loading="lazy"
                          style="border-radius:8px; object-fit:contain;"
                          onerror="this.style.display='none';
                                    this.nextElementSibling.style.display='flex';">
                      <span class="mrt-modal-icon-fallback"
                            style="display:none; color:<?= s($lb->type_color ?? '#f0c040') ?>">
                        <i class="fa <?= s($lb->type_icon ?? 'fa-award') ?>"></i>
                      </span>
                  <?php else: ?>
                    <i class="fa <?= s($lb->type_icon ?? 'fa-award') ?> fa-3x"
                       style="color:<?= s($lb->type_color ?? '#f0c040') ?>"></i>
                  <?php endif; ?>
                </div>
                <div class="mrt-badge-name"><?= s($lb->badge_name) ?></div>
                <?php if (!empty($lb->badge_type)): ?>
                  <div class="mt-1">
                    <span class="badge rounded-pill"
                          style="background-color:<?= s($lb->type_color ?? '#f0c040') ?>; color:#000; font-size:0.7em;">
                      <?= s($lb->badge_type) ?>
                    </span>
                  </div>
                <?php endif; ?>
                <div class="mrt-badge-date text-muted">
                  <?= userdate($lb->timecreated, get_string('strftimedate', 'langconfig')) ?>
                </div>
              </button>
              <div class="mrt-badge-actions mt-2 d-flex flex-column gap-1">
                <a href="<?= s($pdf_url) ?>"
                   class="btn btn-sm mrt-btn-pdf" target="_blank">
                  <i class="fa fa-file-pdf-o me-1"></i><?= get_string('badge_pdf_download', 'local_meritcoin') ?>
                </a>
                <button class="btn btn-sm mrt-btn-copy-link"
                        data-url="<?= s($verify_url) ?>">
                  <i class="fa fa-link me-1"></i><?= get_string('badge_copy_link', 'local_meritcoin') ?>
                </button>
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
                <th><?= get_string('colcourse', 'local_meritcoin') ?></th>
                <th><?= get_string('colactivity', 'local_meritcoin') ?></th>
                <th><?= get_string('colcoins', 'local_meritcoin') ?></th>
                <th><?= get_string('colstatus', 'local_meritcoin') ?></th>
                <th><?= get_string('coldate', 'local_meritcoin') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($events as $event):
                $course = $DB->get_record('course', ['id' => $event->courseid], 'fullname', IGNORE_MISSING);
                $activitylabel = !empty($event->activity_name) ? $event->activity_name : '—';
                $reevals = (int)($event->reeval_count ?? 0);
              ?>
                <tr>
                  <td class="text-truncate" style="max-width:160px;"
                      title="<?= s($course ? $course->fullname : '') ?>">
                    <?= s($course ? $course->fullname : 'Curso ' . $event->courseid) ?>
                  </td>
                  <td class="text-truncate" style="max-width:160px;"
                      title="<?= s($activitylabel) ?>">
                    <?= s($activitylabel) ?>
                  </td>
                  <td class="text-end fw-bold text-success">+<?= number_format((float)$event->coins_amount, 2) ?></td>
                  <td><?= local_meritcoin_status_badge($event->status) ?></td>
                  <td class="text-nowrap">
                    <?= userdate($event->timecreated, get_string('strftimedatetimeshort', 'langconfig')) ?>
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

<!-- ═══════════════════════════════════════════════
     MODAL DE DETALLE DE INSIGNIA
═══════════════════════════════════════════════ -->
<div class="modal fade" id="mrt-badge-modal" tabindex="-1"
     aria-labelledby="mrt-badge-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content mrt-badge-modal-content">

      <!-- Header -->
      <div class="mrt-badge-modal-header">
        <div class="mrt-badge-modal-icon-wrap">
          <img id="mrt-modal-img" src="" alt="" width="80" height="80"
               style="display:none; border-radius:12px; object-fit:contain;">
          <span id="mrt-modal-icon-fallback" class="mrt-modal-icon-fallback">
            <i class="fa fa-award"></i>
          </span>
        </div>
        <div class="mrt-badge-modal-title-wrap">
          <h4 class="modal-title mb-1" id="mrt-badge-modal-title"></h4>
          <span id="mrt-modal-date" class="mrt-modal-date"></span>
        </div>
        <button type="button" class="btn-close btn-close-white ms-auto"
                data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <!-- Body -->
      <div class="modal-body mrt-badge-modal-body">

        <div id="mrt-modal-desc-wrap" class="mrt-modal-section">
          <h6 class="mrt-modal-section-title">
            <i class="fa fa-info-circle"></i> Descripción
          </h6>
          <p id="mrt-modal-desc" class="mb-0"></p>
        </div>

        <div id="mrt-modal-type-wrap" class="mrt-modal-section" style="display:none;">
          <h6 class="mrt-modal-section-title">
            <i class="fa fa-tag"></i> Tipo de insignia
          </h6>
          <span id="mrt-modal-type-pill" class="badge rounded-pill" style="font-size:0.85em;"></span>
        </div>

        <div id="mrt-modal-course-wrap" class="mrt-modal-section" style="display:none;">
          <h6 class="mrt-modal-section-title">
            <i class="fa fa-graduation-cap"></i> Curso
          </h6>
          <p id="mrt-modal-course" class="mb-0"></p>
        </div>

        <div id="mrt-modal-skills-wrap" class="mrt-modal-section" style="display:none;">
          <h6 class="mrt-modal-section-title">
            <i class="fa fa-tags"></i> Habilidades
          </h6>
          <div id="mrt-modal-skills" class="mrt-skills-tags"></div>
        </div>

        <div id="mrt-modal-criteria-wrap" class="mrt-modal-section" style="display:none;">
          <h6 class="mrt-modal-section-title">
            <i class="fa fa-list-check"></i> Criterios de obtención
          </h6>
          <ul id="mrt-modal-criteria" class="mrt-criteria-list"></ul>
        </div>

        <div id="mrt-modal-issuer-wrap" class="mrt-modal-section" style="display:none;">
          <h6 class="mrt-modal-section-title">
            <i class="fa fa-university"></i> Otorgada por
          </h6>
          <p id="mrt-modal-issuer" class="mb-0"></p>
        </div>

      </div>

      <!-- Footer -->
      <div class="modal-footer mrt-badge-modal-footer">
        <a id="mrt-modal-verify-btn" href="#" target="_blank"
           class="btn mrt-btn-verify">
          <i class="fa fa-check-circle me-1"></i> Verificar en blockchain
        </a>
        <a id="mrt-modal-pdf-btn" href="#"
           class="btn mrt-btn-pdf">
          <i class="fa fa-file-pdf me-1"></i> Descargar certificado PDF
        </a>
        <button type="button" class="btn btn-link mrt-btn-copy-link"
                id="mrt-modal-copy-link">
          <i class="fa fa-link me-1"></i> Copiar link
        </button>
      </div>

    </div>
  </div>
</div>

<script>
// ── Copiar wallet ──────────────────────────────────────────────────────────
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

// ── Copiar enlace de insignia ──────────────────────────────────────────────
document.querySelectorAll('.mrt-btn-copy-link[data-url]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var url = this.getAttribute('data-url');
        navigator.clipboard.writeText(url).then(function() {
            btn.innerHTML = '<i class="fa fa-check me-1"></i><?= get_string('badge_link_copied', 'local_meritcoin') ?>';
            setTimeout(function() {
                btn.innerHTML = '<i class="fa fa-link me-1"></i><?= get_string('badge_copy_link', 'local_meritcoin') ?>';
            }, 2000);
        });
    });
});

// ── Modal de insignia ──────────────────────────────────────────────────────
(function() {
    var modalEl = document.getElementById('mrt-badge-modal');
    var modal   = null;

    function openModal() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            if (!modal) modal = new bootstrap.Modal(modalEl);
            modal.show();
        } else if (typeof jQuery !== 'undefined') {
            jQuery('#mrt-badge-modal').modal('show');
        }
    }

    // Cierre explícito por botón de la X, para temas con Bootstrap viejo/jQuery
    var closeBtn = modalEl.querySelector('[data-bs-dismiss="modal"]');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal && modal) {
                modal.hide();
            } else if (typeof jQuery !== 'undefined') {
                jQuery('#mrt-badge-modal').modal('hide');
            }
        });
    }

    document.querySelectorAll('.mrt-badge-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var data;
            try { data = JSON.parse(this.getAttribute('data-badge')); }
            catch(e) { return; }

            // Título y fecha
            document.getElementById('mrt-badge-modal-title').textContent = data.name || 'Insignia';
            document.getElementById('mrt-modal-date').textContent =
                data.awarded_at ? 'Otorgada el ' + data.awarded_at : '';

            // Imagen o ícono fallback
            var img = document.getElementById('mrt-modal-img');
            var ico = document.getElementById('mrt-modal-icon-fallback');
            if (data.image_url) {
                img.onerror = function() {
                    img.style.display = 'none';
                    ico.querySelector('i').className = 'fa ' + (data.type_icon || 'fa-award');
                    ico.querySelector('i').style.color = data.type_color || '#f0c040';
                    ico.style.display = 'flex';
                };
                img.src = data.image_url;
                img.alt = data.name || '';
                img.style.display = 'block';
                ico.style.display = 'none';
            } else {
                ico.querySelector('i').className = 'fa ' + (data.type_icon || 'fa-award');
                ico.querySelector('i').style.color = data.type_color || '#f0c040';
                img.style.display = 'none';
                ico.style.display = 'flex';
            }

            // Descripción
            document.getElementById('mrt-modal-desc').textContent = data.description || '';
            document.getElementById('mrt-modal-desc-wrap').style.display =
                data.description ? '' : 'none';

            // Tipo de insignia
            var typeWrap = document.getElementById('mrt-modal-type-wrap');
            var typePill = document.getElementById('mrt-modal-type-pill');
            if (data.badge_type) {
                typePill.textContent  = data.badge_type;
                typePill.style.backgroundColor = data.type_color || '#f0c040';
                typePill.style.color = '#000';
                typeWrap.style.display = '';
            } else {
                typeWrap.style.display = 'none';
            }

            // Curso
            var courseWrap = document.getElementById('mrt-modal-course-wrap');
            if (data.course) {
                document.getElementById('mrt-modal-course').textContent = data.course;
                courseWrap.style.display = '';
            } else {
                courseWrap.style.display = 'none';
            }

            // Habilidades
            var skillsWrap = document.getElementById('mrt-modal-skills-wrap');
            var skillsEl   = document.getElementById('mrt-modal-skills');
            skillsEl.innerHTML = '';
            if (data.skills && data.skills.length > 0) {
                data.skills.forEach(function(skill) {
                    var tag = document.createElement('span');
                    tag.className = 'mrt-skill-tag';
                    tag.textContent = skill;
                    skillsEl.appendChild(tag);
                });
                skillsWrap.style.display = '';
            } else {
                skillsWrap.style.display = 'none';
            }

            // Criterios
            var criteriaWrap = document.getElementById('mrt-modal-criteria-wrap');
            var criteriaEl   = document.getElementById('mrt-modal-criteria');
            criteriaEl.innerHTML = '';
            if (data.criteria && data.criteria.length > 0) {
                data.criteria.forEach(function(c) {
                    var li = document.createElement('li');
                    li.textContent = c;
                    criteriaEl.appendChild(li);
                });
                criteriaWrap.style.display = '';
            } else {
                criteriaWrap.style.display = 'none';
            }

            // Emisor
            var issuerWrap = document.getElementById('mrt-modal-issuer-wrap');
            if (data.issued_by) {
                document.getElementById('mrt-modal-issuer').textContent = data.issued_by;
                issuerWrap.style.display = '';
            } else {
                issuerWrap.style.display = 'none';
            }

            // Botones
            var verifyBtn = document.getElementById('mrt-modal-verify-btn');
            var pdfBtn    = document.getElementById('mrt-modal-pdf-btn');
            var copyBtn   = document.getElementById('mrt-modal-copy-link');

            verifyBtn.href = data.verify_url || '#';
            verifyBtn.style.display = data.verify_url ? '' : 'none';

            pdfBtn.href = data.pdf_url || '#';
            pdfBtn.style.display = data.pdf_url ? '' : 'none';

            copyBtn.onclick = function() {
                var link = data.verify_url || window.location.href;
                navigator.clipboard.writeText(link).then(function() {
                    copyBtn.innerHTML = '<i class="fa fa-check me-1"></i> ¡Copiado!';
                    setTimeout(function() {
                        copyBtn.innerHTML = '<i class="fa fa-link me-1"></i> Copiar link';
                    }, 2000);
                });
            };

            openModal();
        });
    });
})();
</script>

<?php echo $OUTPUT->footer(); ?>