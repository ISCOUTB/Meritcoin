<?php
// Panel del profesor para otorgar insignias a estudiantes del curso.
// Acceso: editingteacher, teacher, manager, admin.

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);      // 'award' | 'revoke'
$badgeid  = optional_param('badgeid', 0,  PARAM_INT);
$userid   = optional_param('userid',  0,  PARAM_INT);
$confirm  = optional_param('confirm', 0,  PARAM_INT);

// ── Contexto y permisos ───────────────────────────────────────────────────────
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/meritcoin:awardbadges', $context);

$PAGE->set_url(new moodle_url('/local/meritcoin/badge_award.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('badge_award_title', 'local_meritcoin'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

// ── Acciones POST ─────────────────────────────────────────────────────────────
if ($action === 'award' && $badgeid && $userid && $confirm && confirm_sesskey()) {

    // Solo se puede otorgar usando tipos habilitados
    $badge_type = $DB->get_record('local_meritcoin_badge_types',
        ['id' => $badgeid, 'enabled' => 1],
        '*',
        MUST_EXIST
    );

    // EXTRA: si el tipo es de sistema y el profesor no tiene la capability especial, lo bloqueamos
    $syscontext = context_system::instance();
    $typesconditions = ['enabled' => 1];
    if (!has_capability('moodle/site:config', $syscontext)) {
        $typesconditions['is_system'] = 0;
    }

    $student = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

    // Verificar que el estudiante está en el curso
    if (!is_enrolled($context, $student)) {
        throw new moodle_exception('usernotenrolled', 'local_meritcoin');
    }

    // Verificar que no tiene ya esta insignia en este curso
    $already = $DB->record_exists('local_meritcoin_badges', [
        'userid'     => $userid,
        'courseid'   => $courseid,
        'badge_type' => $badge_type->shortname,
    ]);

    if (!$already) {
        $record              = new stdClass();
        $record->userid      = $userid;
        $record->courseid    = $courseid;
        $record->badge_type  = $badge_type->shortname;
        $record->badge_name  = $badge_type->name;
        $record->description = $badge_type->description;
        $record->criteria    = $badge_type->criteria ?? '';
        $record->image_url   = $badge_type->imageurl ?? ''; // usa imageurl del tipo
        $record->issued_by   = $USER->id;
        $record->timecreated = time();
        $record->verify_hash = hash(
            'sha256',
            $userid . $courseid . $badge_type->shortname . time() . random_bytes(16)
        );
        $DB->insert_record('local_meritcoin_badges', $record);
    }

    redirect(
        new moodle_url('/local/meritcoin/badge_award.php', ['courseid' => $courseid]),
        $already
            ? get_string('badge_already_has', 'local_meritcoin')
            : get_string('badge_awarded_ok',  'local_meritcoin'),
        null,
        $already
            ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'revoke' && $badgeid && $userid && $confirm && confirm_sesskey()) {
    $DB->delete_records('local_meritcoin_badges', [
        'id'       => $badgeid,
        'courseid' => $courseid,
    ]);
    redirect(
        new moodle_url('/local/meritcoin/badge_award.php', ['courseid' => $courseid]),
        get_string('badge_revoked_ok', 'local_meritcoin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Datos para la vista ───────────────────────────────────────────────────────

// Tipos de insignia disponibles
// Filtro: solo tipos habilitados y, para profesores normales, que NO sean de sistema.
$typesconditions = ['enabled' => 1];
$syscontext      = context_system::instance();

if (!has_capability('local/meritcoin:manage_badge_types_system', $syscontext)) {
    $typesconditions['is_system'] = 0;
}
$badge_types = $DB->get_records('local_meritcoin_badge_types', $typesconditions, 'sortorder ASC'); // [cite:9]

// Estudiantes del curso (rol student)
$students = get_enrolled_users(
    $context,
    'mod/assign:submit',
    0,
    'u.id, u.firstname, u.lastname, u.email, u.picture, u.imagealt',
    'u.lastname ASC'
);

// Insignias ya otorgadas en este curso
$awarded = $DB->get_records_sql(
    "SELECT b.*, bt.color AS type_color, bt.icon AS type_icon,
            u.firstname, u.lastname
       FROM {local_meritcoin_badges}      b
  LEFT JOIN {local_meritcoin_badge_types} bt ON bt.shortname = b.badge_type
  LEFT JOIN {user}                        u  ON u.id = b.userid
      WHERE b.courseid = :cid
   ORDER BY b.timecreated DESC",
    ['cid' => $courseid]
);

// Índice de insignias por userid → [badge_type => badge_id] para saber cuáles ya tiene
$awarded_index = [];
foreach ($awarded as $aw) {
    $awarded_index[$aw->userid][$aw->badge_type] = $aw->id;
}

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>

<div class="mrt-container" style="max-width:960px; margin:0 auto; padding:1.5rem 1rem;">

  <!-- Encabezado de página -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
      <h2 class="mb-1" style="font-size:1.4rem; font-weight:700;">
        <i class="fa fa-medal me-2" style="color:#f0c040;"></i>
        <?= get_string('badge_award_title', 'local_meritcoin') ?>
      </h2>
      <div class="text-muted" style="font-size:0.85rem;">
        <?= format_string($course->fullname) ?>
      </div>
    </div>
    <!-- PASO 7: navegación circular rewards ↔ badge_award -->
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= new moodle_url('/local/meritcoin/rewards.php', ['courseid' => $courseid]) ?>"
         class="btn btn-sm btn-outline-primary">
        <i class="fa fa-gift me-1"></i><?= get_string('rewardstitle', 'local_meritcoin') ?>
      </a>
      <a href="<?= new moodle_url('/course/view.php', ['id' => $courseid]) ?>"
         class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i><?= get_string('backtocourse', 'local_meritcoin') ?>
      </a>
    </div>
  </div>

  <?php if (empty($badge_types)): ?>
    <!-- Sin tipos de insignia configurados -->
    <div class="alert alert-warning d-flex align-items-center gap-2">
      <i class="fa fa-exclamation-triangle"></i>
      <span><?= get_string('badge_no_types_warning', 'local_meritcoin') ?></span>
    </div>
  <?php else: ?>

  <!-- ── Formulario: otorgar insignia ──────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold py-3">
      <i class="fa fa-plus-circle me-2 text-success"></i>
      <?= get_string('badge_award_new', 'local_meritcoin') ?>
    </div>
    <div class="card-body py-3">
      <form method="post"
            action="<?= new moodle_url('/local/meritcoin/badge_award.php', ['courseid' => $courseid]) ?>"
            id="mrt-award-form">
        <?= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) ?>
        <?= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'award']) ?>
        <?= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => '1']) ?>
        <?= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid','value' => $courseid]) ?>

        <div class="row g-3 align-items-end">

          <!-- Selector de estudiante -->
          <div class="col-12 col-md-5">
            <label for="mrt-select-student" class="form-label fw-semibold small">
              <?= get_string('badge_award_student', 'local_meritcoin') ?>
            </label>
            <select name="userid" id="mrt-select-student" class="form-select" required>
              <option value="">— <?= get_string('badge_award_select_student', 'local_meritcoin') ?> —</option>
              <?php foreach ($students as $s): ?>
                <option value="<?= (int)$s->id ?>">
                  <?= s(fullname($s)) ?> &lt;<?= s($s->email) ?>&gt;
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Selector de tipo de insignia -->
          <div class="col-12 col-md-5">
            <label for="mrt-select-badge" class="form-label fw-semibold small">
              <?= get_string('badge_award_type', 'local_meritcoin') ?>
            </label>
            <select name="badgeid" id="mrt-select-badge" class="form-select" required>
              <option value="">— <?= get_string('badge_award_select_type', 'local_meritcoin') ?> —</option>
              <?php foreach ($badge_types as $bt): ?>
                <option value="<?= (int)$bt->id ?>"
                        data-color="<?= s($bt->color) ?>"
                        data-icon="<?= s($bt->icon) ?>">
                  <?= s($bt->name) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Botón -->
          <div class="col-12 col-md-2">
            <button type="submit" class="btn btn-success w-100">
              <i class="fa fa-award me-1"></i>
              <?= get_string('badge_award_btn', 'local_meritcoin') ?>
            </button>
          </div>

        </div>
      </form>
    </div>
  </div>

  <?php endif; ?>

  <!-- ── Tabla: insignias otorgadas en este curso ───────────────────────────── -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold py-3 d-flex align-items-center justify-content-between">
      <span>
        <i class="fa fa-list me-2"></i>
        <?= get_string('badge_awarded_list', 'local_meritcoin') ?>
      </span>
      <span class="badge bg-secondary rounded-pill"><?= count($awarded) ?></span>
    </div>

    <?php if (empty($awarded)): ?>
      <div class="card-body text-center text-muted py-5">
        <i class="fa fa-medal fa-3x mb-3 d-block" style="opacity:.3;"></i>
        <?= get_string('badge_none_awarded_yet', 'local_meritcoin') ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th><?= get_string('badge_col_badge',    'local_meritcoin') ?></th>
            <th><?= get_string('badge_col_student',  'local_meritcoin') ?></th>
            <th><?= get_string('coldate',            'local_meritcoin') ?></th>
            <th><?= get_string('badge_col_verify',   'local_meritcoin') ?></th>
            <th class="text-end"><?= get_string('rewardactions', 'local_meritcoin') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($awarded as $aw):
            $color   = s($aw->type_color ?? '#f0c040');
            $icon    = s($aw->type_icon  ?? 'fa-award');
            $sname   = s(trim($aw->firstname . ' ' . $aw->lastname));
            $date    = userdate($aw->timecreated, get_string('strftimedate', 'langconfig'));
            $vurl    = new moodle_url('/local/meritcoin/badge_verify.php', ['hash' => $aw->verify_hash]);
            $rurl    = new moodle_url('/local/meritcoin/badge_award.php', [
                'courseid' => $courseid,
                'action'   => 'revoke',
                'badgeid'  => $aw->id,
                'userid'   => $aw->userid,
                'confirm'  => 1,
                'sesskey'  => sesskey(),
            ]);
        ?>
          <tr>
            <!-- Nombre + pill -->
            <td>
              <div class="d-flex align-items-center gap-2">
                <span style="font-size:1.4rem; color:<?= $color ?>;">
                  <i class="fa <?= $icon ?>"></i>
                </span>
                <div>
                  <div class="fw-semibold" style="font-size:0.9rem;"><?= s($aw->badge_name) ?></div>
                  <span class="badge rounded-pill px-2 py-0"
                        style="background:<?= $color ?>; color:#000; font-size:0.7em;">
                    <?= s($aw->badge_type) ?>
                  </span>
                </div>
              </div>
            </td>

            <!-- Estudiante -->
            <td style="font-size:0.9rem;"><?= $sname ?></td>

            <!-- Fecha -->
            <td style="font-size:0.85rem; color:#666;"><?= $date ?></td>

            <!-- Enlace verificación -->
            <td>
              <a href="<?= $vurl ?>" target="_blank"
                 class="btn btn-xs btn-outline-secondary py-0 px-2"
                 style="font-size:0.75rem;">
                <i class="fa fa-external-link-alt me-1"></i><?= get_string('verify', 'local_meritcoin') ?>
              </a>
            </td>

            <!-- Revocar -->
            <td class="text-end">
              <a href="<?= $rurl ?>"
                 class="btn btn-xs btn-outline-danger py-0 px-2"
                 style="font-size:0.75rem;"
                 onclick="return confirm('<?= get_string('badge_revoke_confirm', 'local_meritcoin') ?>')">
                <i class="fa fa-trash me-1"></i><?= get_string('badge_revoke_btn', 'local_meritcoin') ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php echo $OUTPUT->footer(); ?>