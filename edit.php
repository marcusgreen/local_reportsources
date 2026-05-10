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
 * Create / edit an ad-hoc query.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 */

require(__DIR__ . '/../../config.php');

use local_reportsources\form\edit_query_form;
use local_reportsources\local\query;
use local_reportsources\local\sql\validator;

require_login();

$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$context = context_system::instance();
require_capability('local/reportsources:author', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportsources/edit.php', ['id' => $id, 'courseid' => $courseid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('addnew', 'local_reportsources'));
$PAGE->set_heading(get_string('reportsources', 'local_reportsources'));

$existing = null;
if ($id) {
    $existing = query::get_record($id);
    // Authors edit own queries; viewall can edit anything.
    if ((int) $existing->ownerid !== (int) $USER->id &&
        !has_capability('local/reportsources:viewall', $context)) {
        throw new required_capability_exception($context, 'local/reportsources:viewall', 'nopermissions', '');
    }
}

$mform = new edit_query_form();
if ($existing) {
    // Display SQL without {} table braces; auto_brace() re-adds them on save.
    $existing->querysql = validator::strip_braces((string) $existing->querysql);
    $mform->set_data($existing);
} else if ($courseid) {
    $mform->set_data((object) ['courseid' => $courseid]);
}

$returnurl = new moodle_url('/local/reportsources/index.php',
    $courseid ? ['courseid' => $courseid] : []);

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    $newid = query::save($data);
    redirect(
        $returnurl,
        get_string('changessaved'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading($existing
    ? get_string('edit', 'local_reportsources') . ': ' . format_string($existing->name)
    : get_string('addnew', 'local_reportsources'));
$mform->display();
echo $OUTPUT->footer();
