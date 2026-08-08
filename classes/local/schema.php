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

namespace local_reportsources\local;

/**
 * Builds and caches the database schema and foreign-key map that drive editor autocomplete.
 *
 * Both products are expensive: the column map calls get_columns() for every table (~450+), and the
 * foreign-key map parses the install.xml of core plus every installed plugin. They are needed only
 * by the SQL editor, so they are fetched lazily over AJAX rather than dumped into every edit page,
 * and cached in MUC keyed by the Moodle version (schema only changes on upgrade).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema {
    /** @var string MUC cache key for the schema payload. */
    private const CACHE_KEY = 'data';

    /** @var string Bundled curated implied-key map (see tools/xml_to_keys.php). */
    private const IMPLIED_KEYS_FILE = __DIR__ . '/../../db/implied_keys.php';

    /**
     * Return the cached schema payload, rebuilding it if missing or stale.
     *
     * @return array{tables: array<string, string[]>, fkmap: array<string, array>}
     */
    public static function get(): array {
        global $CFG;

        $cache = \cache::make('local_reportsources', 'schema');
        $stamp = self::cache_stamp();
        $cached = $cache->get(self::CACHE_KEY);
        if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp) {
            return ['tables' => $cached['tables'], 'fkmap' => $cached['fkmap']];
        }

        $data = [
            'tables' => self::build_tables(),
            'fkmap'  => self::build_fk_map(),
        ];
        $cache->set(self::CACHE_KEY, ['stamp' => $stamp] + $data);

        return $data;
    }

    /**
     * Cache validity stamp: Moodle version plus the curated implied-key file mtime, so refreshing the
     * bundled implied keys busts the cache without needing a core upgrade.
     *
     * @return string
     */
    private static function cache_stamp(): string {
        global $CFG;
        $mtime = file_exists(self::IMPLIED_KEYS_FILE) ? (int) filemtime(self::IMPLIED_KEYS_FILE) : 0;
        return $CFG->version . ':' . $mtime;
    }

    /**
     * Build a map of table name => list of column names from the live database.
     *
     * @return array<string, string[]>
     */
    private static function build_tables(): array {
        global $DB;
        $tables = [];
        foreach ($DB->get_tables() as $table) {
            $tables[$table] = array_keys($DB->get_columns($table));
        }
        return $tables;
    }

    /**
     * Build a foreign-key map from all installed plugins' install.xml files.
     *
     * Returns ['tablename' => ['colname' => ['reftable' => '...', 'refcol' => '...'], ...], ...].
     *
     * @return array<string, array>
     */
    private static function build_fk_map(): array {
        global $CFG;

        $map = [];
        $xmlfiles = [];

        $corefile = $CFG->libdir . '/db/install.xml';
        if (file_exists($corefile)) {
            $xmlfiles[] = $corefile;
        }

        foreach (\core_component::get_plugin_types() as $type => $unused) {
            foreach (\core_component::get_plugin_list($type) as $plugin => $plugindir) {
                $xmlfile = $plugindir . '/db/install.xml';
                if (file_exists($xmlfile)) {
                    $xmlfiles[] = $xmlfile;
                }
            }
        }

        foreach ($xmlfiles as $file) {
            $xml = @simplexml_load_file($file);
            if (!$xml || !isset($xml->TABLES)) {
                continue;
            }
            foreach ($xml->TABLES->TABLE as $table) {
                $tablename = strtolower((string) $table['NAME']);
                if (!isset($table->KEYS)) {
                    continue;
                }
                foreach ($table->KEYS->KEY as $key) {
                    if (strtolower((string) $key['TYPE']) !== 'foreign') {
                        continue;
                    }
                    $fields = array_map('trim', explode(',', strtolower((string) $key['FIELDS'])));
                    $reftable = strtolower((string) $key['REFTABLE']);
                    $reffields = array_map('trim', explode(',', strtolower((string) $key['REFFIELDS'])));
                    foreach ($fields as $i => $field) {
                        $map[$tablename][$field] = [
                            'reftable' => $reftable,
                            'refcol'   => $reffields[$i] ?? $reffields[0],
                        ];
                    }
                }
            }
        }

        // Fill columns that install.xml leaves undeclared with the curated implied keys. Declared FKs
        // are authoritative, so they win: only add an implied entry where nothing was declared.
        foreach (self::load_implied_keys() as $table => $cols) {
            foreach ($cols as $col => $ref) {
                if (!isset($map[$table][$col])) {
                    $map[$table][$col] = $ref;
                }
            }
        }

        return $map;
    }

    /**
     * Load the bundled curated implied foreign-key map (Moodle relationships not declared in any
     * install.xml). Generated from upstream morekeys.xml by tools/xml_to_keys.php; the shape already
     * matches {@see build_fk_map()} ([table => [col => [reftable, refcol]]]), so no parsing is needed.
     *
     * @return array<string, array<string, array{reftable: string, refcol: string}>>
     */
    private static function load_implied_keys(): array {
        if (!is_readable(self::IMPLIED_KEYS_FILE)) {
            return [];
        }
        $data = require self::IMPLIED_KEYS_FILE;
        return is_array($data) ? $data : [];
    }
}
