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
$string['settingsenabled']            = 'Habilitar MeritCoin';
$string['settingsbackendurl']         = 'URL del backend';
$string['settingswalletfield']        = 'Campo de perfil wallet';

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
$string['task_send_events']         = 'Enviar eventos MeritCoin pendientes al backend';
$string['task_process_redemptions'] = 'Procesar canjes pendientes del marketplace';
$string['task_expire_courses']      = 'Expirar inscripciones de cursos piloto MeritCoin';

// ── Errors ────────────────────────────────────────────────────────────────────
$string['no_wallet']        = 'El estudiante no tiene wallet en el campo \'{$a}\'.';
$string['invalidwallet']    = 'Formato de wallet Ethereum inválido para el usuario {$a}.';
$string['gradebelowmin']    = 'La calificación {$a} está por debajo del mínimo requerido para ganar monedas.';
$string['invaliddate']      = 'La fecha ingresada no es válida.';

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
$string['newrule']                      = 'Nueva regla de monedas';
$string['editrule']                     = 'Editar regla de monedas';
$string['rule_created']                 = 'Regla creada correctamente.';
$string['rule_updated']                 = 'Regla actualizada correctamente.';
$string['rule_deleted']                 = 'Regla eliminada.';
$string['rule_toggled']                 = 'Estado de la regla actualizado.';
$string['rule_duplicate_updated']       = 'Ya existía una regla para esta actividad; se ha actualizado en lugar de crear una nueva.';
$string['rule_scope']                   = 'Alcance de la regla';
$string['rule_scope_course']            = 'Curso completo (por defecto para todas las actividades calificadas)';
$string['rule_scope_activity']          = 'Actividad específica';
$string['rule_scope_activity_type']     = 'Tipo de actividad (todos los entregables, todos los foros, etc.)';
$string['activity_name']                = 'Nombre visible de la actividad';
$string['coins_amount']                 = 'Monedas a otorgar';
$string['coin_symbol']                  = 'Símbolo de la moneda (p. ej. MRT)';
$string['rule_enabled_desc']            = 'Activa';
$string['enabled']                      = 'Habilitado';
$string['selectactivity']               = '— Selecciona una actividad —';
$string['error_positive_coins']         = 'La cantidad de monedas debe ser mayor que cero.';
$string['error_invalid_grade']          = 'Debe ser un número válido';
$string['error_positive_grade']         = 'Debe ser 0 o mayor';
$string['activity_help']                = 'Selecciona la actividad específica a la que aplica esta regla. Si eliges \"Curso completo\", la regla aplica a todas las actividades calificadas que no tengan su propia regla.';
$string['rule_mod_type']                = 'Tipo de módulo';
$string['rule_select_mod_type']         = '— Selecciona un tipo —';
$string['rule_min_grade']               = 'Nota mínima';
$string['rule_min_grade_placeholder']   = 'Dejar vacío para no exigir nota mínima';
$string['rule_min_grade_help']          = 'Si se configura, solo se otorgan monedas cuando el estudiante alcanza o supera esta nota. Dejar vacío para otorgar monedas sin importar la calificación.';
$string['col_reevals']                  = 'Reevals';
$string['col_reevals_hint']             = 'Número de veces que esta actividad ha sido calificada';

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
$string['marketplacetitle']           = 'Mercado de Recompensas';
$string['marketplaceavailable']       = 'Saldo disponible en este curso';
$string['marketplaceempty']           = 'El profesor aún no ha publicado recompensas para este curso.';
$string['marketplaceretroacwarning']  = 'Tu saldo refleja únicamente la actividad registrada desde que MeritCoin fue instalado. Cursos o actividades completados antes de la instalación no generaron tokens.';
$string['marketplaceredeembtn']       = 'Canjear';
$string['marketplaceredeemedbadge']   = 'Ya canjeado';
$string['marketplacenotenoughbtn']    = 'Saldo insuficiente';
$string['marketplaceconfirm']         = '¿Canjear "{name}" por {price} {symbol}? Esta acción no se puede deshacer.';
$string['marketplaceredeemed']        = '¡Recompensa canjeada exitosamente!';
$string['marketplacerewardnotfound']  = 'La recompensa no existe o ya no está disponible.';
$string['marketplacealreadyredeemed'] = 'Ya canjeaste esta recompensa anteriormente.';
$string['marketplacenotenough']       = 'No tienes suficientes tokens en este curso para canjear esta recompensa.';

// ── Admin marketplace ─────────────────────────────────────────────────────────
$string['adminmarketplacetitle']  = 'MeritCoin — Panel del Marketplace';
$string['adminrewardsactive']     = 'Recompensas activas';
$string['adminrewardsinactive']   = 'Recompensas inactivas';
$string['admintotalredemptions']  = 'Canjes totales';
$string['admintotalspent']        = 'Tokens gastados';
$string['adminteacher']           = 'Profesor';
$string['admincolstudent']        = 'Estudiante';
$string['admincoinsspent']        = 'Tokens gastados';
$string['admintxhash']            = 'TX Hash';
$string['tabrewards']             = 'Recompensas';
$string['tabredemptions']         = 'Historial de canjes';
$string['filterbycourse']         = 'Filtrar por curso';
$string['allcourses']             = 'Todos los cursos';
$string['adminrewardsempty']      = 'No hay recompensas creadas aún.';
$string['adminredemptionsempty']  = 'No hay canjes registrados aún.';
$string['pluginsettings']         = 'Configuración del plugin';
$string['admin_tab_transactions'] = 'Todas las transacciones';

// ── Transacciones del profesor ────────────────────────────────────────────────
$string['teacher_transactions_title'] = 'Transacciones del curso';
$string['teacher_tab_earnings']       = 'Monedas otorgadas';
$string['teacher_kpi_awarded']        = 'Monedas otorgadas';
$string['teacher_filter_student']     = 'Filtrar por estudiante';
$string['teacher_all_students']       = 'Todos los estudiantes';
$string['teacher_clear_filter']       = 'Limpiar filtro';
$string['teacher_no_earnings']        = 'No hay monedas otorgadas aún en este curso.';

// ── Límite de estudiantes ─────────────────────────────────────────────────────
$string['student_course_limit']          = 'Límite de MRT por estudiante por curso';
$string['student_course_limit_desc']     = 'Máximo de tokens MRT que un estudiante puede ganar por curso durante todo el semestre.';
$string['student_course_limit_exceeded'] = 'Este estudiante ha alcanzado el límite de MRT para este curso ({$a->used}/{$a->limit}).';

// ── Cursos piloto (v0.5.0) ────────────────────────────────────────────────────
$string['pilotcourses']          = 'Cursos Piloto';
$string['addpilotcourse']        = 'Añadir curso piloto';
$string['choosecourse']          = 'Elige un curso...';
$string['expiresatoverride']     = 'Fecha de cierre del semestre (manual)';
$string['expiresatoverridedesc'] = 'Dejar vacío para usar automáticamente la fecha de fin del curso.';
$string['pilotadded']            = 'Curso añadido como piloto correctamente.';
$string['pilotalreadyexists']    = 'Este curso ya está registrado como piloto.';
$string['expiresatupdated']      = 'Fecha de cierre actualizada correctamente.';
$string['pilotdisabled']         = 'Curso piloto desactivado.';
$string['nopilotcourses']        = 'No hay cursos piloto configurados todavía.';
$string['usescourseenddate']     = 'Usa la fecha de fin del curso';
$string['courseenddate']         = 'Fecha de fin del curso';
$string['noenddate']             = 'Sin fecha de fin';
$string['disabled']              = 'Desactivado';
$string['confirmdisablepilot']   = '¿Seguro que quieres desactivar este curso piloto?';
$string['expiresatrequired']     = 'Por favor selecciona una fecha antes de hacer clic en Update.';

// ── Verificación de insignias (badge_verify.php) ──────────────────────────────
$string['badge_verify_title']         = 'Verificación de Insignia — MeritCoin';
$string['badge_verify_authentic']     = 'Insignia Auténtica';
$string['badge_verify_not_authentic'] = 'Insignia no encontrada';
$string['badge_verify_invalid_title'] = 'Insignia Inválida';
$string['badge_verify_no_hash']       = 'No se proporcionó ningún código de verificación.';
$string['badge_verify_invalid']       = 'El formato del código de verificación es inválido.';
$string['badge_verify_not_found']     = 'No se encontró ninguna insignia con este código de verificación.';
$string['badge_verify_student']       = 'Otorgada a';
$string['badge_verify_course']        = 'Curso';
$string['badge_verify_type']          = 'Tipo';
$string['badge_verify_issued_by']     = 'Emitida por';
$string['badge_verify_issued_on']     = 'Fecha de emisión';
$string['badge_verify_coins']         = 'MRT al momento de emisión';
$string['badge_verify_help']          = 'Si crees que esto es un error, contacta a la institución que emitió la insignia.';
$string['badge_verified']             = '✓ Insignia Verificada';
$string['badge_verify_invalid_desc']  = 'Este enlace de verificación no es válido o la insignia ya no existe.';
$string['badge_awarded_to']           = 'Otorgada a';
$string['badge_issued_by']            = 'Emitida por';
$string['badge_issuer_role']          = 'Instructor del curso';
$string['badge_description']          = 'Descripción';
$string['badge_criteria']             = 'Criterios';
$string['badge_hash']                 = 'Hash de verificación';
$string['verifybadge']                = 'Verificar insignia';
$string['verify']                     = 'Verificar';
$string['balancelocal']               = 'estimado local';

// ── Certificado PDF (badge_pdf.php) ───────────────────────────────────────────
$string['badge_pdf_certificate_label'] = 'Certificado de Insignia';
$string['badge_pdf_awarded_to_label']  = 'Se certifica que';
$string['badge_pdf_course']            = 'Curso';
$string['badge_pdf_issued_by']         = 'Emitido por';
$string['badge_pdf_issued_on']         = 'Fecha de emisión';
$string['badge_pdf_verified']          = 'Verificado';
$string['badge_pdf_verify_at']         = 'Verificar en';
$string['badge_pdf_institution']       = 'Tecnológica de Bolívar';
$string['badge_pdf_download']          = 'Descargar PDF';
$string['badge_copy_link']             = 'Copiar enlace';
$string['badge_link_copied']           = '¡Enlace copiado!';
$string['badge_certificate_title']     = 'Certificado de Insignia';
$string['badge_certificate_of']        = 'Certificado de logro';

// ── Plantillas de insignias ───────────────────────────────────────────────────
$string['badge_templates_title']      = 'Plantillas de Insignias';
$string['template_new']               = 'Nueva plantilla';
$string['template_edit']              = 'Editar plantilla';
$string['template_empty']             = 'Aún no hay plantillas. Crea una para comenzar a otorgar insignias.';
$string['template_created']           = 'Plantilla creada correctamente.';
$string['template_updated']           = 'Plantilla actualizada correctamente.';
$string['template_deleted']           = 'Plantilla eliminada.';
$string['template_has_badges']        = 'No se puede eliminar: ya hay insignias emitidas con esta plantilla.';
$string['template_confirm_delete']    = '¿Eliminar esta plantilla? Esta acción no se puede deshacer.';
$string['template_issued']            = 'emitidas';
$string['template_name']              = 'Nombre de la insignia';
$string['template_type']              = 'Tipo de insignia';
$string['template_description']       = 'Descripción';
$string['template_description_help']  = 'Descripción del logro que representa esta insignia. Aparecerá en el certificado PDF.';
$string['template_criteria']          = 'Criterios';
$string['template_criteria_help']     = 'Explica qué debe hacer el estudiante para merecer esta insignia.';
$string['template_image_url']         = 'URL de imagen (opcional)';
$string['template_scope']             = 'Alcance';
$string['template_scope_help']        = 'Global: disponible para cualquier curso (solo admin). Curso: solo para tu curso.';
$string['template_scope_global']      = 'Global (todos los cursos)';
$string['template_scope_course']      = 'Este curso';
$string['badge_award_btn']            = 'Otorgar insignia';

// ── Otorgar insignia ──────────────────────────────────────────────────────────
$string['award_badge_title']          = 'Otorgar Insignia';
$string['award_select_template']      = 'Plantilla de insignia';
$string['award_select_students']      = 'Estudiantes';
$string['award_select_students_help'] = 'Mantén Ctrl (o Cmd en Mac) para seleccionar varios estudiantes a la vez.';
$string['award_notes']                = 'Nota interna (opcional)';
$string['award_btn']                  = 'Otorgar insignia';
$string['award_success']              = 'Se otorgaron {$a} insignia(s) correctamente.';
$string['award_none_issued']          = 'No se emitió ninguna insignia. Verifica los datos.';
$string['award_no_templates']         = 'No tienes plantillas disponibles. Crea una primero.';
$string['award_no_students']          = 'No hay estudiantes matriculados con los permisos necesarios.';

// ── Panel de otorgamiento de insignias (v0.4.0) ───────────────────────────────
$string['badge_award_title']          = 'Otorgar insignias';
$string['badge_award_new']            = 'Otorgar una nueva insignia';
$string['badge_award_student']        = 'Estudiante';
$string['badge_award_select_student'] = 'Selecciona un estudiante';
$string['badge_award_type']           = 'Tipo de insignia';
$string['badge_award_select_type']    = 'Selecciona un tipo';
$string['badge_award_btn']            = 'Otorgar';
$string['badge_awarded_ok']           = 'Insignia otorgada exitosamente.';
$string['badge_already_has']          = 'Este estudiante ya tiene esta insignia en el curso.';
$string['badge_revoked_ok']           = 'Insignia revocada.';
$string['badge_revoke_btn']           = 'Revocar';
$string['badge_revoke_confirm']       = '¿Revocar esta insignia? Esta acción no se puede deshacer.';
$string['badge_awarded_list']         = 'Insignias otorgadas en este curso';
$string['badge_none_awarded_yet']     = 'Todavía no hay insignias otorgadas en este curso.';
$string['badge_no_types_warning']     = 'No hay tipos de insignia configurados. Ve al panel de administración para crear tipos primero.';
$string['badge_col_badge']            = 'Insignia';
$string['badge_col_student']          = 'Estudiante';
$string['badge_col_verify']           = 'Verificar';

// ── Administración de tipos de insignia (badge_types.php) ─────────────────────
$string['badge_types_menu']            = 'MeritCoin – Tipos de insignia';
$string['badge_types_title']           = 'MeritCoin – Tipos de insignia';
$string['badge_types_desc']            = 'Crea y administra los tipos de insignias que los profesores pueden otorgar a los estudiantes.';
$string['badge_types_list']            = 'Tipos de insignia configurados';
$string['badge_types_empty']           = 'Aún no hay tipos de insignia configurados. Crea uno para comenzar.';
$string['badge_type_new']              = 'Nuevo tipo de insignia';
$string['badge_type_edit']             = 'Editar tipo de insignia';
$string['badge_type_name']             = 'Nombre';
$string['badge_type_shortname']        = 'Nombre corto';
$string['badge_type_shortname_help']   = 'Identificador único, solo letras y números. No se puede cambiar en tipos del sistema.';
$string['badge_type_shortname_exists'] = 'Ya existe un tipo de insignia con ese nombre corto.';
$string['badge_type_description']      = 'Descripción';
$string['badge_type_criteria']         = 'Criterios de otorgamiento';
$string['badge_type_color']            = 'Color';
$string['badge_type_icon']             = 'Ícono';
$string['badge_type_image_url']        = 'URL de imagen';
$string['badge_type_sortorder']        = 'Orden';
$string['badge_type_enabled']          = 'Habilitado';
$string['badge_type_is_system']        = 'Tipo de sistema';
$string['badge_type_created']          = 'Tipo de insignia creado exitosamente.';
$string['badge_type_updated']          = 'Tipo de insignia actualizado exitosamente.';
$string['badge_type_deleted']          = 'Tipo de insignia eliminado.';
$string['badge_type_toggled']          = 'Estado del tipo de insignia actualizado.';
$string['badge_type_delete_confirm']   = '¿Eliminar este tipo de insignia? Esta acción no se puede deshacer.';