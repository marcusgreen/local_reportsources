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

namespace local_reportsources\reportbuilder\datasource;

use core_reportbuilder\datasource;
use local_reportsources\local\query;
use local_reportsources\reportbuilder\local\entities\adhoc_view;

/**
 * Datasource that exposes an ad-hoc SQL query (backed by a database VIEW) to Reportbuilder.
 *
 * One Reportbuilder report is created per saved query at publish time. The report's id is mapped
 * back to the query id via plugin config (`queryid_for_report_<reportid>`).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adhoc_query extends datasource {

    public static function get_name(): string {
        return get_string('reportsource', 'local_reportsources');
    }

    protected function initialise(): void {
        $reportid = (int) $this->get_report_persistent()->get('id');
        $queryid  = (int) get_config('local_reportsources', 'queryid_for_report_' . $reportid);
        if ($queryid <= 0) {
            // Report exists without a backing query (e.g. listing in admin UI before publish).
            // Use a no-op single-column placeholder so RB validation passes.
            $this->initialise_placeholder();
            return;
        }

        try {
            $query = query::get($queryid);
        } catch (\dml_missing_record_exception $e) {
            $this->initialise_placeholder();
            return;
        }

        $viewname = $query->viewname();
        $meta     = $query->columns_meta();
        if (!$viewname || !$meta) {
            $this->initialise_placeholder();
            return;
        }

        $entity = new adhoc_view($viewname, $meta);
        $alias  = $entity->get_table_alias($viewname);
        $this->set_main_table($viewname, $alias);
        $this->add_entity($entity);
        $this->add_all_from_entity($entity->get_entity_name());
    }

    /**
     * Fall-back initialisation when no backing query is resolvable.
     */
    private function initialise_placeholder(): void {
        $this->set_main_table('user', 'u');
        $this->annotate_entity('placeholder', new \lang_string('reportsource', 'local_reportsources'));
        $this->add_column((new \core_reportbuilder\local\report\column(
            'placeholder',
            new \lang_string('reportsource', 'local_reportsources'),
            'placeholder'
        ))->add_field('u.id')->set_type(\core_reportbuilder\local\report\column::TYPE_INTEGER));
    }

    public function get_default_columns(): array {
        return array_slice(array_map(
            static fn(string $name): string => adhoc_view::ENTITY . ':' . $name,
            $this->known_column_names()
        ), 0, 6);
    }

    public function get_default_filters(): array {
        return array_slice(array_map(
            static fn(string $name): string => adhoc_view::ENTITY . ':' . $name,
            $this->known_column_names()
        ), 0, 4);
    }

    public function get_default_conditions(): array {
        return [];
    }

    /**
     * @return string[] Column names from the bound query, or empty array on placeholder mode.
     */
    private function known_column_names(): array {
        $reportid = (int) $this->get_report_persistent()->get('id');
        $queryid  = (int) get_config('local_reportsources', 'queryid_for_report_' . $reportid);
        if ($queryid <= 0) {
            return [];
        }
        try {
            $query = query::get($queryid);
        } catch (\dml_missing_record_exception $e) {
            return [];
        }
        return array_keys($query->columns_meta());
    }
}
