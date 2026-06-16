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
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_reportsources\form\edit_query_form;
use local_reportsources\local\query;
use local_reportsources\local\query_naming;
use local_reportsources\local\sql\validator;

require_login();

$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$aiquestion = optional_param('aiquestion', '', PARAM_RAW_TRIMMED);
$aiaction = optional_param('aiaction', '', PARAM_ALPHA);
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
    if (
        (int) $existing->ownerid !== (int) $USER->id &&
        !has_capability('local/reportsources:viewall', $context)
    ) {
        throw new required_capability_exception($context, 'local/reportsources:viewall', 'nopermissions', '');
    }
}

// The audience picker offers course-scoped options only when the query is bound to a course.
$formcourseid = $existing ? (int) $existing->courseid : $courseid;
$canpublish = has_capability('local/reportsources:approve', $context);
$mform = new edit_query_form(null, ['courseid' => $formcourseid, 'canpublish' => $canpublish]);

// Consolidate form defaults into one object so AI generation can override querysql.
$formdefaults = null;
if ($existing) {
    // Display SQL without {} table braces; auto_brace() re-adds them on save.
    $existing->querysql = validator::strip_braces((string) $existing->querysql);
    // Expand the stored audience choice into the flat form fields.
    foreach (query::explode_audiencemeta($existing->audiencemeta ?? null) as $key => $value) {
        $existing->$key = $value;
    }
    $formdefaults = $existing;
} else if ($courseid) {
    $formdefaults = (object) ['courseid' => $courseid];
}

$airesult = null;
$aierror  = null;
$aisqlchatavailable = class_exists('\local_sqlchat\api')
    && (bool) get_config('local_reportsources', 'aigenerate');

if ($aisqlchatavailable && get_config('local_reportsources', 'syntaxhighlight')) {
    $PAGE->requires->js_call_amd('local_reportsources/ai_feedback', 'init');
}

if ($aisqlchatavailable && $aiaction === 'generate' && $aiquestion !== '') {
    require_sesskey();
    try {
        $airesult = \local_sqlchat\api::generate_sql($aiquestion, $context->id);
        $mergedata = $formdefaults ? (array) $formdefaults : [];
        $mergedata['querysql'] = validator::strip_braces($airesult->sql);
        // Make up a name/description when none exist yet, so the generated query is immediately
        // saveable (name is a required field). A "fix this SQL error" prompt is meaningless as a
        // name, so in that case derive both from the meaning of the generated SQL instead.
        $fromsql = query_naming::is_error_fix_prompt($aiquestion);
        if (trim((string) ($mergedata['name'] ?? '')) === '') {
            $mergedata['name'] = $fromsql
                ? query_naming::from_sql($airesult->sql)
                : query_naming::from_question($aiquestion);
        }
        if (trim((string) ($mergedata['description'] ?? '')) === '') {
            $mergedata['description'] = $fromsql
                ? query_naming::description_from_sql($airesult->sql)
                : $aiquestion;
        }
        $formdefaults = (object) $mergedata;
    } catch (\Throwable $e) {
        $aierror = $e->getMessage();
    }
}

if ($formdefaults !== null) {
    $mform->set_data($formdefaults);
}

$returnurl = new moodle_url(
    '/local/reportsources/index.php',
    $courseid ? ['courseid' => $courseid] : []
);

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    // Prevent an author from scoping a query to a course they have no access to. A courseid that
    // resolves to no course (e.g. stale id from an import) is demoted to site-wide rather than
    // fatalling on context_course::instance().
    if (!empty($data->courseid)) {
        $coursecontext = context_course::instance((int) $data->courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            $data->courseid = 0;
        } else if (
            !has_capability('local/reportsources:viewall', $context) &&
            !has_capability('local/reportsources:view', $coursecontext) &&
            !has_capability('local/reportsources:viewown', $coursecontext)
        ) {
            throw new required_capability_exception($coursecontext, 'local/reportsources:view', 'nopermissions', '');
        }
    }
    $newid = query::save($data);

    // "Save and publish" is a one-click convenience for approvers. The capability is re-checked here
    // (not just on the form button) so a forged submit can't publish. If publishing fails the query
    // is already saved as a draft, so report the failure but keep the saved state.
    if (!empty($data->saveandpublish) && $canpublish) {
        try {
            query::get($newid)->publish();
        } catch (\moodle_exception $e) {
            redirect(
                $returnurl,
                get_string('savedpublishfailed', 'local_reportsources', $e->getMessage()),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        redirect(
            $returnurl,
            get_string('savedandpublished', 'local_reportsources'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

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

if ($aisqlchatavailable) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('ai:heading', 'local_reportsources'), ['class' => 'card-title mt-0']);

    if ($aierror) {
        echo $OUTPUT->notification($aierror, 'error');
    }
    if ($airesult) {
        echo html_writer::tag(
            'p',
            get_string('ai:latency', 'local_reportsources', $airesult->latency_ms),
            ['class' => 'text-muted small mb-2']
        );
    }

    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
    echo html_writer::tag(
        'label',
        get_string('ai:question', 'local_reportsources'),
        ['for' => 'rs-ai-question']
    );
    echo html_writer::tag('textarea', s($aiquestion), [
        'name'        => 'aiquestion',
        'id'          => 'rs-ai-question',
        'rows'        => 2,
        'cols'        => 80,
        'class'       => 'form-control mb-2',
        'placeholder' => get_string('ai:placeholder', 'local_reportsources'),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'aiaction', 'value' => 'generate']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    if ($id) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    }
    if ($courseid) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    }
    echo html_writer::tag('button', get_string('ai:generate', 'local_reportsources'), [
        'type'            => 'submit',
        'id'              => 'rs-ai-generate',
        'class'           => 'btn btn-secondary',
        'data-generating' => get_string('ai:generating', 'local_reportsources'),
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div(); // End card-body.
    echo html_writer::end_div(); // End card.
}

$mform->display();
echo $OUTPUT->footer();
