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

use local_reportsources\local\query;
use local_reportsources\local\transfer;

/**
 * Tests for the export / import transfer of saved queries.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_reportsources\local\transfer
 */
final class transfer_test extends \advanced_testcase {
    /**
     * Build a minimal portable source descriptor as produced by transfer::parse().
     *
     * @param array $extra Extra/override fields.
     * @return array
     */
    private function source(array $extra = []): array {
        return array_merge([
            'name'        => 'Imported view',
            'description' => '',
            'querysql'    => 'SELECT id FROM {user}',
            'rowcap'      => 100,
            'courseid'    => 0,
            'visible'     => 1,
            'chartmeta'   => null,
        ], $extra);
    }

    public function test_import_demotes_unknown_courseid_to_sitewide(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // 123456 is an id no course in a fresh test site will have.
        $sources = [$this->source(['name' => 'Stale', 'courseid' => 123456])];
        $result = transfer::import($sources, [0]);

        $this->assertSame(1, $result['imported']);
        $this->assertArrayHasKey('Stale', $result['demoted']);
        $this->assertSame(123456, $result['demoted']['Stale']);

        $rec = $DB->get_record(query::TABLE, ['name' => 'Stale'], '*', MUST_EXIST);
        $this->assertSame(0, (int) $rec->courseid);
    }

    public function test_import_preserves_existing_courseid(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $sources = [$this->source(['name' => 'Scoped', 'courseid' => (int) $course->id])];
        $result = transfer::import($sources, [0]);

        $this->assertSame(1, $result['imported']);
        $this->assertArrayNotHasKey('Scoped', $result['demoted']);

        $rec = $DB->get_record(query::TABLE, ['name' => 'Scoped'], '*', MUST_EXIST);
        $this->assertSame((int) $course->id, (int) $rec->courseid);
    }
}
