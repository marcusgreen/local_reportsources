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
 * Upgrade steps for the Report sources plugin.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run the plugin upgrade steps.
 *
 * @param int $oldversion The currently installed plugin version.
 * @return bool
 */
function xmldb_local_reportsources_upgrade(int $oldversion): bool {
    if ($oldversion < 2026061400) {
        // The install.xml foreign-key map is now cached in MUC, not config_plugins. Drop the
        // orphaned config entries the previous version wrote during edit-page renders.
        unset_config('fkmapcache', 'local_reportsources');
        unset_config('fkmapcache_ver', 'local_reportsources');

        upgrade_plugin_savepoint(true, 2026061400, 'local', 'reportsources');
    }

    return true;
}
