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

use core_reportbuilder\table\custom_report_table_view_filterset;
use core_table\local\filter\integer_filter;
use local_reportsources\local\query;
use local_reportsources\table\grouped_report_table;

/**
 * The Report Builder-backed grouped (control-break) table: break-column resolution, aggregation
 * guard, group-paginated rendering, the per-group band row and break-value suppression.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_reportsources\table\grouped_report_table
 */
final class grouped_report_table_test extends \advanced_testcase {
    /**
     * Drop VIEWs left by publish() before the framework reset issues DROP TABLE.
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
     * Publish a user-listing query with grouping config and return [queryid, reportid].
     *
     * @return array{0:int,1:int}
     */
    private function published_report(): array {
        $form = (object) [
            'name'     => 'Grouped view',
            'querysql' => 'SELECT id, firstname, lastname FROM {user}',
            'courseid' => 0,
            'visible'  => 1,
        ];
        $id = query::save($form);
        query::get($id)->publish();
        $form->id = $id;
        $form->group_breakcol   = 'id';
        $form->group_headercols = ['firstname', 'lastname'];
        $form->group_detailcols = ['id'];
        $form->group_perpage    = 50;
        query::save($form);

        return [$id, (int) query::get($id)->record()->reportid];
    }

    /**
     * Build a grouped table like grouped.php does and capture its rendered HTML.
     *
     * @param int $reportid
     * @param int $perpage Groups per page.
     * @return string
     */
    private function render(int $reportid, int $perpage): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/local/reportsources/grouped.php'));

        $table = grouped_report_table::create_grouped($reportid, 'id', ['firstname', 'lastname']);
        $filterset = new custom_report_table_view_filterset();
        $filterset->add_filter(new integer_filter('pagesize', null, [$perpage]));
        $table->set_filterset($filterset);
        $table->define_baseurl(new \moodle_url('/local/reportsources/grouped.php'));

        ob_start();
        try {
            $table->out($perpage, false);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function test_create_grouped_resolves_break_alias(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [, $reportid] = $this->published_report();

        $table = grouped_report_table::create_grouped($reportid, 'id', ['firstname', 'lastname']);
        $this->assertTrue($table->break_resolved());
        $this->assertFalse($table->report_has_aggregation());
    }

    public function test_create_grouped_unknown_break_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [, $reportid] = $this->published_report();

        $table = grouped_report_table::create_grouped($reportid, 'no_such_col', []);
        $this->assertFalse($table->break_resolved());
    }

    public function test_render_emits_one_band_per_group(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        for ($i = 0; $i < 4; $i++) {
            $this->getDataGenerator()->create_user();
        }

        [$queryid, $reportid] = $this->published_report();

        // Break column is id (unique), so the number of groups equals the rows the view returns.
        $viewname = query::get($queryid)->record()->viewname;
        $expectedgroups = (int) $DB->count_records_sql("SELECT COUNT(DISTINCT id) FROM {{$viewname}}");

        $html = $this->render($reportid, $expectedgroups + 10); // Page big enough to hold every group.

        // Exactly one band row per group on the page (empty page-padding rows excluded).
        $this->assertSame($expectedgroups, substr_count($html, 'rs-group-header'));
    }

    public function test_render_suppresses_break_value_on_detail_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Zzuniquefirst', 'lastname' => 'Zzuniquelast']);

        [, $reportid] = $this->published_report();

        $html = $this->render($reportid, 200);

        // The header (firstname/lastname) shows in the band; the break value (user id) appears once
        // in the band and is blanked on the detail row, so the id string occurs at most once.
        $this->assertStringContainsString('Zzuniquefirst', $html);
        $this->assertLessThanOrEqual(1, substr_count($html, '>' . $user->id . '<'));
    }
}
