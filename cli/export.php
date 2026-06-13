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
 * Export every saved ad-hoc query on this site as a single JSON file.
 *
 * Wraps {@see \local_reportsources\local\transfer::export()} for every query
 * record, so the output shares the exact format produced by the web export UI
 * (export.php) and can be re-imported via import.php.
 *
 * Usage (from Moodle root):
 *   php local/reportsources/cli/export.php              # write to ./reportsources.json
 *   php local/reportsources/cli/export.php --dir=/tmp   # write to /tmp/reportsources.json
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_reportsources\local\query;
use local_reportsources\local\transfer;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'dir' => ''],
    ['h' => 'help']
);

// Standard output filename, written into the destination folder.
const EXPORT_FILENAME = 'reportsources.json';

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln(<<<'HELP'
Export every saved ad-hoc query on this site as a single JSON file.

Writes to reportsources.json in the destination folder (current directory
by default).

Options:
  -h, --help     Print this help.
  --dir=PATH     Destination folder for reportsources.json (default: current dir).

Examples:
  php local/reportsources/cli/export.php
  php local/reportsources/cli/export.php --dir=/tmp
HELP);
    exit(0);
}

// Every query id on the site, ordered for stable output.
$ids = $DB->get_fieldset_select(query::TABLE, 'id', '', null, 'name ASC');
$payload = transfer::export($ids);

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$dir = $options['dir'] !== '' ? rtrim($options['dir'], '/') : '.';
if (!is_dir($dir)) {
    cli_error("Destination folder does not exist: {$dir}");
}
$path = $dir . '/' . EXPORT_FILENAME;

if (file_put_contents($path, $json) === false) {
    cli_error("Could not write to {$path}.");
}
cli_writeln('Exported ' . count($payload['sources']) . " queries -> {$path}");
