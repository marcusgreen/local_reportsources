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
 * Load the bundled sample report views.
 *
 * Linked from the post-install notification and from the plugin settings page. Imports the
 * samples shipped in samples/reportsources.json as fresh drafts owned by the current admin, reusing
 * {@see \local_reportsources\local\transfer::import_bundled()}.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_reportsources\local\transfer;

require_login();

admin_externalpage_setup('local_reportsources_samples');

$indexurl = new moodle_url('/local/reportsources/index.php');

// Handle the confirmed load.
if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
    $result = transfer::import_bundled();

    $messages = [get_string('importdone', 'local_reportsources', $result['imported'])];
    if (!empty($result['duplicates'])) {
        $messages[] = get_string('samples:duplicates', 'local_reportsources', implode(', ', $result['duplicates']));
    }
    if (!empty($result['demoted'])) {
        $messages[] = get_string('importdemoted', 'local_reportsources', implode(', ', array_keys($result['demoted'])));
    }
    if (!empty($result['skipped'])) {
        $messages[] = get_string('importskipped', 'local_reportsources', implode(', ', array_keys($result['skipped'])));
    }

    redirect($indexurl, implode(' ', $messages), null, \core\output\notification::NOTIFY_SUCCESS);
}

$count = transfer::count_bundled();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('samples:title', 'local_reportsources'));

if ($count === 0) {
    echo $OUTPUT->notification(
        get_string('samples:none', 'local_reportsources'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo html_writer::link($indexurl, get_string('back'));
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('samples:intro', 'local_reportsources', $count));

$confirmurl = new moodle_url('/local/reportsources/samples.php', ['confirm' => 1, 'sesskey' => sesskey()]);
$loadbutton = $OUTPUT->single_button($confirmurl, get_string('samples:load', 'local_reportsources'), 'post');
$cancelbutton = $OUTPUT->single_button($indexurl, get_string('cancel'), 'get');
echo html_writer::div($loadbutton . $cancelbutton, 'd-flex gap-2');

echo $OUTPUT->footer();
