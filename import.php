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
 * Import saved ad-hoc queries from a JSON export file.
 *
 * Step 1: upload the file. Step 2: tick which sources to import. Each chosen source becomes a new
 * draft owned by the importing user. The decoded file is round-tripped through a hidden field
 * between steps so the user never has to upload twice.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 */

require(__DIR__ . '/../../config.php');

use local_reportsources\form\import_form;
use local_reportsources\local\transfer;

require_login();

$context = context_system::instance();
require_capability('local/reportsources:author', $context);

$returnurl = new moodle_url('/local/reportsources/index.php');
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportsources/import.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('import', 'local_reportsources'));
$PAGE->set_heading(get_string('reportsources', 'local_reportsources'));

// Step 3: the selection form was submitted — create the chosen drafts.
if (optional_param('doimport', 0, PARAM_INT)) {
    require_sesskey();
    $payload  = optional_param('payload', '', PARAM_RAW);
    $selected = optional_param_array('sources', [], PARAM_INT);

    $json = base64_decode($payload, true);
    if ($json === false) {
        redirect($returnurl, get_string('errimportformat', 'local_reportsources'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $sources = transfer::parse($json);

    if (!$selected) {
        redirect($returnurl, get_string('errnoimportselection', 'local_reportsources'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $result = transfer::import($sources, $selected);
    $message = get_string('importdone', 'local_reportsources', $result['imported']);
    if ($result['skipped']) {
        $message .= ' ' . get_string('importskipped', 'local_reportsources',
            implode(', ', array_keys($result['skipped'])));
    }
    redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

$mform = new import_form();

if ($mform->is_cancelled()) {
    redirect($returnurl);
}

// Step 2: a file was uploaded — parse it and show the per-source selection form.
if ($data = $mform->get_data()) {
    $json = $mform->get_file_content('importfile');
    if ($json === false) {
        redirect($PAGE->url, get_string('errimportformat', 'local_reportsources'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $sources = transfer::parse($json);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('import', 'local_reportsources'));

    if (!$sources) {
        echo $OUTPUT->notification(get_string('errimportempty', 'local_reportsources'), 'error');
        echo $OUTPUT->single_button($PAGE->url, get_string('back'), 'get');
        echo $OUTPUT->footer();
        exit;
    }

    echo html_writer::tag('p', get_string('importselecthelp', 'local_reportsources'));

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $PAGE->url->out(false),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'doimport', 'value' => 1]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'payload', 'value' => base64_encode($json)]);

    foreach ($sources as $index => $source) {
        $label = format_string($source['name']);
        if ($source['description'] !== '') {
            $label .= ' ' . html_writer::tag('small',
                shorten_text(s($source['description']), 80), ['class' => 'text-muted']);
        }
        echo html_writer::start_div('form-check');
        echo html_writer::empty_tag('input', [
            'type'    => 'checkbox',
            'class'   => 'form-check-input',
            'name'    => 'sources[]',
            'id'      => 's' . $index,
            'value'   => $index,
            'checked' => 'checked',
        ]);
        echo html_writer::tag('label', $label, ['class' => 'form-check-label', 'for' => 's' . $index]);
        echo html_writer::end_div();
    }

    echo html_writer::start_div('mt-3');
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('importselected', 'local_reportsources'),
    ]);
    echo ' ' . html_writer::link($returnurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    echo $OUTPUT->footer();
    exit;
}

// Step 1: show the upload form.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('import', 'local_reportsources'));
echo html_writer::tag('p', get_string('importuploadhelp', 'local_reportsources'));
$mform->display();
echo $OUTPUT->footer();
