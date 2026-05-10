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

use core_reportbuilder\local\models\report as report_model;
use core_reportbuilder\local\helpers\report as reporthelper;
use local_reportsources\local\sql\validator;
use local_reportsources\local\sql\view;

/**
 * Saved ad-hoc query CRUD + lifecycle.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query {

    public const TABLE = 'local_reportsources_query';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';

    /** @var \stdClass */
    private \stdClass $record;

    private function __construct(\stdClass $record) {
        $this->record = $record;
    }

    public static function get(int $id): self {
        global $DB;
        $rec = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        return new self($rec);
    }

    public static function get_record(int $id): \stdClass {
        return self::get($id)->record;
    }

    public function id(): int {
        return (int) $this->record->id;
    }

    public function record(): \stdClass {
        return $this->record;
    }

    public function name(): string {
        return (string) $this->record->name;
    }

    public function sql(): string {
        return (string) $this->record->querysql;
    }

    public function status(): string {
        return (string) $this->record->status;
    }

    public function viewname(): ?string {
        return $this->record->viewname ?: null;
    }

    public function reportid(): ?int {
        return $this->record->reportid ? (int) $this->record->reportid : null;
    }

    public function courseid(): int {
        return (int) ($this->record->courseid ?? 0);
    }

    public function visible(): bool {
        return (int) ($this->record->visible ?? 1) === 1;
    }

    /**
     * Decoded column metadata cached on save (introspected from the live VIEW).
     *
     * @return array<string, array{type:string,label:string}>
     */
    public function columns_meta(): array {
        $raw = $this->record->columnsmeta ?: '[]';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Save (create or update) a query record. Validates the SQL before storing.
     *
     * @param \stdClass $data Form data: id?, name, description, querysql, rowcap.
     * @return int Query id.
     */
    public static function save(\stdClass $data): int {
        global $DB, $USER;

        $sql = validator::validate((string) $data->querysql);
        $now = time();

        $record = (object) [
            'name'         => (string) $data->name,
            'description'  => (string) ($data->description ?? ''),
            'querysql'     => $sql,
            'rowcap'       => (int) ($data->rowcap ?? get_config('local_reportsources', 'rowcapdefault') ?: 5000),
            'courseid'     => (int) ($data->courseid ?? 0),
            'visible'      => isset($data->visible) ? (int) (bool) $data->visible : 1,
            'timemodified' => $now,
        ];

        if (!empty($data->id)) {
            $record->id = (int) $data->id;
            $existing = $DB->get_record(self::TABLE, ['id' => $record->id], '*', MUST_EXIST);
            // SQL change while published: drop view + report so they get rebuilt on next publish.
            if ($existing->status === self::STATUS_PUBLISHED && $existing->querysql !== $sql) {
                self::tear_down((int) $existing->id, $existing);
                $record->status   = self::STATUS_DRAFT;
                $record->viewname = null;
                $record->reportid = null;
                $record->columnsmeta = null;
            }
            $DB->update_record(self::TABLE, $record);
            return $record->id;
        }

        $record->ownerid     = (int) $USER->id;
        $record->status      = self::STATUS_DRAFT;
        $record->timecreated = $now;
        return $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Move a draft to published: create the VIEW, register a Reportbuilder report, cache column meta.
     */
    public function publish(): void {
        global $DB;

        $viewname = view::create_or_replace($this->id(), $this->sql());
        $columns  = view::columns($viewname);

        $meta = [];
        foreach ($columns as $name => $info) {
            $meta[$name] = [
                'type'  => self::map_db_type((string) $info->meta_type),
                'label' => $name,
            ];
        }

        // Register the Reportbuilder report (idempotent). create_report() will set ->type for us.
        // We create with defaults disabled because the datasource cannot resolve its columns until
        // the queryid_for_report_<id> mapping is in place.
        $reportid = $this->record->reportid ? (int) $this->record->reportid : null;
        if (!$reportid) {
            $reportmodel = reporthelper::create_report((object) [
                'name'   => $this->name(),
                'source' => \local_reportsources\reportbuilder\source\adhoc_query::class,
            ], false);
            $reportid = (int) $reportmodel->get('id');
        }

        // Persist hint *before* hydrating defaults so the datasource can introspect the bound query.
        set_config('queryid_for_report_' . $reportid, $this->id(), 'local_reportsources');

        $update = (object) [
            'id'           => $this->id(),
            'status'       => self::STATUS_PUBLISHED,
            'viewname'     => $viewname,
            'reportid'     => $reportid,
            'columnsmeta'  => json_encode($meta),
            'timemodified' => time(),
        ];
        $DB->update_record(self::TABLE, $update);

        // Hydrate default columns / filters / conditions now that the datasource can resolve them.
        $reportpersistent = report_model::get_record(['id' => $reportid], MUST_EXIST);
        $datasourceclass = \local_reportsources\reportbuilder\source\adhoc_query::class;
        /** @var \core_reportbuilder\datasource $datasource */
        $datasource = new $datasourceclass($reportpersistent);
        $existingcolumns = \core_reportbuilder\local\models\column::get_records(['reportid' => $reportid]);
        if (!$existingcolumns) {
            $datasource->add_default_columns();
            $datasource->add_default_filters();
            $datasource->add_default_conditions();
        }

        $this->record = $DB->get_record(self::TABLE, ['id' => $this->id()], '*', MUST_EXIST);
    }

    /**
     * Drop view + RB report, set status to draft.
     */
    public function unpublish(): void {
        global $DB;
        self::tear_down($this->id(), $this->record);
        $DB->update_record(self::TABLE, (object) [
            'id'           => $this->id(),
            'status'       => self::STATUS_DRAFT,
            'viewname'     => null,
            'reportid'     => null,
            'columnsmeta'  => null,
            'timemodified' => time(),
        ]);
        $this->record = $DB->get_record(self::TABLE, ['id' => $this->id()], '*', MUST_EXIST);
    }

    /**
     * Create an additional (blank) Report Builder report bound to this published view.
     *
     * Unlike publish(), this does not recreate the view or touch columnsmeta.
     * The caller is responsible for redirecting to /reportbuilder/edit.php.
     *
     * @return int New report id.
     * @throws \moodle_exception If the query is not published.
     */
    public function create_additional_report(): int {
        if ($this->status() !== self::STATUS_PUBLISHED || !$this->viewname()) {
            throw new \moodle_exception('invalidoperation', 'local_reportsources');
        }

        $reportmodel = reporthelper::create_report((object) [
            'name'   => $this->name(),
            'source' => \local_reportsources\reportbuilder\source\adhoc_query::class,
        ], false);
        $reportid = (int) $reportmodel->get('id');

        set_config('queryid_for_report_' . $reportid, $this->id(), 'local_reportsources');

        return $reportid;
    }

    /**
     * Permanently delete a query and its backing artefacts.
     */
    public function delete(): void {
        global $DB;
        self::tear_down($this->id(), $this->record);
        $DB->delete_records('local_reportsources_log', ['queryid' => $this->id()]);
        $DB->delete_records(self::TABLE, ['id' => $this->id()]);
    }

    /**
     * Tear down view + reportbuilder report for a record.
     *
     * @param int $queryid
     * @param \stdClass $record
     */
    private static function tear_down(int $queryid, \stdClass $record): void {
        global $DB;

        // Delete all Report Builder reports bound to this query via the queryid config.
        $boundreports = $DB->get_records_sql(
            "SELECT * FROM {config_plugins} WHERE plugin = ? AND " . $DB->sql_like('name', '?') . " AND value = ?",
            ['local_reportsources', 'queryid\_for\_report\_%', (string) $queryid]
        );
        foreach ($boundreports as $cfg) {
            $rid = (int) str_replace('queryid_for_report_', '', $cfg->name);
            try {
                $report = report_model::get_record(['id' => $rid]);
                if ($report) {
                    reporthelper::delete_report($rid);
                }
                unset_config($cfg->name, 'local_reportsources');
            } catch (\dml_exception | \moodle_exception $e) {
                debugging('local_reportsources: failed to delete report ' . $rid . ': ' . $e->getMessage());
            }
        }

        if (!empty($record->viewname)) {
            try {
                view::drop($queryid);
            } catch (\moodle_exception $e) {
                debugging('local_reportsources: failed to drop view: ' . $e->getMessage());
            }
        }
    }

    /**
     * Map a Moodle {@see \database_column_info::$meta_type} char to a Reportbuilder column type
     * token used by the datasource.
     *
     * Moodle meta_type chars: R=autoinc, I=int, N=numeric/float, C=char, X=text, B=blob, L=bool,
     * T=timestamp, D=decimal/date.
     *
     * @param string $metatype
     * @return string One of: int, float, bool, timestamp, text.
     */
    private static function map_db_type(string $metatype): string {
        return match (strtoupper($metatype)) {
            'R', 'I' => 'int',
            'N', 'D' => 'float',
            'L'      => 'bool',
            'T'      => 'timestamp',
            default  => 'text',
        };
    }

    /**
     * List queries visible to the current user, optionally scoped to a course.
     *
     * - viewall (system): all queries.
     * - author (system): own queries plus published queries; scoped by courseid when supplied.
     * - view (system or course): published + visible queries; if a course is given, only those
     *   bound to that course or to the site (courseid = 0).
     * - viewown (course): same as view, restricted to the supplied course.
     *
     * @param int $courseid Course id to scope to. 0 means site-wide list (only callable with system caps).
     * @return \stdClass[]
     */
    public static function visible_to_current_user(int $courseid = 0): array {
        global $DB, $USER;

        $syscontext = \context_system::instance();
        $coursecontext = $courseid ? \context_course::instance($courseid) : $syscontext;

        if (has_capability('local/reportsources:viewall', $syscontext)) {
            if ($courseid) {
                return $DB->get_records_select(
                    self::TABLE,
                    'courseid = :c OR courseid = 0',
                    ['c' => $courseid],
                    'timemodified DESC'
                );
            }
            return $DB->get_records(self::TABLE, null, 'timemodified DESC');
        }

        if (has_capability('local/reportsources:author', $syscontext)) {
            $params = ['u' => $USER->id, 'p' => self::STATUS_PUBLISHED, 'v' => 1];
            $where  = '(ownerid = :u OR (status = :p AND visible = :v))';
            if ($courseid) {
                $where .= ' AND (courseid = :c OR courseid = 0)';
                $params['c'] = $courseid;
            }
            return $DB->get_records_select(self::TABLE, $where, $params, 'timemodified DESC');
        }

        // Course-level viewer (teacher) — must supply courseid and have view/viewown there.
        if ($courseid && (
            has_capability('local/reportsources:view', $coursecontext) ||
            has_capability('local/reportsources:viewown', $coursecontext)
        )) {
            return $DB->get_records_select(
                self::TABLE,
                'status = :p AND visible = :v AND (courseid = :c OR courseid = 0)',
                ['p' => self::STATUS_PUBLISHED, 'v' => 1, 'c' => $courseid],
                'timemodified DESC'
            );
        }

        // System view fallback (e.g. admins without viewall): published + visible only, site-wide.
        if (has_capability('local/reportsources:view', $syscontext)) {
            return $DB->get_records_select(
                self::TABLE,
                'status = :p AND visible = :v AND courseid = 0',
                ['p' => self::STATUS_PUBLISHED, 'v' => 1],
                'timemodified DESC'
            );
        }

        return [];
    }
}
