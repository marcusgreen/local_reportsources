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

/**
 * Render a control-break (grouped) view of a published ad-hoc query: one header line per group
 * (e.g. a user's name), then a detail line per row beneath it (e.g. each course they take).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_reportsources\local\query;

$id       = required_param('id', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$format   = optional_param('format', '', PARAM_ALPHA);
$page     = optional_param('page', 0, PARAM_INT);

if ($courseid) {
    require_login($courseid);
    $context = context_course::instance($courseid);
} else {
    require_login();
    $context = context_system::instance();
}

$PAGE->set_context($context);

$q   = query::get($id);
$rec = $q->record();

if ($rec->status !== query::STATUS_PUBLISHED) {
    throw new moodle_exception('errgroupnotpublished', 'local_reportsources');
}

// Same single authoritative gate as chart.php / the RB viewer: managers always, everyone else only
// if core RB's context + audience admit them. Rows are still scoped by the per-user / teacher-course
// filters inside fetch_rows_for_viewer().
if (!$q->current_user_can_view_report()) {
    throw new moodle_exception(
        'nopermissions',
        'error',
        '',
        get_string('groupedview', 'local_reportsources')
    );
}

$meta      = $q->columns_meta();
$groupmeta = $q->group_meta();
$breakcol  = (string) ($groupmeta['breakcol'] ?? '');
if (!$meta || $breakcol === '') {
    throw new moodle_exception('errgroupnotconfigured', 'local_reportsources');
}

$rowlimit = max(1, min(5000, (int) ($groupmeta['rowlimit'] ?? 1000)));
$isexport = ($format === 'csv' || $format === 'excel');
$perpage  = max(1, min(200, (int) ($groupmeta['perpage'] ?? 25))); // Groups (not rows) per page.

if ($isexport) {
    // Exports contain every group up to the row limit, not just the current page.
    $rows = $q->fetch_rows_for_viewer($rowlimit, 0, $breakcol);
    $totalgroups = null;
} else {
    // The on-screen view is paged by group, so a group's detail rows never split across pages.
    $paged = $q->fetch_grouped_page($breakcol, $page, $perpage);
    $rows = $paged['rows'];
    $totalgroups = $paged['totalgroups'];
}

// The columns actually present in the fetched rows (the per-user filter column is stripped out).
$available = $rows ? array_keys($rows[0]) : array_keys($meta);
$keep = static fn(array $cols): array => array_values(array_intersect($cols, $available));

$headercols = $keep($groupmeta['headercols'] ?? []);
if (!$headercols) {
    // Fall back to the break column itself on the header line.
    $headercols = $keep([$breakcol]);
}
$detailcols = $keep($groupmeta['detailcols'] ?? []);
if (!$detailcols) {
    // Default detail columns: everything not already on the header line and not the break key.
    $detailcols = $keep(array_diff($available, $headercols, [$breakcol]));
}
if (!$detailcols) {
    // Degenerate config (e.g. every column is a header column): show the break column as detail.
    $detailcols = $keep([$breakcol]);
}

$label = static function (string $col) use ($meta): string {
    return (string) ($meta[$col]['label'] ?? $col);
};

// Build the ordered groups: [headerstring => [detailrow, ...]] preserving first-seen order.
$groups     = [];
$prevkey    = null;
$curheader  = '';
foreach ($rows as $row) {
    $key = (string) ($row[$breakcol] ?? '');
    if ($key !== $prevkey) {
        $parts = [];
        foreach ($headercols as $col) {
            $val = $q->format_cell($col, $row[$col] ?? '');
            if ($val !== '') {
                $parts[] = $val;
            }
        }
        $curheader = implode(' ', $parts);
        $groups[$curheader] = [];
        $prevkey = $key;
    }
    $detail = [];
    foreach ($detailcols as $col) {
        $detail[$col] = $q->format_cell($col, $row[$col] ?? '');
    }
    $groups[$curheader][] = $detail;
}

// CSV export — stream and exit before any HTML output. Header groups are written as their own row
// (break value in the first cell), detail rows indented into the remaining columns.
if ($format === 'csv') {
    $filename = clean_filename($rec->name) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // Neutralise spreadsheet formula injection (leading =, +, -, @, tab, CR execute as a formula).
    $escape = static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;
    // Column header: a leading "group" column, then the detail column labels.
    fputcsv($out, array_merge([''], array_map($label, $detailcols)));
    foreach ($groups as $header => $details) {
        fputcsv($out, array_map($escape, array_merge([$header], array_fill(0, count($detailcols), ''))));
        foreach ($details as $detail) {
            fputcsv($out, array_map($escape, array_merge([''], array_values($detail))));
        }
    }
    fclose($out);
    exit;
}

// Spreadsheet export — an .xlsx workbook with the same banded layout: group name in the first
// column, detail values indented into the columns to its right, under a bold label row.
if ($format === 'excel') {
    require_once($CFG->libdir . '/excellib.php');
    $workbook = new \MoodleExcelWorkbook(clean_filename($rec->name));
    $workbook->send(clean_filename($rec->name) . '.xlsx');
    $sheet = $workbook->add_worksheet(get_string('groupedview', 'local_reportsources'));

    $bold = $workbook->add_format(['bold' => 1]);
    $r = 0;
    // Label row: blank leading cell (the group column), then the detail column labels.
    $c = 1;
    foreach ($detailcols as $col) {
        $sheet->write_string($r, $c++, $label($col), $bold);
    }
    $r++;
    foreach ($groups as $header => $details) {
        $sheet->write_string($r++, 0, (string) $header, $bold);
        foreach ($details as $detail) {
            $c = 1;
            foreach (array_values($detail) as $value) {
                $sheet->write_string($r, $c++, (string) $value);
            }
            $r++;
        }
    }
    $workbook->close();
    exit;
}

$PAGE->set_url(new moodle_url(
    '/local/reportsources/grouped.php',
    ['id' => $id] + ($courseid ? ['courseid' => $courseid] : [])
));
$PAGE->set_pagelayout($courseid ? 'incourse' : 'admin');
$PAGE->set_title($rec->name);
$PAGE->set_heading($rec->name);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($rec->name));

if (!$groups) {
    echo $OUTPUT->notification(get_string('groupnorows', 'local_reportsources'), 'info');
} else {
    // Page by group: the pager's item count is the total number of groups, not rows.
    $pagingbar = new paging_bar($totalgroups, $page, $perpage, $PAGE->url);
    echo $OUTPUT->render($pagingbar);

    // One table for the whole report: each group name is a full-width header row above its detail
    // rows. No column-label header — the group name carries the context. A single table keeps the
    // detail columns aligned down the page.
    $table = new html_table();
    $table->attributes['class'] = 'table generaltable rs-grouped';
    foreach ($groups as $header => $details) {
        $headercell = new html_table_cell(s($header));
        $headercell->colspan = count($detailcols);
        $headercell->header = true;
        $headercell->attributes['class'] = 'rs-group-header';
        $table->data[] = new html_table_row([$headercell]);
        foreach ($details as $detail) {
            $table->data[] = array_map('s', array_values($detail));
        }
    }
    echo html_writer::table($table);

    echo $OUTPUT->render($pagingbar);
}

$indexurl = new moodle_url('/local/reportsources/index.php', $courseid ? ['courseid' => $courseid] : []);
$csvurl   = new moodle_url(
    '/local/reportsources/grouped.php',
    ['id' => $id, 'format' => 'csv'] + ($courseid ? ['courseid' => $courseid] : [])
);
$excelurl = new moodle_url(
    '/local/reportsources/grouped.php',
    ['id' => $id, 'format' => 'excel'] + ($courseid ? ['courseid' => $courseid] : [])
);

$actions = html_writer::link(
    $indexurl,
    html_writer::tag('i', '', ['class' => 'fa fa-arrow-left mr-1', 'aria-hidden' => 'true']) .
        get_string('back'),
    ['class' => 'btn btn-secondary btn-sm mr-2']
);
$actions .= html_writer::link(
    $csvurl,
    get_string('chartexportcsv', 'local_reportsources'),
    ['class' => 'btn btn-secondary btn-sm mr-2']
);
$actions .= html_writer::link(
    $excelurl,
    get_string('groupexportexcel', 'local_reportsources'),
    ['class' => 'btn btn-secondary btn-sm mr-2']
);
$actions .= html_writer::tag(
    'button',
    get_string('chartprint', 'local_reportsources'),
    ['class' => 'btn btn-secondary btn-sm', 'onclick' => 'window.print(); return false;']
);

echo html_writer::div($actions, 'mt-3 noprint');

echo $OUTPUT->footer();
