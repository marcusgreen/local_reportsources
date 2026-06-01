<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade steps for local_reportsources.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_reportsources_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2026050902) {
        // Rename datasource class from reportbuilder\datasource to reportbuilder\source so it is
        // no longer auto-discovered by core_reportbuilder's namespace scan and does not appear in
        // the "new report" source dropdown.
        $DB->set_field(
            'reportbuilder_report',
            'source',
            'local_reportsources\\reportbuilder\\source\\adhoc_query',
            ['source' => 'local_reportsources\\reportbuilder\\datasource\\adhoc_query']
        );

        upgrade_plugin_savepoint(true, 2026050902, 'local', 'reportsources');
    }

    if ($oldversion < 2026050904) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_reportsources_query');

        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'rowcap');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('visible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026050904, 'local', 'reportsources');
    }

    if ($oldversion < 2026052900) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_reportsources_query');

        $field = new xmldb_field('chartmeta', XMLDB_TYPE_TEXT, null, null, null, null, null, 'columnsmeta');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026052900, 'local', 'reportsources');
    }

    return true;
}
