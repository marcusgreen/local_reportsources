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

/**
 * Apply schema/data changes for each released version.
 *
 * @param int $oldversion The currently installed plugin version.
 * @return bool
 */
function xmldb_local_reportsources_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026061800) {
        // Add the coursecolumn field: names the output column holding a course id, used to limit
        // rows to courses the viewer teaches.
        $table = new xmldb_table('local_reportsources_query');
        $field = new xmldb_field(
            'coursecolumn',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null,
            'useridcolumn'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026061800, 'local', 'reportsources');
    }

    return true;
}
