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

/**
 * Tests for the audience picker storage contract (audiencemeta build / explode / persistence).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_reportsources\local\query
 */
final class audience_test extends \advanced_testcase {
    /**
     * Build a minimal valid form-data object for query::save().
     *
     * @param array $extra Extra/override fields.
     * @return \stdClass
     */
    private function formdata(array $extra = []): \stdClass {
        return (object) array_merge([
            'name'     => 'Test view',
            'querysql' => 'SELECT id FROM {user}',
            'rowcap'   => 100,
            'courseid' => 0,
            'visible'  => 1,
        ], $extra);
    }

    public function test_default_audience_persists_as_null(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata(['audiencetype' => 'default']));

        $this->assertNull($DB->get_field(query::TABLE, 'audiencemeta', ['id' => $id]));
    }

    public function test_allusers_audience_persists(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata(['audiencetype' => 'allusers']));

        $meta = json_decode($DB->get_field(query::TABLE, 'audiencemeta', ['id' => $id]), true);
        $this->assertSame('allusers', $meta['type']);
    }

    public function test_courserole_audience_keeps_roles(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $id = query::save($this->formdata([
            'courseid'      => $course->id,
            'audiencetype'  => 'courserole',
            'audienceroles' => ['3', '4'],
        ]));

        $meta = json_decode($DB->get_field(query::TABLE, 'audiencemeta', ['id' => $id]), true);
        $this->assertSame('courserole', $meta['type']);
        $this->assertSame([3, 4], $meta['roles']);
    }

    public function test_none_audience_persists(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata(['audiencetype' => 'none']));

        $meta = json_decode($DB->get_field(query::TABLE, 'audiencemeta', ['id' => $id]), true);
        $this->assertSame('none', $meta['type']);
    }

    public function test_explode_roundtrips_courserole(): void {
        $json = json_encode(['type' => 'courserole', 'roles' => [3, 5]]);

        $flat = query::explode_audiencemeta($json);

        $this->assertSame('courserole', $flat['audiencetype']);
        $this->assertSame([3, 5], $flat['audienceroles']);
        $this->assertSame([], $flat['audiencecohorts']);
    }

    public function test_explode_null_is_default(): void {
        $flat = query::explode_audiencemeta(null);

        $this->assertSame('default', $flat['audiencetype']);
        $this->assertSame([], $flat['audienceroles']);
        $this->assertSame([], $flat['audiencecohorts']);
    }
}
