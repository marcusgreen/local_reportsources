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

use local_reportsources\local\sql\validator;

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
        $resolved = self::normalise_aliases(self::resolve_placeholders($validatedsql));

        $ddl = "CREATE OR REPLACE VIEW {$fullname} AS {$resolved}";

        try {
            $DB->change_database_structure($ddl);
        } catch (\dml_exception $e) {
            $detail = validator::clean_error($e->error ?: ($e->debuginfo ?: $e->getMessage()));
            if (stripos($detail, 'Duplicate column name') !== false) {
                throw new \moodle_exception(
                    'errcreateview',
                    'local_reportsources',
                    '',
                    get_string('errduplicatecolumn', 'local_reportsources')
                );
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
     * Replace spaces with underscores in quoted column aliases so the resulting VIEW has
     * identifier-safe column names. Operates on both double-quoted and backtick-quoted aliases.
     *
     * e.g.  AS "Common world format"  →  AS "Common_world_format"
     *
     * @param string $sql
     * @return string
     */
    public static function normalise_aliases(string $sql): string {
        return preg_replace_callback(
            '/\bAS\s+(["`])([^"`]+)\1/i',
            static fn(array $m): string => 'AS ' . $m[1] . str_replace(' ', '_', $m[2]) . $m[1],
            $sql
        ) ?? $sql;
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
     * Inspect the view's columns, post-filtering sensitive column names according to the admin
     * denylist.
     *
     * On the Postgres family this cannot use {@see \moodle_database::get_columns()}: Moodle core's
     * pgsql implementation is hard-filtered to `relkind = 'r'` (ordinary tables), so it returns
     * nothing for a VIEW. We introspect `information_schema.columns` instead, which lists view
     * columns on both Postgres and MySQL. Other families keep the native get_columns() path.
     *
     * The returned objects expose a `meta_type` property so callers can map types uniformly.
     *
     * @param string $viewname View name without the Moodle prefix.
     * @return array<string, object> Keyed by column name; each value has a `meta_type` property.
     */
    public static function columns(string $viewname): array {
        global $DB;
        if ($DB->get_dbfamily() === 'postgres') {
            $columns = self::pg_view_columns($viewname);
        } else {
            // MySQL/MariaDB fold result-set column aliases to lowercase, but Report Builder derives
            // each column's SQL alias from the (case-preserving) column name. A mixed-case name such
            // as `Course_Shortname` therefore yields a select alias `c1_Course_Shortname` that the
            // driver returns as `c1_course_shortname`, so RB's case-sensitive value lookup misses and
            // the column renders blank. Lowercasing the keys keeps the alias and the returned name in
            // sync; the unquoted field reference still resolves because MySQL column names are
            // case-insensitive. (Postgres identifiers are case-sensitive, so its path is left intact.)
            $columns = [];
            foreach ($DB->get_columns($viewname, false) as $name => $info) {
                $columns[strtolower($name)] = $info;
            }
        }
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
     * Introspect a VIEW's columns on Postgres via information_schema.
     *
     * @param string $viewname View name without the Moodle prefix.
     * @return array<string, object> Keyed by column name; each value has a `meta_type` property.
     */
    private static function pg_view_columns(string $viewname): array {
        global $DB, $CFG;
        $fullname = $CFG->prefix . $viewname;
        $sql = "SELECT column_name, data_type
                  FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
              ORDER BY ordinal_position";
        $rows = $DB->get_records_sql($sql, [$fullname]);
        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->column_name] = (object) ['meta_type' => self::pg_meta_type($row->data_type)];
        }
        return $columns;
    }

    /**
     * Map a Postgres information_schema `data_type` to a Moodle meta_type char, mirroring the codes
     * {@see \local_reportsources\local\query::map_db_type()} consumes.
     *
     * @param string $datatype
     * @return string One of Moodle's meta_type chars: I, N, L, X.
     */
    private static function pg_meta_type(string $datatype): string {
        return match (strtolower($datatype)) {
            'smallint', 'integer', 'bigint' => 'I',
            'numeric', 'decimal', 'real', 'double precision' => 'N',
            'boolean' => 'L',
            default => 'X',
        };
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
