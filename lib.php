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
        if (has_capability('local/reportsources:author', context_system::instance(), $USER) ||
            has_capability('local/reportsources:view', context_system::instance(), $USER)) {
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
 * Inject an "Ad-hoc reports" link into the primary navbar.
 *
 * Moodle core calls every plugin's `*_render_navbar_output` and concatenates
 * the returned HTML into the top navbar. Used here because Boost no longer
 * renders nodes added via {@see local_reportsources_extend_navigation()}.
 *
 * @param renderer_base $renderer
 * @return string HTML for the navbar, or empty string if the user has no access.
 */
function local_reportsources_render_navbar_output(\renderer_base $renderer): string {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    $context = context_system::instance();
    if (!has_capability('local/reportsources:author', $context, $USER) &&
        !has_capability('local/reportsources:view', $context, $USER)) {
        return '';
    }

    $url   = new moodle_url('/local/reportsources/index.php');
    $label = get_string('reportsources', 'local_reportsources');

    return html_writer::div(
        html_writer::link($url, $label, [
            'class' => 'nav-link',
            'title' => $label,
        ]),
        'popover-region nav-item local_reportsources-navbar'
    );
}
