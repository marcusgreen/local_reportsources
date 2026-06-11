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

namespace local_reportsources\reportbuilder\source;

use core_reportbuilder\datasource;
use local_reportsources\local\query;
use local_reportsources\reportbuilder\local\entities\adhoc_view;

/**
 * Datasource that exposes an ad-hoc SQL query (backed by a database VIEW) to Reportbuilder.
 *
 * One Reportbuilder report is created per saved query at publish time. The report's id is mapped
 * back to the query id via plugin config (`queryid_for_report_<reportid>`).
 *
 * Intentionally placed outside the `reportbuilder\datasource` namespace so Moodle's auto-discovery
 * does not surface it in the "new report" source dropdown — reports are created exclusively by the
 * plugin's own publish workflow.
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

        // Per-user filter: scope every row to the viewing user. The chosen column is a physical
        // column of the view (validated at save against columnsmeta), so referencing it here is
        // safe. The column is also withheld from the entity entirely: once filtered, its value
        // always equals the viewer's own id, so offering it as a column or filter is pure noise.
        // (Unless it is the only column — an entity must expose at least one.)
        $useridcolumn = $query->useridcolumn();
        $peruser = $useridcolumn !== '' && array_key_exists($useridcolumn, $meta);
        $visiblemeta = ($peruser && count($meta) > 1)
            ? array_diff_key($meta, [$useridcolumn => true])
            : $meta;

        $entity = new adhoc_view($viewname, $visiblemeta, $query->name());
        $alias  = $entity->get_table_alias($viewname);
        $this->set_main_table($viewname, $alias);
        $this->add_entity($entity);
        $this->add_all_from_entity($entity->get_entity_name());

        if ($peruser) {
            global $USER;
            $param = \core_reportbuilder\local\helpers\database::generate_param_name();
            $this->add_base_condition_sql("{$alias}.{$useridcolumn} = :{$param}", [$param => (int) $USER->id]);
        }
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
        // Hide the per-user filter column from defaults, mirroring initialise().
        $meta = $query->columns_meta();
        $useridcolumn = $query->useridcolumn();
        if ($useridcolumn !== '' && array_key_exists($useridcolumn, $meta) && count($meta) > 1) {
            unset($meta[$useridcolumn]);
        }
        return array_keys($meta);
    }
}
