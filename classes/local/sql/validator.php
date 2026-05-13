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
 * SELECT-only SQL validator for ad-hoc queries.
 *
 * Defence-in-depth gate before any user-authored SQL reaches the database.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validator {
    /** @var string[] MySQL-specific date functions that will fail on PostgreSQL. */
    private const MYSQL_DATE_FUNCTIONS = [
        'DATE_FORMAT', 'STR_TO_DATE', 'DATE_ADD', 'DATE_SUB',
        'DATEDIFF', 'UNIX_TIMESTAMP', 'FROM_UNIXTIME',
    ];

    /** @var string[] PostgreSQL-specific date/time functions that will fail on MySQL. */
    private const PGSQL_DATE_FUNCTIONS = [
        'TO_TIMESTAMP', 'TO_CHAR', 'DATE_TRUNC', 'EXTRACT', 'AGE',
        'NOW', 'CLOCK_TIMESTAMP', 'TIMEOFDAY', 'MAKE_DATE', 'MAKE_TIME',
        'MAKE_TIMESTAMP', 'MAKE_TIMESTAMPTZ',
    ];

    /** @var string[] Warnings from the most recent validate() call. */
    private static array $warnings = [];

    /** @var string[] Forbidden SQL keywords. Matched as whole tokens after comment/string stripping. */
    private const DENY_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'CREATE',
        'GRANT', 'REVOKE', 'REPLACE', 'CALL', 'LOAD', 'HANDLER', 'LOCK',
        'UNLOCK', 'RENAME', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'USE',
        'EXEC', 'EXECUTE', 'INTO', 'OUTFILE', 'COPY', 'VACUUM', 'MERGE',
        'ATTACH', 'DETACH', 'PRAGMA',
    ];

    /** @var string[] Moodle tables that must never be exposed. */
    private const DENY_TABLES = [
        'config', 'config_plugins', 'config_log',
        'user_password_history', 'user_password_resets',
        'oauth2_issuer', 'oauth2_endpoint', 'oauth2_user_field_mapping',
        'sessions', 'task_adhoc',
    ];

    /**
     * Return warnings accumulated during the most recent validate() call.
     *
     * @return string[]
     */
    public static function get_warnings(): array {
        return self::$warnings;
    }

    /**
     * Validate user-supplied SQL.
     *
     * @param string $sql Raw SQL.
     * @return string Stripped, normalised SQL ready for further use.
     * @throws \moodle_exception when the SQL is rejected.
     */
    public static function validate(string $sql): string {
        self::$warnings = [];
        $sql = trim($sql);
        if ($sql === '') {
            throw new \moodle_exception('errnotselect', 'local_reportsources');
        }

        // Wrap bare table references in {}-placeholders so users do not need to type them.
        $sql = self::auto_brace($sql);

        $stripped = self::strip_comments_and_strings($sql);

        // Single statement.
        if (str_contains(rtrim($stripped, "; \t\n\r"), ';')) {
            throw new \moodle_exception('errmultistatement', 'local_reportsources');
        }

        // Leading keyword must be SELECT or WITH.
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $stripped)) {
            throw new \moodle_exception('errnotselect', 'local_reportsources');
        }

        // Token denylist.
        foreach (self::DENY_KEYWORDS as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $stripped)) {
                throw new \moodle_exception('errdeniedkeyword', 'local_reportsources', '', $kw);
            }
        }

        // Table denylist (Moodle {tablename} syntax).
        if (preg_match_all('/\{([a-z0-9_]+)\}/i', $stripped, $matches)) {
            foreach ($matches[1] as $table) {
                if (in_array(strtolower($table), self::DENY_TABLES, true)) {
                    throw new \moodle_exception('errdeniedtable', 'local_reportsources', '', $table);
                }
            }
        }

        global $CFG;
        $dbtype = $CFG->dbtype ?? 'mysqli';

        // Warn about MySQL-specific date functions that will fail on PostgreSQL.
        if ($dbtype === 'pgsql') {
            foreach (self::MYSQL_DATE_FUNCTIONS as $fn) {
                if (preg_match('/\b' . $fn . '\s*\(/i', $stripped)) {
                    self::$warnings[] = get_string('warnmysqldatefn', 'local_reportsources', $fn);
                }
            }
        }

        // Error on PostgreSQL-specific date/time functions when running MySQL/MariaDB.
        if ($dbtype !== 'pgsql') {
            foreach (self::PGSQL_DATE_FUNCTIONS as $fn) {
                if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $stripped)) {
                    throw new \moodle_exception('errpgsqldatefn', 'local_reportsources', '', $fn);
                }
            }
        }

        return rtrim($sql, "; \t\n\r");
    }

    /**
     * Replace string literals and comments with whitespace so the keyword scan can't be evaded
     * via comments or string content.
     *
     * @param string $sql
     * @return string
     */
    private static function strip_comments_and_strings(string $sql): string {
        // Block comments.
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? '';
        // Line comments.
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? '';
        $sql = preg_replace('/#[^\n]*/', ' ', $sql) ?? '';
        // String literals (single, double, backtick).
        $sql = preg_replace("/'(?:[^']|'')*'/", "''", $sql) ?? '';
        $sql = preg_replace('/"(?:[^"]|"")*"/', '""', $sql) ?? '';
        return $sql;
    }

    /**
     * Wrap bare table references with Moodle's `{tablename}` syntax when the user omits the braces.
     *
     * Walks the SQL once with a tokenising regex that ignores strings, comments and already-braced
     * names. Tables are only braced when they appear in a position where a table is expected
     * (immediately after FROM or JOIN, or after a comma inside a FROM-list). Subqueries push and
     * pop their own table-list state on parentheses so an outer FROM does not leak into them.
     *
     * @param string $sql Raw SQL.
     * @return string SQL with bare table references braced.
     */
    public static function auto_brace(string $sql): string {
        global $DB;
        try {
            $tables = $DB->get_tables();
        } catch (\Throwable $e) {
            return $sql;
        }
        if (!$tables) {
            return $sql;
        }
        $tableset = array_flip(array_map('strtolower', $tables));

        $pattern = '/'
            . '(?P<str>\'(?:[^\']|\'\')*\')'
            . '|(?P<dstr>"(?:[^"]|"")*")'
            . '|(?P<lcmt>--[^\n]*)'
            . '|(?P<hcmt>\#[^\n]*)'
            . '|(?P<bcmt>\/\*[\s\S]*?\*\/)'
            . '|(?P<braced>\{[a-z0-9_]+\})'
            . '|(?P<lparen>\()'
            . '|(?P<rparen>\))'
            . '|(?P<comma>,)'
            . '|(?P<id>[A-Za-z_][A-Za-z0-9_]*)'
            . '/s';

        $clauseend = ['where', 'group', 'order', 'having', 'limit',
            'on', 'using', 'union', 'except', 'intersect'];

        $expecting = false;
        $infromlist = false;
        $stack = [];

        $result = preg_replace_callback(
            $pattern,
            static function (array $m) use ($tableset, $clauseend, &$expecting, &$infromlist, &$stack): string {
                if (isset($m['str']) && $m['str'] !== '') {
                    return $m['str'];
                }
                if (isset($m['dstr']) && $m['dstr'] !== '') {
                    return $m['dstr'];
                }
                if (isset($m['lcmt']) && $m['lcmt'] !== '') {
                    return $m['lcmt'];
                }
                if (isset($m['hcmt']) && $m['hcmt'] !== '') {
                    return $m['hcmt'];
                }
                if (isset($m['bcmt']) && $m['bcmt'] !== '') {
                    return $m['bcmt'];
                }
                if (isset($m['braced']) && $m['braced'] !== '') {
                    if ($expecting) {
                        $expecting = false;
                        $infromlist = true;
                    }
                    return $m['braced'];
                }
                if (isset($m['lparen']) && $m['lparen'] !== '') {
                    $stack[] = [$expecting, $infromlist];
                    $expecting = false;
                    $infromlist = false;
                    return '(';
                }
                if (isset($m['rparen']) && $m['rparen'] !== '') {
                    if ($stack) {
                        [$expecting, $infromlist] = array_pop($stack);
                    }
                    return ')';
                }
                if (isset($m['comma']) && $m['comma'] !== '') {
                    if ($infromlist) {
                        $expecting = true;
                    }
                    return ',';
                }
                $id = $m['id'];
                $idl = strtolower($id);

                if ($expecting && isset($tableset[$idl])) {
                    $expecting = false;
                    $infromlist = true;
                    return '{' . $id . '}';
                }

                if ($idl === 'from' || $idl === 'join') {
                    $expecting = true;
                    $infromlist = true;
                } else if (in_array($idl, $clauseend, true)) {
                    $expecting = false;
                    $infromlist = false;
                } else {
                    $expecting = false;
                }
                return $id;
            },
            $sql
        );
        return $result ?? $sql;
    }

    /**
     * Strip Moodle `{tablename}` braces for display, leaving string/comment contents untouched.
     *
     * @param string $sql Stored SQL.
     * @return string SQL with braces removed around table references.
     */
    public static function strip_braces(string $sql): string {
        $pattern = '/'
            . '(?P<str>\'(?:[^\']|\'\')*\')'
            . '|(?P<dstr>"(?:[^"]|"")*")'
            . '|(?P<lcmt>--[^\n]*)'
            . '|(?P<hcmt>\#[^\n]*)'
            . '|(?P<bcmt>\/\*[\s\S]*?\*\/)'
            . '|\{(?P<tbl>[a-z0-9_]+)\}'
            . '/is';
        $result = preg_replace_callback($pattern, static function (array $m): string {
            if (isset($m['str']) && $m['str'] !== '') {
                return $m['str'];
            }
            if (isset($m['dstr']) && $m['dstr'] !== '') {
                return $m['dstr'];
            }
            if (isset($m['lcmt']) && $m['lcmt'] !== '') {
                return $m['lcmt'];
            }
            if (isset($m['hcmt']) && $m['hcmt'] !== '') {
                return $m['hcmt'];
            }
            if (isset($m['bcmt']) && $m['bcmt'] !== '') {
                return $m['bcmt'];
            }
            return $m['tbl'];
        }, $sql);
        return $result ?? $sql;
    }

    /**
     * Strip DB-server internals from an error message before showing it to a user.
     *
     * MySQL embeds the schema name and Moodle table prefix in error strings, e.g.
     * "Table 'mdl52.mdl_xuser' doesn't exist". Strip both so the user sees
     * "Table 'xuser' doesn't exist".
     *
     * @param string $msg Raw error from dml_exception.
     * @return string Cleaned message.
     */
    public static function clean_error(string $msg): string {
        global $CFG;
        if (!empty($CFG->dbname)) {
            $msg = str_replace($CFG->dbname . '.', '', $msg);
        }
        if (!empty($CFG->prefix)) {
            $msg = preg_replace('/\b' . preg_quote($CFG->prefix, '/') . '/', '', $msg);
        }
        return $msg;
    }

    /**
     * Extract the names of named placeholders (:name) appearing in the SQL.
     *
     * @param string $sql
     * @return string[] Sorted unique placeholder names.
     */
    public static function placeholders(string $sql): array {
        $stripped = self::strip_comments_and_strings($sql);
        preg_match_all('/(?<!:):([a-z_][a-z0-9_]*)/i', $stripped, $m);
        $names = array_values(array_unique($m[1] ?? []));
        sort($names);
        return $names;
    }
}
