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
 * Seed a sample draft query that renders as a pie chart.
 *
 * Inserts a single draft "Role assignments by role" query, pre-configured
 * with a pie chart. Publish it from the UI to build the view + Report Builder
 * report. Uses only core tables, so it works on any Moodle site without
 * creating fixture data.
 *
 * Usage (from Moodle root):
 *   php local/reportsources/cli/sample_pie_query.php
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Run as the primary admin so ownerid is a real account.
$admin = get_admin();
if (!$admin) {
    cli_error('No admin account found.');
}
\core\session\manager::set_user($admin);

$data = (object) [
    'name'        => 'Role assignments by role',
    'description' => 'Sample pie chart: how many role assignments exist for each role.',
    'querysql'    => "SELECT r.shortname AS role, COUNT(ra.id) AS assignments
FROM {role_assignments} ra
JOIN {role} r ON r.id = ra.roleid
GROUP BY r.shortname",
    'courseid'    => 0,
    'visible'     => 1,
    // Pie chart over the two output columns.
    'chart_type'     => 'pie',
    'chart_xcol'     => 'role',
    'chart_ycol'     => 'assignments',
    'chart_rowlimit' => 200,
];

$id = \local_reportsources\local\query::save($data);

cli_writeln("Created draft sample query id={$id}: \"{$data->name}\".");
cli_writeln('Publish it from Site admin -> Report sources to build the view + report.');
