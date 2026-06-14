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
 * Delete an ad-hoc query (drops backing view + report).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_reportsources\local\query;

require_login();
require_sesskey();

$id = required_param('id', PARAM_INT);
$context = context_system::instance();
require_capability('local/reportsources:author', $context);

$query = query::get($id);
$rec = $query->record();
if (
    (int) $rec->ownerid !== (int) $USER->id &&
    !has_capability('local/reportsources:viewall', $context)
) {
    throw new required_capability_exception($context, 'local/reportsources:viewall', 'nopermissions', '');
}

$confirm = optional_param('confirm', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportsources/delete.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('delete', 'local_reportsources'));
$PAGE->set_heading(get_string('reportsources', 'local_reportsources'));

if ($confirm) {
    $query->delete();
    redirect(
        new moodle_url('/local/reportsources/index.php'),
        get_string('deleted'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->confirm(
    get_string('delete', 'local_reportsources') . ': ' . format_string($rec->name) . '?',
    new moodle_url(
        '/local/reportsources/delete.php',
        ['id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]
    ),
    new moodle_url('/local/reportsources/index.php')
);
echo $OUTPUT->footer();
