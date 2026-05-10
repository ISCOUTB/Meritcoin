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
 * Database upgrade steps for local_meritcoin.
 *
 * Cada bloque if ($oldversion < X) representa una versión concreta del esquema.
 * Los nombres de índices deben coincidir EXACTAMENTE con los declarados en
 * install.xml para que el XMLDB checker no reporte diferencias.
 *
 * @package   local_meritcoin
 * @copyright 2026 Universidad Tecnológica de Bolívar
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_meritcoin_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // ── v0.2.0: soporte de monedas por actividad individual ──────────────────
    // Añade cmid, activity_name y coins_amount a la cola, y crea las tablas
    // de reglas y configuración de moneda por curso.
    if ($oldversion < 2026031004) {

        // ── 1. Agregar columnas a local_meritcoin_queue ──────────────────────
        $table = new xmldb_table('local_meritcoin_queue');

        $field = new xmldb_field('cmid', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('activity_name', XMLDB_TYPE_CHAR, '255', null, false, null, '', 'cmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('coins_amount', XMLDB_TYPE_NUMBER, '10,2', null, false, null, null, 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // ── 2. Crear tabla local_meritcoin_rules ─────────────────────────────
        $table = new xmldb_table('local_meritcoin_rules');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('cmid',         XMLDB_TYPE_INTEGER, '10',   null, false,         null, null);
            $table->add_field('coins_fixed',  XMLDB_TYPE_NUMBER,  '10,2', null, false,         null, null);
            $table->add_field('coins_pct',    XMLDB_TYPE_NUMBER,  '5,2',  null, false,         null, null);
            $table->add_field('min_grade',    XMLDB_TYPE_NUMBER,  '10,5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('rules_courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

            $dbman->create_table($table);
        }

        // ── 3. Crear tabla local_meritcoin_course_config ─────────────────────
        $table = new xmldb_table('local_meritcoin_course_config');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',               XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('coin_name',        XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, 'MeritCoin');
            $table->add_field('coin_symbol',      XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL, null, 'MRT');
            $table->add_field('contract_address', XMLDB_TYPE_CHAR,    '42', null, false,         null, '');
            $table->add_field('timecreated',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary',     XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('uq_courseid', XMLDB_KEY_UNIQUE,  ['courseid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026031004, 'local', 'meritcoin');
    }

    // ── v0.2.1: soportar pending_wallet e indexar reglas ────────────────────
    if ($oldversion < 2026042401) {

        $table = new xmldb_table('local_meritcoin_queue');
        $field = new xmldb_field(
            'student_wallet',
            XMLDB_TYPE_CHAR, '42',
            null,
            false,   // nullable
            null, null,
            'coins_amount'
        );
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
        }

        $table = new xmldb_table('local_meritcoin_rules');
        $index = new xmldb_index('rules_course_cmid_scope_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026042401, 'local', 'meritcoin');
    }

    // ── v0.3.0: saldo gastable por curso y reglas simples por actividad ──────
    if ($oldversion < 2026042801) {

        // ── 1. Extender local_meritcoin_rules ────────────────────────────────
        $table = new xmldb_table('local_meritcoin_rules');

        $field = new xmldb_field('rule_scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'activity', 'cmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('activityname', XMLDB_TYPE_CHAR, '255', null, false, null, null, 'rule_scope');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('coins_amount', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0.00', 'activityname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('coin_symbol', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'MRT', 'coins_amount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'coin_symbol');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Actualizar índice de dos campos a tres campos.
        $old_index = new xmldb_index('rules_course_cmid_scope_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid']);
        if ($dbman->index_exists($table, $old_index)) {
            $dbman->drop_index($table, $old_index);
        }
        $new_index = new xmldb_index('rules_course_cmid_scope_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid', 'rule_scope']);
        if (!$dbman->index_exists($table, $new_index)) {
            $dbman->add_index($table, $new_index);
        }

        // ── 2. Crear ledger de ganancias ─────────────────────────────────────
        $table = new xmldb_table('local_meritcoin_earnings');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',             XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('event_id',       XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',         XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('student_wallet', XMLDB_TYPE_CHAR,    '42',   null, false,         null, null);
            $table->add_field('courseid',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('cmid',           XMLDB_TYPE_INTEGER, '10',   null, false,         null, null);
            $table->add_field('event_type',     XMLDB_TYPE_CHAR,    '50',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('coins_earned',   XMLDB_TYPE_NUMBER,  '10,2', null, XMLDB_NOTNULL, null, '0.00');
            $table->add_field('coin_symbol',    XMLDB_TYPE_CHAR,    '20',   null, XMLDB_NOTNULL, null, 'MRT');
            $table->add_field('timecreated',    XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('earnings_event_id_uix',    XMLDB_INDEX_UNIQUE,    ['event_id']);
            $table->add_index('earnings_user_course_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);

            $dbman->create_table($table);
        }

        // ── 3. Crear ledger de gasto ─────────────────────────────────────────
        $table = new xmldb_table('local_meritcoin_spend');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',             XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid',         XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('student_wallet', XMLDB_TYPE_CHAR,    '42',   null, false,         null, null);
            $table->add_field('courseid',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reward_code',    XMLDB_TYPE_CHAR,    '100',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('coins_spent',    XMLDB_TYPE_NUMBER,  '10,2', null, XMLDB_NOTNULL, null, '0.00');
            $table->add_field('status',         XMLDB_TYPE_CHAR,    '20',   null, XMLDB_NOTNULL, null, 'approved');
            $table->add_field('timecreated',    XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('spend_user_course_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026042801, 'local', 'meritcoin');
    }

    // ── v0.3.0: marketplace — recompensas y canjes ───────────────────────────
    if ($oldversion < 2026042804) {

        $table = new xmldb_table('local_meritcoin_rewards');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('teacherid',    XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('name',         XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('description',  XMLDB_TYPE_TEXT,    null,   null, false,         null, null);
            $table->add_field('price_mrt',    XMLDB_TYPE_NUMBER,  '10,2', null, XMLDB_NOTNULL, null, null);
            $table->add_field('active',       XMLDB_TYPE_INTEGER, '1',    null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_course',  XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('idx_teacher', XMLDB_INDEX_NOTUNIQUE, ['teacherid']);
            $table->add_index('idx_active',  XMLDB_INDEX_NOTUNIQUE, ['active']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_meritcoin_redemptions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('rewardid',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('coins_spent',  XMLDB_TYPE_NUMBER,  '10,2', null, XMLDB_NOTNULL, null, null);
            $table->add_field('tx_hash',      XMLDB_TYPE_CHAR,    '66',   null, false,         null, null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_user',       XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('idx_reward',     XMLDB_INDEX_NOTUNIQUE, ['rewardid']);
            $table->add_index('idx_course',     XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('uq_user_reward', XMLDB_INDEX_UNIQUE,    ['userid', 'rewardid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026042804, 'local', 'meritcoin');
    }

    // ── v0.3.0: mod_type y min_grade en rules ────────────────────────────────
    if ($oldversion < 2026043001) {
        $table = new xmldb_table('local_meritcoin_rules');

        $field = new xmldb_field('mod_type', XMLDB_TYPE_CHAR, '50', null, false, null, null, 'rule_scope');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Reemplaza el min_grade viejo (INTEGER sin decimales de v0.2.0) si existe,
        // o lo crea con la definición correcta (NUMBER 10,5 nullable).
        $field = new xmldb_field('min_grade', XMLDB_TYPE_NUMBER, '10,5', null, false, null, null, 'coin_symbol');
        if ($dbman->field_exists($table, $field)) {
            // Puede venir de v0.2.0 con tipo INTEGER; normalizar a NUMBER.
            $dbman->change_field_type($table, $field);
        } else {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('rules_course_modtype_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'mod_type', 'rule_scope']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026043001, 'local', 'meritcoin');
    }

    // ── v0.3.1: tabla de insignias con hash de verificación ─────────────────
    // NOTA: esta versión crea badges con el esquema mínimo original.
    // La versión 2026050704 lo amplía con templateid, criteria, image_url, etc.
    if ($oldversion < 2026050703) {

        $table = new xmldb_table('local_meritcoin_badges');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',              XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid',          XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('badge_name',      XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('badge_type',      XMLDB_TYPE_CHAR,    '50',   null, XMLDB_NOTNULL, null, 'merit');
            $table->add_field('issued_by',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('verify_hash',     XMLDB_TYPE_CHAR,    '64',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('coins_threshold', XMLDB_TYPE_NUMBER,  '10,2', null, false,         null, null);
            $table->add_field('description',     XMLDB_TYPE_TEXT,    null,   null, false,         null, null);
            $table->add_field('timecreated',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_userid',      XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('idx_courseid',    XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('uq_hash',         XMLDB_INDEX_UNIQUE,    ['verify_hash']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026050703, 'local', 'meritcoin');
    }

    // ── v0.3.2: badge_types + badge_templates + refactor de badges ───────────
    if ($oldversion < 2026050704) {

        // ── 1. Tabla badge_types ──────────────────────────────────────────────
        $table = new xmldb_table('local_meritcoin_badge_types');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name',        XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('shortname',   XMLDB_TYPE_CHAR,    '50',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
            $table->add_field('criteria',    XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
            $table->add_field('color',       XMLDB_TYPE_CHAR,    '7',   null, XMLDB_NOTNULL, null, '#f0c040');
            $table->add_field('icon',        XMLDB_TYPE_CHAR,    '50',  null, XMLDB_NOTNULL, null, 'fa-award');
            $table->add_field('image_url',   XMLDB_TYPE_CHAR,    '500', null, false,         null, null);
            $table->add_field('is_system',   XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enabled',     XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '1');
            $table->add_field('sortorder',   XMLDB_TYPE_INTEGER, '5',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby',   XMLDB_TYPE_INTEGER, '10',  null, false,         null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary',      XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('uq_shortname', XMLDB_KEY_UNIQUE,  ['shortname']);
            $table->add_index('idx_is_system', XMLDB_INDEX_NOTUNIQUE, ['is_system']);
            $table->add_index('idx_enabled',   XMLDB_INDEX_NOTUNIQUE, ['enabled']);
            $table->add_index('idx_sortorder', XMLDB_INDEX_NOTUNIQUE, ['sortorder']);

            $dbman->create_table($table);
        }

        // ── 2. Seeder de tipos base ───────────────────────────────────────────
        $base_types = [
            ['name' => 'Mérito',        'shortname' => 'merit',         'description' => 'Reconocimiento al mérito académico general.',         'color' => '#f0c040', 'icon' => 'fa-star',    'is_system' => 1, 'sortorder' => 1],
            ['name' => 'Honor',         'shortname' => 'honor',         'description' => 'Distinción de honor por desempeño sobresaliente.',    'color' => '#6f42c1', 'icon' => 'fa-crown',   'is_system' => 1, 'sortorder' => 2],
            ['name' => 'Excelencia',    'shortname' => 'excellence',    'description' => 'Máximo reconocimiento por excelencia académica.',     'color' => '#0d3b5e', 'icon' => 'fa-trophy',  'is_system' => 1, 'sortorder' => 3],
            ['name' => 'Participación', 'shortname' => 'participation', 'description' => 'Reconocimiento a la participación activa.',           'color' => '#198754', 'icon' => 'fa-users',   'is_system' => 1, 'sortorder' => 4],
            ['name' => 'Especial',      'shortname' => 'special',       'description' => 'Reconocimiento especial a criterio del instructor.',  'color' => '#dc3545', 'icon' => 'fa-gem',     'is_system' => 1, 'sortorder' => 5],
        ];
        foreach ($base_types as $type) {
            if (!$DB->record_exists('local_meritcoin_badge_types', ['shortname' => $type['shortname']])) {
                $DB->insert_record('local_meritcoin_badge_types', (object) array_merge($type, [
                    'criteria'    => null,
                    'image_url'   => null,
                    'enabled'     => 1,
                    'createdby'   => null,
                    'timecreated' => time(),
                ]));
            }
        }

        // ── 3. Tabla badge_templates ──────────────────────────────────────────
        $table = new xmldb_table('local_meritcoin_badge_templates');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name',         XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description',  XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
            $table->add_field('criteria',     XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
            $table->add_field('type_id',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('image_url',    XMLDB_TYPE_CHAR,    '255', null, false,         null, null);
            $table->add_field('scope',        XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL, null, 'course');
            $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10',  null, false,         null, null);
            $table->add_field('createdby',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary',  XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_type',  XMLDB_KEY_FOREIGN, ['type_id'], 'local_meritcoin_badge_types', ['id']);
            $table->add_index('idx_createdby', XMLDB_INDEX_NOTUNIQUE, ['createdby']);
            $table->add_index('idx_courseid',  XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('idx_scope',     XMLDB_INDEX_NOTUNIQUE, ['scope']);

            $dbman->create_table($table);
        }

        // ── 4. Extender local_meritcoin_badges con campos del refactor ────────
        $btable = new xmldb_table('local_meritcoin_badges');
        if ($dbman->table_exists($btable)) {

            // templateid — FK opcional a badge_templates
            $field = new xmldb_field('templateid', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'id');
            if (!$dbman->field_exists($btable, $field)) {
                $dbman->add_field($btable, $field);
            }

            // criteria — snapshot del criterio al momento de otorgar
            $field = new xmldb_field('criteria', XMLDB_TYPE_TEXT, null, null, false, null, null, 'description');
            if (!$dbman->field_exists($btable, $field)) {
                $dbman->add_field($btable, $field);
            }

            // image_url — snapshot de la imagen al momento de otorgar
            $field = new xmldb_field('image_url', XMLDB_TYPE_CHAR, '500', null, false, null, null, 'criteria');
            if (!$dbman->field_exists($btable, $field)) {
                $dbman->add_field($btable, $field);
            }

            // notes — nota interna del profesor, no visible al estudiante
            $field = new xmldb_field('notes', XMLDB_TYPE_TEXT, null, null, false, null, null, 'image_url');
            if (!$dbman->field_exists($btable, $field)) {
                $dbman->add_field($btable, $field);
            }

            // timemodified — necesario para sincronización con install.xml
            $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');
            if (!$dbman->field_exists($btable, $field)) {
                $dbman->add_field($btable, $field);
            }

            // FK templateid → badge_templates (solo si aún no existe)
            $key = new xmldb_key('fk_template', XMLDB_KEY_FOREIGN, ['templateid'], 'local_meritcoin_badge_templates', ['id']);
            if (!$dbman->find_key_name($btable, $key)) {
                $dbman->add_key($btable, $key);
            }

            // Índice issued_by
            $index = new xmldb_index('idx_issued_by', XMLDB_INDEX_NOTUNIQUE, ['issued_by']);
            if (!$dbman->index_exists($btable, $index)) {
                $dbman->add_index($btable, $index);
            }

            // Índice compuesto userid+courseid
            $index = new xmldb_index('idx_user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            if (!$dbman->index_exists($btable, $index)) {
                $dbman->add_index($btable, $index);
            }

            // Índice badge_type
            $index = new xmldb_index('idx_badge_type', XMLDB_INDEX_NOTUNIQUE, ['badge_type']);
            if (!$dbman->index_exists($btable, $index)) {
                $dbman->add_index($btable, $index);
            }

            // Renombrar índices de v0.3.1 que no coinciden con install.xml
            // badges_userid_idx → idx_userid
            $old = new xmldb_index('badges_userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            if ($dbman->index_exists($btable, $old)) {
                $dbman->drop_index($btable, $old);
                $new = new xmldb_index('idx_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
                if (!$dbman->index_exists($btable, $new)) {
                    $dbman->add_index($btable, $new);
                }
            }
            // badges_courseid_idx → idx_courseid
            $old = new xmldb_index('badges_courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            if ($dbman->index_exists($btable, $old)) {
                $dbman->drop_index($btable, $old);
                $new = new xmldb_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
                if (!$dbman->index_exists($btable, $new)) {
                    $dbman->add_index($btable, $new);
                }
            }
            // badges_hash_uix → uq_hash  ✅ CORREGIDO: KEY en lugar de INDEX
            $old_index = new xmldb_index('badges_hash_uix', XMLDB_INDEX_UNIQUE, ['verify_hash']);
            if ($dbman->index_exists($btable, $old_index)) {
                $dbman->drop_index($btable, $old_index);
            }
            $key = new xmldb_key('uq_hash', XMLDB_KEY_UNIQUE, ['verify_hash']);
            if (!$dbman->find_key_name($btable, $key)) {
                $dbman->add_key($btable, $key);
            }

        } else {
            // Instalación limpia sin v0.3.1 previa: crear badges completo
            $btable->add_field('id',              XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $btable->add_field('templateid',      XMLDB_TYPE_INTEGER, '10',   null, false,         null, null);
            $btable->add_field('userid',          XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $btable->add_field('courseid',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $btable->add_field('badge_type',      XMLDB_TYPE_CHAR,    '50',   null, XMLDB_NOTNULL, null, 'merit');
            $btable->add_field('badge_name',      XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null, null);
            $btable->add_field('description',     XMLDB_TYPE_TEXT,    null,   null, false,         null, null);
            $btable->add_field('criteria',        XMLDB_TYPE_TEXT,    null,   null, false,         null, null);
            $btable->add_field('image_url',       XMLDB_TYPE_CHAR,    '500',  null, false,         null, null);
            $btable->add_field('issued_by',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $btable->add_field('verify_hash',     XMLDB_TYPE_CHAR,    '64',   null, XMLDB_NOTNULL, null, null);
            $btable->add_field('coins_threshold', XMLDB_TYPE_NUMBER,  '10,2', null, false,         null, null);
            $btable->add_field('notes',           XMLDB_TYPE_TEXT,    null,   null, false,         null, null);
            $btable->add_field('timecreated',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
            $btable->add_field('timemodified',    XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');

            $btable->add_key('primary',     XMLDB_KEY_PRIMARY, ['id']);
            $btable->add_key('uq_hash',     XMLDB_KEY_UNIQUE,  ['verify_hash']);
            $btable->add_key('fk_template', XMLDB_KEY_FOREIGN, ['templateid'], 'local_meritcoin_badge_templates', ['id']);
            $btable->add_index('idx_userid',      XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $btable->add_index('idx_courseid',    XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $btable->add_index('idx_issued_by',   XMLDB_INDEX_NOTUNIQUE, ['issued_by']);
            $btable->add_index('idx_user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $btable->add_index('idx_badge_type',  XMLDB_INDEX_NOTUNIQUE, ['badge_type']);

            $dbman->create_table($btable);
        }

        upgrade_plugin_savepoint(true, 2026050704, 'local', 'meritcoin');
    }

    if ($oldversion < 2026050707) {
        $table = new xmldb_table('local_meritcoin_badge_types');

        $field1 = new xmldb_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'is_system');
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        $field2 = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0', 'enabled');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        upgrade_plugin_savepoint(true, 2026050707, 'local', 'meritcoin');
    }

    return true;
}