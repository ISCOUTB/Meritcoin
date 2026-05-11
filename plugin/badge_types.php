<?php
// Administración de tipos de insignia (CRUD).
// Acceso: manager, admin.

require_once('../../config.php');
require_once($CFG->dirroot . '/local/meritcoin/lib.php');

$action  = optional_param('action',  '', PARAM_ALPHANUMEXT);
$typeid  = optional_param('typeid',  0,   PARAM_INT);
$confirm = optional_param('confirm', 0,   PARAM_INT);

// ── Contexto y permisos ───────────────────────────────────────────────────────
$context = context_system::instance();
require_login();
$is_admin = has_capability('moodle/site:config', $context);
$is_teacher = !$is_admin && local_meritcoin_user_has_teacher_role();
if (!$is_admin && !$is_teacher) {
    throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
}

$PAGE->set_url(new moodle_url('/local/meritcoin/badge_types.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('badge_types_title', 'local_meritcoin'));
$PAGE->set_heading(get_string('badge_types_title', 'local_meritcoin'));
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/meritcoin/styles/dashboard.css'));

// ── Acciones POST ─────────────────────────────────────────────────────────────

// Eliminar
if ($action === 'delete' && $typeid && $confirm && confirm_sesskey()) {
    $DB->delete_records('local_meritcoin_badge_types', ['id' => $typeid]);
    redirect(
        new moodle_url('/local/meritcoin/badge_types.php'),
        get_string('badge_type_deleted', 'local_meritcoin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Activar / desactivar
if ($action === 'toggle' && $typeid && confirm_sesskey()) {
    $rec = $DB->get_record('local_meritcoin_badge_types', ['id' => $typeid], '*', MUST_EXIST);
    $DB->set_field('local_meritcoin_badge_types', 'enabled', $rec->enabled ? 0 : 1, ['id' => $typeid]);
    redirect(
        new moodle_url('/local/meritcoin/badge_types.php'),
        get_string('badge_type_toggled', 'local_meritcoin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Guardar (crear o editar)

// Crear nuevo tipo
if ($action === 'save_new' && confirm_sesskey()) {
    $rec               = new stdClass();
    $rec->name         = required_param('name',       PARAM_TEXT);
    $rec->shortname    = required_param('shortname',  PARAM_ALPHANUMEXT);
    $rec->description  = optional_param('description','',  PARAM_TEXT);
    $rec->criteria     = optional_param('criteria',   '',  PARAM_TEXT);
    $rec->color        = optional_param('color',      '#f0c040', PARAM_TEXT);
    $rec->icon         = optional_param('icon',       'fa-award', PARAM_TEXT);
    $rec->imageurl     = optional_param('imageurl',   '',  PARAM_URL);
    $rec->sortorder    = optional_param('sortorder',  0,   PARAM_INT);
    $rec->enabled      = optional_param('enabled',    0,   PARAM_INT);
    $rec->createdby    = $USER->id;
    $rec->timecreated  = time();

    // is_system solo lo puede tocar quien tenga la capability especial
    $rec->is_system = has_capability('local/meritcoin:manage_badge_types_system', $context)
        ? optional_param('is_system', 0, PARAM_INT)
        : 0;

    if ($DB->record_exists('local_meritcoin_badge_types', ['shortname' => $rec->shortname])) {
        redirect(
            new moodle_url('/local/meritcoin/badge_types.php', ['action' => 'new']),
            get_string('badge_type_shortname_exists', 'local_meritcoin'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $DB->insert_record('local_meritcoin_badge_types', $rec);
    redirect(
        new moodle_url('/local/meritcoin/badge_types.php'),
        get_string('badge_type_created', 'local_meritcoin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Editar tipo existente
if ($action === 'save_edit' && $typeid && confirm_sesskey()) {
    $rec              = $DB->get_record('local_meritcoin_badge_types', ['id' => $typeid], '*', MUST_EXIST);
    $rec->name        = required_param('name',       PARAM_TEXT);
    $rec->description = optional_param('description','',  PARAM_TEXT);
    $rec->criteria    = optional_param('criteria',   '',  PARAM_TEXT);
    $rec->color       = optional_param('color',      '#f0c040', PARAM_TEXT);
    $rec->icon        = optional_param('icon',       'fa-award', PARAM_TEXT);
    $rec->imageurl    = optional_param('imageurl',   '',  PARAM_URL);
    $rec->sortorder   = optional_param('sortorder',  0,   PARAM_INT);
    $rec->enabled     = optional_param('enabled',    0,   PARAM_INT);

    // shortname solo editable si no es sistema
    if (!$rec->is_system) {
        $rec->shortname = required_param('shortname', PARAM_ALPHANUMEXT);
    }

    // is_system solo editable por quien tenga la capability
    if (has_capability('local/meritcoin:manage_badge_types_system', $context)) {
        $rec->is_system = optional_param('is_system', $rec->is_system, PARAM_INT);
    }

    $DB->update_record('local_meritcoin_badge_types', $rec);
    redirect(
        new moodle_url('/local/meritcoin/badge_types.php'),
        get_string('badge_type_updated', 'local_meritcoin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Datos ─────────────────────────────────────────────────────────────────────
// Filtro: si no tiene la capability especial, no ve tipos de sistema
$conditions = [];
if (!has_capability('local/meritcoin:manage_badge_types_system', $context)) {
    $conditions['is_system'] = 0;
}
$types = $DB->get_records('local_meritcoin_badge_types', $conditions, 'sortorder ASC, id ASC'); // [cite:9]

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>

<div class="mrt-container" style="max-width:960px; margin:0 auto; padding:1.5rem 1rem;">

  <!-- Encabezado -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
      <h2 class="mb-1" style="font-size:1.4rem; font-weight:700;">
        <i class="fa fa-tags me-2" style="color:#f0c040;"></i>
        <?= get_string('badge_types_title', 'local_meritcoin') ?>
      </h2>
      <div class="text-muted" style="font-size:0.85rem;">
        <?= get_string('badge_types_desc', 'local_meritcoin') ?>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= new moodle_url('/local/meritcoin/badge_types.php', ['action' => 'new']) ?>"
         class="btn btn-sm btn-success">
        <i class="fa fa-plus me-1"></i><?= get_string('badge_type_new', 'local_meritcoin') ?>
      </a>
      <a href="<?= new moodle_url('/admin/settings.php', ['section' => 'local_meritcoin']) ?>"
         class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-cog me-1"></i><?= get_string('pluginsettings', 'local_meritcoin') ?>
      </a>
    </div>
  </div>

  <?php if ($action === 'new' || ($action === 'edit' && $typeid)): ?>
  <!-- Formulario crear / editar -->
  <?php
    $editing = ($action === 'edit' && $typeid)
        ? $DB->get_record('local_meritcoin_badge_types', ['id' => $typeid], '*', MUST_EXIST)
        : null;
  ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold py-3">
      <i class="fa fa-<?= $editing ? 'edit' : 'plus-circle' ?> me-2 text-<?= $editing ? 'primary' : 'success' ?>"></i>
      <?= $editing ? get_string('badge_type_edit', 'local_meritcoin') : get_string('badge_type_new', 'local_meritcoin') ?>
    </div>
    <div class="card-body py-3">
      <form method="post" action="<?= new moodle_url('/local/meritcoin/badge_types.php') ?>">
        <?= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) ?>
        <?= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => $editing ? 'save_edit' : 'save_new']) ?>
        <?php if ($editing): ?>
          <?= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'typeid', 'value' => $editing->id]) ?>
        <?php endif; ?>

        <div class="row g-3">

          <!-- Nombre -->
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold small"><?= get_string('badge_type_name', 'local_meritcoin') ?> *</label>
            <input type="text" name="name" class="form-control"
                   value="<?= s($editing->name ?? '') ?>" required maxlength="100">
          </div>

          <!-- Shortname -->
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold small"><?= get_string('badge_type_shortname', 'local_meritcoin') ?> *</label>
            <input type="text" name="shortname" class="form-control"
                   value="<?= s($editing->shortname ?? '') ?>"
                   required maxlength="50"
                   <?= $editing && $editing->is_system ? 'readonly' : '' ?>>
            <div class="form-text"><?= get_string('badge_type_shortname_help', 'local_meritcoin') ?></div>
          </div>

          <!-- Descripción -->
          <div class="col-12">
            <label class="form-label fw-semibold small"><?= get_string('badge_type_description', 'local_meritcoin') ?></label>
            <textarea name="description" class="form-control" rows="2"><?= s($editing->description ?? '') ?></textarea>
          </div>

          <!-- Criterios -->
          <div class="col-12">
            <label class="form-label fw-semibold small"><?= get_string('badge_type_criteria', 'local_meritcoin') ?></label>
            <textarea name="criteria" class="form-control" rows="2"><?= s($editing->criteria ?? '') ?></textarea>
          </div>

          <!-- Color -->
          <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small"><?= get_string('badge_type_color', 'local_meritcoin') ?></label>
            <input type="color" name="color" class="form-control form-control-color"
                   value="<?= s($editing->color ?? '#f0c040') ?>">
          </div>

          <!-- Icono -->
          <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small"><?= get_string('badge_type_icon', 'local_meritcoin') ?></label>
            <input type="text" name="icon" class="form-control"
                   value="<?= s($editing->icon ?? 'fa-award') ?>" maxlength="50"
                   placeholder="fa-award">
            <div class="form-text">FontAwesome class</div>
          </div>

          <!-- Image URL -->
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold small"><?= get_string('badge_type_image_url', 'local_meritcoin') ?></label>
            <input type="url" name="imageurl" class="form-control"
                   value="<?= s($editing->imageurl ?? '') ?>" maxlength="500">
          </div>

          <!-- Orden -->
          <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small"><?= get_string('badge_type_sortorder', 'local_meritcoin') ?></label>
            <input type="number" name="sortorder" class="form-control"
                   value="<?= (int)($editing->sortorder ?? 0) ?>" min="0">
          </div>

          <!-- Habilitado -->
          <div class="col-6 col-md-3 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="enabled" value="1" id="chk-enabled"
                     <?= (!$editing || $editing->enabled) ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold small" for="chk-enabled">
                <?= get_string('badge_type_enabled', 'local_meritcoin') ?>
              </label>
            </div>
          </div>

          <!-- Solo admin/manager: marcar como tipo de sistema -->
          <?php if (has_capability('local/meritcoin:manage_badge_types_system', $context)): ?>
          <div class="col-12 col-md-3 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="is_system" value="1" id="chk-is-system"
                     <?= (!empty($editing) && !empty($editing->is_system)) ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold small" for="chk-is-system">
                <?= get_string('badge_type_is_system', 'local_meritcoin') ?>
              </label>
            </div>
          </div>
          <?php endif; ?>

        </div><!-- row -->

        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-primary">
            <i class="fa fa-save me-1"></i><?= get_string('savechanges') ?>
          </button>
          <a href="<?= new moodle_url('/local/meritcoin/badge_types.php') ?>"
             class="btn btn-outline-secondary">
            <?= get_string('cancel') ?>
          </a>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tabla de tipos -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold py-3 d-flex align-items-center justify-content-between">
      <span><i class="fa fa-list me-2"></i><?= get_string('badge_types_list', 'local_meritcoin') ?></span>
      <span class="badge bg-secondary rounded-pill"><?= count($types) ?></span>
    </div>

    <?php if (empty($types)): ?>
      <div class="card-body text-center text-muted py-5">
        <i class="fa fa-tags fa-3x mb-3 d-block" style="opacity:.3;"></i>
        <?= get_string('badge_types_empty', 'local_meritcoin') ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th><?= get_string('badge_type_name',      'local_meritcoin') ?></th>
            <th><?= get_string('badge_type_shortname', 'local_meritcoin') ?></th>
            <th><?= get_string('badge_type_color',     'local_meritcoin') ?></th>
            <th><?= get_string('badge_type_sortorder', 'local_meritcoin') ?></th>
            <th><?= get_string('rules_table_status',   'local_meritcoin') ?></th>
            <th class="text-end"><?= get_string('rewardactions', 'local_meritcoin') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($types as $t):
            $toggleurl = new moodle_url('/local/meritcoin/badge_types.php', [
                'action'  => 'toggle',
                'typeid'  => $t->id,
                'sesskey' => sesskey(),
            ]);
            $editurl = new moodle_url('/local/meritcoin/badge_types.php', [
                'action' => 'edit',
                'typeid' => $t->id,
            ]);
            $delurl = new moodle_url('/local/meritcoin/badge_types.php', [
                'action'  => 'delete',
                'typeid'  => $t->id,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]);
        ?>
          <tr>
            <!-- Nombre + icono -->
            <td>
              <div class="d-flex align-items-center gap-2">
                <span style="font-size:1.3rem; color:<?= s($t->color) ?>;">
                  <i class="fa <?= s($t->icon) ?>"></i>
                </span>
                <div>
                  <div class="fw-semibold" style="font-size:0.9rem;"><?= s($t->name) ?></div>
                  <?php if ($t->is_system): ?>
                    <span class="badge bg-info text-dark" style="font-size:0.65em;">system</span>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <!-- Shortname -->
            <td><code style="font-size:0.8rem;"><?= s($t->shortname) ?></code></td>

            <!-- Color pill -->
            <td>
              <span class="badge rounded-pill px-3"
                    style="background:<?= s($t->color) ?>; color:#000;">
                <?= s($t->color) ?>
              </span>
            </td>

            <!-- Orden -->
            <td><?= (int)$t->sortorder ?></td>

            <!-- Estado -->
            <td>
              <?php if ($t->enabled): ?>
                <span class="badge bg-success"><?= get_string('rule_enabled',  'local_meritcoin') ?></span>
              <?php else: ?>
                <span class="badge bg-secondary"><?= get_string('rule_disabled', 'local_meritcoin') ?></span>
              <?php endif; ?>
            </td>

            <!-- Acciones -->
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end flex-wrap">
                <a href="<?= $editurl ?>" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size:0.75rem;">
                  <i class="fa fa-edit me-1"></i><?= get_string('edit') ?>
                </a>
                <a href="<?= $toggleurl ?>" class="btn btn-xs btn-outline-warning py-0 px-2" style="font-size:0.75rem;">
                  <i class="fa fa-power-off me-1"></i>
                  <?= $t->enabled ? get_string('rewarddeactivate', 'local_meritcoin') : get_string('rewardactivate', 'local_meritcoin') ?>
                </a>
                <?php if (!$t->is_system): ?>
                <a href="<?= $delurl ?>"
                   class="btn btn-xs btn-outline-danger py-0 px-2" style="font-size:0.75rem;"
                   onclick="return confirm('<?= get_string('badge_type_delete_confirm', 'local_meritcoin') ?>')">
                  <i class="fa fa-trash me-1"></i><?= get_string('rewarddelete', 'local_meritcoin') ?>
                </a>
                <?php endif; ?>
              </div>
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