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
 * Select saved ad-hoc queries and download them as a JSON export.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_reportsources\local\query;
use local_reportsources\local\transfer;

require_login();

$context = context_system::instance();
require_capability('local/reportsources:author', $context);

$returnurl = new moodle_url('/local/reportsources/index.php');
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportsources/export.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('export', 'local_reportsources'));
$PAGE->set_heading(get_string('reportsources', 'local_reportsources'));

// Download step: build the JSON for the selected ids and stream it.
if (optional_param('download', 0, PARAM_INT)) {
    require_sesskey();
    $ids = optional_param_array('queryids', [], PARAM_INT);
    if (!$ids) {
        redirect($returnurl, get_string('errnoexportselection', 'local_reportsources'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $payload = transfer::export($ids);
    $filename = clean_filename('reportsources-export-' . userdate(time(), '%Y%m%d-%H%M') . '.json');
    send_file(
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $filename,
        0,
        0,
        true,
        true,
        'application/json'
    );
}

$queries = query::visible_to_current_user();
// Authors can only export what they own; viewall users may export anything they can see.
if (!has_capability('local/reportsources:viewall', $context)) {
    $queries = array_filter($queries, static fn($q): bool => (int) $q->ownerid === (int) $USER->id);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('export', 'local_reportsources'));

if (!$queries) {
    echo $OUTPUT->notification(get_string('noqueries', 'local_reportsources'), 'info');
    echo $OUTPUT->single_button($returnurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('exportselecthelp', 'local_reportsources'));

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/reportsources/export.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'download', 'value' => 1]);

foreach ($queries as $rec) {
    $label = format_string($rec->name) .
        ' ' . html_writer::tag('span',
            get_string('status_' . $rec->status, 'local_reportsources'),
            ['class' => 'badge badge-secondary ml-1']);
    echo html_writer::start_div('form-check');
    echo html_writer::empty_tag('input', [
        'type'  => 'checkbox',
        'class' => 'form-check-input',
        'name'  => 'queryids[]',
        'id'    => 'q' . $rec->id,
        'value' => $rec->id,
        'checked' => 'checked',
    ]);
    echo html_writer::tag('label', $label, ['class' => 'form-check-label', 'for' => 'q' . $rec->id]);
    echo html_writer::end_div();
}

echo html_writer::start_div('mt-3');
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('exportselected', 'local_reportsources'),
]);
echo ' ' . html_writer::link($returnurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
