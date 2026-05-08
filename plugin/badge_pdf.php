<?php
// Genera y descarga un certificado PDF de insignia
// Usa HTML+CSS imprimible con window.print() — sin dependencias externas

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

$hash = required_param('hash', PARAM_ALPHANUM);

// Verificar que la insignia existe
global $DB, $CFG;

$badge = $DB->get_record_sql(
    "SELECT b.*,
            bt.color        AS type_color,
            bt.icon         AS type_icon,
            bt.name         AS type_name,
            c.fullname      AS course_fullname,
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

if (!$badge) {
    throw new moodle_exception('invalidhash', 'local_meritcoin');
}

$student_name  = trim($badge->student_firstname . ' ' . $badge->student_lastname);
$issuer_name   = trim($badge->issuer_firstname  . ' ' . $badge->issuer_lastname);
$awarded_date  = userdate($badge->timecreated, get_string('strftimedate', 'langconfig'));
$verify_url    = $CFG->wwwroot . '/local/meritcoin/badge_verify.php?hash=' . urlencode($badge->verify_hash);
$type_color    = $badge->type_color  ?? '#f0c040';
$type_name     = $badge->type_name   ?? $badge->badge_type;
$site_name     = get_site()->fullname;

// Sin layout Moodle — página standalone para imprimir/guardar como PDF
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars(get_string('badge_certificate_title', 'local_meritcoin')) ?> — <?= htmlspecialchars($badge->badge_name) ?></title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;500;600&display=swap');

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', sans-serif;
      background: #f5f4f0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
    }

    .certificate {
      background: #fff;
      width: 794px;          /* A4 horizontal */
      min-height: 560px;
      border-radius: 16px;
      box-shadow: 0 8px 40px rgba(0,0,0,0.12);
      overflow: hidden;
      position: relative;
    }

    /* Franja superior de color */
    .cert-top-bar {
      height: 12px;
      background: <?= htmlspecialchars($type_color) ?>;
    }

    .cert-body {
      padding: 3rem 3.5rem 2.5rem;
      text-align: center;
    }

    /* Logo / ícono */
    .cert-icon {
      font-size: 5rem;
      line-height: 1;
      color: <?= htmlspecialchars($type_color) ?>;
      margin-bottom: 1.5rem;
    }
    .cert-icon img {
      width: 100px;
      height: 100px;
      object-fit: contain;
      border-radius: 12px;
    }

    /* Encabezado */
    .cert-eyebrow {
      font-size: 0.75rem;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: #888;
      margin-bottom: 0.5rem;
    }
    .cert-title {
      font-family: 'Playfair Display', Georgia, serif;
      font-size: 2.4rem;
      color: #1a1a1a;
      line-height: 1.15;
      margin-bottom: 0.5rem;
    }
    .cert-type-pill {
      display: inline-block;
      background: <?= htmlspecialchars($type_color) ?>;
      color: #000;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.25rem 0.9rem;
      border-radius: 9999px;
      margin-bottom: 1.5rem;
    }

    /* Estudiante */
    .cert-awarded-to {
      font-size: 0.85rem;
      color: #666;
      margin-bottom: 0.25rem;
    }
    .cert-student {
      font-size: 1.6rem;
      font-weight: 700;
      color: #1a1a1a;
      margin-bottom: 1.5rem;
    }

    /* Descripción */
    .cert-description {
      font-size: 0.92rem;
      color: #555;
      max-width: 520px;
      margin: 0 auto 1.5rem;
      line-height: 1.6;
    }

    /* Metadatos en fila */
    .cert-meta {
      display: flex;
      justify-content: center;
      gap: 3rem;
      margin-bottom: 2rem;
      padding: 1.25rem 0;
      border-top: 1px solid #e8e8e8;
      border-bottom: 1px solid #e8e8e8;
    }
    .cert-meta-item { text-align: center; }
    .cert-meta-label {
      font-size: 0.7rem;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: #999;
      margin-bottom: 0.2rem;
    }
    .cert-meta-value {
      font-size: 0.9rem;
      font-weight: 600;
      color: #222;
    }

    /* Firma / emisor */
    .cert-issuer {
      margin-bottom: 1.5rem;
    }
    .cert-issuer-line {
      width: 200px;
      height: 1px;
      background: #ccc;
      margin: 0 auto 0.4rem;
    }
    .cert-issuer-name {
      font-size: 0.85rem;
      font-weight: 600;
      color: #333;
    }
    .cert-issuer-label {
      font-size: 0.72rem;
      color: #999;
    }

    /* Footer hash + QR hint */
    .cert-footer {
      background: #f9f8f5;
      padding: 0.75rem 3.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.68rem;
      color: #aaa;
      word-break: break-all;
    }
    .cert-footer a {
      color: #666;
      text-decoration: none;
    }
    .cert-site {
      font-weight: 600;
      white-space: nowrap;
      margin-right: 1rem;
    }

    /* Botón imprimir — desaparece al imprimir */
    .print-btn-wrap {
      text-align: center;
      margin-top: 1.5rem;
    }
    .print-btn {
      background: #1a1a1a;
      color: #fff;
      border: none;
      padding: 0.6rem 1.8rem;
      border-radius: 8px;
      font-size: 0.9rem;
      cursor: pointer;
      font-family: inherit;
    }
    .print-btn:hover { background: #333; }

    @media print {
      body { background: #fff; padding: 0; }
      .certificate { box-shadow: none; border-radius: 0; width: 100%; }
      .print-btn-wrap { display: none; }
    }
  </style>
</head>
<body>

  <div>
    <div class="certificate">

      <div class="cert-top-bar"></div>

      <div class="cert-body">

        <!-- Ícono -->
        <div class="cert-icon">
          <?php if (!empty($badge->image_url)): ?>
            <img src="<?= htmlspecialchars($badge->image_url) ?>"
                 alt="<?= htmlspecialchars($badge->badge_name) ?>">
          <?php else: ?>
            <i class="fa fa-award"></i>
          <?php endif; ?>
        </div>

        <div class="cert-eyebrow"><?= htmlspecialchars(get_string('badge_certificate_of', 'local_meritcoin')) ?></div>
        <h1 class="cert-title"><?= htmlspecialchars($badge->badge_name) ?></h1>

        <?php if ($type_name): ?>
          <div class="cert-type-pill"><?= htmlspecialchars($type_name) ?></div>
        <?php endif; ?>

        <div class="cert-awarded-to"><?= htmlspecialchars(get_string('badge_awarded_to', 'local_meritcoin')) ?></div>
        <div class="cert-student"><?= htmlspecialchars($student_name) ?></div>

        <?php if (!empty($badge->description)): ?>
          <p class="cert-description"><?= htmlspecialchars($badge->description) ?></p>
        <?php endif; ?>

        <div class="cert-meta">
          <div class="cert-meta-item">
            <div class="cert-meta-label"><?= htmlspecialchars(get_string('colcourse', 'local_meritcoin')) ?></div>
            <div class="cert-meta-value"><?= htmlspecialchars($badge->course_fullname) ?></div>
          </div>
          <div class="cert-meta-item">
            <div class="cert-meta-label"><?= htmlspecialchars(get_string('coldate', 'local_meritcoin')) ?></div>
            <div class="cert-meta-value"><?= htmlspecialchars($awarded_date) ?></div>
          </div>
          <div class="cert-meta-item">
            <div class="cert-meta-label"><?= htmlspecialchars(get_string('badge_issued_by', 'local_meritcoin')) ?></div>
            <div class="cert-meta-value"><?= htmlspecialchars($issuer_name ?: '—') ?></div>
          </div>
        </div>

        <?php if ($issuer_name): ?>
          <div class="cert-issuer">
            <div class="cert-issuer-line"></div>
            <div class="cert-issuer-name"><?= htmlspecialchars($issuer_name) ?></div>
            <div class="cert-issuer-label"><?= htmlspecialchars(get_string('badge_issuer_role', 'local_meritcoin')) ?></div>
          </div>
        <?php endif; ?>

      </div>

      <div class="cert-footer">
        <span class="cert-site"><?= htmlspecialchars($site_name) ?></span>
        <span>
          <strong><?= htmlspecialchars(get_string('badge_hash', 'local_meritcoin')) ?>:</strong>
          <a href="<?= htmlspecialchars($verify_url) ?>" target="_blank">
            <?= htmlspecialchars(substr($badge->verify_hash, 0, 20) . '...') ?>
          </a>
        </span>
      </div>

    </div>

    <div class="print-btn-wrap">
      <button class="print-btn" onclick="window.print()">
        ⬇ <?= htmlspecialchars(get_string('badge_pdf_download', 'local_meritcoin')) ?>
      </button>
    </div>
  </div>

  <!-- FontAwesome para el ícono fallback -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer">

</body>
</html>
<?php
// No llamar a $OUTPUT->footer() — página standalone sin layout Moodle
die;
?>