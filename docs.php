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
 * Render the bundled user documentation (docs/userdocs.md) in the browser.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
// Only authors (people who can create report views) get the docs page.
require_capability('local/reportsources:author', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportsources/docs.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('userdocs', 'local_reportsources'));
$PAGE->set_heading(get_string('reportsources', 'local_reportsources'));

$markdown = file_get_contents(__DIR__ . '/docs/userdocs.md');

// The markdown references images by bare filename (e.g. logo.png); they live in docs/. Rewrite both
// markdown image syntax ![alt](file.png) and raw <img src="file.png"> to absolute plugin URLs so they
// resolve once the doc is served from docs.php rather than the docs/ directory.
$docsbase = (new moodle_url('/local/reportsources/docs/'))->out(false);
$markdown = preg_replace_callback(
    '/!\[([^\]]*)\]\((?!https?:\/\/)([^)]+)\)/',
    function ($m) use ($docsbase) {
        return '![' . $m[1] . '](' . $docsbase . ltrim($m[2], '/') . ')';
    },
    $markdown
);
$markdown = preg_replace_callback(
    '/<img\b([^>]*?)\bsrc="(?!https?:\/\/)([^"]+)"/i',
    function ($m) use ($docsbase) {
        return '<img' . $m[1] . 'src="' . $docsbase . ltrim($m[2], '/') . '"';
    },
    $markdown
);

echo $OUTPUT->header();
echo $OUTPUT->box(
    format_text($markdown, FORMAT_MARKDOWN, ['context' => $context, 'noclean' => true]),
    'generalbox'
);
echo $OUTPUT->footer();
