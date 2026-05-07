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
 * Genera y descarga el certificado PDF de una insignia MeritCoin.
 * Solo accesible por el propietario de la insignia o un admin.
 * URL: /local/meritcoin/badge_pdf.php?hash=XXXX
 *
 * @package   local_meritcoin
 * @copyright 2026 Universidad Tecnológica de Bolívar
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
// Forzar recarga del string manager
get_string_manager()->reset_caches();
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/meritcoin/badge_pdf.php'));
force_current_language($CFG->lang ?? 'es');
require_once($CFG->libdir . '/tcpdf/tcpdf.php');

require_login();

$hash = required_param('hash', PARAM_ALPHANUM);

// ── Validar hash ──────────────────────────────────────────────────────────────
$clean = preg_replace('/[^a-f0-9]/i', '', $hash);
if (strlen($clean) !== 64) {
    throw new moodle_exception('badge_verify_invalid', 'local_meritcoin');
}

// ── Obtener insignia ──────────────────────────────────────────────────────────
$badge = $DB->get_record('local_meritcoin_badges', ['verify_hash' => $clean], '*', MUST_EXIST);

// ── Solo el dueño o un admin puede descargar ──────────────────────────────────
$sysctx = context_system::instance();
if ($badge->userid !== $USER->id && !has_capability('moodle/site:config', $sysctx)) {
    throw new moodle_exception('nopermissions', 'error', '', 'download badge PDF');
}

// ── Cargar datos relacionados ─────────────────────────────────────────────────
$student = $DB->get_record('user',   ['id' => $badge->userid],    'id,firstname,lastname', MUST_EXIST);
$issuer  = $DB->get_record('user',   ['id' => $badge->issued_by], 'id,firstname,lastname', IGNORE_MISSING);
$course  = $DB->get_record('course', ['id' => $badge->courseid],  'id,fullname,shortname', MUST_EXIST);
$siteurl = $CFG->wwwroot;
$verifyurl = $siteurl . '/local/meritcoin/badge_verify.php?hash=' . $clean;
$issued_date = userdate($badge->timecreated, '%d de %B de %Y');

// ── Colores corporativos ──────────────────────────────────────────────────────
$navy   = [13,  59,  94];   // #0d3b5e
$gold   = [240, 192, 64];   // #f0c040
$white  = [255, 255, 255];
$gray   = [108, 117, 125];
$light  = [248, 249, 250];
$green  = [25,  135, 84];

// ── Configurar TCPDF ──────────────────────────────────────────────────────────
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MeritCoin - UTB');
$pdf->SetAuthor($siteurl);
$pdf->SetTitle('Certificado de Insignia - ' . $badge->badge_name);
$pdf->SetSubject('MeritCoin Badge Certificate');
$pdf->SetKeywords('MeritCoin, Badge, Certificate, ' . $badge->badge_name);

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);
$pdf->AddPage();

$W = 297; // A4 landscape width mm
$H = 210; // A4 landscape height mm

// ── FONDO ─────────────────────────────────────────────────────────────────────
// Fondo completo navy
$pdf->SetFillColor(...$navy);
$pdf->Rect(0, 0, $W, $H, 'F');

// Franja dorada superior
$pdf->SetFillColor(...$gold);
$pdf->Rect(0, 0, $W, 4, 'F');

// Franja dorada inferior
$pdf->Rect(0, $H - 4, $W, 4, 'F');

// Panel blanco central
$pdf->SetFillColor(...$white);
$pdf->RoundedRect(20, 14, $W - 40, $H - 28, 6, '1111', 'F');

// Banda lateral izquierda navy (decorativa)
$pdf->SetFillColor(...$navy);
$pdf->Rect(20, 14, 52, $H - 28, 'F');

// ── LADO IZQUIERDO: logo + ícono ──────────────────────────────────────────────
// Hexágono decorativo (ícono MRT)
$pdf->SetFillColor(...$gold);
$pdf->SetFont('helvetica', 'B', 32);
$pdf->SetTextColor(...$gold);
$pdf->SetXY(20, 30);
$pdf->Cell(52, 20, "\xE2\xAC\xA1", 0, 1, 'C'); // ⬡ Unicode

// Nombre del plugin
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(...$white);
$pdf->SetXY(20, 50);
$pdf->Cell(52, 8, 'MeritCoin', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(180, 200, 220);
$pdf->SetXY(20, 57);
$pdf->Cell(52, 6, 'Universidad', 0, 1, 'C');
$pdf->SetXY(20, 62);
$pdf->Cell(52, 6, get_string('badge_pdf_institution', 'local_meritcoin'), 0, 1, 'C');

// Tipo de insignia (pill)
$type_label = strtoupper($badge->badge_type);
$pdf->SetFillColor(...$gold);
$pdf->SetTextColor(...$navy);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->RoundedRect(28, 130, 36, 9, 3, '1111', 'FD');
$pdf->SetXY(28, 131);
$pdf->Cell(36, 7, $type_label, 0, 1, 'C');

// Monedas (si aplica)
if ($badge->coins_threshold !== null) {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(180, 200, 220);
    $pdf->SetXY(20, 145);
    $pdf->Cell(52, 6, number_format((float)$badge->coins_threshold, 2) . ' MRT', 0, 1, 'C');
}

// ── LADO DERECHO: contenido ───────────────────────────────────────────────────
$rx = 80;  // x inicio contenido derecho
$rw = $W - 40 - 52 - 10; // ancho disponible

// "CERTIFICADO DE INSIGNIA"
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...$gray);
$pdf->SetXY($rx, 22);
$pdf->Cell($rw, 7, strtoupper(get_string('badge_pdf_certificate_label', 'local_meritcoin')), 0, 1, 'L');

// Nombre de la insignia
$pdf->SetFont('helvetica', 'B', 26);
$pdf->SetTextColor(...$navy);
$pdf->SetXY($rx, 28);
$pdf->MultiCell($rw, 12, $badge->badge_name, 0, 'L');

// Línea separadora dorada
$pdf->SetDrawColor(...$gold);
$pdf->SetLineWidth(0.8);
$pdf->Line($rx, 55, $rx + 120, 55);

// "Se certifica que"
$pdf->SetFont('helvetica', 'I', 11);
$pdf->SetTextColor(...$gray);
$pdf->SetXY($rx, 60);
$pdf->Cell($rw, 8, get_string('badge_pdf_awarded_to_label', 'local_meritcoin'), 0, 1, 'L');

// Nombre del estudiante
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetTextColor(...$navy);
$pdf->SetXY($rx, 67);
$pdf->Cell($rw, 12, fullname($student), 0, 1, 'L');

// Descripción (si existe)
if (!empty($badge->description)) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(...$gray);
    $pdf->SetXY($rx, 80);
    $pdf->MultiCell($rw - 10, 6, $badge->description, 0, 'L');
    $y_after_desc = $pdf->GetY() + 3;
} else {
    $y_after_desc = 82;
}

// Curso
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...$gray);
$pdf->SetXY($rx, $y_after_desc);
$pdf->Cell(30, 6, get_string('badge_pdf_course', 'local_meritcoin') . ':', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(...$navy);
$pdf->Cell($rw - 30, 6, $course->fullname, 0, 1, 'L');

// Emitido por
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...$gray);
$pdf->SetXY($rx, $y_after_desc + 8);
$pdf->Cell(30, 6, get_string('badge_pdf_issued_by', 'local_meritcoin') . ':', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(...$navy);
$pdf->Cell($rw - 30, 6, $issuer ? fullname($issuer) : '—', 0, 1, 'L');

// Fecha
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...$gray);
$pdf->SetXY($rx, $y_after_desc + 16);
$pdf->Cell(30, 6, get_string('badge_pdf_issued_on', 'local_meritcoin') . ':', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(...$navy);
$pdf->Cell($rw - 30, 6, $issued_date, 0, 1, 'L');

// ── SECCIÓN VERIFICACIÓN (parte inferior derecha) ─────────────────────────────
$vy = $H - 38;

// Línea separadora sutil
$pdf->SetDrawColor(220, 220, 220);
$pdf->SetLineWidth(0.3);
$pdf->Line($rx, $vy, $rx + $rw - 5, $vy);

// Ícono de verificado + texto
$pdf->SetFillColor(...$green);
$pdf->SetTextColor(...$white);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->RoundedRect($rx, $vy + 4, 28, 7, 2, '1111', 'F');
$pdf->SetXY($rx, $vy + 5);
$pdf->Cell(28, 5, "\xE2\x9C\x94 " . strtoupper(get_string('badge_pdf_verified', 'local_meritcoin')), 0, 0, 'C');

// URL de verificación
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(...$gray);
$pdf->SetXY($rx + 32, $vy + 4);
$pdf->Cell($rw - 32, 5, get_string('badge_pdf_verify_at', 'local_meritcoin') . ':', 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetTextColor(13, 59, 94);
$pdf->SetXY($rx + 32, $vy + 9);
$pdf->Cell($rw - 32, 5, $verifyurl, 0, 1, 'L');

// Hash
$pdf->SetFont('helvetica', '', 6);
$pdf->SetTextColor(...$gray);
$pdf->SetXY($rx, $vy + 17);
$pdf->Cell($rw, 5, 'SHA-256: ' . $clean, 0, 1, 'L');

// ── GENERAR Y DESCARGAR ───────────────────────────────────────────────────────
$filename = 'meritcoin_badge_' . preg_replace('/[^a-z0-9]/i', '_', $badge->badge_name) . '.pdf';
$pdf->Output($filename, 'D'); // D = fuerza descarga
exit;