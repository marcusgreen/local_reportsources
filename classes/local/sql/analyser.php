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

namespace local_reportsources\local\sql;

/**
 * Gives an author feedback on a report source: date-like columns that should use
 * the %%TIMESTAMP()%% token, the result row count, and per-table index / full-scan
 * information so an excessively slow report can be spotted before publishing.
 *
 * Everything here goes through the Moodle DB API ($DB): get_columns()/view::columns(),
 * count_records_sql(), get_indexes() and get_records_sql("EXPLAIN ...").
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyser {
    /** @var int Result size above which the report is warned as potentially slow. */
    private const LARGE_RESULT = 50000;

    /** @var int Estimated per-table scan rows above which a full scan is worth flagging. */
    private const SCAN_ROWS = 1000;

    /**
     * @var string Column-name fragments that hint at a stored Unix timestamp. Kept deliberately
     * broad — the sampled-value epoch check ({@see self::EPOCH_FLOOR}) rebuts non-date ints such as
     * counts, ids and durations (e.g. enrolperiod = 2592000 falls below the epoch floor), so a loose
     * name match rarely yields a false suggestion when the query returns rows.
     */
    private const DATE_NAME_PATTERN = '/time|date|created|modified|start|end|expir|due|login|logout'
        . '|access|seen|stamp|cron|sync|sent|finish|run/i';

    /** @var int Earliest epoch a sampled integer must reach to look like a real date (2000-01-01). */
    private const EPOCH_FLOOR = 946684800;

    /**
     * Analyse a report source and return structured feedback.
     *
     * @param string $sql Raw author SQL.
     * @param int $courseid Bound course id (0 = site-wide) for placeholder resolution.
     * @return array{ok: bool, error: string, rowcount: int, suggestions: string[], warnings: string[], indexinfo: string[]}
     */
    public static function analyse(string $sql, int $courseid = 0): array {
        $result = [
            'ok'          => true,
            'error'       => '',
            'rowcount'    => 0,
            'suggestions' => [],
            'warnings'    => [],
            'indexinfo'   => [],
        ];

        // Reuse the static validator so we analyse the same auto-braced SQL that publish would.
        try {
            $validated = validator::validate($sql);
        } catch (\moodle_exception $e) {
            $result['ok'] = false;
            $result['error'] = $e->getMessage();
            return $result;
        }

        $resolved = view::resolve_placeholders($validated, $courseid);

        $result['rowcount'] = self::row_count($resolved, $result['warnings']);
        $result['suggestions'] = self::date_suggestions($validated, $resolved);
        $result['indexinfo'] = self::index_report($validated, $resolved, $result['warnings']);
        self::performance_hints($validated, $result['rowcount'], $result['warnings']);

        return $result;
    }

    /**
     * Count the rows the report would return. Wrapping as a subquery keeps a trailing
     * line comment from swallowing the COUNT (mirrors validate_sql's dry-run guard).
     *
     * @param string $resolved Placeholder-resolved SQL.
     * @param string[] $warnings Collected warnings (appended in place).
     * @return int Row count, or -1 if the count could not be run.
     */
    private static function row_count(string $resolved, array &$warnings): int {
        global $DB;
        try {
            $count = $DB->count_records_sql("SELECT COUNT(*) FROM ({$resolved}) rs_count");
        } catch (\dml_exception $e) {
            return -1;
        }
        if ($count >= self::LARGE_RESULT) {
            $warnings[] = get_string('checklargeresult', 'local_reportsources', $count);
        }
        return $count;
    }

    /**
     * Find integer columns that look like stored Unix timestamps and suggest wrapping
     * their source expression in %%TIMESTAMP()%% (which types + formats + keeps them sortable).
     *
     * @param string $validated Auto-braced validated SQL.
     * @param string $resolved Placeholder-resolved SQL.
     * @return string[] Human-readable suggestions.
     */
    private static function date_suggestions(string $validated, string $resolved): array {
        global $DB, $CFG;

        $suggestions = [];
        $already = view::timestamp_columns($validated); // Keyed by lowercased column name.

        $probe = privilege_check::PROBE_NAME . '_chk';
        $fullprobe = $CFG->prefix . $probe;
        $columns = [];
        try {
            $DB->change_database_structure("CREATE OR REPLACE VIEW {$fullprobe} AS {$resolved}");
            $columns = view::columns($probe);
            $DB->change_database_structure("DROP VIEW IF EXISTS {$fullprobe}");
        } catch (\moodle_exception $e) {
            // If the probe view cannot be built, skip date suggestions silently — validate_sql
            // is the endpoint that reports SQL faults; analyse() is advisory only.
            $DB->change_database_structure("DROP VIEW IF EXISTS {$fullprobe}");
            return $suggestions;
        }

        // Sample one row so a name match can be corroborated by a plausible-epoch value.
        $sample = null;
        try {
            $rows = $DB->get_records_sql("SELECT * FROM ({$resolved}) rs_sample LIMIT 1", []);
            $sample = $rows ? (array) reset($rows) : [];
        } catch (\dml_exception $e) {
            $sample = [];
        }

        $ceiling = time() + (10 * YEARSECS); // Allow dates up to ~10 years out (e.g. course end).
        foreach ($columns as $name => $info) {
            $lname = strtolower((string) $name);
            if (isset($already[$lname])) {
                continue; // Already a %%TIMESTAMP()%% column.
            }
            if (!in_array((string) ($info->meta_type ?? ''), ['I', 'R'], true)) {
                continue; // Only integer columns can hold an epoch.
            }
            if (!preg_match(self::DATE_NAME_PATTERN, $lname)) {
                continue;
            }
            // Corroborate with the sampled value when we have one for this column. 0/null is a
            // common "never" sentinel (e.g. lastlogin on a user who never logged in), so treat it
            // as neutral — only a non-zero value clearly outside the epoch window rebuts the name.
            $plausible = true;
            if (is_array($sample) && array_key_exists($lname, array_change_key_case($sample, CASE_LOWER))) {
                $val = array_change_key_case($sample, CASE_LOWER)[$lname];
                if ($val !== null && is_numeric($val) && (int) $val !== 0) {
                    $plausible = ($val >= self::EPOCH_FLOOR && $val <= $ceiling);
                }
            }
            if ($plausible) {
                $suggestions[] = get_string('checkdatecolumn', 'local_reportsources', $name);
            }
        }
        return $suggestions;
    }

    /**
     * Report indexes and row counts for each referenced base table, and flag full
     * table scans via EXPLAIN (dialect-branched).
     *
     * @param string $validated Auto-braced validated SQL (table names are {braced}).
     * @param string $resolved Placeholder-resolved SQL.
     * @param string[] $warnings Collected warnings (appended in place).
     * @return string[] Per-table index / scan lines.
     */
    private static function index_report(string $validated, string $resolved, array &$warnings): array {
        global $DB;

        $lines = [];
        // {tablename} placeholders survive validation, so referenced tables are easy to recover.
        preg_match_all('/\{(\w+)\}/', $validated, $m);
        $tables = array_unique($m[1]);

        foreach ($tables as $table) {
            try {
                $indexes = $DB->get_indexes($table);
                $rows = $DB->count_records($table);
            } catch (\dml_exception $e) {
                continue; // Not a real table (e.g. a CTE name caught by the regex) — skip.
            }
            $names = $indexes
                ? implode(', ', array_map([self::class, 'unprefix'], array_keys($indexes)))
                : '-';
            $lines[] = get_string('checktableindexes', 'local_reportsources',
                (object) ['table' => $table, 'rows' => $rows, 'indexes' => $names]);
        }

        self::explain_scans($resolved, $warnings);
        return $lines;
    }

    /**
     * Run EXPLAIN and warn about full table scans. MySQL/MariaDB read the structured
     * plan rows; Postgres greps the textual plan for "Seq Scan"; other families skip.
     *
     * @param string $resolved Placeholder-resolved SQL.
     * @param string[] $warnings Collected warnings (appended in place).
     */
    private static function explain_scans(string $resolved, array &$warnings): void {
        global $DB;

        $family = $DB->get_dbfamily();
        try {
            if ($family === 'mysql') {
                $plan = $DB->get_records_sql("EXPLAIN {$resolved}", []);
                foreach ($plan as $row) {
                    $row = (array) $row;
                    $type = strtoupper((string) ($row['type'] ?? ''));
                    $key = $row['key'] ?? null;
                    $estrows = (int) ($row['rows'] ?? 0);
                    if ($type === 'ALL' && ($key === null || $key === '') && $estrows >= self::SCAN_ROWS) {
                        $warnings[] = get_string('checkfullscan', 'local_reportsources',
                            (object) ['table' => self::unprefix((string) ($row['table'] ?? '?')), 'rows' => $estrows]);
                    }
                }
            } else if ($family === 'postgres') {
                $plan = $DB->get_records_sql("EXPLAIN {$resolved}", []);
                foreach ($plan as $row) {
                    $line = (string) reset($row);
                    if (preg_match('/Seq Scan on (\w+)/i', $line, $sm)) {
                        $warnings[] = get_string('checkfullscan', 'local_reportsources',
                            (object) ['table' => self::unprefix($sm[1]), 'rows' => 0]);
                    }
                }
            }
            // Other families: no portable EXPLAIN, index listing already stands.
        } catch (\dml_exception $e) {
            // EXPLAIN is best-effort; a failure here does not invalidate the rest of the report.
            return;
        }
    }

    /**
     * Static-SQL performance heuristics that EXPLAIN does not surface directly: query shapes that
     * scale badly with the result size or that quietly defeat an index. Purely textual — advisory
     * only, so each pattern is deliberately conservative to keep false positives low.
     *
     * @param string $validated Auto-braced validated SQL (string literals still present).
     * @param int $rowcount Result row count from {@see self::row_count()}, or -1 if unknown.
     * @param string[] $warnings Collected warnings (appended in place).
     */
    private static function performance_hints(string $validated, int $rowcount, array &$warnings): void {
        // String literals blanked for the structural scans; the raw SQL is kept for the LIKE
        // check, which needs to see the pattern literal itself.
        $nostr = self::blank_literals($validated);

        // A LIKE pattern that opens with % or _ cannot use a B-tree index — full scan.
        if (preg_match("/\\bLIKE\\s+N?'\\s*[%_]/i", $validated)) {
            $warnings[] = get_string('checkleadingwildcard', 'local_reportsources');
        }

        // DISTINCT over a large result sorts + de-duplicates the whole set.
        if ($rowcount >= self::LARGE_RESULT && preg_match('/\bSELECT\s+DISTINCT\b/i', $nostr)) {
            $warnings[] = get_string('checkdistinctlarge', 'local_reportsources', $rowcount);
        }

        // A subquery in the select list runs once per output row.
        if (self::has_select_list_subquery($nostr)) {
            $warnings[] = get_string('checkselectsubquery', 'local_reportsources');
        }

        // A function wrapping a column on the filtered side of WHERE is non-sargable.
        if (self::has_nonsargable_where($nostr)) {
            $warnings[] = get_string('checknonsargable', 'local_reportsources');
        }
    }

    /**
     * Whether a subquery appears in the top-level SELECT list (between the outer SELECT and its
     * matching FROM at paren depth 0). Such a subquery is evaluated per returned row.
     *
     * WITH (CTE) queries are skipped: their leading `AS (SELECT ...)` opens a paren before the outer
     * select list, which this simple depth scan cannot disambiguate without a full parse.
     *
     * @param string $nostr SQL with string literals blanked.
     * @return bool
     */
    private static function has_select_list_subquery(string $nostr): bool {
        if (preg_match('/^\s*WITH\b/i', $nostr)) {
            return false;
        }
        $pos = stripos($nostr, 'select');
        if ($pos === false) {
            return false;
        }
        $rest = substr($nostr, $pos + 6);
        $len = strlen($rest);
        for ($i = 0; $i < $len; $i++) {
            $ch = $rest[$i];
            if ($ch === '(') {
                if (preg_match('/^\(\s*select\b/i', substr($rest, $i, 12))) {
                    return true; // (SELECT ... in the select list.
                }
            } else if ($ch === ')') {
                // Unbalanced ) here means we left the select list into an outer scope — stop.
                return false;
            } else if (
                ($i === 0 || (!ctype_alnum($rest[$i - 1]) && $rest[$i - 1] !== '_'))
                && preg_match('/^from\b/i', substr($rest, $i))
            ) {
                return false; // Reached the top-level FROM with no select-list subquery.
            }
        }
        return false;
    }

    /**
     * Whether the WHERE clause wraps a column in a function on the filtered side, which prevents
     * the database using an index on that column (non-sargable). Only the first argument being a
     * bare identifier / {table} reference (not a literal) is treated as a wrapped column.
     *
     * @param string $nostr SQL with string literals blanked.
     * @return bool
     */
    private static function has_nonsargable_where(string $nostr): bool {
        $where = self::where_region($nostr);
        if ($where === '') {
            return false;
        }
        return (bool) preg_match(
            '/\b(?:LOWER|UPPER|DATE|YEAR|MONTH|DAY|TO_CHAR|DATE_FORMAT|DATE_TRUNC|'
            . 'SUBSTR|SUBSTRING|CAST|CONVERT)\s*\(\s*[A-Za-z_{]/i',
            $where
        );
    }

    /**
     * Extract the WHERE clause text up to the next major clause keyword. Approximate (a subquery's
     * own WHERE is included) but adequate for an advisory scan.
     *
     * @param string $nostr SQL with string literals blanked.
     * @return string WHERE-clause text, or '' when there is no WHERE.
     */
    private static function where_region(string $nostr): string {
        if (!preg_match('/\bWHERE\b/i', $nostr, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $tail = substr($nostr, $m[0][1] + strlen($m[0][0]));
        return preg_split('/\b(?:GROUP|ORDER|HAVING|LIMIT|WINDOW)\b/i', $tail)[0];
    }

    /**
     * Blank single/double-quoted string literals (content removed, delimiters kept) so structural
     * scans are not confused by SQL-like text inside literals.
     *
     * @param string $sql
     * @return string
     */
    private static function blank_literals(string $sql): string {
        $sql = preg_replace("/'(?:[^']|'')*'/", "''", $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"]|"")*"/', '""', $sql) ?? $sql;
        return $sql;
    }

    /**
     * Strip the Moodle table prefix (e.g. "mdl_") from a physical table name so the feedback
     * shows the Moodle table name authors recognise ("mdl_user" → "user").
     *
     * @param string $table Physical table name as reported by EXPLAIN.
     * @return string Table name without the configured prefix.
     */
    private static function unprefix(string $table): string {
        global $CFG;
        $prefix = $CFG->prefix;
        if ($prefix !== '' && strpos($table, $prefix) === 0) {
            return substr($table, strlen($prefix));
        }
        return $table;
    }
}
