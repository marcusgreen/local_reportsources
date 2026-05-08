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
 * Admin probe: verify that the DB user can CREATE/DROP views.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_reportsources\local\sql\privilege_check;

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_reportsources_testview');

$result = privilege_check::probe();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('testview:title', 'local_reportsources'));

if ($result['ok']) {
    echo $OUTPUT->notification(
        get_string('testview:ok', 'local_reportsources'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    echo $OUTPUT->notification(
        get_string('testview:fail', 'local_reportsources', s($result['error'])),
        \core\output\notification::NOTIFY_ERROR
    );
    echo html_writer::tag('p', get_string('testview:grantshint', 'local_reportsources'));
}

echo html_writer::link(
    new moodle_url('/admin/settings.php', ['section' => 'local_reportsources']),
    get_string('back')
);

echo $OUTPUT->footer();
