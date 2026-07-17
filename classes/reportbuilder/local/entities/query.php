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

namespace local_reportsources\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{boolean_select, date, select, text};
use core_reportbuilder\local\report\{column, filter};
use html_writer;
use lang_string;
use local_reportsources\local\query as query_model;

/**
 * Report Builder entity describing a saved ad-hoc query record (the report-sources listing itself).
 *
 * This is the meta layer: it lets the plugin render its own list of queries as a system report,
 * giving free sorting, paging, filtering and export. It is unrelated to {@see adhoc_view}, which
 * wraps the published VIEW of a single query's result data.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query extends base {
    /** @var string Internal entity name. */
    public const ENTITY = 'query';

    /**
     * Database tables this entity references.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['local_reportsources_query', 'user', 'course'];
    }

    /**
     * Default title shown as the column-picker group heading.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entityquery', 'local_reportsources');
    }

    /**
     * Add this entity's columns and filters.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter);
        }
        return $this;
    }

    /**
     * LEFT JOIN onto the owner user record.
     *
     * @return string
     */
    private function owner_join(): string {
        $q = $this->get_table_alias('local_reportsources_query');
        $u = $this->get_table_alias('user');
        return "LEFT JOIN {user} {$u} ON {$u}.id = {$q}.ownerid";
    }

    /**
     * LEFT JOIN onto the bound course (courseid 0 = site-wide, so no matching row).
     *
     * @return string
     */
    private function course_join(): string {
        $q = $this->get_table_alias('local_reportsources_query');
        $c = $this->get_table_alias('course');
        return "LEFT JOIN {course} {$c} ON {$c}.id = {$q}.courseid";
    }

    /**
     * Build every column offered by this entity.
     *
     * @return column[]
     */
    private function get_all_columns(): array {
        global $DB;
        $q = $this->get_table_alias('local_reportsources_query');
        $u = $this->get_table_alias('user');
        $c = $this->get_table_alias('course');
        $columns = [];

        // Name. Queries with a chart configured get a leading glyph matching the chart type, so the
        // list tells bar/line/pie apart at a glance (doughnut shares the pie glyph; FA6 has no
        // doughnut icon) — same treatment as the main listing page.
        $columns[] = (new column('name', new lang_string('name', 'local_reportsources'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$q}.name, {$q}.chartmeta")
            ->set_is_sortable(true, ["{$q}.name"])
            ->add_callback(static function ($value, \stdClass $row): string {
                if ($value === null) {
                    return '';
                }
                $name = format_string($value);

                // Cap the column width (main used a 33% html_table size); long names wrap within it.
                $wrap = static fn(string $html): string => \html_writer::span($html, 'rs-query-name');

                $chartmeta = !empty($row->chartmeta) ? json_decode($row->chartmeta, true) : [];
                if (empty($chartmeta['type']) || $chartmeta['type'] === 'none') {
                    return $wrap($name);
                }

                // Glyph + Bootstrap text-colour class per type (theme-aware, dark-mode safe).
                $charticons = [
                    'bar'      => ['fa-chart-column', 'text-primary'],
                    'line'     => ['fa-chart-line', 'text-danger'],
                    'pie'      => ['fa-chart-pie', 'text-success'],
                    'doughnut' => ['fa-chart-pie', 'text-info'],
                ];
                [$faclass, $colourclass] = $charticons[$chartmeta['type']] ?? ['fa-chart-column', 'text-primary'];
                $icon = html_writer::tag('i', '', [
                    'class'       => 'fa ' . $faclass . ' ' . $colourclass . ' me-1',
                    'title'       => get_string('viewchart', 'local_reportsources'),
                    'aria-hidden' => 'true',
                ]);
                return $wrap($icon . $name);
            });

        // Status (draft|published|disabled) rendered via lang string.
        $columns[] = (new column('status', new lang_string('status', 'local_reportsources'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$q}.status")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                if (!$value) {
                    return '';
                }
                // Same badge styling as the main listing page: green published, grey draft,
                // amber anything else (e.g. disabled).
                $badgeclass = [
                    query_model::STATUS_PUBLISHED => 'badge bg-success',
                    query_model::STATUS_DRAFT     => 'badge bg-secondary',
                ][$value] ?? 'badge bg-warning text-dark';
                return html_writer::span(get_string('status_' . $value, 'local_reportsources'), $badgeclass);
            });

        // Owner full name.
        $columns[] = (new column('owner', new lang_string('owner', 'local_reportsources'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->add_join($this->owner_join())
            ->set_type(column::TYPE_TEXT)
            ->add_field($DB->sql_fullname("{$u}.firstname", "{$u}.lastname"), 'fullname')
            ->set_is_sortable(true)
            // Keep the full name on one line so the column claims the room it needs (main used a 17% width).
            ->add_callback(static fn($value): string =>
                \html_writer::span(s($value !== null && $value !== '' ? $value : '-'), 'text-nowrap'));

        // Bound course full name ('-' when site-wide).
        $columns[] = (new column('course', new lang_string('course'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->add_join($this->course_join())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$c}.fullname")
            ->set_is_sortable(true)
            // Keep the course name on one line (freed space from the narrowed Name column), so it is
            // less likely to wrap — the same treatment as the Owner column.
            ->add_callback(static fn($value): string =>
                \html_writer::span($value ? format_string($value) : '-', 'text-nowrap'));

        // Visible flag.
        $columns[] = (new column('visible', new lang_string('visible', 'local_reportsources'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$q}.visible")
            ->set_is_sortable(true)
            ->add_callback([\core_reportbuilder\local\helpers\format::class, 'boolean_as_text']);

        // Timestamps.
        $columns[] = (new column('timemodified', new lang_string('lastmodified', 'local_reportsources'),
            $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$q}.timemodified")
            ->set_is_sortable(true)
            // Compact date-time, e.g. 15/07/26, 22:17 (%d/%m/%y, %H:%M).
            ->add_callback([\core_reportbuilder\local\helpers\format::class, 'userdate'],
                get_string('strftimedatetimeshort', 'langconfig'));

        $columns[] = (new column('timecreated', new lang_string('timecreated', 'local_reportsources'),
            $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$q}.timecreated")
            ->set_is_sortable(true)
            ->add_callback([\core_reportbuilder\local\helpers\format::class, 'userdate']);

        return $columns;
    }

    /**
     * Build every filter offered by this entity.
     *
     * @return filter[]
     */
    private function get_all_filters(): array {
        global $DB;
        $q = $this->get_table_alias('local_reportsources_query');
        $u = $this->get_table_alias('user');
        $filters = [];

        // Name (partial text match).
        $filters[] = (new filter(text::class, 'name', new lang_string('name', 'local_reportsources'),
            $this->get_entity_name(), "{$q}.name"))
            ->add_joins($this->get_joins());

        // Status (fixed option list).
        $filters[] = (new filter(select::class, 'status', new lang_string('status', 'local_reportsources'),
            $this->get_entity_name(), "{$q}.status"))
            ->add_joins($this->get_joins())
            ->set_options([
                query_model::STATUS_DRAFT     => get_string('status_draft', 'local_reportsources'),
                query_model::STATUS_PUBLISHED => get_string('status_published', 'local_reportsources'),
            ]);

        // Owner full name (partial text match).
        $filters[] = (new filter(text::class, 'owner', new lang_string('owner', 'local_reportsources'),
            $this->get_entity_name(), $DB->sql_fullname("{$u}.firstname", "{$u}.lastname")))
            ->add_joins($this->get_joins())
            ->add_join($this->owner_join());

        // Bound course full name — standard text criteria filter (Contains / Is equal to / …).
        // Site-wide rows have no course row (courseid = 0), so "Is empty" matches them.
        $c = $this->get_table_alias('course');
        $filters[] = (new filter(text::class, 'course', new lang_string('course'),
            $this->get_entity_name(), "{$c}.fullname"))
            ->add_joins($this->get_joins())
            ->add_join($this->course_join());

        // Visible flag.
        $filters[] = (new filter(boolean_select::class, 'visible', new lang_string('visible', 'local_reportsources'),
            $this->get_entity_name(), "{$q}.visible"))
            ->add_joins($this->get_joins());

        // Last-modified / created date ranges.
        $filters[] = (new filter(date::class, 'timemodified', new lang_string('lastmodified', 'local_reportsources'),
            $this->get_entity_name(), "{$q}.timemodified"))
            ->add_joins($this->get_joins());
        $filters[] = (new filter(date::class, 'timecreated', new lang_string('timecreated', 'local_reportsources'),
            $this->get_entity_name(), "{$q}.timecreated"))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
