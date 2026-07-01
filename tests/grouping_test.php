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
 * Control-break grouping: groupmeta persistence, break-column ordering, cell formatting, and
 * that grouping config travels through export/import.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_reportsources\local\query
 */
final class grouping_test extends \advanced_testcase {
    /**
     * Build a minimal valid form-data object for query::save().
     *
     * @param array $extra Extra/override fields.
     * @return \stdClass
     */
    private function formdata(array $extra = []): \stdClass {
        return (object) array_merge([
            'name'     => 'Grouped view',
            'querysql' => 'SELECT id, firstname, lastname FROM {user}',
            'courseid' => 0,
            'visible'  => 1,
        ], $extra);
    }

    /**
     * Drop any VIEWs left behind by publish() before the framework reset runs (reset issues DROP
     * TABLE, which errors on a VIEW).
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

    /**
     * Publish a query and apply grouping config, returning its id.
     *
     * @return int
     */
    private function published_grouped_query(): int {
        $id = query::save($this->formdata());
        query::get($id)->publish();
        // Grouping is only accepted on a published query (columns come from the live view).
        query::save($this->formdata([
            'id'               => $id,
            'group_breakcol'   => 'id',
            'group_headercols' => ['firstname', 'lastname'],
            'group_detailcols' => ['id'],
            'group_rowlimit'   => 500,
            'group_perpage'    => 10,
        ]));
        return $id;
    }

    public function test_save_persists_grouping_config(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->published_grouped_query();
        $meta = query::get($id)->group_meta();

        $this->assertSame('id', $meta['breakcol']);
        $this->assertSame(['firstname', 'lastname'], $meta['headercols']);
        $this->assertSame(['id'], $meta['detailcols']);
        $this->assertSame(500, $meta['rowlimit']);
        $this->assertSame(10, $meta['perpage']);
    }

    public function test_empty_break_column_leaves_grouping_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata());
        query::get($id)->publish();
        query::save($this->formdata(['id' => $id, 'group_breakcol' => '']));

        $meta = query::get($id)->group_meta();
        $this->assertSame('', $meta['breakcol']);
    }

    public function test_fetch_orders_rows_by_break_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Three users beyond the default admin/guest, so ordering is observable.
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();

        $id = $this->published_grouped_query();
        $rows = query::get($id)->fetch_rows_for_viewer(100, 0, 'id');

        $this->assertNotEmpty($rows);
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'Rows are returned ordered by the break column.');
    }

    public function test_fetch_ignores_unknown_sort_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->published_grouped_query();
        // A sort column that is not a real output column must not throw or be interpolated.
        $rows = query::get($id)->fetch_rows_for_viewer(100, 0, 'no_such_col');
        $this->assertNotEmpty($rows);
    }

    public function test_grouped_page_paginates_by_group(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Count the users that already exist (admin, guest) plus the ones we add, so the expected
        // group total is exact regardless of the base install.
        global $DB;
        $before = (int) $DB->count_records('user');
        for ($i = 0; $i < 5; $i++) {
            $this->getDataGenerator()->create_user();
        }
        $total = $before + 5;

        $id = $this->published_grouped_query();

        // Two groups per page: page 0 has 2, and the total group count is every user.
        $p0 = query::get($id)->fetch_grouped_page('id', 0, 2);
        $this->assertSame($total, $p0['totalgroups']);
        $ids0 = array_values(array_unique(array_map(static fn($r) => (int) $r['id'], $p0['rows'])));
        $this->assertCount(2, $ids0);

        // Page 1 holds the next two groups, disjoint from page 0.
        $p1 = query::get($id)->fetch_grouped_page('id', 1, 2);
        $ids1 = array_values(array_unique(array_map(static fn($r) => (int) $r['id'], $p1['rows'])));
        $this->assertCount(2, $ids1);
        $this->assertSame([], array_intersect($ids0, $ids1));
    }

    public function test_grouped_page_rejects_unknown_break_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->published_grouped_query();
        $result = query::get($id)->fetch_grouped_page('no_such_col', 0, 25);
        $this->assertSame(['rows' => [], 'totalgroups' => 0], $result);
    }

    public function test_format_cell_returns_plain_text(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->published_grouped_query();
        $this->assertSame('Marcus', query::get($id)->format_cell('firstname', 'Marcus'));
    }

    public function test_grouping_config_survives_export_import(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->published_grouped_query();

        $exported = transfer::export([$id]);
        $json = json_encode($exported);
        $sources = transfer::parse($json);
        $this->assertNotEmpty($sources[0]['groupmeta']);

        $names = [$sources[0]['name']];
        transfer::import($sources, $names);

        // The imported draft is a fresh record; find the newest one and check its groupmeta.
        $records = $DB->get_records(query::TABLE, null, 'id DESC');
        $newest = reset($records);
        $meta = json_decode($newest->groupmeta, true);
        $this->assertSame('id', $meta['breakcol']);
        $this->assertSame(['firstname', 'lastname'], $meta['headercols']);
    }
}
