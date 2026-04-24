<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Database upgrade steps for local_meritcoin.
 *
 * @package local_meritcoin
 * @copyright 2026 Universidad Tecnológica de Bolívar
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_meritcoin upgrade from the given old version.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_local_meritcoin_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // ── v0.2.0: soporte de monedas por actividad individual ──────────────────
    if ($oldversion < 2026031004) {

        // ── 1. Agregar columnas a local_meritcoin_queue ──────────────────────
        $table = new xmldb_table('local_meritcoin_queue');

        // cmid: ID del course module (null = calificación final del curso)
        $field = new xmldb_field('cmid', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // activity_name: nombre de la actividad o del curso
        $field = new xmldb_field('activity_name', XMLDB_TYPE_CHAR, '255', null, false, null, '', 'cmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // coins_amount: monedas calculadas según la regla
        $field = new xmldb_field('coins_amount', XMLDB_TYPE_NUMBER, '10', null, false, null, null, 'grade');
        if (!$dbman->field_exists($table, $field)) {
            // XMLDB_TYPE_NUMBER requiere precisión y decimales como array
            $field = new xmldb_field('coins_amount');
            $field->set_attributes(XMLDB_TYPE_NUMBER, '10, 2', null, false, null, null, 'grade');
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
            $table->add_index('idx_course', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('idx_cm',     XMLDB_INDEX_NOTUNIQUE, ['cmid']);

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

    // ── v0.2.1: soportar pending_wallet e indexar reglas por actividad ──────────
    if ($oldversion < 2026042401) {

        // 1) Permitir student_wallet null en la cola.
        $table = new xmldb_table('local_meritcoin_queue');
        $field = new xmldb_field('student_wallet', XMLDB_TYPE_CHAR, '42', null, null, null, null, 'coins_amount');

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
        }

        // 2) Agregar índice compuesto courseid + cmid en reglas.
        $table = new xmldb_table('local_meritcoin_rules');
        $index = new xmldb_index('idx_course_cmid', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid']);

        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026042401, 'local', 'meritcoin');
    }

    return true;
}
