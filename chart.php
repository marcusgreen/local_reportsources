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
 * Render a chart for a published ad-hoc query.
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

if ($courseid) {
    require_login($courseid);
    $context = context_course::instance($courseid);
} else {
    require_login();
    $context = context_system::instance();
}

$PAGE->set_context($context);

if ($courseid) {
    if (!has_capability('local/reportsources:view', $context) &&
        !has_capability('local/reportsources:viewown', $context) &&
        !has_capability('local/reportsources:author', context_system::instance()) &&
        !has_capability('local/reportsources:viewall', context_system::instance())) {
        require_capability('local/reportsources:view', $context);
    }
} else {
    if (!has_capability('local/reportsources:viewall', $context) &&
        !has_capability('local/reportsources:author', $context) &&
        !has_capability('local/reportsources:view', $context)) {
        require_capability('local/reportsources:view', $context);
    }
}

$q   = query::get($id);
$rec = $q->record();

if ($rec->status !== query::STATUS_PUBLISHED) {
    throw new moodle_exception('errchartnotpublished', 'local_reportsources');
}

// The chart reads the same data as the RB report, so it must honour the same report-level
// access: managers always, everyone else only if core RB's context + audience admit them.
$syscontext = context_system::instance();
$canmanage = has_capability('local/reportsources:author', $syscontext)
    || has_capability('local/reportsources:approve', $syscontext)
    || has_capability('local/reportsources:viewall', $syscontext);

$reportmodel = $rec->reportid
    ? \core_reportbuilder\local\models\report::get_record(['id' => $rec->reportid])
    : null;

if (!$canmanage && (!$reportmodel
        || !\core_reportbuilder\permission::can_view_report($reportmodel))) {
    throw new moodle_exception('nopermissions', 'error', '',
        get_string('viewchart', 'local_reportsources'));
}

$chartmeta = $rec->chartmeta ? json_decode($rec->chartmeta, true) : [];
if (empty($chartmeta['type']) || $chartmeta['type'] === 'none') {
    throw new moodle_exception('errchartnotconfigured', 'local_reportsources');
}

$xcol     = (string) ($chartmeta['xcol'] ?? '');
$ycol     = (string) ($chartmeta['ycol'] ?? '');
$rowlimit = max(1, min(5000, (int) ($chartmeta['rowlimit'] ?? 200)));
$type     = $chartmeta['type'];

// Fetch only the published columns: columnsmeta is denylist-stripped at publish time, while the
// physical VIEW may still hold denied columns (password etc.) — never select *. Per-user queries
// are additionally scoped to the viewing user, mirroring the RB base condition in adhoc_query.
$meta = $q->columns_meta();
if (!$meta) {
    throw new moodle_exception('errchartnotconfigured', 'local_reportsources');
}
$conditions = null;
$useridcolumn = $q->useridcolumn();
if ($useridcolumn !== '') {
    if (!array_key_exists($useridcolumn, $meta)) {
        // Fail closed: a per-user query whose filter column is no longer in the published
        // metadata must not fall through to showing every user's rows.
        throw new moodle_exception('errchartnotconfigured', 'local_reportsources');
    }
    $conditions = [$useridcolumn => $USER->id];
    // Hide the filter column from chart and CSV output: after filtering, its value is always
    // the viewer's own id. get_recordset() applies $conditions independently of $fields.
    if (count($meta) > 1) {
        unset($meta[$useridcolumn]);
    }
}
$fields = implode(', ', array_keys($meta));

$rows = [];
try {
    $rs = $DB->get_recordset($rec->viewname, $conditions, '', $fields, 0, $rowlimit);
    foreach ($rs as $row) {
        $rows[] = (array) $row;
    }
    $rs->close();
} catch (\dml_exception $e) {
    // Viewers may be ordinary audience members: never surface the raw DB error (it can
    // contain SQL fragments and physical table names). Detail goes to developer debugging.
    debugging($e->getMessage(), DEBUG_DEVELOPER);
    throw new moodle_exception('errchartdata', 'local_reportsources');
}

// CSV export — stream and exit before any HTML output.
if ($format === 'csv') {
    $filename = clean_filename($rec->name) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        // Neutralise spreadsheet formula injection: a leading =, +, -, @, tab or CR would
        // execute as a formula when the CSV is opened in Excel/Sheets.
        $escape = static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;
        foreach ($rows as $row) {
            fputcsv($out, array_map($escape, $row));
        }
    }
    fclose($out);
    exit;
}

$labels = [];
$values = [];
foreach ($rows as $row) {
    $labels[] = (string) ($row[$xcol] ?? '');
    $values[] = (float) ($row[$ycol] ?? 0);
}

$chart = match ($type) {
    'line'            => new \core\chart_line(),
    'pie', 'doughnut' => new \core\chart_pie(),
    default           => new \core\chart_bar(),
};
if ($type === 'doughnut') {
    $chart->set_doughnut(true);
}

$series = new \core\chart_series($ycol, $values);
$chart->add_series($series);
$chart->set_labels($labels);
$chart->set_title(format_string($rec->name));

$PAGE->set_url(new moodle_url('/local/reportsources/chart.php',
    ['id' => $id] + ($courseid ? ['courseid' => $courseid] : [])));
$PAGE->set_pagelayout($courseid ? 'incourse' : 'admin');
$PAGE->set_title($rec->name);
$PAGE->set_heading($rec->name);

$indexurl = new moodle_url('/local/reportsources/index.php', $courseid ? ['courseid' => $courseid] : []);
$csvurl   = new moodle_url('/local/reportsources/chart.php',
    ['id' => $id, 'format' => 'csv'] + ($courseid ? ['courseid' => $courseid] : []));

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($rec->name));
echo $OUTPUT->render_chart($chart, false);

$PAGE->requires->js_call_amd('local_reportsources/chart_download', 'init', [clean_filename($rec->name)]);

$actions = html_writer::link($indexurl,
    html_writer::tag('i', '', ['class' => 'fa fa-arrow-left mr-1', 'aria-hidden' => 'true']) .
        get_string('back'),
    ['class' => 'btn btn-secondary btn-sm mr-2']);
$actions .= html_writer::link($csvurl, get_string('chartexportcsv', 'local_reportsources'),
    ['class' => 'btn btn-secondary btn-sm mr-2']);
$actions .= html_writer::tag('button', get_string('chartdownloadpng', 'local_reportsources'),
    ['id' => 'local-reportsources-download-png', 'class' => 'btn btn-secondary btn-sm mr-2']);
$actions .= html_writer::tag('button', get_string('chartprint', 'local_reportsources'),
    ['class' => 'btn btn-secondary btn-sm', 'onclick' => 'window.print(); return false;']);

echo html_writer::div($actions, 'mt-3 noprint');

echo $OUTPUT->footer();
