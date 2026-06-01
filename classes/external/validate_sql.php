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

namespace local_reportsources\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_reportsources\local\sql\validator;
use local_reportsources\local\sql\view;

/**
 * Validates user-supplied SQL: static checks then a live DB dry-run.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validate_sql extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sql' => new external_value(PARAM_RAW, 'SQL to validate'),
        ]);
    }

    /**
     * Run static validation then a zero-row dry-run to catch bad table/column names.
     *
     * @param string $sql
     * @return array{ok: bool, error: string}
     */
    public static function execute(string $sql): array {
        global $DB, $CFG;

        ['sql' => $sql] = self::validate_parameters(self::execute_parameters(), ['sql' => $sql]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/reportsources:author', $context);

        // Static denylist + SELECT-only check.
        try {
            $validated = validator::validate($sql);
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Live dry-run: LIMIT 0 validates tables/columns without returning data or wrapping
        // (wrapping causes false "Duplicate column name" errors when SELECT * joins two tables).
        $resolved = view::resolve_placeholders($validated);

        // Syntax/table/column check — LIMIT 0 returns no rows and avoids the
        // "Duplicate column name" false-positive that the VIEW wrapper triggers.
        // Strip any existing LIMIT (and optional OFFSET) so we don't produce "LIMIT n LIMIT 0".
        $dryrunsql = preg_replace('/\bLIMIT\s+\d+(\s+OFFSET\s+\d+)?\s*$/i', '', trim($resolved));
        try {
            $DB->get_records_sql("{$dryrunsql} LIMIT 0", []);
        } catch (\dml_exception $e) {
            $detail = $e->error ?: ($e->debuginfo ?: $e->getMessage());
            return ['ok' => false, 'error' => validator::clean_error($detail)];
        }

        // View-compatibility check — creating a VIEW enforces unique column names,
        // so test that now before the user hits it at publish time.
        // change_database_structure() throws ddl_change_structure_exception (a moodle_exception
        // subclass, not a dml_exception), so we catch the broader moodle_exception here.
        $testview = $CFG->prefix . \local_reportsources\local\sql\privilege_check::PROBE_NAME . '_col';
        try {
            $DB->change_database_structure("CREATE OR REPLACE VIEW {$testview} AS {$resolved}");
            $DB->change_database_structure("DROP VIEW IF EXISTS {$testview}");
        } catch (\moodle_exception $e) {
            $detail = $e->debuginfo ?: $e->getMessage();
            if (stripos($detail, 'Duplicate column name') !== false) {
                return ['ok' => false, 'error' =>
                    get_string('errduplicatecolumn', 'local_reportsources')];
            }
            // Any other DDL error (syntax error, multiple statements, etc.) is also fatal.
            return ['ok' => false, 'error' => validator::clean_error($detail)];
        }

        return ['ok' => true, 'error' => '', 'warnings' => validator::get_warnings()];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'       => new external_value(PARAM_BOOL, 'True if SQL is valid'),
            'error'    => new external_value(PARAM_TEXT, 'Error message, empty on success'),
            'warnings' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Warning message'),
                'Non-fatal warnings (e.g. portability issues)',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
