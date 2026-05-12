<?php
// Verificación pública de insignia por hash SHA-256
// Accesible sin login para compartir externamente

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

$hash = required_param('hash', PARAM_ALPHANUM);

$PAGE->set_url(new moodle_url('/local/meritcoin/badge_verify.php', ['hash' => $hash]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('badge_verify_title', 'local_meritcoin'));
$PAGE->set_heading(get_string('badge_verify_title', 'local_meritcoin'));
$PAGE->set_pagelayout('base');

global $DB, $OUTPUT;

// Buscar insignia por hash
$badge = $DB->get_record_sql(
    "SELECT b.*,
            bt.color        AS type_color,
            bt.icon         AS type_icon,
            bt.name         AS type_name,
            c.fullname      AS course_fullname,
            c.shortname     AS course_shortname,
            u.firstname     AS student_firstname,
            u.lastname      AS student_lastname,
            iss.firstname   AS issuer_firstname,
            iss.lastname    AS issuer_lastname
       FROM {local_meritcoin_badges}      b
  LEFT JOIN {local_meritcoin_badge_types} bt  ON bt.shortname = b.badge_type
  LEFT JOIN {course}                      c   ON c.id         = b.courseid
  LEFT JOIN {user}                        u   ON u.id         = b.userid
  LEFT JOIN {user}                        iss ON iss.id       = b.issued_by
      WHERE b.verify_hash = :hash",
    ['hash' => $hash]
);

$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

echo $OUTPUT->header();

if (!$badge) {
    echo html_writer::div(
        html_writer::tag('i', '', ['class' => 'fa fa-times-circle fa-4x text-danger mb-3 d-block']) .
        html_writer::tag('h3', get_string('badge_verify_invalid', 'local_meritcoin')) .
        html_writer::tag('p', get_string('badge_verify_invalid_desc', 'local_meritcoin'), ['class' => 'text-muted']),
        'text-center py-5'
    );
    echo $OUTPUT->footer();
    die;
}

$student_name  = s(trim($badge->student_firstname . ' ' . $badge->student_lastname));
$issuer_name   = s(trim($badge->issuer_firstname  . ' ' . $badge->issuer_lastname));
$awarded_date  = userdate($badge->timecreated, get_string('strftimedate', 'langconfig'));
$type_color    = s($badge->type_color  ?? '#f0c040');
$type_icon     = s($badge->type_icon   ?? 'fa-award');
$site_name = get_site()->fullname;
?>

<div class="container py-5" style="max-width:640px;">

  <!-- Tarjeta de verificación -->
  <div class="card shadow-sm border-0 text-center">

    <!-- Banner de estado verificado -->
    <div class="d-flex align-items-center justify-content-center gap-2 py-3 rounded-top"
         style="background:linear-gradient(135deg,#1a7f37,#2ea44f); color:#fff;">
      <i class="fa fa-check-circle fa-lg"></i>
      <strong><?= get_string('badge_verified', 'local_meritcoin') ?></strong>
    </div>

    <div class="card-body py-4">

      <!-- Ícono / imagen -->
      <div class="mb-3">
        <?php if (!empty($badge->image_url)): ?>
          <img src="<?= s($badge->image_url) ?>" alt="<?= s($badge->badge_name) ?>"
               width="96" height="96" loading="lazy"
               style="border-radius:16px; object-fit:contain;">
        <?php else: ?>
          <span style="font-size:4rem; display:block; line-height:1;">
            <i class="fa <?= $type_icon ?>" style="color:<?= $type_color ?>"></i>
          </span>
        <?php endif; ?>
      </div>

      <!-- Tipo pill -->
      <?php if (!empty($badge->badge_type)): ?>
        <div class="mb-2">
          <span class="badge rounded-pill px-3 py-1"
                style="background-color:<?= $type_color ?>; color:#000; font-size:0.8em;">
            <?= s($badge->type_name ?? $badge->badge_type) ?>
          </span>
        </div>
      <?php endif; ?>

      <!-- Nombre de la insignia -->
      <h2 class="fw-bold mb-1"><?= s($badge->badge_name) ?></h2>

      <!-- Estudiante -->
      <p class="text-muted mb-3">
        <i class="fa fa-user me-1"></i>
        <?= get_string('badge_awarded_to', 'local_meritcoin') ?>
        <strong><?= $student_name ?></strong>
      </p>

      <hr class="my-3">

      <!-- Metadatos en grid -->
      <div class="row g-3 text-start">

        <div class="col-6">
          <div class="mrt-meta-label text-muted small"><?= get_string('colcourse', 'local_meritcoin') ?></div>
          <div class="mrt-meta-value fw-semibold"><?= s($badge->course_fullname) ?></div>
        </div>

        <div class="col-6">
          <div class="mrt-meta-label text-muted small"><?= get_string('coldate', 'local_meritcoin') ?></div>
          <div class="mrt-meta-value fw-semibold"><?= $awarded_date ?></div>
        </div>

        <?php if ($issuer_name): ?>
        <div class="col-12">
          <div class="mrt-meta-label text-muted small"><?= get_string('badge_issued_by', 'local_meritcoin') ?></div>
          <div class="mrt-meta-value fw-semibold"><?= $issuer_name ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($badge->description)): ?>
        <div class="col-12">
          <div class="mrt-meta-label text-muted small"><?= get_string('badge_description', 'local_meritcoin') ?></div>
          <div class="mrt-meta-value"><?= s($badge->description) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($badge->criteria)): ?>
        <div class="col-12">
          <div class="mrt-meta-label text-muted small mb-1"><?= get_string('badge_criteria', 'local_meritcoin') ?></div>
          <ul class="mb-0 ps-3">
            <?php foreach (array_filter(array_map('trim', explode("\n", $badge->criteria))) as $c): ?>
              <li><?= s($c) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

      </div>

      <!-- Sello de autenticidad -->
      <div class="d-flex align-items-center justify-content-center gap-2 mt-3 mb-1"
          style="font-size:0.8rem; color:#555;">
        <i class="fa fa-university text-warning"></i>
        <span>
          Emitido por <strong>MeritCoin – <?= s($site_name ?? 'Plataforma Académica') ?></strong>
        </span>
      </div>
      <div class="text-muted" style="font-size:0.72em;">
        Registro: <?= userdate($badge->timecreated, '%d %b %Y, %H:%M') ?> UTC
      </div>

      <hr class="my-3">

      <!-- Hash colapsable -->
      <div class="text-center">
        <button class="btn btn-sm btn-link text-muted" type="button"
                id="mrt-toggle-hash"
                style="font-size:0.78em;">
          <i class="fa fa-fingerprint me-1"></i>
          Ver código de verificación
        </button>
        <div id="mrt-hash-block"
            style="display:none; margin-top:.5rem;
                    background:#f8f9fa; border-radius:6px;
                    padding:.5rem .75rem; font-size:0.68em;
                    word-break:break-all; color:#666;">
          <?= s($badge->verify_hash) ?>
        </div>
      </div>

    </div>

    <!-- Footer de la tarjeta -->
    <div class="card-footer bg-transparent border-top d-flex justify-content-center gap-2 py-3">
      <a href="<?= new moodle_url('/local/meritcoin/badge_pdf.php', ['hash' => $hash]) ?>"
         class="btn btn-sm mrt-btn-pdf" target="_blank">
        <i class="fa fa-file-pdf me-1"></i><?= get_string('badge_pdf_download', 'local_meritcoin') ?>
      </a>
      <button class="btn btn-sm btn-outline-secondary"
              onclick="navigator.clipboard.writeText(window.location.href).then(function(){
                  var b=this;
              });" id="mrt-copy-verify-link">
        <i class="fa fa-link me-1"></i><?= get_string('badge_copy_link', 'local_meritcoin') ?>
      </button>
    </div>

  </div>

</div>

<script>
document.getElementById('mrt-copy-verify-link').addEventListener('click', function() {
    navigator.clipboard.writeText(window.location.href).then(function() {
        var btn = document.getElementById('mrt-copy-verify-link');
        btn.innerHTML = '<i class="fa fa-check me-1"></i><?= get_string('badge_link_copied', 'local_meritcoin') ?>';
        setTimeout(function() {
            btn.innerHTML = '<i class="fa fa-link me-1"></i><?= get_string('badge_copy_link', 'local_meritcoin') ?>';
        }, 2000);
    });
});

// Toggle hash
var toggleBtn  = document.getElementById('mrt-toggle-hash');
var hashBlock  = document.getElementById('mrt-hash-block');
if (toggleBtn && hashBlock) {
    toggleBtn.addEventListener('click', function() {
        var visible = hashBlock.style.display !== 'none';
        hashBlock.style.display = visible ? 'none' : 'block';
        toggleBtn.innerHTML = visible
            ? '<i class="fa fa-fingerprint me-1"></i> Ver código de verificación'
            : '<i class="fa fa-eye-slash me-1"></i> Ocultar código';
    });
}
</script>

<?php echo $OUTPUT->footer(); ?>);