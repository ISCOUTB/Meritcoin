<?php
// This file is part of Moodle - http://moodle.org/
//
// @package local_meritcoin
// @copyright 2026 Universidad Tecnológica de Bolívar
// @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

defined('MOODLE_INTERNAL') || die();

// ── General ───────────────────────────────────────────────────────────────────
$string['pluginname']       = 'MeritCoin';
$string['mymeritcoin']      = 'Mi MeritCoin';

// ── Dashboard ─────────────────────────────────────────────────────────────────
$string['dashboardtitle']   = 'Mi Panel MeritCoin';
$string['dashboardheading'] = 'MeritCoin - Mis Logros y Recompensas';

// ── Hero ──────────────────────────────────────────────────────────────────────
$string['mrtbalance']       = 'Balance MRT';
$string['walletaddress']    = 'Dirección de Wallet';
$string['nowallet']         = 'Sin wallet registrada';
$string['copywallet']       = 'Copiar dirección';

// ── Alertas ───────────────────────────────────────────────────────────────────
$string['backendunavailable'] = 'El servicio blockchain no está disponible en este momento. Se muestran datos locales.';
$string['nowalletmsg']        = 'No tienes una wallet Ethereum registrada. Para recibir tokens MRT, agrega tu dirección en tu';
$string['editprofile']        = 'perfil de usuario';

// ── Estadísticas ──────────────────────────────────────────────────────────────
$string['statcompletions']  = 'Cursos completados';
$string['statavggrade']     = 'Nota promedio';
$string['statsent']         = 'Eventos enviados';
$string['statpending']      = 'En espera';
$string['stattotalcoins']   = 'Total de monedas ganadas';   // NUEVO v0.2.0

// ── Badges ────────────────────────────────────────────────────────────────────
$string['badgessection']        = 'Mis Insignias';
$string['badgesbackendneeded']  = 'Las insignias se mostrarán cuando el servicio blockchain esté activo.';
$string['nobadgesyet']          = 'Aún no tienes insignias';
$string['nobadgeshint']         = 'Completa cursos y obtén buenas notas para ganar insignias MeritCoin.';

// ── Historial ─────────────────────────────────────────────────────────────────
$string['eventshistory']    = 'Historial de Actividad';
$string['noeventsyet']      = 'Aún no hay actividad registrada.';
$string['showinglast20']    = 'Mostrando los últimos 20 eventos. Ver todos en el panel de administración.';

// ── Columnas de tabla ─────────────────────────────────────────────────────────
$string['coltype']          = 'Tipo';
$string['colcourse']        = 'Curso';
$string['colactivity']      = 'Actividad';       // NUEVO v0.2.0
$string['colgrade']         = 'Nota';
$string['colcoins']         = 'Monedas';         // NUEVO v0.2.0
$string['colstatus']        = 'Estado';
$string['coldate']          = 'Fecha';
$string['courseid']         = 'Curso ID';

// ── Tipos de evento ───────────────────────────────────────────────────────────
$string['typecompletion']   = 'Completado';
$string['typegrade']        = 'Calificación';

// ── Estados ───────────────────────────────────────────────────────────────────
$string['statussent']       = 'Enviado';
$string['statuspending']    = 'Pendiente';
$string['statusfailed']     = 'Fallido';
$string['statusunknown']    = 'Desconocido';

// ── Settings ──────────────────────────────────────────────────────────────────
$string['settingsenabled']      = 'Habilitar plugin';
$string['settingsbackendurl']   = 'URL del backend';
$string['settingshmacsecret']   = 'Secreto HMAC';
$string['settingswalletfield']  = 'Campo de wallet';

// ── Reglas de recompensa (NUEVO v0.2.0) ───────────────────────────────────────
$string['settingsrules']            = 'Reglas de Recompensa';
$string['settingsrulesdesc']        = 'Configura cuántas monedas otorga cada curso o actividad. Si no hay regla, se usa la fórmula por defecto: nota / 10 monedas, o 50 monedas al completar el curso.';
$string['rulescourseid']            = 'ID del curso';
$string['rulesactivity']            = 'Actividad (opcional)';
$string['rulesactivitydesc']        = 'Dejar vacío para aplicar la regla a todo el curso.';
$string['rulescoinsfixed']          = 'Monedas fijas';
$string['rulescoinsfixeddesc']      = 'Otorga esta cantidad de monedas sin importar la nota (ej: 10).';
$string['rulescoinspct']            = 'Multiplicador de nota';
$string['rulescoinspctdesc']        = 'Multiplica la nota por este factor (ej: 0.5 → nota 85 = 42.5 monedas).';
$string['rulesmingrade']            = 'Nota mínima';
$string['rulesmingratedesc']        = 'El estudiante debe alcanzar esta nota para ganar monedas (defecto: 0).';
$string['norulefound']              = 'Sin regla configurada. Usando fórmula por defecto.';

// ── Config de moneda por curso (NUEVO v0.2.0) ─────────────────────────────────
$string['settingscourseconfig']         = 'Configuración de Moneda por Curso';
$string['settingscourseconfigdesc']     = 'Asigna un nombre, símbolo y contrato inteligente propio a cada curso.';
$string['courseconfigcoinname']         = 'Nombre de la moneda';
$string['courseconfigcoinsymbol']       = 'Símbolo de la moneda';
$string['courseconfigcontract']         = 'Dirección del contrato';
$string['courseconfigcontractdesc']     = 'Contrato ERC-20 específico del curso (opcional). Dejar vacío para usar el contrato MRT global.';

// ── Tarea ─────────────────────────────────────────────────────────────────────
$string['task:sendevents']  = 'MeritCoin: Enviar eventos pendientes al backend blockchain';

// ── Errores ───────────────────────────────────────────────────────────────────
$string['no_wallet']        = 'El estudiante no tiene wallet en el campo '{$a}'.';
$string['invalidwallet']    = 'Formato de wallet Ethereum inválido para el usuario {$a}.';
$string['gradebelowmin']    = 'La nota {$a} no alcanza el mínimo requerido para ganar monedas.';
