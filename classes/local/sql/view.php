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
 * Manages database VIEWs that back published ad-hoc queries.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {

    /** @var string Prefix for generated view names (without Moodle prefix). */
    public const NAME_PREFIX = 'local_reportsources_v_';

    /**
     * Build the view name for a given query id (without Moodle prefix).
     *
     * @param int $queryid
     * @return string
     */
    public static function name_for(int $queryid): string {
        return self::NAME_PREFIX . $queryid;
    }

    /**
     * (Re)create the VIEW for a saved query.
     *
     * Replaces Moodle's `{tablename}` placeholders with prefixed table names before issuing
     * CREATE OR REPLACE VIEW. The provided SQL must already have been validated.
     *
     * @param int $queryid
     * @param string $validatedsql
     * @return string The view name (without prefix) on success.
     * @throws \moodle_exception
     */
    public static function create_or_replace(int $queryid, string $validatedsql): string {
        global $DB, $CFG;

        $viewname = self::name_for($queryid);
        $fullname = $CFG->prefix . $viewname;
        $resolved = self::resolve_placeholders($validatedsql);

        $ddl = "CREATE OR REPLACE VIEW {$fullname} AS {$resolved}";

        try {
            $DB->change_database_structure($ddl);
        } catch (\dml_exception $e) {
            $detail = validator::clean_error($e->error ?: ($e->debuginfo ?: $e->getMessage()));
            if (stripos($detail, 'Duplicate column name') !== false) {
                throw new \moodle_exception('errcreateview', 'local_reportsources', '',
                    get_string('errduplicatecolumn', 'local_reportsources'));
            }
            throw new \moodle_exception('errcreateview', 'local_reportsources', '', $detail);
        }
        return $viewname;
    }

    /**
     * Drop the VIEW for a saved query, if it exists.
     *
     * @param int $queryid
     */
    public static function drop(int $queryid): void {
        global $DB, $CFG;

        $viewname = self::name_for($queryid);
        $fullname = $CFG->prefix . $viewname;

        try {
            $DB->change_database_structure("DROP VIEW IF EXISTS {$fullname}");
        } catch (\dml_exception $e) {
            $detail = $e->error ?: ($e->debuginfo ?: $e->getMessage());
            throw new \moodle_exception('errdropview', 'local_reportsources', '', $detail);
        }
    }

    /**
     * Replace `{tablename}` with the prefixed table name. The Moodle DML layer normally does this
     * for parameterised queries but DDL statements bypass that path.
     *
     * @param string $sql
     * @return string
     */
    public static function resolve_placeholders(string $sql): string {
        global $CFG;
        return preg_replace_callback(
            '/\{([a-z0-9_]+)\}/i',
            static fn(array $m): string => $CFG->prefix . $m[1],
            $sql
        ) ?? $sql;
    }

    /**
     * Inspect the view's columns. Wraps {@see \moodle_database::get_columns()} so we can post-filter
     * sensitive column names according to the admin denylist.
     *
     * @param string $viewname
     * @return array<string, \database_column_info>
     */
    public static function columns(string $viewname): array {
        global $DB;
        $columns = $DB->get_columns($viewname, false);
        $deny = self::denylist();
        if ($deny) {
            $columns = array_filter(
                $columns,
                static fn(string $name): bool => !in_array(strtolower($name), $deny, true),
                ARRAY_FILTER_USE_KEY
            );
        }
        return $columns;
    }

    /**
     * Return lowercased denylist of sensitive column names.
     *
     * @return string[]
     */
    private static function denylist(): array {
        $raw = (string) get_config('local_reportsources', 'denycolumns');
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map('strtolower', $items);
    }
}
