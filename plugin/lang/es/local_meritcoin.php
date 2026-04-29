<?php
// This file is part of Moodle - [http://moodle.org/](http://moodle.org/)
//
// @package local_meritcoin
// @copyright 2026 Universidad Tecnológica de Bolívar
// @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

defined('MOODLE_INTERNAL') || die();

// ── General ───────────────────────────────────────────────────────────────────
$string['pluginname']       = 'MeritCoin';
$string['mymeritcoin']      = 'Mi MeritCoin';

// ── Dashboard ─────────────────────────────────────────────────────────────────
$string['dashboardtitle']   = 'Mi panel MeritCoin';
$string['dashboardheading'] = 'MeritCoin - Mis logros y recompensas';

// ── Hero ──────────────────────────────────────────────────────────────────────
$string['mrtbalance']       = 'Saldo MRT';
$string['walletaddress']    = 'Dirección de wallet';
$string['nowallet']         = 'Sin wallet registrada';
$string['copywallet']       = 'Copiar dirección';

// ── Alerts ────────────────────────────────────────────────────────────────────
$string['backendunavailable'] = 'El servicio blockchain no está disponible en este momento. Mostrando datos locales.';
$string['nowalletmsg']        = 'No tienes una wallet Ethereum registrada. Para recibir tokens MRT, agrega tu dirección en tu';
$string['editprofile']        = 'perfil de usuario';

// ── Stats ─────────────────────────────────────────────────────────────────────
$string['statcompletions']  = 'Cursos completados';
$string['statavggrade']     = 'Calificación promedio';
$string['statsent']         = 'Eventos enviados';
$string['statpending']      = 'Pendientes';
$string['stattotalcoins']   = 'Total de monedas ganadas';

// ── Badges ────────────────────────────────────────────────────────────────────
$string['badgessection']        = 'Mis insignias';
$string['badgesbackendneeded']  = 'Las insignias aparecerán cuando el servicio blockchain esté activo.';
$string['nobadgesyet']          = 'Todavía no tienes insignias';
$string['nobadgeshint']         = 'Completa cursos y obtén buenas calificaciones para recibir insignias MeritCoin.';

// ── Activity history ──────────────────────────────────────────────────────────
$string['eventshistory']    = 'Historial de actividad';
$string['noeventsyet']      = 'Todavía no hay actividad registrada.';
$string['showinglast20']    = 'Mostrando los últimos 20 eventos. Ver todos en el panel de administración.';

// ── Table columns ─────────────────────────────────────────────────────────────
$string['coltype']          = 'Tipo';
$string['colcourse']        = 'Curso';
$string['colactivity']      = 'Actividad';
$string['colgrade']         = 'Calificación';
$string['colcoins']         = 'Monedas';
$string['colstatus']        = 'Estado';
$string['coldate']          = 'Fecha';
$string['courseid']         = 'ID del curso';

// ── Event types ───────────────────────────────────────────────────────────────
$string['typecompletion']   = 'Finalización';
$string['typegrade']        = 'Calificación';

// ── Statuses ──────────────────────────────────────────────────────────────────
$string['statussent']              = 'Enviado';
$string['statuspending']           = 'Pendiente';
$string['statusfailed']            = 'Fallido';
$string['statusunknown']           = 'Desconocido';
$string['queue_status_pending']        = 'Pendiente';
$string['queue_status_pending_wallet'] = 'Esperando wallet';
$string['queue_status_sent']           = 'Enviado';
$string['queue_status_failed']         = 'Fallido';

// ── Settings ──────────────────────────────────────────────────────────────────
$string['settings_enabled']           = 'Habilitar plugin';
$string['settings_enabled_desc']      = 'Cuando está deshabilitado, no se encolarán ni enviarán eventos.';
$string['settings_backend_url']       = 'URL del backend';
$string['settings_backend_url_desc']  = 'URL base del backend FastAPI, p. ej. https://api.example.com';
$string['settings_api_key']           = 'Clave API';
$string['settings_api_key_desc']      = 'Clave secreta enviada en cada solicitud al backend.';
$string['settings_wallet_field']      = 'Campo de wallet';
$string['settings_wallet_field_desc'] = 'Nombre corto del campo personalizado de perfil que almacena la dirección Ethereum del estudiante (p. ej. wallet).';
$string['settingshmacsecret']         = 'Secreto HMAC';

// ── Reward rules (v0.2.0) ─────────────────────────────────────────────────────
$string['settingsrules']            = 'Reglas de recompensa';
$string['settingsrulesdesc']        = 'Configura cuántas monedas otorga cada curso o actividad.';
$string['rulescourseid']            = 'ID del curso';
$string['rulesactivity']            = 'Actividad (opcional)';
$string['rulesactivitydesc']        = 'Deja vacío para aplicar esta regla a todo el curso.';
$string['rulescoinsfixed']          = 'Monedas fijas';
$string['rulescoinsfixeddesc']      = 'Otorga esta cantidad de monedas sin importar la calificación (p. ej. 10).';
$string['rulescoinspct']            = 'Multiplicador de calificación';
$string['rulescoinspctdesc']        = 'Multiplica la calificación por este factor (p. ej. 0.5 → calificación 85 = 42.5 monedas).';
$string['rulesmingrade']            = 'Calificación mínima';
$string['rulesmingratedesc']        = 'El estudiante debe alcanzar esta calificación para ganar monedas (por defecto: 0).';
$string['norulefound']              = 'No se encontró ninguna regla. Usando la fórmula por defecto.';

// ── Course coin config (v0.2.0) ───────────────────────────────────────────────
$string['settingscourseconfig']         = 'Configuración de moneda por curso';
$string['settingscourseconfigdesc']     = 'Asigna un nombre, símbolo y dirección de contrato inteligente personalizado por curso.';
$string['courseconfigcoinname']         = 'Nombre de la moneda';
$string['courseconfigcoinsymbol']       = 'Símbolo de la moneda';
$string['courseconfigcontract']         = 'Dirección del contrato';
$string['courseconfigcontractdesc']     = 'Contrato ERC-20 específico para este curso (opcional). Dejar vacío para usar el contrato global MRT.';

// ── Task ──────────────────────────────────────────────────────────────────────
$string['task_send_events']  = 'Enviar eventos MeritCoin pendientes al backend';

// ── Errors ────────────────────────────────────────────────────────────────────
$string['no_wallet']        = 'El estudiante no tiene wallet en el campo \'{$a}\'.';
$string['invalidwallet']    = 'Formato de wallet Ethereum inválido para el usuario {$a}.';
$string['gradebelowmin']    = 'La calificación {$a} está por debajo del mínimo requerido para ganar monedas.';

// ── Manage rules page ─────────────────────────────────────────────────────────
$string['manage_rules']          = 'MeritCoin – Reglas de monedas';
$string['manage_rules_desc']     = 'Configura cuántas monedas ganan los estudiantes por actividad o al completar este curso.';
$string['rules_table_scope']     = 'Alcance';
$string['rules_table_activity']  = 'Actividad';
$string['rules_table_coins']     = 'Monedas';
$string['rules_table_symbol']    = 'Símbolo';
$string['rules_table_status']    = 'Estado';
$string['rules_table_actions']   = 'Acciones';
$string['rule_enabled']          = 'Activa';
$string['rule_disabled']         = 'Inactiva';
$string['rule_enable_action']    = 'Activar';
$string['rule_disable_action']   = 'Desactivar';
$string['rule_delete_action']    = 'Eliminar';
$string['rule_delete_confirm']   = '¿Estás seguro de que deseas eliminar esta regla?';
$string['norules']               = 'Todavía no hay reglas configuradas. Crea una para comenzar a otorgar monedas.';

// ── Rule form (editrule.php + rule_form.php) ──────────────────────────────────
$string['newrule']                = 'Nueva regla de monedas';
$string['editrule']               = 'Editar regla de monedas';
$string['rule_created']           = 'Regla creada correctamente.';
$string['rule_updated']           = 'Regla actualizada correctamente.';
$string['rule_deleted']           = 'Regla eliminada.';
$string['rule_toggled']           = 'Estado de la regla actualizado.';
$string['rule_duplicate_updated'] = 'Ya existía una regla para esta actividad; se ha actualizado en lugar de crear una nueva.';
$string['rule_scope']             = 'Alcance de la regla';
$string['rule_scope_course']      = 'Curso completo (por defecto para todas las actividades calificadas)';
$string['rule_scope_activity']    = 'Actividad específica';
$string['activity_name']          = 'Nombre visible de la actividad';
$string['coins_amount']           = 'Monedas a otorgar';
$string['coin_symbol']            = 'Símbolo de la moneda (p. ej. MRT)';
$string['rule_enabled_desc']      = 'Activa';
$string['selectactivity']         = '— Selecciona una actividad —';
$string['error_positive_coins']   = 'La cantidad de monedas debe ser mayor que cero.';
$string['activity_help']          = 'Selecciona la actividad específica a la que aplica esta regla. Si eliges "Curso completo", la regla aplica a todas las actividades calificadas que no tengan su propia regla.';

// ── Marketplace: recompensas (profesor) ───────────────────────────────────────
$string['rewardstitle']         = 'Recompensas del Mercado';
$string['rewardnew']            = 'Nueva recompensa';
$string['rewardname']           = 'Nombre';
$string['rewardnameph']         = 'Ej: Exoneración de un quiz';
$string['rewarddesc']           = 'Descripción';
$string['rewarddescph']         = 'Ej: Te exonera del quiz de la semana 3';
$string['rewardprice']          = 'Precio';
$string['rewardcreatebtn']      = 'Crear recompensa';
$string['rewardslist']          = 'Recompensas creadas';
$string['rewardsempty']         = 'Aún no has creado recompensas para este curso.';
$string['rewardactive']         = 'Activa';
$string['rewardinactive']       = 'Inactiva';
$string['rewardactivate']       = 'Activar';
$string['rewarddeactivate']     = 'Desactivar';
$string['rewarddelete']         = 'Eliminar';
$string['rewardconfirmdelete']  = '¿Eliminar esta recompensa? Esta acción no se puede deshacer.';
$string['rewardredemptions']    = 'Canjes';
$string['rewardactions']        = 'Acciones';
$string['rewardcreated']        = 'Recompensa creada exitosamente.';
$string['rewardtoggled']        = 'Estado de la recompensa actualizado.';
$string['rewarddeleted']        = 'Recompensa eliminada.';
$string['rewardinvaliddata']    = 'Datos inválidos. Verifica el nombre y el precio.';
$string['rewardhasredemptions'] = 'No se puede eliminar: ya hay estudiantes que canjearon esta recompensa.';
$string['backtocourse']         = 'Volver al curso';

// ── Marketplace: vista estudiante ─────────────────────────────────────────────
$string['marketplacetitle']          = 'Mercado de Recompensas';
$string['marketplaceavailable']      = 'Saldo disponible en este curso';
$string['marketplaceempty']          = 'El profesor aún no ha publicado recompensas para este curso.';
$string['marketplaceretroacwarning'] = 'Tu saldo refleja únicamente la actividad registrada desde que MeritCoin fue instalado. Cursos o actividades completados antes de la instalación no generaron tokens.';
$string['marketplaceredeembtn']      = 'Canjear';
$string['marketplaceredeemedbadge']  = 'Ya canjeado';
$string['marketplacenotenoughbtn']   = 'Saldo insuficiente';
$string['marketplaceconfirm']        = '¿Canjear "{name}" por {price} {symbol}? Esta acción no se puede deshacer.';
$string['marketplaceredeemed']       = '¡Recompensa canjeada exitosamente!';
$string['marketplacerewardnotfound'] = 'La recompensa no existe o ya no está disponible.';
$string['marketplacealreadyredeemed']= 'Ya canjeaste esta recompensa anteriormente.';
$string['marketplacenotenough']      = 'No tienes suficientes tokens en este curso para canjear esta recompensa.';