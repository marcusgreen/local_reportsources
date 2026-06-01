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

namespace local_reportsources;

use local_reportsources\local\sql\validator;

/**
 * Unit tests for the SQL validator.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_reportsources\local\sql\validator
 */
final class sql_validator_test extends \advanced_testcase {

    public static function valid_provider(): array {
        return [
            'simple SELECT' => ['SELECT id, fullname FROM {course}'],
            'WITH CTE'      => ['WITH x AS (SELECT id FROM {course}) SELECT * FROM x'],
            'aggregate'     => ['SELECT COUNT(*) c FROM {user}'],
            'trailing semi' => ['SELECT 1;'],
            'JOIN with ON'  => ['SELECT u.id FROM {user} u JOIN {user_enrolments} ue ON ue.userid = u.id'],
            'LEFT JOIN ON'  => ['SELECT u.id FROM {user} u LEFT JOIN {role} r ON r.id = u.id'],
            'CROSS JOIN'    => ['SELECT a.id FROM {course} a CROSS JOIN {user} b'],
            'comma join'    => ['SELECT a.id FROM {course} a, {user} b WHERE a.id = b.id'],
            'JOIN USING'    => ['SELECT u.id FROM {user} u JOIN {role} r USING (id)'],
        ];
    }

    /**
     * @dataProvider valid_provider
     */
    public function test_valid(string $sql): void {
        $this->assertNotEmpty(validator::validate($sql));
    }

    public static function invalid_provider(): array {
        return [
            'empty'           => [''],
            'INSERT'          => ['INSERT INTO {course} VALUES (1)'],
            'UPDATE'          => ['UPDATE {course} SET fullname = \'x\''],
            'DELETE'          => ['DELETE FROM {course}'],
            'DROP'            => ['DROP TABLE {course}'],
            'multi statement' => ['SELECT 1; SELECT 2'],
            'SELECT INTO'     => ['SELECT * INTO foo FROM {user}'],
            'denied table'    => ['SELECT * FROM {config}'],
            'EXECUTE'         => ['EXECUTE my_proc'],
            'CREATE VIEW'     => ['CREATE VIEW foo AS SELECT 1'],
            'JOIN missing ON' => ['SELECT u.firstname FROM {user} u JOIN {user_enrolments} ue.userid = u.id'],
            'LEFT JOIN no ON' => ['SELECT u.id FROM {user} u LEFT JOIN {role} r'],
        ];
    }

    /**
     * @dataProvider invalid_provider
     */
    public function test_invalid(string $sql): void {
        $this->expectException(\moodle_exception::class);
        validator::validate($sql);
    }

    public function test_comment_only_select_passes(): void {
        // Comment-stripped form is "SELECT 1", with no trailing keyword. Should still pass.
        $this->assertNotEmpty(validator::validate('SELECT 1 /* harmless */'));
    }

    public function test_string_literal_does_not_evade_keyword_scan(): void {
        // String literals are blanked before scan, so "DROP" inside a literal won't trigger.
        $this->assertNotEmpty(validator::validate("SELECT 'DROP TABLE x' AS s"));
    }

    public function test_join_without_on_reports_specific_error(): void {
        // A JOIN missing its ON condition should raise the dedicated, friendly message
        // rather than letting the DB return an opaque syntax error.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errjoinnoon', 'local_reportsources'));
        validator::validate('SELECT u.firstname FROM {user} u JOIN {user_enrolments} ue.userid = u.id');
    }

    public function test_placeholders(): void {
        $names = validator::placeholders(
            'SELECT id FROM {course} WHERE category = :cat AND timecreated > :since AND :cat = :cat'
        );
        $this->assertSame(['cat', 'since'], $names);
    }
}
