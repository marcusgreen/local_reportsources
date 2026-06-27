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

namespace local_reportsources\local;

use local_reportsources\local\sql\validator;
use local_reportsources\local\sql\view;

/**
 * Import SQL reports from the Configurable Reports block (block_configurable_reports).
 *
 * Reads each `type='sql'` Configurable Reports instance, decodes its embedded query, applies a
 * deterministic Configurable-Reports → Report-sources translation (token swaps, MySQL date-function
 * rewrites, double-quote and `?` normalisation), then re-validates the result through the same
 * {@see validator} and live dry-run the edit form uses. Reports that translate cleanly are handed to
 * {@see transfer::import()} and land as fresh drafts owned by the importer; everything else is
 * rejected with a printed reason and never written.
 *
 * No AI is involved: every transformation here is a fixed rule. Anything the rules cannot map
 * faithfully (e.g. %%USERID%%, %%FILTER_*%%, DATEDIFF) is rejected rather than guessed.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cr_import {
    /** Configurable Reports DB table. */
    private const CR_TABLE = 'block_configurable_reports';

    /**
     * Whether the Configurable Reports block is installed (its report table exists).
     *
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::CR_TABLE);
    }

    /**
     * Discover all Configurable Reports instances and classify each for import.
     *
     * @return array<int, array{id:int,name:string,type:string,verdict:string,reason:string,
     *         notes:string[],source:?array<string,mixed>}> Keyed by CR report id.
     */
    public static function discover(): array {
        global $DB;

        if (!self::available()) {
            return [];
        }

        $out = [];
        foreach ($DB->get_records(self::CR_TABLE, null, 'name ASC') as $rec) {
            $out[(int) $rec->id] = self::classify($rec);
        }
        return $out;
    }

    /**
     * Classify a single Configurable Reports record: importable or rejected, with reason/notes.
     *
     * @param \stdClass $rec A block_configurable_reports row.
     * @return array{id:int,name:string,type:string,verdict:string,reason:string,
     *         notes:string[],source:?array<string,mixed>}
     */
    public static function classify(\stdClass $rec): array {
        $base = [
            'id'      => (int) $rec->id,
            'name'    => (string) $rec->name,
            'type'    => (string) ($rec->type ?? ''),
            'verdict' => 'reject',
            'reason'  => '',
            'notes'   => [],
            'source'  => null,
        ];

        if (($rec->type ?? '') !== 'sql') {
            $base['reason'] = get_string('crimport:reasonnotsql', 'local_reportsources', $base['type'] ?: '?');
            return $base;
        }

        $sql = self::extract_sql($rec);
        if ($sql === '') {
            $base['reason'] = get_string('crimport:reasonnosql', 'local_reportsources');
            return $base;
        }

        // Deterministic CR → RS translation.
        $converted = self::convert($sql);
        if ($converted['fatal'] !== null) {
            $base['reason'] = $converted['fatal'];
            return $base;
        }

        // Static validation (denylist, SELECT-only, supported tokens, ? rejection, ...).
        try {
            $validated = validator::validate($converted['sql']);
        } catch (\moodle_exception $e) {
            $base['reason'] = $e->getMessage();
            return $base;
        }

        // Live dry-run: catches bad/dropped tables (e.g. mdl_log), missing columns, dialect errors
        // and VIEW duplicate-column problems — exactly the failures static checks cannot see.
        $dryrunerror = self::dry_run($validated);
        if ($dryrunerror !== null) {
            $base['reason'] = $dryrunerror;
            return $base;
        }

        // Accepted.
        $base['verdict'] = 'import';
        $base['notes'] = array_merge($converted['notes'], validator::get_warnings());
        $base['source'] = [
            'name'        => (string) $rec->name,
            'description' => self::clean_summary((string) ($rec->summary ?? '')),
            'querysql'    => $validated,
            'courseid'    => self::map_courseid((int) ($rec->courseid ?? 0)),
            'visible'     => (int) ($rec->visible ?? 1) ? 1 : 0,
            'chartmeta'   => null,
        ];
        return $base;
    }

    /**
     * Import the selected Configurable Reports instances as draft queries.
     *
     * Re-discovers and re-classifies (never trusts ids blindly), keeps only those whose verdict is
     * 'import', and feeds them to {@see transfer::import()} so they share the standard re-validation,
     * courseid-demotion and draft-creation path.
     *
     * @param int[] $ids CR report ids selected by the admin.
     * @return array{imported:int,skipped:array<string,string>,demoted:array<string,int>,
     *         rejected:array<string,string>} import() result plus names rejected at classify time.
     */
    public static function import(array $ids): array {
        $wanted = array_flip(array_map('intval', $ids));
        $classified = self::discover();

        $sources = [];
        $selected = [];
        $rejected = [];
        foreach ($classified as $id => $info) {
            if (!isset($wanted[$id])) {
                continue;
            }
            if ($info['verdict'] !== 'import' || $info['source'] === null) {
                $rejected[$info['name']] = $info['reason'];
                continue;
            }
            $selected[] = count($sources);
            $sources[] = $info['source'];
        }

        $result = transfer::import($sources, $selected);
        $result['rejected'] = $rejected;
        return $result;
    }

    /**
     * Decode the SQL embedded in a Configurable Reports record's serialised `components` blob.
     *
     * Mirrors block_configurable_reports' own `cr_unserialize()`: the blob is
     * serialize(urlencode_recursive(...)), with config objects stored as `O:6:"object"`. We rewrite
     * that to stdClass before unserialising and urldecode the recovered query.
     *
     * @param \stdClass $rec A block_configurable_reports row.
     * @return string The decoded SQL, or '' if none could be recovered.
     */
    private static function extract_sql(\stdClass $rec): string {
        $blob = (string) ($rec->components ?? '');
        if ($blob === '') {
            return '';
        }
        $blob = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $blob);
        $data = @unserialize($blob, ['allowed_classes' => [\stdClass::class]]);
        if (!is_array($data) || !isset($data['customsql']['config'])) {
            return '';
        }
        $config = (array) $data['customsql']['config'];
        if (!isset($config['querysql'])) {
            return '';
        }
        return trim(urldecode((string) $config['querysql']));
    }

    /**
     * Apply the deterministic CR → RS translation passes to a decoded query.
     *
     * @param string $sql Decoded Configurable Reports SQL.
     * @return array{sql:string,notes:string[],fatal:?string} Translated SQL plus human notes, or a
     *         fatal rejection reason when a construct cannot be mapped faithfully.
     */
    public static function convert(string $sql): array {
        $notes = [];

        // 1. MySQL double-quoted string literals -> single-quoted (portable, and lets RS keep
        //    case-sensitive output safe). CR reports are authored against MySQL where "x" is a string.
        $converted = self::rewrite_double_quotes($sql);
        if ($converted !== $sql) {
            $notes[] = get_string('crimport:notequotes', 'local_reportsources');
        }
        $sql = $converted;

        // 2. CR placeholder tokens.
        $tokenresult = self::rewrite_tokens($sql, $notes);
        if ($tokenresult['fatal'] !== null) {
            return ['sql' => $sql, 'notes' => $notes, 'fatal' => $tokenresult['fatal']];
        }
        $sql = $tokenresult['sql'];

        // 3. MySQL date functions -> portable %%TIMESTAMP%% / %%EPOCH%% / %%NOW%% tokens.
        $dateresult = self::rewrite_date_functions($sql, $notes);
        if ($dateresult['fatal'] !== null) {
            return ['sql' => $sql, 'notes' => $notes, 'fatal' => $dateresult['fatal']];
        }
        $sql = $dateresult['sql'];

        // 4. Literal `?` inside string literals -> CONCAT(..., chr(63), ...). RS treats a bare ? as a
        //    bound parameter, so links such as view.php?id= must be rebuilt with chr(63).
        $qresult = self::rewrite_questionmarks($sql);
        if ($qresult !== $sql) {
            $notes[] = get_string('crimport:noteqmark', 'local_reportsources');
        }
        $sql = $qresult;

        return ['sql' => $sql, 'notes' => $notes, 'fatal' => null];
    }

    /**
     * Rewrite CR placeholder tokens to their RS equivalents, or reject unmappable ones.
     *
     * @param string $sql
     * @param string[] $notes Collected human-readable notes (passed by reference).
     * @return array{sql:string,fatal:?string}
     */
    private static function rewrite_tokens(string $sql, array &$notes): array {
        // Faithful CR substitutions: STARTTIME/ENDTIME are the time-range filter bounds CR fills with
        // 0 and a far-future epoch when no range is chosen; DEBUG is a flag CR strips from the SQL.
        $direct = [
            '%%STARTTIME%%' => '0',
            '%%ENDTIME%%'   => '2145938400',
            '%%DEBUG%%'     => '',
        ];
        foreach ($direct as $token => $replacement) {
            if (stripos($sql, $token) !== false) {
                $sql = str_ireplace($token, $replacement, $sql);
                $notes[] = get_string('crimport:notetoken', 'local_reportsources', $token);
            }
        }

        // Scan every remaining token. %%WWWROOT%% and %%COURSEID%% are shared with RS and kept as-is.
        // Anything else has no faithful mapping, so reject and name it.
        if (preg_match_all('/%%[A-Za-z0-9_]+%%/', $sql, $ms)) {
            foreach (array_unique($ms[0]) as $token) {
                $upper = strtoupper($token);
                if ($upper === '%%WWWROOT%%' || $upper === '%%COURSEID%%') {
                    continue;
                }
                if (preg_match('/^%%\s*USER_?IDS?\s*%%$/i', $token)) {
                    return ['sql' => $sql, 'fatal' =>
                        get_string('crimport:reasonuserid', 'local_reportsources', $token)];
                }
                if (stripos($token, '%%FILTER') === 0) {
                    return ['sql' => $sql, 'fatal' =>
                        get_string('crimport:reasonfilter', 'local_reportsources', $token)];
                }
                return ['sql' => $sql, 'fatal' =>
                    get_string('crimport:reasontoken', 'local_reportsources', $token)];
            }
        }

        return ['sql' => $sql, 'fatal' => null];
    }

    /**
     * Rewrite MySQL date functions to portable RS tokens, or reject ones with no clean mapping.
     *
     * Handles, in order of nesting:
     *  - DATE_FORMAT(FROM_UNIXTIME(<e>)[, '<fmt>'])  -> %%TIMESTAMP(<e>[, <neutral>])%%
     *  - FROM_UNIXTIME(<e>[, '<fmt>'])               -> %%TIMESTAMP(<e>[, <neutral>])%%
     *  - UNIX_TIMESTAMP()                            -> %%NOW%%
     *  - UNIX_TIMESTAMP(<e>)                         -> %%EPOCH(<e>)%%
     * Any remaining MySQL-only date function (DATEDIFF, DATE_ADD, DATE_SUB, STR_TO_DATE) is fatal.
     *
     * @param string $sql
     * @param string[] $notes Collected notes (by reference).
     * @return array{sql:string,fatal:?string}
     */
    private static function rewrite_date_functions(string $sql, array &$notes): array {
        $changed = false;

        // DATE_FORMAT(FROM_UNIXTIME(e), 'fmt') and DATE_FORMAT(FROM_UNIXTIME(e, ...), 'fmt').
        $sql = self::replace_calls($sql, 'DATE_FORMAT', function (array $args) use (&$changed) {
            if (count($args) !== 2) {
                return null; // Unsupported shape -> leave for the fatal sweep below.
            }
            $inner = trim($args[0]);
            $fu = self::match_single_call($inner, 'FROM_UNIXTIME');
            if ($fu === null) {
                return null;
            }
            $expr = trim($fu[0]); // First arg of FROM_UNIXTIME is the epoch expression.
            $neutral = self::format_to_neutral($args[1]);
            if ($neutral === null) {
                return null;
            }
            $changed = true;
            return '%%TIMESTAMP(' . $expr . ($neutral === '' ? '' : ', ' . $neutral) . ')%%';
        });

        // FROM_UNIXTIME(e) and FROM_UNIXTIME(e, 'fmt').
        $sql = self::replace_calls($sql, 'FROM_UNIXTIME', function (array $args) use (&$changed) {
            if (count($args) < 1 || count($args) > 2) {
                return null;
            }
            $expr = trim($args[0]);
            $neutral = '';
            if (count($args) === 2) {
                $neutral = self::format_to_neutral($args[1]);
                if ($neutral === null) {
                    return null;
                }
            }
            $changed = true;
            return '%%TIMESTAMP(' . $expr . ($neutral === '' ? '' : ', ' . $neutral) . ')%%';
        });

        // UNIX_TIMESTAMP() -> %%NOW%%, UNIX_TIMESTAMP(e) -> %%EPOCH(e)%%.
        $sql = self::replace_calls($sql, 'UNIX_TIMESTAMP', function (array $args) use (&$changed) {
            $changed = true;
            if (count($args) === 0 || (count($args) === 1 && trim($args[0]) === '')) {
                return '%%NOW%%';
            }
            if (count($args) === 1) {
                return '%%EPOCH(' . trim($args[0]) . ')%%';
            }
            return null;
        });

        if ($changed) {
            $notes[] = get_string('crimport:notedatefn', 'local_reportsources');
        }

        // Any MySQL-only date function we cannot map is a hard reject.
        foreach (['DATE_FORMAT', 'FROM_UNIXTIME', 'UNIX_TIMESTAMP', 'DATEDIFF',
                  'DATE_ADD', 'DATE_SUB', 'STR_TO_DATE'] as $fn) {
            if (preg_match('/\b' . $fn . '\s*\(/i', self::mask_strings($sql))) {
                return ['sql' => $sql, 'fatal' =>
                    get_string('crimport:reasondatefn', 'local_reportsources', $fn)];
            }
        }

        return ['sql' => $sql, 'fatal' => null];
    }

    /**
     * Translate a MySQL DATE_FORMAT/FROM_UNIXTIME format literal to RS's neutral format vocabulary.
     *
     * Returns '' for an empty/whitespace format (RS then applies its default), the neutral string on
     * success, or null when the format contains a specifier RS cannot express (so the caller rejects
     * rather than render the wrong date).
     *
     * @param string $arg The raw argument text, expected to be a quoted string literal.
     * @return string|null
     */
    private static function format_to_neutral(string $arg): ?string {
        $arg = trim($arg);
        // Must be a single-quoted (or double-quoted, pre-normalisation) string literal.
        if (!preg_match("/^'((?:[^']|'')*)'$/", $arg, $m) && !preg_match('/^"((?:[^"]|"")*)"$/', $arg, $m)) {
            return null;
        }
        $fmt = $m[1];
        if (trim($fmt) === '') {
            return '';
        }

        // MySQL specifier -> RS neutral token.
        $map = [
            '%Y' => 'yyyy', '%y' => 'yy', '%m' => 'mm', '%c' => 'mm', '%d' => 'dd', '%e' => 'dd',
            '%H' => 'hh', '%k' => 'hh', '%i' => 'mi', '%s' => 'ss', '%S' => 'ss',
            '%M' => 'month', '%b' => 'mon', '%a' => 'ddd', '%%' => '%',
        ];
        $out = '';
        $len = strlen($fmt);
        for ($i = 0; $i < $len; $i++) {
            if ($fmt[$i] === '%' && $i + 1 < $len) {
                $spec = substr($fmt, $i, 2);
                if (!isset($map[$spec])) {
                    return null; // Unsupported specifier -> reject.
                }
                $out .= $map[$spec];
                $i++;
                continue;
            }
            if ($fmt[$i] === '%') {
                return null; // Trailing lone % -> reject.
            }
            $out .= $fmt[$i];
        }
        return $out;
    }

    /**
     * Replace every top-level call to a named function, passing its parsed arguments to a callback.
     *
     * Respects string literals and nested parentheses. The callback receives the argument list (raw
     * text, split on top-level commas) and returns the replacement text, or null to leave the call
     * untouched.
     *
     * @param string $sql
     * @param string $name Function name (case-insensitive).
     * @param callable(array<int,string>):?string $callback
     * @return string
     */
    private static function replace_calls(string $sql, string $name, callable $callback): string {
        $out = '';
        $offset = 0;
        $len = strlen($sql);
        $namelen = strlen($name);

        while ($offset < $len) {
            // Find the next case-insensitive function name preceded by a word boundary and followed
            // by an opening paren (allowing whitespace).
            if (!preg_match('/\b' . preg_quote($name, '/') . '\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $out .= substr($sql, $offset);
                break;
            }
            $matchstart = $m[0][1];
            // Skip a match that sits inside a string literal.
            if (self::in_string($sql, $matchstart)) {
                $out .= substr($sql, $offset, $matchstart + 1 - $offset);
                $offset = $matchstart + 1;
                continue;
            }
            $parenpos = $matchstart + strlen($m[0][0]) - 1;
            $end = self::matching_paren($sql, $parenpos);
            if ($end === null) {
                $out .= substr($sql, $offset);
                break;
            }
            $argstr = substr($sql, $parenpos + 1, $end - $parenpos - 1);
            $args = self::split_args($argstr);
            $replacement = $callback($args);

            $out .= substr($sql, $offset, $matchstart - $offset);
            if ($replacement === null) {
                $out .= substr($sql, $matchstart, $end + 1 - $matchstart);
            } else {
                $out .= $replacement;
            }
            $offset = $end + 1;
        }

        return $out;
    }

    /**
     * If $expr is exactly a single call to $name, return its argument list; otherwise null.
     *
     * @param string $expr
     * @param string $name
     * @return array<int,string>|null
     */
    private static function match_single_call(string $expr, string $name): ?array {
        $expr = trim($expr);
        if (!preg_match('/^' . preg_quote($name, '/') . '\s*\(/i', $expr, $m)) {
            return null;
        }
        $parenpos = strlen($m[0]) - 1;
        $end = self::matching_paren($expr, $parenpos);
        if ($end === null || $end !== strlen($expr) - 1) {
            return null;
        }
        return self::split_args(substr($expr, $parenpos + 1, $end - $parenpos - 1));
    }

    /**
     * Index of the parenthesis matching the open paren at $open, respecting string literals.
     *
     * @param string $sql
     * @param int $open Index of the opening parenthesis.
     * @return int|null Index of the matching close paren, or null if unbalanced.
     */
    private static function matching_paren(string $sql, int $open): ?int {
        $depth = 0;
        $len = strlen($sql);
        $instr = false;
        $quote = '';
        for ($i = $open; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($instr) {
                if ($ch === $quote) {
                    // Doubled quote is an escaped quote, not a terminator.
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $i++;
                        continue;
                    }
                    $instr = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $instr = true;
                $quote = $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } else if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Split a function argument string on top-level commas, respecting parens and strings.
     *
     * @param string $argstr
     * @return array<int,string>
     */
    private static function split_args(string $argstr): array {
        if (trim($argstr) === '') {
            return [];
        }
        $args = [];
        $depth = 0;
        $instr = false;
        $quote = '';
        $current = '';
        $len = strlen($argstr);
        for ($i = 0; $i < $len; $i++) {
            $ch = $argstr[$i];
            if ($instr) {
                $current .= $ch;
                if ($ch === $quote) {
                    if ($i + 1 < $len && $argstr[$i + 1] === $quote) {
                        $current .= $argstr[++$i];
                        continue;
                    }
                    $instr = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $instr = true;
                $quote = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } else if ($ch === ')') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $args[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $args[] = $current;
        return $args;
    }

    /**
     * Whether the character at $pos lies inside a string literal.
     *
     * @param string $sql
     * @param int $pos
     * @return bool
     */
    private static function in_string(string $sql, int $pos): bool {
        $instr = false;
        $quote = '';
        for ($i = 0; $i < $pos; $i++) {
            $ch = $sql[$i];
            if ($instr) {
                if ($ch === $quote) {
                    if ($i + 1 < strlen($sql) && $sql[$i + 1] === $quote) {
                        $i++;
                        continue;
                    }
                    $instr = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $instr = true;
                $quote = $ch;
            }
        }
        return $instr;
    }

    /**
     * Blank the contents of string literals (keeping the quotes) so a bare regex can scan only code.
     *
     * @param string $sql
     * @return string
     */
    private static function mask_strings(string $sql): string {
        $sql = preg_replace("/'(?:[^']|'')*'/", "''", $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"]|"")*"/', '""', $sql) ?? $sql;
        return $sql;
    }

    /**
     * Convert MySQL double-quoted string literals to single-quoted ones.
     *
     * Single-quoted literals and the rest of the SQL are passed through untouched. Any single quote
     * inside a converted literal is doubled so it stays escaped.
     *
     * @param string $sql
     * @return string
     */
    private static function rewrite_double_quotes(string $sql): string {
        $pattern = "/(?P<sq>'(?:[^']|'')*')|(?P<dq>\"(?:[^\"]|\"\")*\")/";
        return preg_replace_callback($pattern, static function (array $m): string {
            if (($m['sq'] ?? '') !== '') {
                return $m['sq'];
            }
            $inner = substr($m['dq'], 1, -1);
            $inner = str_replace('""', '"', $inner);      // Un-escape doubled double-quotes.
            $inner = str_replace("'", "''", $inner);       // Escape single quotes for the new literal.
            return "'" . $inner . "'";
        }, $sql) ?? $sql;
    }

    /**
     * Rewrite any single-quoted string literal containing `?` into a CONCAT(... chr(63) ...) chain.
     *
     * @param string $sql
     * @return string
     */
    private static function rewrite_questionmarks(string $sql): string {
        $pattern = "/'(?:[^']|'')*'/";
        return preg_replace_callback($pattern, static function (array $m): string {
            $literal = $m[0];
            if (strpos($literal, '?') === false) {
                return $literal;
            }
            $inner = substr($literal, 1, -1);
            $parts = explode('?', $inner);
            $pieces = [];
            foreach ($parts as $part) {
                $pieces[] = "'" . $part . "'";
            }
            return 'CONCAT(' . implode(', chr(63), ', $pieces) . ')';
        }, $sql) ?? $sql;
    }

    /**
     * Map a Configurable Reports courseid to an RS course scope.
     *
     * CR uses courseid 1 (the site course) for site-wide reports; RS uses 0. A real course id is
     * kept only if that course still exists, else demoted to site-wide.
     *
     * @param int $crcourseid
     * @return int
     */
    private static function map_courseid(int $crcourseid): int {
        global $DB;
        if ($crcourseid <= 1) {
            return 0;
        }
        return $DB->record_exists('course', ['id' => $crcourseid]) ? $crcourseid : 0;
    }

    /**
     * Reduce a CR HTML summary to a plain-text description.
     *
     * @param string $summary
     * @return string
     */
    private static function clean_summary(string $summary): string {
        return trim(html_to_text($summary, 0, false));
    }

    /**
     * Run the live dry-run checks against already-validated SQL.
     *
     * Mirrors {@see \local_reportsources\external\validate_sql::execute()}: a single-row fetch to
     * exercise tables/columns and select-list expressions, then a CREATE/DROP VIEW to catch
     * duplicate output column names. Returns a cleaned error string, or null when the SQL runs.
     *
     * @param string $validated SQL already passed through {@see validator::validate()}.
     * @return string|null
     */
    private static function dry_run(string $validated): ?string {
        global $DB, $CFG;

        $resolved = view::resolve_placeholders($validated);

        try {
            $DB->get_records_sql("SELECT * FROM ({$resolved}) rs_dryrun LIMIT 1", []);
        } catch (\dml_exception $e) {
            $detail = $e->error ?: ($e->debuginfo ?: $e->getMessage());
            return validator::clean_error($detail);
        }

        $testview = $CFG->prefix . \local_reportsources\local\sql\privilege_check::PROBE_NAME . '_col';
        try {
            $DB->change_database_structure("CREATE OR REPLACE VIEW {$testview} AS {$resolved}");
            $DB->change_database_structure("DROP VIEW IF EXISTS {$testview}");
        } catch (\moodle_exception $e) {
            $detail = $e->debuginfo ?: $e->getMessage();
            if (stripos($detail, 'Duplicate column name') !== false) {
                return get_string('errduplicatecolumn', 'local_reportsources');
            }
            return validator::clean_error($detail);
        }

        return null;
    }
}
