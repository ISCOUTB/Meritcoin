<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // ── Página de configuración principal ─────────────────────────────────────
    $settings = new admin_settingpage(
        'local_meritcoin',
        get_string('pluginname', 'local_meritcoin')
    );

    $settings->add(new admin_setting_heading(
        'local_meritcoin/header',
        'MeritCoin settings',
        'Configure the connection between Moodle and the MeritCoin backend.'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_meritcoin/enabled',
        get_string('settingsenabled', 'local_meritcoin'),
        'Enable or disable event delivery from Moodle to the MeritCoin backend.',
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_meritcoin/api_url',
        get_string('settingsbackendurl', 'local_meritcoin'),
        'Backend base URL. In this Docker Compose setup, use http://meritcoin-backend:8000',
        'http://meritcoin-backend:8000',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_meritcoin/hmac_secret',
        get_string('settingshmacsecret', 'local_meritcoin'),
        'Shared secret used to sign requests sent from Moodle to the backend.',
        'cambia-este-secreto-en-produccion'
    ));

    $settings->add(new admin_setting_configtext(
        'local_meritcoin/wallet_field',
        get_string('settingswalletfield', 'local_meritcoin'),
        'Short name of the custom user profile field that stores the student wallet.',
        'wallet',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_meritcoin/student_course_limit',
        get_string('student_course_limit', 'local_meritcoin'),
        get_string('student_course_limit_desc', 'local_meritcoin'),
        16,
        PARAM_INT
    ));

    // ── Categoría raíz visible al nivel de "Development" ──────────────────────
    $ADMIN->add('root', new admin_category(
        'local_meritcoin_cat',
        get_string('pluginname', 'local_meritcoin')
    ));

    // Configuración principal dentro de la categoría
    $ADMIN->add('local_meritcoin_cat', $settings);

    // Cursos piloto
    $ADMIN->add('local_meritcoin_cat', new admin_externalpage(
        'local_meritcoin_pilot_courses',
        get_string('pilotcourses', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/admin_pilot_courses.php'),
        'local/meritcoin:manage'
    ));

    // Marketplace panel
    $ADMIN->add('local_meritcoin_cat', new admin_externalpage(
        'local_meritcoin_marketplace',
        get_string('adminmarketplacetitle', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/admin_marketplace.php'),
        'local/meritcoin:manage'
    ));

    // Tipos de insignia
    $ADMIN->add('local_meritcoin_cat', new admin_externalpage(
        'local_meritcoin_badge_types',
        get_string('badge_types_title', 'local_meritcoin'),
        new moodle_url('/local/meritcoin/badge_types.php'),
        'moodle/site:config'
    ));
}