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
     * Validate user-supplied SQL.
     *
     * @param string $sql Raw SQL.
     * @return string Stripped, normalised SQL ready for further use.
     * @throws \moodle_exception when the SQL is rejected.
     */
    public static function validate(string $sql): string {
        $sql = trim($sql);
        if ($sql === '') {
            throw new \moodle_exception('errnotselect', 'local_reportsources');
        }

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
