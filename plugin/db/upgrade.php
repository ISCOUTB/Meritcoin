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
    // Hace student_wallet nullable en la cola y añade índice en rules.
    if ($oldversion < 2026042401) {

        // 1) Hacer student_wallet nullable para poder encolar eventos sin wallet.
        //    IMPORTANTE: change_field_notnull requiere la definición COMPLETA
        //    del campo (tipo, longitud, notnull, default, previous) para que
        //    PostgreSQL y MySQL lo procesen correctamente.
        $table = new xmldb_table('local_meritcoin_queue');
        $field = new xmldb_field(
            'student_wallet',
            XMLDB_TYPE_CHAR,
            '42',
            null,
            false,   // nullable = true
            null,
            null,
            'coins_amount'
        );
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
        }

        // 2) Índice compuesto en rules para búsqueda por curso+actividad.
        //    Solo dos campos en esta versión; en v0.3.0 se amplía a tres.
        $table = new xmldb_table('local_meritcoin_rules');
        $index = new xmldb_index('rules_course_cmid_scope_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026042401, 'local', 'meritcoin');
    }

    // ── v0.3.0: saldo gastable por curso y reglas simples por actividad ─────
    if ($oldversion < 2026042801) {

        // ── 1. Extender local_meritcoin_rules con campos de UI ───────────────
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

        // Actualizar el índice de dos campos (v0.2.1) a tres campos (v0.3.0).
        // Hay que dropearlo primero porque el nombre era distinto en v0.2.1.
        $old_index = new xmldb_index('rules_course_cmid_scope_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid']);
        if ($dbman->index_exists($table, $old_index)) {
            $dbman->drop_index($table, $old_index);
        }

        $new_index = new xmldb_index('rules_course_cmid_scope_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid', 'rule_scope']);
        if (!$dbman->index_exists($table, $new_index)) {
            $dbman->add_index($table, $new_index);
        }

        // ── 2. Crear ledger de ganancias ─────────────────────────────────────
        // Nombres de índices alineados con install.xml.
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
        // Nombres de índices alineados con install.xml.
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

    // ── v0.3.0: marketplace ── recompensas y canjes ──────────────────────────────
    if ($oldversion < 2026042802) {

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

        upgrade_plugin_savepoint(true, 2026042802, 'local', 'meritcoin');
    }

    return true;
}