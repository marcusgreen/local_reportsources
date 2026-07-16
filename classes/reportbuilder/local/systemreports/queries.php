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

declare(strict_types=1);

namespace local_reportsources\reportbuilder\local\systemreports;

use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\action;
use core_reportbuilder\local\report\column;
use core_reportbuilder\system_report;
use html_writer;
use lang_string;
use local_reportsources\local\query;
use local_reportsources\reportbuilder\local\entities\query as query_entity;
use moodle_url;
use pix_icon;

/**
 * System report listing saved ad-hoc queries (report sources), with paging, sorting and filtering.
 *
 * Row visibility mirrors {@see query::visible_to_current_user()} via a SQL base condition, so the
 * report never surfaces a query the current user is not entitled to see on the plugin's own pages.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queries extends system_report {
    /**
     * Initialise the report: main table, entity, columns, filters, actions.
     */
    protected function initialise(): void {
        $entity = new query_entity();
        $entityname = $entity->get_entity_name();
        $alias = $entity->get_table_alias('local_reportsources_query');

        $this->set_main_table('local_reportsources_query', $alias);
        $this->add_entity($entity);

        // Base fields consumed by the row action URLs and their per-row visibility callbacks below.
        $this->add_base_fields("{$alias}.id, {$alias}.status, {$alias}.reportid, {$alias}.ownerid, {$alias}.chartmeta");

        $this->add_columns_from_entities([
            "{$entityname}:name",
            "{$entityname}:owner",
            "{$entityname}:course",
            "{$entityname}:status",
            "{$entityname}:visible",
            "{$entityname}:timemodified",
        ]);

        // The most-used actions (Open report / Edit query / Edit in Report Builder) render as inline
        // buttons in their own column; the rest stay in the row's kebab menu (see add_report_actions()).
        $this->add_buttons_column($entityname, $alias);

        // Course filter is redundant when the listing is already scoped to a course via the
        // 'courseid' report parameter, so only offer it on the site-wide listing.
        $courseid = (int) $this->get_parameter('courseid', 0, PARAM_INT);
        $filters = [
            "{$entityname}:name",
            "{$entityname}:owner",
        ];
        if (!$courseid) {
            $filters[] = "{$entityname}:course";
        }
        $filters = array_merge($filters, [
            "{$entityname}:status",
            "{$entityname}:visible",
            "{$entityname}:timemodified",
            "{$entityname}:timecreated",
        ]);
        $this->add_filters_from_entities($filters);

        $this->add_report_actions();

        [$where, $params] = $this->build_visibility_condition();
        $this->add_base_condition_sql($where, $params);

        $this->set_downloadable(true, get_string('reportsources', 'local_reportsources'));
    }

    /**
     * Only users allowed on the plugin's listing pages may view this report.
     *
     * @return bool
     */
    protected function can_view(): bool {
        $context = $this->get_context();
        return has_capability('local/reportsources:viewall', $context)
            || has_capability('local/reportsources:author', $context)
            || has_capability('local/reportsources:view', $context)
            || has_capability('local/reportsources:viewown', $context);
    }

    /**
     * Build the [$where, $params] pair matching query::visible_to_current_user(). The optional
     * 'courseid' report parameter scopes the listing to a course (site-wide queries always included).
     *
     * @return array{0:string,1:array}
     */
    private function build_visibility_condition(): array {
        global $USER;

        $alias = $this->get_main_table_alias();
        $courseid = (int) $this->get_parameter('courseid', 0, PARAM_INT);
        $syscontext = \context_system::instance();
        $coursecontext = $courseid ? \context_course::instance($courseid) : $syscontext;

        // Report Builder requires all base-condition param names to come from generate_param_name().
        $paramcourse = database::generate_param_name();
        $paramuser = database::generate_param_name();
        $parampub = database::generate_param_name();

        // Course-scope clause reused by several branches (site-wide rows always included).
        $scope = '';
        $scopeparams = [];
        if ($courseid) {
            $scope = " AND ({$alias}.courseid = :{$paramcourse} OR {$alias}.courseid = 0)";
            $scopeparams[$paramcourse] = $courseid;
        }

        // viewall — every query (course-scoped when a course is given).
        if (has_capability('local/reportsources:viewall', $syscontext)) {
            return $courseid
                ? ["({$alias}.courseid = :{$paramcourse} OR {$alias}.courseid = 0)", [$paramcourse => $courseid]]
                : ['1 = 1', []];
        }

        // author — own queries, plus any published+visible query.
        if (has_capability('local/reportsources:author', $syscontext)) {
            $where = "({$alias}.ownerid = :{$paramuser}"
                . " OR ({$alias}.status = :{$parampub} AND {$alias}.visible = 1)){$scope}";
            return [$where, [$paramuser => $USER->id, $parampub => query::STATUS_PUBLISHED] + $scopeparams];
        }

        // Course-level viewer (teacher) — needs a course and view/viewown there.
        if (
            $courseid && (
            has_capability('local/reportsources:view', $coursecontext) ||
            has_capability('local/reportsources:viewown', $coursecontext)
            )
        ) {
            $where = "{$alias}.status = :{$parampub} AND {$alias}.visible = 1"
                . " AND ({$alias}.courseid = :{$paramcourse} OR {$alias}.courseid = 0)";
            return [$where, [$parampub => query::STATUS_PUBLISHED, $paramcourse => $courseid]];
        }

        // System viewer fallback — published + visible, site-wide only.
        if (has_capability('local/reportsources:view', $syscontext)) {
            return [
                "{$alias}.status = :{$parampub} AND {$alias}.visible = 1 AND {$alias}.courseid = 0",
                [$parampub => query::STATUS_PUBLISHED],
            ];
        }

        // Nothing visible.
        return ['1 = 0', []];
    }

    /**
     * Add a leading column rendering the most-used actions (Open report / Edit query / Edit in
     * Report Builder) as inline buttons, so they sit outside the kebab menu holding the rest.
     *
     * @param string $entityname entity the column is attached to
     * @param string $alias main-table alias for the button-gating fields
     * @return void
     */
    private function add_buttons_column(string $entityname, string $alias): void {
        $courseid = (int) $this->get_parameter('courseid', 0, PARAM_INT);
        $urlcourse = $courseid ? ['courseid' => $courseid] : [];

        $column = (new column('buttons', new lang_string('actions', 'local_reportsources'), $entityname))
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$alias}.id, {$alias}.status, {$alias}.reportid, {$alias}.ownerid")
            ->set_is_sortable(false)
            ->add_callback(static function ($value, \stdClass $row) use ($urlcourse): string {
                global $USER;
                $buttons = '';

                // Open the published report.
                if ($row->status === query::STATUS_PUBLISHED && !empty($row->reportid)) {
                    $buttons .= html_writer::link(
                        new moodle_url('/reportbuilder/view.php', ['id' => $row->reportid]),
                        get_string('runreport', 'local_reportsources'),
                        ['class' => 'btn btn-sm btn-primary me-1']
                    );
                }

                // Edit the query. Admin-owned queries are locked to site admins.
                $canmodify = !is_siteadmin($row->ownerid) || is_siteadmin($USER);
                if ($canmodify && has_capability('local/reportsources:author', \context_system::instance())) {
                    $buttons .= html_writer::link(
                        new moodle_url('/local/reportsources/edit.php', ['id' => $row->id] + $urlcourse),
                        get_string('edit'),
                        ['class' => 'btn btn-sm btn-secondary me-1']
                    );
                }

                // Edit the underlying Report Builder report (RB editors only).
                if (
                    $row->status === query::STATUS_PUBLISHED && !empty($row->reportid)
                    && has_any_capability(
                        ['moodle/reportbuilder:edit', 'moodle/reportbuilder:editall'],
                        \context_system::instance()
                    )
                ) {
                    $buttons .= html_writer::link(
                        new moodle_url('/reportbuilder/edit.php', ['id' => $row->reportid]),
                        get_string('editreport', 'local_reportsources'),
                        ['class' => 'btn btn-sm btn-secondary']
                    );
                }

                return $buttons;
            });

        $this->add_column($column);
    }

    /**
     * Add the per-row kebab action links, mirroring the plugin's own listing page.
     *
     * Open report, Edit query and Edit in Report Builder render as inline buttons (see
     * add_buttons_column()); this covers the remainder: Unpublish, View chart, Schedule emails,
     * New report, Publish, Duplicate and Delete. Each is gated by the same capability / status /
     * owner-lock rules the hand-rolled index.php table used.
     *
     * @return void
     */
    private function add_report_actions(): void {
        // The bound course carries through the query/chart edit URLs so those pages stay in-course.
        $courseid = (int) $this->get_parameter('courseid', 0, PARAM_INT);
        $urlcourse = $courseid ? ['courseid' => $courseid] : [];

        // Admin-owned queries are locked to site admins (mirrors index.php row guard).
        $canmodifyrow = static function (\stdClass $row): bool {
            global $USER;
            return !is_siteadmin($row->ownerid) || is_siteadmin($USER);
        };

        // Unpublish a published query.
        $this->add_action((new action(
            new moodle_url(
                '/local/reportsources/run.php',
                ['id' => ':id', 'action' => 'unpublish', 'sesskey' => sesskey()]
            ),
            new pix_icon('t/hide', ''),
            [],
            false,
            new lang_string('unpublish', 'local_reportsources')
        ))->add_callback(static function (\stdClass $row) use ($canmodifyrow): bool {
            return $canmodifyrow($row) && $row->status === query::STATUS_PUBLISHED
                && has_capability('local/reportsources:approve', \context_system::instance());
        }));

        // View the configured chart (only when the query has one).
        $this->add_action((new action(
            new moodle_url('/local/reportsources/chart.php', ['id' => ':id'] + $urlcourse),
            new pix_icon('i/chartbar', ''),
            [],
            false,
            new lang_string('viewchart', 'local_reportsources')
        ))->add_callback(static function (\stdClass $row): bool {
            if ($row->status !== query::STATUS_PUBLISHED || empty($row->reportid)) {
                return false;
            }
            $chartmeta = $row->chartmeta ? json_decode($row->chartmeta, true) : [];
            return !empty($chartmeta['type']) && $chartmeta['type'] !== 'none';
        }));

        // Deep-link to the report's Schedules tab (RB editors only).
        $this->add_action((new action(
            new moodle_url('/reportbuilder/edit.php', ['id' => ':reportid'], 'schedules'),
            new pix_icon('i/scheduled', ''),
            [],
            false,
            new lang_string('schedule', 'local_reportsources')
        ))->add_callback(static function (\stdClass $row): bool {
            return $row->status === query::STATUS_PUBLISHED && !empty($row->reportid)
                && has_any_capability(
                    ['moodle/reportbuilder:edit', 'moodle/reportbuilder:editall'],
                    \context_system::instance()
                );
        }));

        // Create an additional report from a published query.
        $this->add_action((new action(
            new moodle_url(
                '/local/reportsources/run.php',
                ['id' => ':id', 'action' => 'newreport', 'sesskey' => sesskey()]
            ),
            new pix_icon('t/add', ''),
            [],
            false,
            new lang_string('newreport', 'local_reportsources')
        ))->add_callback(static function (\stdClass $row) use ($canmodifyrow): bool {
            return $canmodifyrow($row) && $row->status === query::STATUS_PUBLISHED
                && has_capability('local/reportsources:approve', \context_system::instance());
        }));

        // Publish a draft query.
        $this->add_action((new action(
            new moodle_url(
                '/local/reportsources/run.php',
                ['id' => ':id', 'action' => 'publish', 'sesskey' => sesskey()]
            ),
            new pix_icon('t/show', ''),
            [],
            false,
            new lang_string('publish', 'local_reportsources')
        ))->add_callback(static function (\stdClass $row) use ($canmodifyrow): bool {
            return $canmodifyrow($row) && $row->status === query::STATUS_DRAFT
                && has_capability('local/reportsources:approve', \context_system::instance());
        }));

        // Duplicate the query (any author).
        $this->add_action((new action(
            new moodle_url(
                '/local/reportsources/run.php',
                ['id' => ':id', 'action' => 'copy', 'sesskey' => sesskey()]
            ),
            new pix_icon('t/copy', ''),
            [],
            false,
            new lang_string('duplicate', 'local_reportsources')
        ))->add_callback(static function (\stdClass $row): bool {
            return has_capability('local/reportsources:author', \context_system::instance());
        }));

        // Delete the query.
        $this->add_action((new action(
            new moodle_url('/local/reportsources/delete.php', ['id' => ':id', 'sesskey' => sesskey()]),
            new pix_icon('t/delete', ''),
            [],
            false,
            new lang_string('delete')
        ))->add_callback(static function (\stdClass $row) use ($canmodifyrow): bool {
            global $USER;
            return $canmodifyrow($row)
                && has_capability('local/reportsources:author', \context_system::instance())
                && ($row->ownerid == $USER->id
                    || has_capability('local/reportsources:viewall', \context_system::instance()));
        }));
    }
}
