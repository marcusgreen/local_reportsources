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
 * Dev-time converter: upstream morekeys.xml -> committed db/implied_keys.php.
 *
 * Runs on a developer machine ONLY, when the upstream implied-key list changes. Its output
 * (db/implied_keys.php) is reviewed and committed to the plugin. Nothing here executes at install
 * time or at runtime — install merely copies the committed PHP file (see implied_keys_plan.md).
 *
 * The upstream file (marcusgreen/moodle_local-sqlgenerator/morekeys.xml) is a headerless fragment of
 * XMLDB <KEY> elements. Because a bare <KEY> has no owning <TABLE>, the source table is encoded in
 * NAME as "<table>_erd_<label>", so the table is the substring before the first "_erd_".
 *
 * Usage (from the plugin root):
 *   php tools/xml_to_keys.php path/to/morekeys.xml           # writes db/implied_keys.php
 *   php tools/xml_to_keys.php path/to/morekeys.xml -         # prints to stdout
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$source = $argv[1] ?? null;
if ($source === null || !is_readable($source)) {
    fwrite(STDERR, "Usage: php tools/xml_to_keys.php <morekeys.xml> [dest.php|-]\n");
    exit(1);
}
$dest = $argv[2] ?? (__DIR__ . '/../db/implied_keys.php');

$raw = file_get_contents($source);
if ($raw === false) {
    fwrite(STDERR, "Could not read {$source}\n");
    exit(1);
}

// The upstream file is a headerless fragment; wrap it so simplexml has a single root.
$prev = libxml_use_internal_errors(true);
$xml = simplexml_load_string("<KEYS>\n{$raw}\n</KEYS>");
libxml_use_internal_errors($prev);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse XML in {$source}\n");
    exit(1);
}

$map = [];
$skipped = 0;
foreach ($xml->KEY as $key) {
    if (strtolower((string) $key['TYPE']) !== 'foreign') {
        $skipped++;
        continue;
    }
    $name = (string) $key['NAME'];
    $pos = strpos($name, '_erd_');
    if ($pos === false || $pos === 0) {
        $skipped++;
        continue;
    }
    $table = strtolower(substr($name, 0, $pos));

    $fields = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $key['FIELDS'])))));
    $reftable = strtolower(trim((string) $key['REFTABLE']));
    $reffields = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $key['REFFIELDS'])))));
    $comment = trim((string) $key['COMMENT']);

    if ($fields === [] || $reftable === '' || $reffields === []) {
        $skipped++;
        continue;
    }

    foreach ($fields as $i => $field) {
        $entry = [
            'reftable' => $reftable,
            'refcol'   => $reffields[$i] ?? $reffields[0],
        ];
        if ($comment !== '') {
            $entry['comment'] = $comment;
        }
        $map[$table][$field] = $entry;
    }
}

// Stable ordering so regenerated diffs stay minimal.
ksort($map);
foreach ($map as &$cols) {
    ksort($cols);
}
unset($cols);

$out = render_php($map, basename($source));

if ($dest === '-') {
    echo $out;
} else {
    if (file_put_contents($dest, $out) === false) {
        fwrite(STDERR, "Could not write {$dest}\n");
        exit(1);
    }
    $count = array_sum(array_map('count', $map));
    fwrite(STDERR, "Wrote {$dest}: " . count($map) . " tables, {$count} keys ({$skipped} rows skipped).\n");
}

/**
 * Render the implied-key map as a committable PHP file that returns the array.
 *
 * @param array<string, array<string, array<string, string>>> $map
 * @param string $sourcename Basename of the source XML, for the do-not-edit banner.
 * @return string
 */
function render_php(array $map, string $sourcename): string {
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '// This file is part of Moodle - https://moodle.org/';
    $lines[] = '//';
    $lines[] = '// Moodle is free software: you can redistribute it and/or modify';
    $lines[] = '// it under the terms of the GNU General Public License as published by';
    $lines[] = '// the Free Software Foundation, either version 3 of the License, or';
    $lines[] = '// (at your option) any later version.';
    $lines[] = '//';
    $lines[] = '// Moodle is distributed in the hope that it will be useful,';
    $lines[] = '// but WITHOUT ANY WARRANTY; without even the implied warranty of';
    $lines[] = '// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the';
    $lines[] = '// GNU General Public License for more details.';
    $lines[] = '//';
    $lines[] = '// You should have received a copy of the GNU General Public License';
    $lines[] = '// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.';
    $lines[] = '';
    $lines[] = '/**';
    $lines[] = " * Curated implied foreign-key map — Moodle relationships not declared in install.xml.";
    $lines[] = ' *';
    $lines[] = " * GENERATED from upstream {$sourcename} by tools/xml_to_keys.php. DO NOT HAND-EDIT —";
    $lines[] = ' * edit the upstream morekeys.xml and regenerate. Shape matches schema::build_fk_map():';
    $lines[] = ' * [table => [column => [reftable => ..., refcol => ...]]].';
    $lines[] = ' *';
    $lines[] = ' * @package   local_reportsources';
    $lines[] = ' * @copyright 2026 Marcus Green';
    $lines[] = ' * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later';
    $lines[] = ' */';
    $lines[] = '';
    $lines[] = 'return [';
    foreach ($map as $table => $cols) {
        $lines[] = "    '" . addslashes($table) . "' => [";
        foreach ($cols as $col => $entry) {
            $pair = "['reftable' => '" . addslashes($entry['reftable']) . "', 'refcol' => '"
                . addslashes($entry['refcol']) . "']";
            $line = "        '" . addslashes($col) . "' => {$pair},";
            if (!empty($entry['comment'])) {
                $line .= ' // ' . str_replace(["\r", "\n"], ' ', $entry['comment']);
            }
            $lines[] = $line;
        }
        $lines[] = '    ],';
    }
    $lines[] = '];';
    $lines[] = '';

    return implode("\n", $lines);
}
