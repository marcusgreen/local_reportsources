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

namespace local_reportsources\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{boolean_select, date, number, text};
use core_reportbuilder\local\report\{column, filter};
use lang_string;

/**
 * Reportbuilder entity that wraps a database VIEW representing a saved ad-hoc query.
 *
 * Columns and filters are constructed at runtime from the cached column metadata stored on the
 * query record (which itself was introspected from the live VIEW at publish time).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adhoc_view extends base {
    /** @var string Internal entity name. */
    public const ENTITY = 'adhoc';

    /** @var array<string, array{type:string,label:string}> */
    private array $columnsmeta;

    /** @var string VIEW name (without Moodle prefix). */
    private string $viewname;

    /** @var string Display title for the entity (defaults to the query name). */
    private string $title;

    /**
     * @param string $viewname
     * @param array  $columnsmeta
     * @param string $title Display title shown as the column-picker group heading.
     */
    public function __construct(string $viewname, array $columnsmeta, string $title = '') {
        $this->viewname = $viewname;
        $this->columnsmeta = $columnsmeta;
        $this->title = $title;
        $this->set_entity_name(self::ENTITY);
    }

    protected function get_default_tables(): array {
        return [$this->viewname];
    }

    protected function get_default_entity_title(): lang_string {
        if ($this->title !== '') {
            return new lang_string('reportsourceheader', 'local_reportsources', $this->title);
        }
        return new lang_string('reportsource', 'local_reportsources');
    }

    public function initialise(): base {
        foreach ($this->build_columns() as $col) {
            $this->add_column($col);
        }
        foreach ($this->build_filters() as $f) {
            $this->add_filter($f);
            $this->add_condition($f);
        }
        return $this;
    }

    /**
     * @return column[]
     */
    private function build_columns(): array {
        $alias = $this->get_table_alias($this->viewname);
        $cols = [];
        foreach ($this->columnsmeta as $name => $meta) {
            $cols[] = (new column(
                $name,
                self::raw_title($name),
                $this->get_entity_name()
            ))
                ->add_field("{$alias}.{$name}")
                ->set_type(self::rb_column_type($meta['type'] ?? 'text'))
                ->set_is_sortable(true);
        }
        return $cols;
    }

    /**
     * @return filter[]
     */
    private function build_filters(): array {
        $alias = $this->get_table_alias($this->viewname);
        $filters = [];
        foreach ($this->columnsmeta as $name => $meta) {
            $filters[] = (new filter(
                self::rb_filter_class($meta['type'] ?? 'text'),
                $name,
                self::raw_title($name),
                $this->get_entity_name(),
                "{$alias}.{$name}"
            ))->add_joins($this->get_joins());
        }
        return $filters;
    }

    /**
     * Render an arbitrary column name as a {@see lang_string}. Routed through the language
     * entry `adhocheader = '{$a}'` so we don't need a language entry per column name.
     */
    private static function raw_title(string $name): lang_string {
        return new lang_string('reportsourceheader', 'local_reportsources', $name);
    }

    private static function rb_column_type(string $token): int {
        return match ($token) {
            'int'       => column::TYPE_INTEGER,
            'float'     => column::TYPE_FLOAT,
            'bool'      => column::TYPE_BOOLEAN,
            'timestamp' => column::TYPE_TIMESTAMP,
            default     => column::TYPE_TEXT,
        };
    }

    /**
     * @param string $token
     * @return class-string
     */
    private static function rb_filter_class(string $token): string {
        return match ($token) {
            'int', 'float' => number::class,
            'bool'         => boolean_select::class,
            'timestamp'    => date::class,
            default        => text::class,
        };
    }
}
