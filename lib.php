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
 * Library callbacks for the Report sources plugin.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Legacy global navigation hook. Kept for any theme that still renders the
 * flat navigation drawer; Boost (Moodle 4.x+) does not.
 *
 * @param global_navigation $navigation
 */
function local_reportsources_extend_navigation(global_navigation $navigation): void {
    global $USER;
    if (isloggedin() && !isguestuser()) {
        if (
            has_capability('local/reportsources:author', context_system::instance(), $USER) ||
            has_capability('local/reportsources:view', context_system::instance(), $USER)
        ) {
            $node = $navigation->add(
                get_string('reportsources', 'local_reportsources'),
                new moodle_url('/local/reportsources/index.php'),
                navigation_node::TYPE_CUSTOM,
                null,
                'local_reportsources'
            );
            $node->showinflatnavigation = true;
        }
    }
}

/**
 * Add a "Report sources" link under the course Reports menu (Course → More →
 * Reports) for users with author or view capability in the course context.
 *
 * @param navigation_node $parentnode
 * @param stdClass $course
 * @param context_course $context
 */
function local_reportsources_extend_navigation_course(
    navigation_node $parentnode,
    stdClass $course,
    context_course $context
): void {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }
    if (
        !has_capability('local/reportsources:author', $context, $USER) &&
        !has_capability('local/reportsources:view', $context, $USER)
    ) {
        return;
    }

    $reportsnode = $parentnode->get('coursereports');
    if (!$reportsnode) {
        // Core removes the Reports container before local plugin hooks run when
        // no report_* plugin added a link to it; recreate it so the link stays
        // reachable via Course → More → Reports.
        $reportsnode = $parentnode->add(
            get_string('reports'),
            new moodle_url('/report/view.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CONTAINER,
            null,
            'coursereports',
            new pix_icon('i/stats', '')
        );
    }

    $reportsnode->add(
        get_string('reportsources', 'local_reportsources'),
        new moodle_url('/local/reportsources/index.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_reportsources',
        new pix_icon('i/report', '')
    );
}

/**
 * Fragment: render the first page of an unsaved query as a Report Builder report, for the inline
 * Preview button in the edit form.
 *
 * Validates the SQL (same static denylist as publish), (re)creates the author's throwaway preview
 * VIEW, introspects its columns, then renders an ephemeral system report over it. Nothing is
 * persisted — no reportbuilder_report row, no config binding, no audiences. Returned HTML carries
 * the report's own queued JS via the Fragment API, so the client can inject it live.
 *
 * @param array $args Fragment arguments: 'sql' (string), 'courseid' (int), plus core's 'context'.
 * @return string Rendered report HTML (or an inline error notification).
 */
function local_reportsources_output_fragment_preview(array $args): string {
    global $USER, $OUTPUT;

    $context = \context_system::instance();
    require_capability('local/reportsources:author', $context);

    $sql = (string) ($args['sql'] ?? '');
    $courseid = (int) ($args['courseid'] ?? 0);

    // Mirror edit.php: an author may not preview a query scoped to a course they cannot access. A
    // courseid that resolves to no course (stale/imported id) is demoted to site-wide.
    if ($courseid > 0) {
        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            $courseid = 0;
        } else if (
            !has_capability('local/reportsources:viewall', $context) &&
            !has_capability('local/reportsources:view', $coursecontext) &&
            !has_capability('local/reportsources:viewown', $coursecontext)
        ) {
            throw new required_capability_exception($coursecontext, 'local/reportsources:view', 'nopermissions', '');
        }
    }

    try {
        // Same static denylist as the publish path; errors surface inline instead of crashing.
        $validated = \local_reportsources\local\sql\validator::validate($sql);
    } catch (\moodle_exception $e) {
        // Validation failed before any view was created — nothing to clean up.
        return $OUTPUT->notification($e->getMessage(), 'error');
    }

    // Preview is first-page-only, so output() renders the rows synchronously and the throwaway view
    // is not needed once it returns. Drop it in a finally so every exit path — success, inline error
    // or exception — leaves no residual DB view behind.
    try {
        $viewname = \local_reportsources\local\sql\view::create_or_replace_preview($USER->id, $validated, $courseid);
        $columns = \local_reportsources\local\sql\view::columns($viewname);

        // An unaliased expression yields a view column RB cannot build — fail with a clear message.
        if (($badcol = \local_reportsources\local\sql\view::first_unaliased_column($columns)) !== null) {
            $errkey = preg_match('/\s/', $badcol) ? 'erraliasspaces' : 'errcolumnnoalias';
            return $OUTPUT->notification(get_string($errkey, 'local_reportsources', $badcol), 'error');
        }

        $meta = \local_reportsources\local\query::build_columnsmeta($columns, $validated);

        $report = \core_reportbuilder\system_report_factory::create(
            \local_reportsources\reportbuilder\local\systemreports\preview::class,
            $context,
            '',
            '',
            0,
            [
                'viewname'    => $viewname,
                'columnsmeta' => json_encode($meta),
                'title'       => get_string('preview', 'local_reportsources'),
            ]
        );

        // Reuse the just-built preview view to attach the same advisory summary the Test button
        // shows — row count and performance warnings — so a preview doubles as a lightweight test
        // without standing up a second view. Passing $viewname makes the analyser skip its own
        // dry-run and probe view. Advisory only: any failure degrades to just the rendered rows.
        $summary = local_reportsources_preview_summary($sql, $courseid, $viewname);

        return $summary . $report->output();
    } catch (\moodle_exception $e) {
        return $OUTPUT->notification($e->getMessage(), 'error');
    } finally {
        \local_reportsources\local\sql\view::drop_preview($USER->id);
    }
}

/**
 * Build the advisory summary strip shown above the inline preview rows: row count and any
 * performance warnings, from {@see \local_reportsources\local\sql\analyser::analyse()} run against
 * the caller's already-built preview view.
 *
 * @param string $sql Raw author SQL (re-validated inside analyse()).
 * @param int $courseid Bound course id (0 = site-wide).
 * @param string $viewname Unprefixed name of the live preview view to reuse for introspection.
 * @return string Summary HTML, or '' when the analysis yields nothing worth showing.
 */
function local_reportsources_preview_summary(string $sql, int $courseid, string $viewname): string {
    $feedback = \local_reportsources\local\sql\analyser::analyse($sql, $courseid, $viewname);
    if (!$feedback['ok']) {
        // The rows rendered, so a failed advisory pass is not worth surfacing here.
        return '';
    }

    $lines = [];
    if ($feedback['rowcount'] >= 0) {
        $lines[] = get_string('checkrowcount', 'local_reportsources', $feedback['rowcount']);
    }
    $lines = array_merge($lines, $feedback['warnings']);
    if (!$lines) {
        return '';
    }

    $items = array_map(static fn(string $line): string => \html_writer::div(s($line)), $lines);
    return \html_writer::div(implode('', $items), 'alert alert-secondary py-1 mb-2', ['role' => 'status']);
}
