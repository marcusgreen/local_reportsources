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
 * Regression net for the query lifecycle (save / publish / unpublish / duplicate / delete /
 * listing) before the query god-class is split into focused units.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_reportsources\local\query
 */
final class query_test extends \advanced_testcase {
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
            'courseid' => 0,
            'visible'  => 1,
        ], $extra);
    }

    /**
     * Drop any VIEWs left behind by publish() before the framework reset runs. Moodle's PHPUnit
     * reset enumerates tables and issues DROP TABLE, which errors on a VIEW — so a test that leaves
     * a query published would otherwise fail in teardown rather than in the test body.
     */
    protected function tearDown(): void {
        global $DB;
        $prefix = $DB->get_prefix() . 'local_reportsources_v_';
        $views = $DB->get_records_sql(
            "SELECT table_name FROM information_schema.views WHERE table_schema = DATABASE() AND table_name LIKE ?",
            [$prefix . '%']
        );
        foreach ($views as $view) {
            $name = $view->table_name ?? reset($view);
            $DB->execute('DROP VIEW IF EXISTS ' . $name);
        }
        parent::tearDown();
    }

    public function test_save_creates_draft_owned_by_current_user(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata(['name' => 'My draft']));

        $record = $DB->get_record(query::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('My draft', $record->name);
        $this->assertSame(query::STATUS_DRAFT, $record->status);
        $this->assertSame((int) $USER->id, (int) $record->ownerid);
        $this->assertNull($record->viewname);
        $this->assertNull($record->reportid);
    }

    public function test_save_updates_existing_record_in_place(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata(['name' => 'Original']));
        $sameid = query::save($this->formdata(['id' => $id, 'name' => 'Renamed']));

        $this->assertSame($id, $sameid);
        $this->assertSame('Renamed', $DB->get_field(query::TABLE, 'name', ['id' => $id]));
        $this->assertSame(1, $DB->count_records(query::TABLE));
    }

    public function test_publish_sets_status_view_report_and_columnsmeta(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata());
        query::get($id)->publish();

        $record = $DB->get_record(query::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(query::STATUS_PUBLISHED, $record->status);
        $this->assertNotEmpty($record->viewname);
        $this->assertNotEmpty($record->reportid);

        $meta = json_decode($record->columnsmeta, true);
        $this->assertArrayHasKey('id', $meta);
        $this->assertSame('int', $meta['id']['type']);

        // The view actually exists and is queryable.
        $columns = $DB->get_columns($record->viewname);
        $this->assertArrayHasKey('id', $columns);
    }

    public function test_publish_binds_queryid_config_to_report(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata());
        query::get($id)->publish();
        $reportid = (int) query::get($id)->reportid();

        $this->assertSame(
            (string) $id,
            get_config('local_reportsources', 'queryid_for_report_' . $reportid)
        );
    }

    public function test_unpublish_reverts_to_draft_and_clears_artefacts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata());
        query::get($id)->publish();
        query::get($id)->unpublish();

        $record = $DB->get_record(query::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(query::STATUS_DRAFT, $record->status);
        $this->assertNull($record->viewname);
        $this->assertNull($record->reportid);
        $this->assertNull($record->columnsmeta);
    }

    public function test_sql_edit_while_published_demotes_to_draft(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata());
        query::get($id)->publish();
        $reportid = (int) query::get($id)->reportid();

        // Changing the SQL on a published query tears down view + report and returns to draft.
        query::save($this->formdata(['id' => $id, 'querysql' => 'SELECT id, username FROM {user}']));

        $record = $DB->get_record(query::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(query::STATUS_DRAFT, $record->status);
        $this->assertNull($record->viewname);
        $this->assertNull($record->reportid);
        // The bound report config key is cleaned up by tear_down().
        $this->assertFalse(get_config('local_reportsources', 'queryid_for_report_' . $reportid));
    }

    public function test_duplicate_creates_draft_copy(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata(['name' => 'Source']));
        query::get($id)->publish();
        $copyid = query::get($id)->duplicate();

        $this->assertNotSame($id, $copyid);
        $copy = $DB->get_record(query::TABLE, ['id' => $copyid], '*', MUST_EXIST);
        $this->assertSame(query::STATUS_DRAFT, $copy->status);
        $this->assertNull($copy->viewname);
        $this->assertNull($copy->reportid);
        $this->assertSame('SELECT id FROM {user}', $copy->querysql);
    }

    public function test_delete_removes_record_and_artefacts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata());
        query::get($id)->publish();
        $reportid = (int) query::get($id)->reportid();
        query::get($id)->delete();

        $this->assertFalse($DB->record_exists(query::TABLE, ['id' => $id]));
        $this->assertFalse(get_config('local_reportsources', 'queryid_for_report_' . $reportid));
    }

    public function test_visible_to_current_user_admin_sees_all(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $draftid = query::save($this->formdata(['name' => 'Draft one']));
        $pubid = query::save($this->formdata(['name' => 'Published two']));
        query::get($pubid)->publish();

        $visible = query::visible_to_current_user();
        $this->assertArrayHasKey($draftid, $visible);
        $this->assertArrayHasKey($pubid, $visible);
    }

    public function test_teacher_course_ids_returns_only_taught_courses(): void {
        global $DB;
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $teacher = $gen->create_user();
        $taught = $gen->create_course();
        $studied = $gen->create_course();

        $gen->enrol_user($teacher->id, $taught->id, 'editingteacher');
        $gen->enrol_user($teacher->id, $studied->id, 'student');

        $ids = query::teacher_course_ids((int) $teacher->id);

        $this->assertContains((int) $taught->id, $ids);
        $this->assertNotContains((int) $studied->id, $ids);
    }

    public function test_teacher_course_ids_requires_active_enrolment(): void {
        global $DB;
        $this->resetAfterTest();

        $gen     = $this->getDataGenerator();
        $teacher = $gen->create_user();
        $course  = $gen->create_course();

        // Teacher role assigned at the course context, but NOT enrolled.
        $coursecontext = \context_course::instance($course->id);
        $editingteacher = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        role_assign($editingteacher, $teacher->id, $coursecontext->id);

        // Role without an enrolment must not count.
        $this->assertNotContains((int) $course->id, query::teacher_course_ids((int) $teacher->id));

        // Suspended enrolment must not count either.
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->assertNotContains((int) $course->id, query::teacher_course_ids((int) $teacher->id));
    }

    public function test_teacher_course_ids_empty_when_teaches_nothing(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $this->assertSame([], query::teacher_course_ids((int) $student->id));
    }

    public function test_save_coursecolumn_accepts_valid_and_rejects_unknown_column(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // courseid is a real column of this query, gibberish is not.
        $id = query::save($this->formdata(['querysql' => 'SELECT id, courseid FROM {enrol}']));
        query::get($id)->publish();

        query::save($this->formdata([
            'id'           => $id,
            'querysql'     => 'SELECT id, courseid FROM {enrol}',
            'coursecolumn' => 'courseid',
        ]));
        $this->assertSame('courseid', $DB->get_field(query::TABLE, 'coursecolumn', ['id' => $id]));

        query::save($this->formdata([
            'id'           => $id,
            'querysql'     => 'SELECT id, courseid FROM {enrol}',
            'coursecolumn' => 'nosuchcolumn',
        ]));
        $this->assertNull($DB->get_field(query::TABLE, 'coursecolumn', ['id' => $id]));
    }

    public function test_published_menu_lists_only_published(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $draft = query::save($this->formdata(['name' => 'Draft menu']));
        $pub   = query::save($this->formdata(['name' => 'Published menu']));
        query::get($pub)->publish();

        $menu = query::published_menu();
        $this->assertArrayHasKey($pub, $menu);
        $this->assertArrayNotHasKey($draft, $menu);
        $this->assertSame('Published menu', $menu[$pub]);
    }

    public function test_fetch_rows_for_viewer_scopes_to_taught_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen     = $this->getDataGenerator();
        $teacher = $gen->create_user();
        $taught  = $gen->create_course();
        $other   = $gen->create_course();
        $gen->enrol_user($teacher->id, $taught->id, 'editingteacher');

        $sql = 'SELECT id AS courseid, shortname FROM {course}';
        $id  = query::save($this->formdata(['querysql' => $sql]));
        query::get($id)->publish();
        // Designate the course filter column (published-edit path; SQL unchanged so stays published).
        query::save($this->formdata(['id' => $id, 'querysql' => $sql, 'coursecolumn' => 'courseid']));

        $this->setUser($teacher);
        $rows = query::get($id)->fetch_rows_for_viewer();
        $ids  = array_map(static fn($r): int => (int) $r['courseid'], $rows);

        $this->assertContains((int) $taught->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_fetch_rows_for_viewer_empty_when_teaches_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $student = $this->getDataGenerator()->create_user();
        $sql = 'SELECT id AS courseid, shortname FROM {course}';
        $id  = query::save($this->formdata(['querysql' => $sql]));
        query::get($id)->publish();
        query::save($this->formdata(['id' => $id, 'querysql' => $sql, 'coursecolumn' => 'courseid']));

        $this->setUser($student);
        $this->assertSame([], query::get($id)->fetch_rows_for_viewer());
    }
}
