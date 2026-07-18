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

namespace local_reportsources\reportbuilder\datasource;

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\{course, user};
use local_reportsources\reportbuilder\local\entities\grade;

/**
 * Gradebook grades datasource.
 *
 * A ready-made Report Builder source for {grade_grades}, joined to the core user and
 * course entities. Unlike the plugin's adhoc_query source this one lives in the
 * reportbuilder\datasource namespace on purpose, so core auto-discovery lists it in the
 * "New report" source picker. Visibility is gated by is_available() reading a settings
 * toggle, so admins can switch the whole source off without removing the class.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades extends datasource {
    /**
     * Return user friendly name of the datasource.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('gradessource', 'local_reportsources');
    }

    /**
     * Only surface this source in the "New report" picker when the admin has enabled it.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return !empty(get_config('local_reportsources', 'enablegradessource'));
    }

    /**
     * Initialise report.
     */
    protected function initialise(): void {
        $gradeentity = new grade();
        $gg = $gradeentity->get_table_alias('grade_grades');
        $gi = $gradeentity->get_table_alias('grade_items');

        $this->set_main_table('grade_grades', $gg);
        $this->add_entity($gradeentity);

        // The grade_items table is the entity's second table — always joined (INNER)
        // since every grade row belongs to exactly one item, and the columns depend on it.
        $this->add_join("JOIN {grade_items} {$gi} ON {$gi}.id = {$gg}.itemid");

        // User entity — the graded user.
        $userentity = new user();
        $useralias = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_join("JOIN {user} {$useralias} ON {$useralias}.id = {$gg}.userid AND {$useralias}.deleted = 0"));

        // Course entity — the course owning the grade item.
        $courseentity = new course();
        $coursealias = $courseentity->get_table_alias('course');
        $this->add_entity($courseentity
            ->add_join("JOIN {course} {$coursealias} ON {$coursealias}.id = {$gi}.courseid"));

        $this->add_all_from_entities();
    }

    /**
     * Return the columns that will be added to the report as part of default setup.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'user:fullname',
            'course:fullname',
            'grade:itemname',
            'grade:grade',
        ];
    }

    /**
     * Return the column sorting that will be added to the report upon creation.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'user:fullname'   => SORT_ASC,
            'course:fullname' => SORT_ASC,
            'grade:itemname'  => SORT_ASC,
        ];
    }

    /**
     * Return the filters that will be added to the report as part of default setup.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'course:fullname',
            'user:fullname',
            'grade:itemname',
        ];
    }

    /**
     * Return the conditions that will be added to the report as part of default setup.
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'course:fullname',
            'grade:itemtype',
        ];
    }
}
