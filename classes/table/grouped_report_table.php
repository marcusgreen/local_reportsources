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

namespace local_reportsources\table;

use core_reportbuilder\local\helpers\database;
use core_reportbuilder\table\custom_report_table_view;

/**
 * Control-break (master-detail) rendering of a published ad-hoc query's Report Builder report.
 *
 * Reuses the whole core RB table (columns, filters, audience, formatting) but:
 *  - paginates by distinct break value, so a group's detail rows never split across a page;
 *  - injects a full-width band row above each group (the break value / header columns);
 *  - suppresses the break value on the detail rows beneath the band (it is shown once, in the band).
 *
 * Rendering only — every data row is still a normal RB row, so sorting/filtering/export keep working.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grouped_report_table extends custom_report_table_view {
    /** @var string SELECT alias of the break column, resolved from its SQL name. */
    protected $breakalias = '';

    /** @var string[] SELECT aliases of the header columns shown in the band (empty ⇒ use the break column). */
    protected $headeraliases = [];

    /** @var int|null Positional index of the break column within a rendered row (resolved lazily). */
    protected $breakindex = null;

    /** @var int[]|null Positional indices of the header columns within a rendered row (resolved lazily). */
    protected $headerindices = null;

    /** @var string|null Break value of the row last rendered, for control-break detection. */
    protected $lastbreak = null;

    /**
     * Build a grouped table for a report, resolving the break/header SQL column names to their RB aliases.
     *
     * @param int $reportid Report Builder report id.
     * @param string $breakcol View column name to break groups on.
     * @param string[] $headercols View column names shown in the group band (empty ⇒ the break column).
     * @param string $download Download format, or '' for the on-screen view.
     * @return self
     */
    public static function create_grouped(
        int $reportid,
        string $breakcol,
        array $headercols = [],
        string $download = ''
    ): self {
        $table = self::create($reportid, $download);

        $aliasfor = [];
        foreach ($table->report->get_active_columns() as $column) {
            $identifier = $column->get_unique_identifier();
            $name = substr($identifier, strpos($identifier, ':') + 1);
            $aliasfor[$name] = $column->get_column_alias();
        }

        $table->breakalias = $aliasfor[$breakcol] ?? '';
        foreach ($headercols as $name) {
            if (isset($aliasfor[$name])) {
                $table->headeraliases[] = $aliasfor[$name];
            }
        }

        $table->hide_band_columns();

        return $table;
    }

    /**
     * Render as a plain (non-dynamic) table.
     *
     * The core RB table is a dynamic table: {@see \flexible_table::out()} emits a `core_table/dynamic`
     * wrapper and JS that turns header-sort / paging clicks into an AJAX reload via
     * {@see \core_table\external\dynamic\get}. That reload reconstructs the table from its class alone —
     * it has no break/header columns and expects a `grouped_report_table_filterset` class — so it cannot
     * restore the grouped state and throws "The filter specified ... is invalid".
     *
     * Suppressing the wrapper + JS makes the sort links and paging bar plain full-page GET links back to
     * grouped.php (our base URL), which re-runs {@see query_db()} and re-bands the rows server-side. Sort
     * still works (the `tsort` param feeds {@see \flexible_table::get_sql_sort()}, appended after the break
     * order in {@see query_db()}); paging still works via the page param.
     *
     * @return string
     */
    protected function get_dynamic_table_html_start(): string {
        return '';
    }

    /**
     * Close of {@see get_dynamic_table_html_start()}: no dynamic wrapper was opened and no
     * `core_table/dynamic` JS is loaded, keeping the grouped view a server-rendered snapshot.
     *
     * @return string
     */
    protected function get_dynamic_table_html_end(): string {
        return '';
    }

    /**
     * Hide the break and header columns as on-screen table columns: their values are shown once in the
     * group band above each group, so repeating them as a column down every detail row (and in the table
     * header) is redundant. Tagging a column applies the class to both its {@see \flexible_table::print_headers()}
     * `<th>` and every {@see \flexible_table::get_row_html()} `<td>`; `styles.css` collapses `.rs-group-hide`
     * with `display:none`. Downloads keep every column (they are flat, with no band) so this is skipped there.
     */
    protected function hide_band_columns(): void {
        if ($this->is_downloading()) {
            return;
        }
        foreach (array_merge([$this->breakalias], $this->headeraliases) as $alias) {
            if ($alias !== '') {
                $this->column_class($alias, 'rs-group-hide');
            }
        }
    }

    /**
     * Whether the break column is present as an active column of the report (else grouping can't run).
     *
     * @return bool
     */
    public function break_resolved(): bool {
        return $this->breakalias !== '';
    }

    /**
     * Whether any active column carries an aggregation — banding is incompatible with GROUP BY reports.
     *
     * @return bool
     */
    public function report_has_aggregation(): bool {
        foreach ($this->report->get_active_columns() as $column) {
            if ($column->get_persistent()->get('aggregation')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch a page of whole groups. Overrides the row-paginated core query so a group never splits a page:
     * the page offset/limit select distinct break values, then every row for those groups is returned.
     *
     * @param int $pagesize Groups per page.
     * @param bool $useinitialsbar Unused (no initials bar on a grouped view).
     */
    public function query_db($pagesize, $useinitialsbar = true): void {
        global $DB;

        $inner = $this->get_table_sql(false);
        $ba = $this->breakalias;

        if ($this->is_downloading()) {
            // Downloads are flat: every row, ordered by the break column (no group paging, no band).
            $this->rawdata = $DB->get_recordset_sql(
                "SELECT rb.* FROM ({$inner}) rb ORDER BY rb.{$ba} ASC",
                $this->sql->params
            );
            return;
        }

        // Set the page size first so get_page_start()/get_page_size() below give the group offset/limit.
        $this->pagesize($pagesize, 0);

        $countalias = database::generate_alias();
        $totalgroups = (int) $DB->count_records_sql(
            "SELECT COUNT(1) FROM (SELECT DISTINCT rb.{$ba} FROM ({$inner}) rb) {$countalias}",
            $this->sql->params
        );
        $this->pagesize($pagesize, $totalgroups);

        if ($totalgroups === 0) {
            $this->rawdata = [];
            return;
        }

        // This page's break values, in the order the rows will be grouped.
        $keyrecs = $DB->get_records_sql(
            "SELECT DISTINCT rb.{$ba} AS rs_key FROM ({$inner}) rb ORDER BY rb.{$ba} ASC",
            $this->sql->params,
            (int) $this->get_page_start(),
            (int) $this->get_page_size()
        );
        $keys = array_map(static fn($r) => $r->rs_key, $keyrecs);
        if (!$keys) {
            $this->rawdata = [];
            return;
        }

        // All rows for exactly this page's groups, break column first, then the report's own sort.
        [$insql, $inparams] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED, 'rsbk');
        $order = "rb.{$ba} ASC";
        if ($sort = $this->get_sql_sort()) {
            $order .= ', ' . $sort;
        }
        $this->rawdata = $DB->get_recordset_sql(
            "SELECT rb.* FROM ({$inner}) rb WHERE rb.{$ba} {$insql} ORDER BY {$order}",
            $this->sql->params + $inparams
        );
    }

    /**
     * Prepend a full-width band row on each control break, and suppress the (now redundant) break value
     * on the detail rows beneath it.
     *
     * By this point the row has been flattened to a positional array (see {@see get_row_from_keyed}),
     * so the break/header columns are addressed by their position, not their alias.
     *
     * @param array|\stdClass $row Formatted row, positionally indexed in column order.
     * @param string $classname
     * @return string
     */
    public function get_row_html($row, $classname = '') {
        // Empty padding rows that finish_html() adds to fill the page must not start a group.
        if ($classname === 'emptyrow') {
            return parent::get_row_html($row, $classname);
        }

        $row = array_values((array) $row);
        $this->resolve_indices();

        $html = '';
        if ($this->breakindex !== null) {
            $key = (string) ($row[$this->breakindex] ?? '');
            if ($key !== $this->lastbreak) {
                $this->lastbreak = $key;
                $html .= $this->group_band_html($row);
            }
            // A1 break suppression: the break value shows once in the band, not repeated down the group.
            $row[$this->breakindex] = '';
        }

        return $html . parent::get_row_html($row, $classname);
    }

    /**
     * Resolve break/header column aliases to their positional indices in a rendered row (once).
     */
    protected function resolve_indices(): void {
        if ($this->breakindex !== null || $this->headerindices !== null) {
            return;
        }
        $order = array_keys($this->columns);
        $break = array_search($this->breakalias, $order, true);
        $this->breakindex = ($break === false) ? null : $break;

        $this->headerindices = [];
        foreach ($this->headeraliases as $alias) {
            $index = array_search($alias, $order, true);
            if ($index !== false) {
                $this->headerindices[] = $index;
            }
        }
    }

    /**
     * Build the full-width band row that introduces a group.
     *
     * @param array $row Positional row (values already escaped by Report Builder).
     * @return string A single <tr> spanning every column.
     */
    protected function group_band_html(array $row): string {
        $indices = $this->headerindices ?: [$this->breakindex];
        $parts = [];
        foreach ($indices as $index) {
            $value = trim((string) ($row[$index] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $cell = \html_writer::tag('td', implode(' ', $parts), [
            'colspan' => max(1, count($this->columns)),
            'class' => 'rs-group-header',
        ]);
        return \html_writer::tag('tr', $cell);
    }
}
