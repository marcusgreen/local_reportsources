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
 * List saved ad-hoc queries.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 */

require(__DIR__ . '/../../config.php');

use local_reportsources\local\query;

require_login();

$context = context_system::instance();
require_capability('local/reportsources:view', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportsources/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('queries', 'local_reportsources'));
$PAGE->set_heading(get_string('reportsources', 'local_reportsources'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('queries', 'local_reportsources'));

if (has_capability('local/reportsources:author', $context)) {
    echo $OUTPUT->single_button(
        new moodle_url('/local/reportsources/edit.php'),
        get_string('addnew', 'local_reportsources'),
        'get'
    );
}

$queries = query::visible_to_current_user();
if (!$queries) {
    echo $OUTPUT->notification(get_string('noqueries', 'local_reportsources'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('name', 'local_reportsources'),
    get_string('owner', 'local_reportsources'),
    get_string('status', 'local_reportsources'),
    get_string('actions', 'local_reportsources'),
];

foreach ($queries as $rec) {
    $owner = core_user::get_user($rec->ownerid);
    $statuskey = 'status_' . $rec->status;
    $actions = [];
    if (has_capability('local/reportsources:author', $context)) {
        $actions[] = html_writer::link(
            new moodle_url('/local/reportsources/edit.php', ['id' => $rec->id]),
            get_string('edit', 'local_reportsources')
        );
    }
    if ($rec->status === query::STATUS_PUBLISHED && $rec->reportid) {
        $actions[] = html_writer::link(
            new moodle_url('/reportbuilder/view.php', ['id' => $rec->reportid]),
            get_string('runreport', 'local_reportsources')
        );
        if (has_capability('moodle/reportbuilder:edit', $context)) {
            $actions[] = html_writer::link(
                new moodle_url('/reportbuilder/edit.php', ['id' => $rec->reportid]),
                get_string('editreport', 'local_reportsources')
            );
        }
    }
    if ($rec->status === query::STATUS_PUBLISHED && has_capability('local/reportsources:approve', $context)) {
        $actions[] = html_writer::link(
            new moodle_url('/local/reportsources/run.php',
                ['id' => $rec->id, 'action' => 'newreport', 'sesskey' => sesskey()]),
            get_string('newreport', 'local_reportsources')
        );
    }
    if ($rec->status === query::STATUS_DRAFT && has_capability('local/reportsources:approve', $context)) {
        $actions[] = html_writer::link(
            new moodle_url('/local/reportsources/run.php',
                ['id' => $rec->id, 'action' => 'publish', 'sesskey' => sesskey()]),
            get_string('publish', 'local_reportsources')
        );
    }
    if ($rec->status === query::STATUS_PUBLISHED && has_capability('local/reportsources:approve', $context)) {
        $actions[] = html_writer::link(
            new moodle_url('/local/reportsources/run.php',
                ['id' => $rec->id, 'action' => 'unpublish', 'sesskey' => sesskey()]),
            get_string('unpublish', 'local_reportsources')
        );
    }
    if (has_capability('local/reportsources:author', $context) &&
        ($rec->ownerid == $USER->id || has_capability('local/reportsources:viewall', $context))) {
        $actions[] = html_writer::link(
            new moodle_url('/local/reportsources/delete.php', ['id' => $rec->id, 'sesskey' => sesskey()]),
            get_string('delete', 'local_reportsources'),
            ['class' => 'text-danger']
        );
    }
    $table->data[] = [
        format_string($rec->name),
        $owner ? fullname($owner) : '-',
        get_string($statuskey, 'local_reportsources'),
        implode(' | ', $actions),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
