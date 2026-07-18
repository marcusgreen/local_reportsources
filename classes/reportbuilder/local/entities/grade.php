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

use lang_string;
use stdClass;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{date, number, select, text};
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\{column, filter};

/**
 * Gradebook grade entity for Report Builder.
 *
 * Exposes rows from {grade_grades} joined to {grade_items}. The raw finalgrade is kept
 * as the sortable/filterable numeric field; the human-facing value is produced by a
 * callback that runs it through the gradebook display API (respecting each item's
 * display type + scale) and gates hidden grades behind moodle/grade:viewhidden.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'grade_grades',
            'grade_items',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('gradessource:grade', 'local_reportsources');
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_available_columns(): array {
        $gg = $this->get_table_alias('grade_grades');
        $gi = $this->get_table_alias('grade_items');

        // Item name. Falls back to item type/module for module items that store their
        // name on the activity rather than in grade_items.itemname.
        $columns[] = (new column(
            'itemname',
            new lang_string('gradeitem', 'core_grades'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_field("{$gi}.itemname")
            ->add_fields("{$gi}.itemtype AS giitemtype, {$gi}.itemmodule AS giitemmodule")
            ->set_is_sortable(true)
            ->set_callback(static function (?string $itemname, stdClass $row): string {
                if ($itemname !== null && $itemname !== '') {
                    return format_string($itemname);
                }
                if ($row->giitemtype === 'course') {
                    return get_string('coursetotal', 'core_grades');
                }
                if ($row->giitemtype === 'category') {
                    return get_string('categorytotal', 'core_grades');
                }
                return trim(ucfirst((string) $row->giitemtype) . ' ' . (string) $row->giitemmodule);
            });

        // Item type (assign, quiz, manual, course, category, ...).
        $columns[] = (new column(
            'itemtype',
            new lang_string('gradessource:itemtype', 'local_reportsources'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$gi}.itemtype, {$gi}.itemmodule")
            ->set_is_sortable(true)
            ->set_callback(static function (?string $itemtype, stdClass $row): string {
                if ($itemtype === null) {
                    return '';
                }
                return $row->itemmodule ? "{$itemtype} ({$row->itemmodule})" : $itemtype;
            });

        // Formatted grade — the display value. Raw finalgrade drives the sort; the
        // callback renders it via the gradebook display API and hides hidden grades.
        $columns[] = (new column(
            'grade',
            new lang_string('gradessource:grade', 'local_reportsources'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_field("{$gg}.finalgrade")
            ->add_fields("{$gi}.id AS giid, {$gi}.courseid AS gicourseid, {$gi}.gradetype AS gigradetype,
                {$gi}.grademax AS gigrademax, {$gi}.grademin AS gigrademin, {$gi}.scaleid AS giscaleid,
                {$gi}.display AS gidisplay, {$gi}.decimals AS gidecimals, {$gi}.hidden AS gihidden,
                {$gg}.hidden AS gghidden")
            ->set_is_sortable(true)
            ->set_callback(static function (?float $finalgrade, stdClass $row): string {
                global $CFG;
                require_once("{$CFG->libdir}/gradelib.php");

                if (empty($row->giid)) {
                    return '';
                }

                // Hidden gating: mirror grade_grade::is_hidden() (grade OR item, each
                // supporting a "hidden until" timestamp) without a per-row DB fetch.
                $now = time();
                $ishidden = ($row->gghidden == 1) || ($row->gghidden > 1 && $row->gghidden > $now)
                    || ($row->gihidden == 1) || ($row->gihidden > 1 && $row->gihidden > $now);
                if ($ishidden) {
                    $context = \context_course::instance((int) $row->gicourseid, IGNORE_MISSING);
                    if (!$context || !has_capability('moodle/grade:viewhidden', $context)) {
                        return get_string('hidden', 'core_grades');
                    }
                }

                if ($finalgrade === null) {
                    return '-';
                }

                // Rebuild a lightweight grade_item (no DB fetch) so the display type and
                // scale are honoured exactly as they are in the gradebook.
                $gradeitem = new \grade_item([
                    'id'        => $row->giid,
                    'courseid'  => $row->gicourseid,
                    'gradetype' => $row->gigradetype,
                    'grademax'  => $row->gigrademax,
                    'grademin'  => $row->gigrademin,
                    'scaleid'   => $row->giscaleid,
                    'display'   => $row->gidisplay,
                    'decimals'  => $row->gidecimals,
                ], false);

                return grade_format_gradevalue((float) $finalgrade, $gradeitem, true);
            });

        // Raw final grade — numeric, for aggregation/filtering without display formatting.
        $columns[] = (new column(
            'finalgrade',
            new lang_string('gradessource:rawgrade', 'local_reportsources'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_fields("{$gg}.finalgrade")
            ->set_is_sortable(true);

        // Maximum grade.
        $columns[] = (new column(
            'grademax',
            new lang_string('grademax', 'core_grades'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_fields("{$gi}.grademax")
            ->set_is_sortable(true);

        // Feedback.
        $columns[] = (new column(
            'feedback',
            new lang_string('feedback', 'core_grades'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_fields("{$gg}.feedback, {$gg}.feedbackformat")
            ->set_is_sortable(false)
            ->set_callback(static function (?string $feedback, stdClass $row): string {
                if ($feedback === null || $feedback === '') {
                    return '';
                }
                return format_text($feedback, $row->feedbackformat ?? FORMAT_MOODLE);
            });

        // Time modified.
        $columns[] = (new column(
            'timemodified',
            new lang_string('timemodified', 'core_reportbuilder'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_fields("{$gg}.timemodified")
            ->set_is_sortable(true)
            ->set_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * Return list of all available filters.
     *
     * @return filter[]
     */
    protected function get_available_filters(): array {
        $gg = $this->get_table_alias('grade_grades');
        $gi = $this->get_table_alias('grade_items');

        // Item name filter.
        $filters[] = (new filter(
            text::class,
            'itemname',
            new lang_string('gradeitem', 'core_grades'),
            $this->get_entity_name(),
            "{$gi}.itemname"
        ))
            ->add_joins($this->get_joins());

        // Item type filter.
        $filters[] = (new filter(
            select::class,
            'itemtype',
            new lang_string('gradessource:itemtype', 'local_reportsources'),
            $this->get_entity_name(),
            "{$gi}.itemtype"
        ))
            ->add_joins($this->get_joins())
            ->set_options([
                'mod'      => new lang_string('gradessource:module', 'local_reportsources'),
                'manual'   => new lang_string('manualitem', 'core_grades'),
                'course'   => new lang_string('coursetotal', 'core_grades'),
                'category' => new lang_string('categorytotal', 'core_grades'),
            ]);

        // Raw grade filter (numeric range).
        $filters[] = (new filter(
            number::class,
            'finalgrade',
            new lang_string('gradessource:rawgrade', 'local_reportsources'),
            $this->get_entity_name(),
            "{$gg}.finalgrade"
        ))
            ->add_joins($this->get_joins());

        // Time modified filter.
        $filters[] = (new filter(
            date::class,
            'timemodified',
            new lang_string('timemodified', 'core_reportbuilder'),
            $this->get_entity_name(),
            "{$gg}.timemodified"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
