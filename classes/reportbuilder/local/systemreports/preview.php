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

namespace local_reportsources\reportbuilder\local\systemreports;

use core_reportbuilder\system_report;
use local_reportsources\reportbuilder\local\entities\adhoc_view;

/**
 * Ephemeral system report used to preview an unsaved ad-hoc query inline in the edit form.
 *
 * Unlike a published report, this is never persisted: it is built on the fly by
 * {@see \local_reportsources_output_fragment_preview()} over a throwaway per-user preview VIEW
 * (see {@see \local_reportsources\local\sql\view::create_or_replace_preview()}), reusing the same
 * {@see adhoc_view} entity as the real datasource so the columns type and format identically.
 *
 * The preview is read-only and non-interactive — first page only, no filters, no download — so no
 * paging AJAX round-trip is exercised (the first page is server-rendered in {@see output()}).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview extends system_report {
    /**
     * Initialise the report from the fragment parameters (view name + frozen column metadata).
     */
    protected function initialise(): void {
        $viewname = $this->get_parameter('viewname', '', PARAM_ALPHANUMEXT);
        $columnsmeta = json_decode($this->get_parameter('columnsmeta', '[]', PARAM_RAW), true) ?: [];
        $title = $this->get_parameter('title', '', PARAM_TEXT);

        $entity = new adhoc_view($viewname, $columnsmeta, $title);
        $entityname = $entity->get_entity_name();
        $alias = $entity->get_table_alias($viewname);

        $this->set_main_table($viewname, $alias);
        $this->add_entity($entity);

        // Show every column the query produces, in order — this mirrors add_default_columns() on the
        // real datasource, so the preview reflects the report an author would get on publish.
        $columns = array_map(
            static fn(string $name): string => "{$entityname}:{$name}",
            array_keys($columnsmeta)
        );
        $this->add_columns_from_entities($columns);

        // Preview is non-interactive: strip sorting from every column so no header sort links render.
        foreach ($this->get_columns() as $column) {
            $column->set_is_sortable(false);
        }

        // Preview only: first 5 rows, no interactivity, no export.
        $this->set_default_per_page(5);
        $this->set_downloadable(false);
    }

    /**
     * Only report-source authors may preview a query.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return has_capability('local/reportsources:author', $this->get_context());
    }
}
