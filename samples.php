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
 * Browse and import report sources, either the bundled samples or the remote shared repository.
 *
 * Linked from the index page (single mode), the post-install notification and the plugin settings
 * page. By default it lists the samples shipped in samples/samples.json; with `remote=1` (and the
 * shared-repository feature switched on) it instead lists the RS-format export files in the
 * configured GitHub repo via {@see \local_reportsources\local\repository}. Either way the chosen
 * sources are imported as fresh drafts owned by the current user, reusing
 * {@see \local_reportsources\local\transfer::import()}.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_reportsources\local\repository;
use local_reportsources\local\transfer;

require_login();

admin_externalpage_setup('local_reportsources_samples');

$indexurl = new moodle_url('/local/reportsources/index.php');

// Single mode (linked from the index page) offers radio buttons so exactly one source imports;
// the default (settings / post-install) offers checkboxes for a bulk import.
$single = optional_param('single', 0, PARAM_BOOL);

// Remote mode lists the configured shared repository instead of the bundled samples. Only honoured
// when the shared-repository feature is switched on, so a disabled feature can never reach out.
$remote = optional_param('remote', 0, PARAM_BOOL) && repository::enabled();

// Preserve the mode across the form POST and the mode-switch / refresh links.
$modeparams = ($single ? ['single' => 1] : []) + ($remote ? ['remote' => 1] : []);
$selfurl = new moodle_url('/local/reportsources/samples.php', $modeparams);

// A Refresh action (remote only) busts the MUC cache so a just-updated repo shows through without
// waiting for the TTL.
if ($remote && optional_param('refresh', 0, PARAM_BOOL) && confirm_sesskey()) {
    repository::remote_sources(true);
    redirect($selfurl);
}

$sources = $remote ? repository::remote_sources() : transfer::bundled_samples();

// Handle a selective import of the ticked sources.
if (optional_param('import', 0, PARAM_BOOL) && confirm_sesskey()) {
    $duplicates = [];

    if ($single) {
        // Radio mode: import exactly one source, whatever it is. Prefix the name so an
        // already-present source lands as a distinct copy rather than colliding.
        $index = optional_param('selected', -1, PARAM_INT);
        if (!isset($sources[$index])) {
            redirect(
                $selfurl,
                get_string('samples:noneselected', 'local_reportsources'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        $source = $sources[$index];
        $source['name'] = get_string('samples:sampleprefix', 'local_reportsources', $source['name']);
        $sources = [$source];
        $wanted = [0];
    } else {
        // Checkbox mode posts selected[].
        $selected = optional_param_array('selected', [], PARAM_INT);

        // Server-side guard: never import a source whose name already exists, even if the client
        // re-enabled the disabled control. Fold those back into the "already present" message.
        $wanted = [];
        foreach ($selected as $index) {
            if (!isset($sources[$index])) {
                continue;
            }
            if (!empty($sources[$index]['duplicate'])) {
                $duplicates[] = (string) $sources[$index]['name'];
                continue;
            }
            $wanted[] = $index;
        }

        if (empty($wanted) && empty($duplicates)) {
            redirect(
                $selfurl,
                get_string('samples:noneselected', 'local_reportsources'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
    }

    $result = transfer::import($sources, $wanted);

    $messages = [get_string('importdone', 'local_reportsources', $result['imported'])];
    if (!empty($duplicates)) {
        $messages[] = get_string('samples:duplicates', 'local_reportsources', implode(', ', $duplicates));
    }
    if (!empty($result['demoted'])) {
        $messages[] = get_string('importdemoted', 'local_reportsources', implode(', ', array_keys($result['demoted'])));
    }
    if (!empty($result['skipped'])) {
        $messages[] = get_string('importskipped', 'local_reportsources', implode(', ', array_keys($result['skipped'])));
    }

    redirect($indexurl, implode(' ', $messages), null, \core\output\notification::NOTIFY_SUCCESS);
}

// The configured repository URL, only needed in remote mode for the intro / notices.
$repourl = $remote ? repository::configured_url() : '';

if ($remote) {
    $heading = get_string($single ? 'repository:titlesingle' : 'repository:title', 'local_reportsources');
} else {
    $heading = get_string($single ? 'samples:titlesingle' : 'samples:title', 'local_reportsources');
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);

// Toolbar: Back, a mode-switch button (only when the shared-repository feature is on) and, in
// remote mode, a Refresh button.
$buttons = $OUTPUT->single_button($indexurl, get_string('back'), 'get');

if (repository::enabled()) {
    if ($remote) {
        // Switch back to the bundled samples.
        $buttons .= $OUTPUT->single_button(
            new moodle_url('/local/reportsources/samples.php', $single ? ['single' => 1] : []),
            get_string('samples:linklabel', 'local_reportsources'),
            'get'
        );
    } else {
        // Switch to the shared repository.
        $buttons .= $OUTPUT->single_button(
            new moodle_url('/local/reportsources/samples.php',
                ['remote' => 1] + ($single ? ['single' => 1] : [])),
            get_string('repository:linklabel', 'local_reportsources'),
            'get'
        );
    }
}

if ($remote) {
    $buttons .= $OUTPUT->single_button(
        new moodle_url('/local/reportsources/samples.php',
            ['refresh' => 1, 'sesskey' => sesskey()] + $modeparams),
        get_string('repository:refresh', 'local_reportsources'),
        'post'
    );
}

echo html_writer::div($buttons, 'mb-3 d-flex flex-wrap gap-2');

if (empty($sources)) {
    if ($remote) {
        // Distinguish "no repository configured" from "configured but nothing came back".
        $notice = $repourl === ''
            ? get_string('repository:unconfigured', 'local_reportsources')
            : get_string('repository:none', 'local_reportsources', s($repourl));
    } else {
        $notice = get_string('samples:none', 'local_reportsources');
    }
    echo $OUTPUT->notification($notice, \core\output\notification::NOTIFY_WARNING);
    echo html_writer::link($indexurl, get_string('back'));
    echo $OUTPUT->footer();
    exit;
}

// Build the source context: one row per source.
// Checkbox (bulk) mode disables any source whose name already exists and pre-ticks the rest.
// Radio (single) mode lets you import ANY source — the import prefixes the name, so an
// already-present source is imported as a fresh "Sample: ..." copy, never blocked. The first
// row is pre-selected (radios are mutually exclusive).
// Glyph + Bootstrap text-colour class per chart type — same treatment as the query listing entity,
// so a source that carries a chart config is flagged bar/line/pie at a glance.
$charticons = [
    'bar'      => ['fa-chart-column', 'text-primary'],
    'line'     => ['fa-chart-line', 'text-danger'],
    'pie'      => ['fa-chart-pie', 'text-success'],
    'doughnut' => ['fa-chart-pie', 'text-info'],
];

$rows = [];
$radiochosen = false;
foreach ($sources as $source) {
    $isdup = !empty($source['duplicate']);
    if ($single) {
        $disabled = false;
        $checked = !$radiochosen;
        if ($checked) {
            $radiochosen = true;
        }
    } else {
        $disabled = $isdup;
        $checked = !$isdup;
    }

    // Leading glyph. A chart config wins (bar/line/pie/doughnut); otherwise a heuristic flags
    // aggregate "summary" sources — SQL with GROUP BY or an aggregate function.
    $nameicon = '';
    $charttype = $source['chartmeta']['type'] ?? 'none';
    if ($charttype !== 'none' && isset($charticons[$charttype])) {
        [$faclass, $colourclass] = $charticons[$charttype];
        $nameicon = html_writer::tag('i', '', [
            'class'       => 'fa ' . $faclass . ' ' . $colourclass . ' me-1',
            'title'       => get_string('viewchart', 'local_reportsources'),
            'aria-hidden' => 'true',
        ]);
    } else if (preg_match('/\bgroup\s+by\b|\b(?:count|sum|avg|min|max)\s*\(/i', (string) $source['querysql'])) {
        $nameicon = html_writer::tag('i', '', [
            'class'       => 'fa fa-calculator text-info me-1',
            'title'       => get_string('summaryreport', 'local_reportsources'),
            'aria-hidden' => 'true',
        ]);
    }

    $rows[] = [
        'index'       => $source['index'],
        'name'        => $source['name'],
        'nameicon'    => $nameicon,
        'description' => $source['description'],
        'querysql'    => $source['querysql'],
        'duplicate'   => $isdup,
        'disabled'    => $disabled,
        'checked'     => $checked,
    ];
}

if ($remote) {
    $intro = get_string($single ? 'repository:introsingle' : 'repository:intro',
        'local_reportsources', s($repourl));
} else {
    $intro = get_string($single ? 'samples:introsingle' : 'samples:intro',
        'local_reportsources', count($sources));
}

echo $OUTPUT->render_from_template('local_reportsources/samples_list', [
    'intro'     => $intro,
    'single'    => $single,
    'remote'    => $remote,
    'actionurl' => $selfurl->out(false),
    'cancelurl' => $indexurl->out(false),
    'sesskey'   => sesskey(),
    'samples'   => $rows,
]);

echo $OUTPUT->footer();
