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

use local_reportsources\local\sql\analyser;

/**
 * Unit tests for the report-source analyser (Check button backend).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_reportsources\local\sql\analyser
 */
final class analyser_test extends \advanced_testcase {
    /**
     * Invalid SQL fails cleanly with ok=false and an error message.
     */
    public function test_invalid_sql_reports_error(): void {
        $this->resetAfterTest();
        $result = analyser::analyse('DELETE FROM {user}');
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['error']);
    }

    /**
     * The row count matches the number of rows the query returns.
     */
    public function test_row_count(): void {
        $this->resetAfterTest();
        $before = analyser::analyse('SELECT id FROM {user}')['rowcount'];
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $after = analyser::analyse('SELECT id FROM {user}')['rowcount'];
        $this->assertSame($before + 2, $after);
    }

    /**
     * An integer column whose name looks like a date and whose value is a plausible
     * epoch is suggested for the %%TIMESTAMP()%% token.
     */
    public function test_date_column_suggested(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timecreated' => time()]);

        $result = analyser::analyse('SELECT id, timecreated FROM {user} WHERE id = ' . (int) $user->id);
        $this->assertTrue($result['ok']);
        $joined = implode("\n", $result['suggestions']);
        $this->assertStringContainsStringIgnoringCase('timecreated', $joined);
    }

    /**
     * lastlogin is recognised as a date column (name-implied), even sampled as 0 ("never").
     */
    public function test_lastlogin_suggested(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['lastlogin' => 0]);

        $result = analyser::analyse('SELECT id, lastlogin FROM {user} WHERE id = ' . (int) $user->id);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsStringIgnoringCase('lastlogin', implode("\n", $result['suggestions']));
    }

    /**
     * A plain non-date integer column is not suggested.
     */
    public function test_plain_column_not_suggested(): void {
        $this->resetAfterTest();
        $result = analyser::analyse('SELECT id FROM {user}');
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['suggestions']);
    }

    /**
     * A column already wrapped in %%TIMESTAMP()%% is not re-suggested.
     */
    public function test_timestamp_token_not_re_suggested(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timecreated' => time()]);

        $result = analyser::analyse(
            'SELECT id, %%TIMESTAMP(timecreated)%% AS timecreated FROM {user} WHERE id = ' . (int) $user->id
        );
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['suggestions']);
    }

    /**
     * Referenced base tables appear in the index report with a row count.
     */
    public function test_index_report_lists_tables(): void {
        $this->resetAfterTest();
        $result = analyser::analyse('SELECT id FROM {user}');
        $joined = implode("\n", $result['indexinfo']);
        $this->assertStringContainsStringIgnoringCase('user', $joined);
    }

    /**
     * A LIKE pattern with a leading wildcard is flagged as non-indexable.
     */
    public function test_leading_wildcard_warned(): void {
        $this->resetAfterTest();
        $result = analyser::analyse("SELECT id FROM {user} WHERE username LIKE '%admin'");
        $this->assertTrue($result['ok']);
        $this->assertStringContainsStringIgnoringCase('wildcard', implode("\n", $result['warnings']));
    }

    /**
     * An anchored LIKE pattern (no leading wildcard) is not flagged.
     */
    public function test_anchored_like_not_warned(): void {
        $this->resetAfterTest();
        $result = analyser::analyse("SELECT id FROM {user} WHERE username LIKE 'admin%'");
        $this->assertTrue($result['ok']);
        $this->assertStringNotContainsStringIgnoringCase('wildcard', implode("\n", $result['warnings']));
    }

    /**
     * A function wrapping a column in WHERE is flagged as non-sargable.
     */
    public function test_nonsargable_where_warned(): void {
        $this->resetAfterTest();
        $result = analyser::analyse("SELECT id FROM {user} WHERE LOWER(username) = 'admin'");
        $this->assertTrue($result['ok']);
        $this->assertStringContainsStringIgnoringCase('non-sargable', implode("\n", $result['warnings']));
    }

    /**
     * A subquery in the SELECT list is flagged as per-row work.
     */
    public function test_select_list_subquery_warned(): void {
        $this->resetAfterTest();
        $result = analyser::analyse('SELECT id, (SELECT COUNT(*) FROM {user}) AS total FROM {user}');
        $this->assertTrue($result['ok']);
        $this->assertStringContainsStringIgnoringCase('subquery', implode("\n", $result['warnings']));
    }

    /**
     * A subquery used only in the FROM/WHERE (not the select list) is not flagged as a
     * select-list subquery.
     */
    public function test_where_subquery_not_flagged_as_select_list(): void {
        $this->resetAfterTest();
        $result = analyser::analyse(
            'SELECT id FROM {user} WHERE id IN (SELECT userid FROM {user_enrolments})'
        );
        $this->assertTrue($result['ok']);
        $this->assertStringNotContainsStringIgnoringCase('per returned row', implode("\n", $result['warnings']));
    }

    /**
     * A plain query trips none of the performance heuristics.
     */
    public function test_plain_query_no_performance_warnings(): void {
        $this->resetAfterTest();
        $joined = implode("\n", analyser::analyse('SELECT id, username FROM {user}')['warnings']);
        $this->assertStringNotContainsStringIgnoringCase('wildcard', $joined);
        $this->assertStringNotContainsStringIgnoringCase('non-sargable', $joined);
        $this->assertStringNotContainsStringIgnoringCase('subquery', $joined);
    }
}
