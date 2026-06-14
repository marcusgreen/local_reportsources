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

$courseid = optional_param('courseid', 0, PARAM_INT);

if ($courseid) {
    require_login($courseid);
    $context = context_course::instance($courseid);
    if (
        !has_capability('local/reportsources:view', $context) &&
        !has_capability('local/reportsources:viewown', $context) &&
        !has_capability('local/reportsources:author', context_system::instance()) &&
        !has_capability('local/reportsources:viewall', context_system::instance())
    ) {
        require_capability('local/reportsources:view', $context);
    }
} else {
    require_login();
    $context = context_system::instance();
    if (
        !has_capability('local/reportsources:viewall', $context) &&
        !has_capability('local/reportsources:author', $context) &&
        !has_capability('local/reportsources:view', $context)
    ) {
        require_capability('local/reportsources:view', $context);
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(
    '/local/reportsources/index.php',
    $courseid ? ['courseid' => $courseid] : []
));
$PAGE->set_pagelayout($courseid ? 'incourse' : 'admin');
$PAGE->set_title(get_string('queries', 'local_reportsources'));
$PAGE->set_heading(get_string('reportsources', 'local_reportsources'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('queries', 'local_reportsources') .
    $OUTPUT->help_icon('pluginexplained', 'local_reportsources'));

$syscontext = context_system::instance();
if (has_capability('local/reportsources:author', $syscontext)) {
    echo $OUTPUT->single_button(
        new moodle_url(
            '/local/reportsources/edit.php',
            $courseid ? ['courseid' => $courseid] : []
        ),
        get_string('addnew', 'local_reportsources'),
        'get'
    );
}

/**
 * Render the Export / Import buttons shown at the foot of the listing.
 */
$rendertransferbuttons = function () use ($OUTPUT, $syscontext) {
    if (!has_capability('local/reportsources:author', $syscontext)) {
        return;
    }
    echo html_writer::start_div('d-flex flex-wrap gap-2 mt-4');
    echo $OUTPUT->single_button(
        new moodle_url('/local/reportsources/export.php'),
        get_string('export', 'local_reportsources'),
        'get'
    );
    echo $OUTPUT->single_button(
        new moodle_url('/local/reportsources/import.php'),
        get_string('import', 'local_reportsources'),
        'get'
    );
    echo html_writer::end_div();
};

$queries = query::visible_to_current_user($courseid);
if (!$queries) {
    echo $OUTPUT->notification(get_string('noqueries', 'local_reportsources'), 'info');
    $rendertransferbuttons();
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

// Audience-allowed report ids for the current user, fetched once. can_view_report() would run this
// same query per row, so we hoist it and do the remaining (cheap, cached) capability checks inline.
// Note: user_reports_list() returns report ids as strings; cast so the strict in_array() below matches.
$allowedreports = array_map('intval', \core_reportbuilder\local\helpers\audience::user_reports_list());

// Managers (author/approve/viewall) administer the queries themselves, so they always see every
// listed row. A pure viewer (view/viewown only) should not see a row whose underlying RB report
// audience excludes them — listing it leaks the query's name/owner without an openable report.
$canmanage = has_capability('local/reportsources:author', $syscontext)
    || has_capability('local/reportsources:approve', $syscontext)
    || has_capability('local/reportsources:viewall', $syscontext);

foreach ($queries as $rec) {
    // Only offer the report links if the current user can actually open the underlying RB report;
    // its audience may exclude them even though the query is listed here.
    $canviewreport = false;
    if ($rec->status === query::STATUS_PUBLISHED && $rec->reportid) {
        $reportmodel = \core_reportbuilder\local\models\report::get_record(['id' => $rec->reportid]);
        if ($reportmodel) {
            // Mirror core_reportbuilder\permission::can_view_report() but reuse the pre-fetched
            // audience list instead of re-querying it for every row.
            $reportcontext = $reportmodel->get_context();
            $canviewreport = \core_reportbuilder\permission::can_view_reports_list(null, $reportcontext)
                && (has_capability('moodle/reportbuilder:viewall', $reportcontext)
                    || \core_reportbuilder\permission::can_edit_report($reportmodel)
                    || in_array((int) $reportmodel->get('id'), $allowedreports, true));
        }
    }

    // Pure viewers see only rows they can actually open.
    if (!$canmanage && !$canviewreport) {
        continue;
    }

    $owner = core_user::get_user($rec->ownerid);
    $statuskey = 'status_' . $rec->status;
    $actions = [];
    if (has_capability('local/reportsources:author', $syscontext)) {
        $actions[] = html_writer::link(
            new moodle_url(
                '/local/reportsources/edit.php',
                ['id' => $rec->id] + ($courseid ? ['courseid' => $courseid] : [])
            ),
            get_string('edit', 'local_reportsources')
        );
    }
    if ($canviewreport) {
        $chartmeta = $rec->chartmeta ? json_decode($rec->chartmeta, true) : [];
        if (!empty($chartmeta['type']) && $chartmeta['type'] !== 'none') {
            $actions[] = html_writer::link(
                new moodle_url(
                    '/local/reportsources/chart.php',
                    ['id' => $rec->id] + ($courseid ? ['courseid' => $courseid] : [])
                ),
                $OUTPUT->pix_icon('i/chartbar', '', 'moodle', ['class' => 'iconsmall me-1']) .
                    get_string('viewchart', 'local_reportsources')
            );
        }
        $actions[] = html_writer::link(
            new moodle_url('/reportbuilder/view.php', ['id' => $rec->reportid]),
            get_string('runreport', 'local_reportsources')
        );
        if (has_capability('moodle/reportbuilder:edit', $syscontext)) {
            $actions[] = html_writer::link(
                new moodle_url('/reportbuilder/edit.php', ['id' => $rec->reportid]),
                get_string('editreport', 'local_reportsources')
            );
            // Deep-link to the report's Schedules tab. The RB editor uses JS dynamic tabs whose ids
            // are the short class name (schedules); core/dynamic_tabs activates the matching tab from
            // the URL hash. Recipients are the report's RB audiences, set at publish.
            $actions[] = html_writer::link(
                new moodle_url('/reportbuilder/edit.php', ['id' => $rec->reportid], 'schedules'),
                $OUTPUT->pix_icon('i/scheduled', '', 'moodle', ['class' => 'iconsmall me-1']) .
                    get_string('schedule', 'local_reportsources')
            );
        }
    }
    if ($rec->status === query::STATUS_PUBLISHED && has_capability('local/reportsources:approve', $syscontext)) {
        $actions[] = html_writer::link(
            new moodle_url(
                '/local/reportsources/run.php',
                ['id' => $rec->id, 'action' => 'newreport', 'sesskey' => sesskey()]
            ),
            get_string('newreport', 'local_reportsources')
        );
    }
    if ($rec->status === query::STATUS_DRAFT && has_capability('local/reportsources:approve', $syscontext)) {
        $actions[] = html_writer::link(
            new moodle_url(
                '/local/reportsources/run.php',
                ['id' => $rec->id, 'action' => 'publish', 'sesskey' => sesskey()]
            ),
            get_string('publish', 'local_reportsources')
        );
    }
    if ($rec->status === query::STATUS_PUBLISHED && has_capability('local/reportsources:approve', $syscontext)) {
        $actions[] = html_writer::link(
            new moodle_url(
                '/local/reportsources/run.php',
                ['id' => $rec->id, 'action' => 'unpublish', 'sesskey' => sesskey()]
            ),
            get_string('unpublish', 'local_reportsources')
        );
    }
    if (has_capability('local/reportsources:author', $syscontext)) {
        $actions[] = html_writer::link(
            new moodle_url(
                '/local/reportsources/run.php',
                ['id' => $rec->id, 'action' => 'copy', 'sesskey' => sesskey()]
            ),
            get_string('duplicate', 'local_reportsources')
        );
    }
    if (
        has_capability('local/reportsources:author', $syscontext) &&
        ($rec->ownerid == $USER->id || has_capability('local/reportsources:viewall', $syscontext))
    ) {
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

if (empty($table->data)) {
    // Every visible query was filtered out by the per-report audience check above.
    echo $OUTPUT->notification(get_string('noqueries', 'local_reportsources'), 'info');
} else {
    echo html_writer::table($table);
}
$rendertransferbuttons();
echo $OUTPUT->footer();
