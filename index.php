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

use core_reportbuilder\system_report_factory;
use local_reportsources\reportbuilder\local\systemreports\queries;

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
    // Authors can read the bundled user documentation in the browser.
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/reportsources/docs.php'),
            get_string('userdocs', 'local_reportsources'),
            ['class' => 'btn btn-link p-0']
        ),
        'mb-2'
    );
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

// Render the Bulk actions menu (export / import / delete) shown at the foot of the listing.
$rendertransferbuttons = function () use ($OUTPUT, $syscontext) {
    if (!has_capability('local/reportsources:author', $syscontext)) {
        return;
    }
    $menu = new action_menu();
    $menu->set_menu_trigger(get_string('bulkactions', 'local_reportsources'), 'btn btn-secondary');
    $menu->add(new action_menu_link_secondary(
        new moodle_url('/local/reportsources/export.php'),
        null,
        get_string('export', 'local_reportsources')
    ));
    $menu->add(new action_menu_link_secondary(
        new moodle_url('/local/reportsources/import.php'),
        null,
        get_string('import', 'local_reportsources')
    ));
    $menu->add(new action_menu_link_secondary(
        new moodle_url('/local/reportsources/deletemany.php'),
        null,
        get_string('delete', 'local_reportsources')
    ));
    echo html_writer::div($OUTPUT->render($menu), 'd-flex flex-wrap gap-2 mt-4');
};

// Render the queries listing as a Report Builder system report: paging, per-column sorting and
// filtering come for free. The 'courseid' parameter scopes the base visibility condition to the
// course (see queries::build_visibility_condition()); row visibility mirrors
// query::visible_to_current_user().
$report = system_report_factory::create(
    queries::class,
    $context,
    'local_reportsources',
    '',
    0,
    ['courseid' => $courseid]
);
echo $report->output();

$rendertransferbuttons();
echo $OUTPUT->footer();
