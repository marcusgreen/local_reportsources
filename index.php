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
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_reportsources\local\query;

$courseid = optional_param('courseid', 0, PARAM_INT);
// Column sort: blank keeps the default timemodified DESC ordering from visible_to_current_user().
$tsort = optional_param('tsort', '', PARAM_ALPHA);
$tdir  = optional_param('tdir', 'asc', PARAM_ALPHA) === 'desc' ? 'desc' : 'asc';
if (!in_array($tsort, ['name', 'owner', 'status'], true)) {
    $tsort = '';
}

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
    // Wrapped with a stable id so the user tour can anchor a step to the New report view button.
    echo html_writer::div(
        $OUTPUT->single_button(
            new moodle_url(
                '/local/reportsources/edit.php',
                $courseid ? ['courseid' => $courseid] : []
            ),
            get_string('addnew', 'local_reportsources'),
            'get'
        ),
        '',
        ['id' => 'rs-tour-newbutton']
    );
}

// Render the Export / Import buttons shown at the foot of the listing.
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

// Resolve owner full names once so they can drive both the owner-column sort and the rendered cell.
$ownernames = [];
foreach ($queries as $rec) {
    $owner = core_user::get_user($rec->ownerid);
    $ownernames[$rec->id] = $owner ? fullname($owner) : '-';
}

// Apply the column sort when one is selected. The default (no tsort) keeps the timemodified DESC
// order returned by the query, so this only runs on explicit user request.
if ($tsort) {
    $dir = $tdir === 'desc' ? -1 : 1;
    uasort($queries, function ($a, $b) use ($tsort, $dir, $ownernames) {
        switch ($tsort) {
            case 'owner':
                $cmp = strcasecmp($ownernames[$a->id], $ownernames[$b->id]);
                break;
            case 'status':
                $cmp = strcasecmp($a->status, $b->status);
                break;
            default:
                $cmp = strcasecmp($a->name, $b->name);
        }
        return $cmp * $dir;
    });
}

// Build a sortable column header: a link that re-requests the page sorted by $column, toggling the
// direction when the column is already the active sort. An arrow marks the active column/direction.
$sortheader = function (string $column, string $label) use ($PAGE, $tsort, $tdir) {
    $active = ($tsort === $column);
    $nextdir = ($active && $tdir === 'asc') ? 'desc' : 'asc';
    $url = new moodle_url($PAGE->url, ['tsort' => $column, 'tdir' => $nextdir]);
    $arrow = '';
    if ($active) {
        $arrow = ' ' . ($tdir === 'asc' ? '▲' : '▼');
    }
    return html_writer::link($url, $label . $arrow);
};

$table = new html_table();
$table->id = 'rs-tour-table';
$table->attributes['class'] = 'generaltable table table-hover';
// Spread the columns evenly across the full page width rather than letting the long Name column
// absorb all the slack.
$table->size = ['40%', '20%', '15%', '25%'];
$table->head = [
    $sortheader('name', get_string('name', 'local_reportsources')),
    $sortheader('owner', get_string('owner', 'local_reportsources')),
    $sortheader('status', get_string('status', 'local_reportsources')),
    get_string('actions', 'local_reportsources'),
];
// Keep the actions cell on one line so the kebab menu and buttons never wrap.
$table->colclasses = ['', '', '', 'text-nowrap'];

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

    $urlcourse = $courseid ? ['courseid' => $courseid] : [];
    $chartmeta = $rec->chartmeta ? json_decode($rec->chartmeta, true) : [];
    $haschart = !empty($chartmeta['type']) && $chartmeta['type'] !== 'none';
    $reportname = format_string($rec->name);
    // Mark queries that have a chart configured with the same graph icon shown in the action menu.
    $namecell = $reportname;
    if ($haschart) {
        $namecell = $OUTPUT->pix_icon('i/chartbar', get_string('viewchart', 'local_reportsources'),
            'moodle', ['class' => 'me-1']) . $reportname;
    }

    // The most-used action (open the published report) stays as an inline button; everything else
    // goes into a kebab action menu so the row stays short and scannable.
    if ($canviewreport) {
        $primary = html_writer::link(
            new moodle_url('/reportbuilder/view.php', ['id' => $rec->reportid]),
            get_string('runreport', 'local_reportsources'),
            // Row-specific accessible name so the link is distinguishable when the visible label
            // "Open report" repeats down the list.
            ['class' => 'btn btn-sm btn-primary me-2',
                'aria-label' => get_string('runreportfor', 'local_reportsources', $reportname)]
        );
    } else {
        // No Open report button on this row: reserve its width with an invisible placeholder so any
        // Edit button (and the column edge) lines up with the rows that do have the Open button.
        $primary = html_writer::span(
            get_string('runreport', 'local_reportsources'),
            'btn btn-sm btn-primary me-2 invisible',
            ['aria-hidden' => 'true']
        );
    }

    // Editing the query is the most common action after opening, so it sits inline as a button
    // next to Open report rather than in the kebab menu.
    $editbtn = '';
    if (has_capability('local/reportsources:author', $syscontext)) {
        $editbtn = html_writer::link(
            new moodle_url('/local/reportsources/edit.php', ['id' => $rec->id] + $urlcourse),
            get_string('edit', 'local_reportsources'),
            ['class' => 'btn btn-sm btn-secondary me-2',
                'aria-label' => get_string('editfor', 'local_reportsources', $reportname)]
        );
    }

    // Editing the underlying Report builder report sits inline next to Edit, for users who can edit
    // RB reports and only when the report exists (published rows the current user can view).
    $editreportbtn = '';
    if ($canviewreport && has_capability('moodle/reportbuilder:edit', $syscontext)) {
        $editreportbtn = html_writer::link(
            new moodle_url('/reportbuilder/edit.php', ['id' => $rec->reportid]),
            get_string('editreport', 'local_reportsources'),
            ['class' => 'btn btn-sm btn-secondary me-2',
                'aria-label' => get_string('editreport', 'local_reportsources') . ' ' . $reportname]
        );
    }

    // Unpublish sits inline as a button, but only for rows that are actually published.
    $unpublishbtn = '';
    if ($rec->status === query::STATUS_PUBLISHED && has_capability('local/reportsources:approve', $syscontext)) {
        $unpublishbtn = html_writer::link(
            new moodle_url('/local/reportsources/run.php',
                ['id' => $rec->id, 'action' => 'unpublish', 'sesskey' => sesskey()]),
            get_string('unpublish', 'local_reportsources'),
            ['class' => 'btn btn-sm btn-secondary me-2',
                'aria-label' => get_string('unpublishfor', 'local_reportsources', $reportname)]
        );
    }

    $menu = new action_menu();
    // Row-specific trigger label: a generic "Actions" repeats for every row and gives a screen
    // reader no way to tell the menus apart.
    $menu->set_kebab_trigger(get_string('actionsfor', 'local_reportsources', $reportname));

    if ($canviewreport) {
        if ($haschart) {
            $menu->add(new action_menu_link_secondary(
                new moodle_url('/local/reportsources/chart.php', ['id' => $rec->id] + $urlcourse),
                new pix_icon('i/chartbar', ''),
                get_string('viewchart', 'local_reportsources')
            ));
        }
        if (has_capability('moodle/reportbuilder:edit', $syscontext)) {
            // Deep-link to the report's Schedules tab. The RB editor uses JS dynamic tabs whose ids
            // are the short class name (schedules); core/dynamic_tabs activates the matching tab from
            // the URL hash. Recipients are the report's RB audiences, set at publish.
            $menu->add(new action_menu_link_secondary(
                new moodle_url('/reportbuilder/edit.php', ['id' => $rec->reportid], 'schedules'),
                new pix_icon('i/scheduled', ''),
                get_string('schedule', 'local_reportsources')
            ));
        }
    }
    if ($rec->status === query::STATUS_PUBLISHED && has_capability('local/reportsources:approve', $syscontext)) {
        $menu->add(new action_menu_link_secondary(
            new moodle_url('/local/reportsources/run.php', ['id' => $rec->id, 'action' => 'newreport', 'sesskey' => sesskey()]),
            new pix_icon('t/add', ''),
            get_string('newreport', 'local_reportsources')
        ));
    }
    if ($rec->status === query::STATUS_DRAFT && has_capability('local/reportsources:approve', $syscontext)) {
        $menu->add(new action_menu_link_secondary(
            new moodle_url('/local/reportsources/run.php', ['id' => $rec->id, 'action' => 'publish', 'sesskey' => sesskey()]),
            new pix_icon('t/show', ''),
            get_string('publish', 'local_reportsources')
        ));
    }
    if (has_capability('local/reportsources:author', $syscontext)) {
        $menu->add(new action_menu_link_secondary(
            new moodle_url('/local/reportsources/run.php', ['id' => $rec->id, 'action' => 'copy', 'sesskey' => sesskey()]),
            new pix_icon('t/copy', ''),
            get_string('duplicate', 'local_reportsources')
        ));
    }
    if (
        has_capability('local/reportsources:author', $syscontext) &&
        ($rec->ownerid == $USER->id || has_capability('local/reportsources:viewall', $syscontext))
    ) {
        $menu->add(new action_menu_link_secondary(
            new moodle_url('/local/reportsources/delete.php', ['id' => $rec->id, 'sesskey' => sesskey()]),
            new pix_icon('t/delete', ''),
            get_string('delete', 'local_reportsources'),
            ['class' => 'text-danger']
        ));
    }

    $badgeclass = [
        query::STATUS_PUBLISHED => 'badge bg-success',
        query::STATUS_DRAFT     => 'badge bg-secondary',
    ][$rec->status] ?? 'badge bg-warning text-dark';
    $statusbadge = html_writer::span(get_string('status_' . $rec->status, 'local_reportsources'), $badgeclass);

    // Kebab leads the actions cell, followed by the inline buttons, all on one flex line.
    $actionscell = html_writer::div(
        html_writer::div($OUTPUT->render($menu), 'me-2') . $primary . $editbtn . $editreportbtn . $unpublishbtn,
        'd-flex align-items-center flex-nowrap'
    );

    $table->data[] = [
        $namecell,
        $ownernames[$rec->id],
        $statusbadge,
        $actionscell,
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
