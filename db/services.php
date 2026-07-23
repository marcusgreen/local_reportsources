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
 * Web service definitions for local_reportsources.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_reportsources_validate_sql' => [
        'classname'   => 'local_reportsources\external\validate_sql',
        'methodname'  => 'execute',
        'description' => 'Validate an ad-hoc SQL query: static checks + live DB dry-run',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'local/reportsources:author',
        'loginrequired' => true,
    ],
    'local_reportsources_get_schema' => [
        'classname'   => 'local_reportsources\external\get_schema',
        'methodname'  => 'execute',
        'description' => 'Return the DB schema and foreign-key map for editor autocomplete',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'local/reportsources:author',
        'loginrequired' => true,
    ],
    'local_reportsources_test_query' => [
        'classname'   => 'local_reportsources\external\test_query',
        'methodname'  => 'execute',
        'description' => 'Analyse an ad-hoc SQL query: date columns, row count, index/full-scan feedback',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'local/reportsources:author',
        'loginrequired' => true,
    ],
];
