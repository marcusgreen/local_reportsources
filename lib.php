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
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 */

defined('MOODLE_INTERNAL') || die();

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
