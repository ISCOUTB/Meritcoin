<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Página pública de verificación de insignias MeritCoin.
 * Accesible sin login. URL: /local/meritcoin/badge_verify.php?hash=XXXX
 *
 * @package   local_meritcoin
 * @copyright 2026 Universidad Tecnológica de Bolívar
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Permitir acceso sin sesión activa.
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

$hash = optional_param('hash', '', PARAM_ALPHANUM);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/meritcoin/badge_verify.php', ['hash' => $hash]));
$PAGE->set_title(get_string('badge_verify_title', 'local_meritcoin'));
$PAGE->set_heading(get_string('badge_verify_title', 'local_meritcoin'));
$PAGE->set_pagelayout('base');

// ── Consulta ──────────────────────────────────────────────────────────────────
$badge    = null;
$student  = null;
$issuer   = null;
$course   = null;
$valid    = false;
$error    = '';

if (empty($hash)) {
    $error = get_string('badge_verify_no_hash', 'local_meritcoin');
} else {
    // Limpiar hash: solo hex lowercase.
    $clean = preg_replace('/[^a-f0-9]/i', '', $hash);
    if (strlen($clean) !== 64) {
        $error = get_string('badge_verify_invalid', 'local_meritcoin');
    } else {
        $badge = $DB->get_record('local_meritcoin_badges', ['verify_hash' => $clean]);
        if (!$badge) {
            $error = get_string('badge_verify_not_found', 'local_meritcoin');
        } else {
            $valid   = true;
            $student = $DB->get_record('user',   ['id' => $badge->userid],   'id,firstname,lastname,email');
            $issuer  = $DB->get_record('user',   ['id' => $badge->issued_by], 'id,firstname,lastname');
            $course  = $DB->get_record('course', ['id' => $badge->courseid],  'id,fullname,shortname');
        }
    }
}

// ── Salida HTML ───────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
.mrt-verify-wrap {
    max-width: 680px;
    margin: 2rem auto;
    padding: 0 1rem;
    font-family: inherit;
}
.mrt-verify-card {
    border-radius: 1rem;
    padding: 2.5rem 2rem;
    text-align: center;
}
.mrt-verify-card.valid {
    background: #f0fdf4;
    border: 2px solid #22c55e;
}
.mrt-verify-card.invalid {
    background: #fef2f2;
    border: 2px solid #ef4444;
}
.mrt-verify-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
    display: block;
}
.mrt-verify-badge-name {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1a2540;
    margin-bottom: 0.5rem;
}
.mrt-verify-status {
    display: inline-block;
    padding: 0.3rem 1rem;
    border-radius: 9999px;
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 1.5rem;
}
.mrt-verify-status.valid  { background: #22c55e; color: #fff; }
.mrt-verify-status.invalid { background: #ef4444; color: #fff; }
.mrt-verify-details {
    background: #fff;
    border-radius: 0.75rem;
    padding: 1.25rem 1.5rem;
    text-align: left;
    margin-top: 1.5rem;
    border: 1px solid #e5e7eb;
}
.mrt-verify-details dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.5rem 1.5rem;
    margin: 0;
}
.mrt-verify-details dt {
    font-weight: 600;
    color: #6b7280;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    align-self: center;
}
.mrt-verify-details dd {
    color: #1a2540;
    margin: 0;
    font-size: 0.95rem;
    align-self: center;
}
.mrt-verify-hash {
    margin-top: 1.5rem;
    background: #f3f4f6;
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    font-family: monospace;
    font-size: 0.75rem;
    color: #6b7280;
    word-break: break-all;
    text-align: left;
}
.mrt-verify-error {
    color: #dc2626;
    font-size: 1.1rem;
    margin: 0.5rem 0 1.5rem;
}
.mrt-verify-back {
    margin-top: 1.5rem;
    display: inline-block;
    padding: 0.6rem 1.5rem;
    background: #0d3b5e;
    color: #fff;
    border-radius: 0.5rem;
    text-decoration: none;
    font-weight: 500;
}
.mrt-verify-back:hover { background: #1a5276; color: #fff; }
</style>

<div class="mrt-verify-wrap">

<?php if ($valid && $badge): ?>

    <div class="mrt-verify-card valid">
        <span class="mrt-verify-icon">🏅</span>
        <div class="mrt-verify-badge-name"><?php echo s($badge->badge_name); ?></div>
        <span class="mrt-verify-status valid">
            ✔ <?php echo get_string('badge_verify_authentic', 'local_meritcoin'); ?>
        </span>

        <?php if ($badge->description): ?>
            <p style="color:#374151;margin-bottom:0;"><?php echo s($badge->description); ?></p>
        <?php endif; ?>

        <div class="mrt-verify-details">
            <dl>
                <dt><?php echo get_string('badge_verify_student', 'local_meritcoin'); ?></dt>
                <dd><?php echo s(fullname($student)); ?></dd>

                <dt><?php echo get_string('badge_verify_course', 'local_meritcoin'); ?></dt>
                <dd>
                    <?php echo s($course->fullname); ?>
                    <?php if ($course->shortname !== $course->fullname): ?>
                        <small style="color:#9ca3af;">(<?php echo s($course->shortname); ?>)</small>
                    <?php endif; ?>
                </dd>

                <dt><?php echo get_string('badge_verify_type', 'local_meritcoin'); ?></dt>
                <dd><?php echo s($badge->badge_type); ?></dd>

                <dt><?php echo get_string('badge_verify_issued_by', 'local_meritcoin'); ?></dt>
                <dd><?php echo $issuer ? s(fullname($issuer)) : '—'; ?></dd>

                <dt><?php echo get_string('badge_verify_issued_on', 'local_meritcoin'); ?></dt>
                <dd><?php echo userdate($badge->timecreated, get_string('strftimedatefullshort', 'core_langconfig')); ?></dd>

                <?php if ($badge->coins_threshold !== null): ?>
                <dt><?php echo get_string('badge_verify_coins', 'local_meritcoin'); ?></dt>
                <dd><?php echo number_format((float)$badge->coins_threshold, 2); ?> MRT</dd>
                <?php endif; ?>
            </dl>
        </div>

        <div class="mrt-verify-hash">
            <strong>Hash de verificación:</strong><br>
            <?php echo s($badge->verify_hash); ?>
        </div>
    </div>

<?php else: ?>

    <div class="mrt-verify-card invalid">
        <span class="mrt-verify-icon">❌</span>
        <div class="mrt-verify-badge-name"><?php echo get_string('badge_verify_invalid_title', 'local_meritcoin'); ?></div>
        <span class="mrt-verify-status invalid">
            <?php echo get_string('badge_verify_not_authentic', 'local_meritcoin'); ?>
        </span>
        <p class="mrt-verify-error"><?php echo s($error); ?></p>
        <p style="color:#6b7280;font-size:0.9rem;">
            <?php echo get_string('badge_verify_help', 'local_meritcoin'); ?>
        </p>
    </div>

<?php endif; ?>

    <div style="text-align:center;">
        <a class="mrt-verify-back" href="<?php echo new moodle_url('/local/meritcoin/dashboard.php'); ?>">
            ← <?php echo get_string('pluginname', 'local_meritcoin'); ?>
        </a>
    </div>

</div>
<?php
echo $OUTPUT->footer();