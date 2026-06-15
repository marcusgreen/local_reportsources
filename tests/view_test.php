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

use local_reportsources\local\sql\view;

/**
 * Tests for VIEW building and the portable date/time placeholder tokens.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_reportsources\local\sql\view
 */
final class view_test extends \advanced_testcase {
    /**
     * %%TIMESTAMP(expr[, format])%% resolves to the bare epoch expression — no DB date function,
     * format dropped — so the column stays an integer that sorts chronologically.
     */
    public function test_resolve_strips_timestamp_token_to_epoch(): void {
        $this->resetAfterTest();

        $resolved = view::resolve_placeholders(
            'SELECT %%TIMESTAMP(u.lastaccess)%% AS a, %%TIMESTAMP(u.timecreated, dd/mm/yyyy)%% AS b FROM {user} u'
        );

        $this->assertStringContainsString('(u.lastaccess) AS a', $resolved);
        $this->assertStringContainsString('(u.timecreated) AS b', $resolved);
        // No date function and no leftover token or format text.
        $this->assertStringNotContainsStringIgnoringCase('from_unixtime', $resolved);
        $this->assertStringNotContainsStringIgnoringCase('to_timestamp', $resolved);
        $this->assertStringNotContainsString('%%', $resolved);
        $this->assertStringNotContainsString('dd/mm/yyyy', $resolved);
    }

    /**
     * %%NOW%% expands to the current-epoch expression for the live database.
     */
    public function test_resolve_now_token_is_dialect(): void {
        global $DB;
        $this->resetAfterTest();

        $resolved = view::resolve_placeholders('SELECT id FROM {user} WHERE lastlogin > %%NOW%%');

        if ($DB->get_dbfamily() === 'postgres') {
            $this->assertStringContainsString('EXTRACT(EPOCH FROM now())::int', $resolved);
        } else {
            $this->assertStringContainsString('UNIX_TIMESTAMP()', $resolved);
        }
        $this->assertStringNotContainsString('%%', $resolved);
    }

    /**
     * %%COURSECONTEXT%% resolves to the bound course's context row id, and to 0 site-wide.
     */
    public function test_resolve_course_context_token(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $courseid = (int) $course->id;
        $contextid = \context_course::instance($courseid)->id;

        $resolved = view::resolve_placeholders(
            'SELECT id FROM {role_assignments} WHERE contextid = %%COURSECONTEXT%%',
            $courseid
        );
        $this->assertStringContainsString('contextid = ' . $contextid, $resolved);
        $this->assertStringNotContainsString('%%', $resolved);

        // Site-wide (courseid 0) has no course context — resolves to 0.
        $sitewide = view::resolve_placeholders(
            'SELECT id FROM {role_assignments} WHERE contextid = %%COURSECONTEXT%%'
        );
        $this->assertStringContainsString('contextid = 0', $sitewide);
    }

    /**
     * timestamp_columns() maps each token's output column (AS alias, else trailing identifier) to
     * its requested format ('' when none).
     */
    public function test_timestamp_columns_parses_aliases_and_formats(): void {
        $sql = 'SELECT '
            . '%%TIMESTAMP(u.lastaccess)%% AS lastaccess, '          // aliased, no format
            . '%%TIMESTAMP(u.timecreated, ddd dd Mon yyyy)%% AS created, ' // aliased + format
            . '%%TIMESTAMP(timemodified)%%, '                        // no alias -> trailing ident
            . "CONCAT(firstname, ' ', %%TIMESTAMP(lastlogin, dd/mm/yy)%%) AS junk " // in expr, aliased outer
            . 'FROM {user} u';

        $map = view::timestamp_columns($sql);

        $this->assertSame('', $map['lastaccess']);
        $this->assertSame('ddd dd Mon yyyy', $map['created']);
        $this->assertSame('', $map['timemodified']);
        // The lastlogin token has no AS of its own; it is named after its trailing identifier.
        $this->assertSame('dd/mm/yy', $map['lastlogin']);
    }
}
