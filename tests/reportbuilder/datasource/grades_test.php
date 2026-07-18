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

use core_reportbuilder_generator;
use core_reportbuilder\tests\core_reportbuilder_testcase;

/**
 * Unit tests for the Gradebook grades datasource.
 *
 * @package   local_reportsources
 * @covers    \local_reportsources\reportbuilder\datasource\grades
 * @covers    \local_reportsources\reportbuilder\local\entities\grade
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grades_test extends core_reportbuilder_testcase {
    /**
     * Seed a course, a user, a manual grade item and a grade for that user.
     *
     * @param float $gradevalue
     * @return array{course: \stdClass, user: \stdClass, item: \grade_item}
     */
    private function seed_grade(float $gradevalue = 75.0): array {
        global $CFG;
        require_once("{$CFG->libdir}/gradelib.php");

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry']);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $item = new \grade_item($this->getDataGenerator()->create_grade_item([
            'courseid'   => $course->id,
            'itemname'   => 'Midterm',
            'gradetype'  => GRADE_TYPE_VALUE,
            'grademax'   => 100,
            'grademin'   => 0,
        ]), false);
        $item->update_final_grade($user->id, $gradevalue);

        return ['course' => $course, 'user' => $user, 'item' => $item];
    }

    /**
     * A default report on the source renders the seeded grade with display formatting.
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablegradessource', 1, 'local_reportsources');

        $seed = $this->seed_grade(75.0);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name'    => 'Grades',
            'source'  => grades::class,
            'default' => 1,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));

        // Default columns: fullname, course fullname, item name, formatted grade.
        $this->assertCount(1, $content);
        [$fullname, $coursename, $itemname, $gradedisplay] = array_values($content[0]);

        $this->assertStringContainsString('Ada Lovelace', $fullname);
        $this->assertStringContainsString('Chemistry', $coursename);
        $this->assertStringContainsString('Midterm', $itemname);
        // GRADE_TYPE_VALUE with grademax 100 renders the real value to 2 decimals.
        $this->assertStringContainsString('75.00', $gradedisplay);
    }

    /**
     * A hidden grade is masked for a user without moodle/grade:viewhidden.
     */
    public function test_hidden_grade_is_masked(): void {
        $this->resetAfterTest();
        set_config('enablegradessource', 1, 'local_reportsources');

        $seed = $this->seed_grade(88.0);

        // Hide the grade item.
        $seed['item']->set_hidden(1, true);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name'    => 'Grades hidden',
            'source'  => grades::class,
            'default' => 1,
        ]);

        // A student lacks moodle/grade:viewhidden, so the value must be masked.
        $student = $this->getDataGenerator()->create_and_enrol($seed['course'], 'student');
        $this->setUser($student);

        $content = $this->get_custom_report_content($report->get('id'));
        $gradecolumn = array_values($content[0])[3];
        $this->assertStringNotContainsString('88', $gradecolumn);
        $this->assertStringContainsString(get_string('hidden', 'core_grades'), $gradecolumn);

        // An admin (has viewhidden) sees the real value.
        $this->setAdminUser();
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertStringContainsString('88.00', array_values($content[0])[3]);
    }
}
