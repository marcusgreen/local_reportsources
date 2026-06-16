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
use core_reportbuilder\local\models\audience as audience_model;
use core_reportbuilder\local\helpers\report as reporthelper;
use core_reportbuilder\reportbuilder\audience\allusers;
use core_cohort\reportbuilder\audience\cohortmember;
use local_reportsources\reportbuilder\audience\courseparticipant;
use local_reportsources\reportbuilder\audience\courserole;
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
    /** @var string Database table holding saved queries. */
    public const TABLE = 'local_reportsources_query';

    /** @var string Status: saved but not published. */
    public const STATUS_DRAFT = 'draft';
    /** @var string Status: view + Report Builder report are live. */
    public const STATUS_PUBLISHED = 'published';

    /** @var string Audience token: derive the audience automatically from scope/visibility. */
    public const AUDIENCE_DEFAULT = 'default';
    /** @var string Audience token: all site users. */
    public const AUDIENCE_ALLUSERS = 'allusers';
    /** @var string Audience token: participants enrolled in the report's course. */
    public const AUDIENCE_COURSEPARTICIPANT = 'courseparticipant';
    /** @var string Audience token: users holding a chosen role in the course. */
    public const AUDIENCE_COURSEROLE = 'courserole';
    /** @var string Audience token: members of chosen cohorts. */
    public const AUDIENCE_COHORT = 'cohort';
    /** @var string Audience token: nobody (owner + reportbuilder:viewall only). */
    public const AUDIENCE_NONE = 'none';

    /** @var \stdClass */
    private \stdClass $record;

    /**
     * Wrap a query database record.
     *
     * @param \stdClass $record The query database record.
     */
    private function __construct(\stdClass $record) {
        $this->record = $record;
    }

    /**
     * Load a query by id.
     *
     * @param int $id
     * @return self
     */
    public static function get(int $id): self {
        global $DB;
        $rec = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        return new self($rec);
    }

    /**
     * Load a query record by id.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function get_record(int $id): \stdClass {
        return self::get($id)->record;
    }

    /**
     * Get the query id.
     *
     * @return int
     */
    public function id(): int {
        return (int) $this->record->id;
    }

    /**
     * Get the underlying query record.
     *
     * @return \stdClass
     */
    public function record(): \stdClass {
        return $this->record;
    }

    /**
     * Get the query name.
     *
     * @return string
     */
    public function name(): string {
        return (string) $this->record->name;
    }

    /**
     * Get the query SQL.
     *
     * @return string
     */
    public function sql(): string {
        return (string) $this->record->querysql;
    }

    /**
     * Get the query status (draft|published).
     *
     * @return string
     */
    public function status(): string {
        return (string) $this->record->status;
    }

    /**
     * Get the database view name, or null if not published.
     *
     * @return string|null
     */
    public function viewname(): ?string {
        return $this->record->viewname ?: null;
    }

    /**
     * Get the bound Report Builder report id, or null if not published.
     *
     * @return int|null
     */
    public function reportid(): ?int {
        return $this->record->reportid ? (int) $this->record->reportid : null;
    }

    /**
     * Get the course id this query is scoped to (0 = site-wide).
     *
     * @return int
     */
    public function courseid(): int {
        return (int) ($this->record->courseid ?? 0);
    }

    /**
     * Whether the published report is listed in the plugin UI.
     *
     * @return bool
     */
    public function visible(): bool {
        return (int) ($this->record->visible ?? 1) === 1;
    }

    /**
     * Output column whose value is matched against the viewing user's id to scope the report to
     * "rows about me". Empty string means no per-user filter.
     *
     * @return string
     */
    public function useridcolumn(): string {
        return (string) ($this->record->useridcolumn ?? '');
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
     * Coerce a submitted per-user column choice to a valid value: it must name one of the columns
     * in the supplied columnsmeta JSON, otherwise the filter is cleared (returns null).
     *
     * @param string $choice Raw submitted column name.
     * @param string|null $columnsmeta JSON column metadata of the published query.
     * @return string|null Validated column name, or null for no filter.
     */
    private static function valid_useridcolumn(string $choice, ?string $columnsmeta): ?string {
        $choice = clean_param($choice, PARAM_ALPHANUMEXT);
        if ($choice === '') {
            return null;
        }
        $meta = json_decode((string) ($columnsmeta ?: '[]'), true);
        return (is_array($meta) && array_key_exists($choice, $meta)) ? $choice : null;
    }

    /**
     * Remove saved Reportbuilder column/filter/condition instances of the given view column from
     * the bound report. Used when a column becomes the per-user filter: the datasource no longer
     * exposes it, so saved instances referencing it would be orphaned.
     *
     * @param int $reportid Bound Reportbuilder report id.
     * @param string $columnname View column name (entity-local, without the entity prefix).
     */
    private static function purge_report_column(int $reportid, string $columnname): void {
        $unique = \local_reportsources\reportbuilder\local\entities\adhoc_view::ENTITY . ':' . $columnname;

        $columns = \core_reportbuilder\local\models\column::get_records(
            ['reportid' => $reportid, 'uniqueidentifier' => $unique]
        );
        foreach ($columns as $column) {
            \core_reportbuilder\local\helpers\report::delete_report_column($reportid, (int) $column->get('id'));
        }

        $filters = \core_reportbuilder\local\models\filter::get_records(
            ['reportid' => $reportid, 'uniqueidentifier' => $unique]
        );
        foreach ($filters as $filter) {
            if ($filter->get('iscondition')) {
                \core_reportbuilder\local\helpers\report::delete_report_condition($reportid, (int) $filter->get('id'));
            } else {
                \core_reportbuilder\local\helpers\report::delete_report_filter($reportid, (int) $filter->get('id'));
            }
        }
    }

    /**
     * Save (create or update) a query record. Validates the SQL before storing.
     *
     * @param \stdClass $data Form data: id?, name, description, querysql.
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
            'courseid'     => (int) ($data->courseid ?? 0),
            'visible'      => isset($data->visible) ? (int) (bool) $data->visible : 1,
            'audiencemeta' => self::build_audiencemeta($data),
            'timemodified' => $now,
        ];

        if (isset($data->chart_type)) {
            $allowedtypes = ['none', 'bar', 'line', 'pie', 'doughnut'];
            $record->chartmeta = json_encode([
                'type'     => in_array($data->chart_type, $allowedtypes, true) ? $data->chart_type : 'none',
                'xcol'     => clean_param((string) ($data->chart_xcol ?? ''), PARAM_ALPHANUMEXT),
                'ycol'     => clean_param((string) ($data->chart_ycol ?? ''), PARAM_ALPHANUMEXT),
                'rowlimit' => max(1, min(5000, (int) ($data->chart_rowlimit ?? 200))),
            ]);
        }

        if (!empty($data->id)) {
            $record->id = (int) $data->id;
            $existing = $DB->get_record(self::TABLE, ['id' => $record->id], '*', MUST_EXIST);
            // SQL change while published: drop view + report so they get rebuilt on next publish.
            // A transaction here would be illusory — tear_down() issues DROP VIEW via
            // change_database_structure(), and DDL implicitly commits on MySQL. So demote the
            // record to draft *first*, then tear down: if the teardown fails partway, the record
            // already reads draft rather than claiming published over a destroyed view/report.
            if ($existing->status === self::STATUS_PUBLISHED && $existing->querysql !== $sql) {
                $record->status       = self::STATUS_DRAFT;
                $record->viewname     = null;
                $record->reportid     = null;
                $record->columnsmeta  = null;
                // Old column names no longer apply once the view is rebuilt.
                $record->useridcolumn = null;
                $DB->update_record(self::TABLE, $record);
                self::tear_down((int) $existing->id, $existing);
                \local_reportsources\event\query_updated::create_and_trigger($record->id, $record->name);
                return $record->id;
            }
            // The per-user column is picked from the live view's columns, so only accept it for an
            // already-published query and only when it names one of that query's output columns.
            $record->useridcolumn = self::valid_useridcolumn(
                (string) ($data->useridcolumn ?? ''),
                $existing->columnsmeta
            );
            // The datasource stops offering a per-user filter column; purge any saved instances
            // of it from the report so they don't linger as stale config.
            if (
                $record->useridcolumn !== null
                    && $existing->status === self::STATUS_PUBLISHED && !empty($existing->reportid)
            ) {
                self::purge_report_column((int) $existing->reportid, $record->useridcolumn);
            }
            $DB->update_record(self::TABLE, $record);
            // Audience/visibility edits on an already-published report take effect immediately.
            if ($existing->status === self::STATUS_PUBLISHED && !empty($existing->reportid)) {
                self::get($record->id)->apply_report_visibility((int) $existing->reportid);
            }
            \local_reportsources\event\query_updated::create_and_trigger($record->id, $record->name);
            return $record->id;
        }

        $record->ownerid     = (int) $USER->id;
        $record->status      = self::STATUS_DRAFT;
        $record->timecreated = $now;
        $newid = $DB->insert_record(self::TABLE, $record);
        \local_reportsources\event\query_created::create_and_trigger($newid, $record->name);
        return $newid;
    }

    /**
     * Move a draft to published: create the VIEW, register a Reportbuilder report, cache column meta.
     */
    public function publish(): void {
        global $DB;

        $viewname = view::create_or_replace($this->id(), $this->sql(), $this->courseid());
        $columns  = view::columns($viewname);

        // %%TIMESTAMP() columns resolve to a bare epoch integer in the view, so introspection alone
        // would type them as int. Recover the intended timestamp type — and any requested display
        // format — from the saved SQL tokens, keyed by output column name.
        $tsformats = view::timestamp_columns($this->sql());

        $meta = [];
        foreach ($columns as $name => $info) {
            $key = strtolower($name);
            if (array_key_exists($key, $tsformats)) {
                $meta[$name] = [
                    'type'       => 'timestamp',
                    'label'      => $name,
                    'dateformat' => $tsformats[$key],
                ];
            } else {
                $meta[$name] = [
                    'type'  => self::map_db_type((string) $info->meta_type),
                    'label' => $name,
                ];
            }
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

        $this->apply_report_visibility($reportid);

        $this->record = $DB->get_record(self::TABLE, ['id' => $this->id()], '*', MUST_EXIST);

        \local_reportsources\event\query_published::create_and_trigger($this->id(), $this->name());
    }

    /**
     * Limit who can open the RB report by setting core Report Builder's context + audience.
     *
     * These are the two levers of {@see \core_reportbuilder\permission::can_view_report()}:
     *
     * - Context: course-scoped queries (courseid > 0) place their report in that course context, so
     *   the moodle/reportbuilder:view capability is evaluated there rather than site-wide.
     * - Audience: taken from the query's audiencemeta picker. When that is empty (DEFAULT) the
     *   audience is derived automatically — a hidden query (visible = 0) gets none (owner +
     *   reportbuilder:viewall only); a course-scoped query gets {@see courseparticipant}; a visible
     *   site-wide query gets {@see allusers}.
     *
     * Idempotent: existing audiences are cleared first so re-publishing, toggling visibility or
     * changing the picker does not accumulate duplicates. These reports are created solely by this
     * plugin, so wiping their audiences is safe.
     *
     * @param int $reportid
     */
    public function apply_report_visibility(int $reportid): void {
        $courseid = (int) ($this->record->courseid ?? 0);
        $visible  = (int) ($this->record->visible ?? 1);

        // Context follows course scope. A courseid pointing at a course that no longer exists (e.g.
        // course deleted after scoping, or a stale id carried in from an older import) degrades to
        // site-wide rather than fatalling on context_course::instance().
        $context = \context_system::instance();
        if ($courseid > 0) {
            $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
            if ($coursecontext) {
                $context = $coursecontext;
            } else {
                $courseid = 0;
            }
        }
        $reportpersistent = report_model::get_record(['id' => $reportid], MUST_EXIST);
        if ((int) $reportpersistent->get('contextid') !== (int) $context->id) {
            $reportpersistent->set('contextid', $context->id);
            $reportpersistent->save();
        }

        // Reset any audiences this plugin previously attached to the report.
        foreach (audience_model::get_records(['reportid' => $reportid]) as $audience) {
            $audience->delete();
        }

        $meta = $this->record->audiencemeta ? json_decode($this->record->audiencemeta, true) : null;
        $type = is_array($meta) ? ($meta['type'] ?? self::AUDIENCE_DEFAULT) : self::AUDIENCE_DEFAULT;

        // Automatic: derive from scope + visibility.
        if ($type === self::AUDIENCE_DEFAULT) {
            if (!$visible) {
                return;
            }
            if ($courseid > 0) {
                // Course-scoped reports default to course staff (teacher / non-editing teacher /
                // manager) rather than every enrolled user, so students do not see them unless the
                // author explicitly chooses "Course participants". Fall back to participants only if
                // the site somehow has no staff roles defined.
                $roles = self::staff_role_ids();
                if ($roles) {
                    courserole::create($reportid, ['courseid' => $courseid, 'roles' => $roles]);
                } else {
                    courseparticipant::create($reportid, ['courseid' => $courseid]);
                }
            } else {
                allusers::create($reportid, []);
            }
            return;
        }

        // Explicit picker choice.
        switch ($type) {
            case self::AUDIENCE_ALLUSERS:
                allusers::create($reportid, []);
                break;
            case self::AUDIENCE_COURSEPARTICIPANT:
                if ($courseid > 0) {
                    courseparticipant::create($reportid, ['courseid' => $courseid]);
                }
                break;
            case self::AUDIENCE_COURSEROLE:
                $roles = array_values(array_filter(array_map('intval', (array) ($meta['roles'] ?? []))));
                if ($courseid > 0 && $roles) {
                    courserole::create($reportid, ['courseid' => $courseid, 'roles' => $roles]);
                }
                break;
            case self::AUDIENCE_COHORT:
                $cohorts = array_values(array_filter(array_map('intval', (array) ($meta['cohorts'] ?? []))));
                if ($cohorts) {
                    cohortmember::create($reportid, ['cohorts' => $cohorts]);
                }
                break;
            case self::AUDIENCE_NONE:
            default:
                // No audience: owner + reportbuilder:viewall only.
                break;
        }
    }

    /**
     * Role ids considered "course staff" — those with a teaching or management archetype.
     *
     * Used for the automatic course-scoped audience so students are excluded by default.
     *
     * @return int[]
     */
    private static function staff_role_ids(): array {
        $roleids = [];
        foreach (['editingteacher', 'teacher', 'manager'] as $archetype) {
            foreach (get_archetype_roles($archetype) as $role) {
                $roleids[(int) $role->id] = (int) $role->id;
            }
        }
        return array_values($roleids);
    }

    /**
     * Build the audiencemeta JSON blob from submitted form data.
     *
     * @param \stdClass $data Form data (audiencetype, audienceroles, audiencecohorts).
     * @return string|null JSON string, or null for the automatic default.
     */
    private static function build_audiencemeta(\stdClass $data): ?string {
        $type = (string) ($data->audiencetype ?? self::AUDIENCE_DEFAULT);
        switch ($type) {
            case self::AUDIENCE_ALLUSERS:
                return json_encode(['type' => self::AUDIENCE_ALLUSERS]);
            case self::AUDIENCE_COURSEPARTICIPANT:
                return json_encode(['type' => self::AUDIENCE_COURSEPARTICIPANT]);
            case self::AUDIENCE_COURSEROLE:
                return json_encode([
                    'type'  => self::AUDIENCE_COURSEROLE,
                    'roles' => array_values(array_map('intval', (array) ($data->audienceroles ?? []))),
                ]);
            case self::AUDIENCE_COHORT:
                return json_encode([
                    'type'    => self::AUDIENCE_COHORT,
                    'cohorts' => array_values(array_map('intval', (array) ($data->audiencecohorts ?? []))),
                ]);
            case self::AUDIENCE_NONE:
                return json_encode(['type' => self::AUDIENCE_NONE]);
            default:
                return null;
        }
    }

    /**
     * Expand a stored audiencemeta blob into flat form field values for set_data().
     *
     * @param string|null $json Stored audiencemeta JSON.
     * @return array{audiencetype:string,audienceroles:int[],audiencecohorts:int[]}
     */
    public static function explode_audiencemeta(?string $json): array {
        $meta = $json ? json_decode($json, true) : null;
        return [
            'audiencetype'    => is_array($meta) ? ($meta['type'] ?? self::AUDIENCE_DEFAULT) : self::AUDIENCE_DEFAULT,
            'audienceroles'   => array_map('intval', (array) ($meta['roles'] ?? [])),
            'audiencecohorts' => array_map('intval', (array) ($meta['cohorts'] ?? [])),
        ];
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

        \local_reportsources\event\query_unpublished::create_and_trigger($this->id(), $this->name());
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

        $this->apply_report_visibility($reportid);

        return $reportid;
    }

    /**
     * Permanently delete a query and its backing artefacts.
     */
    public function delete(): void {
        global $DB;
        // Snapshot id/name before the record is gone so the event can still describe it.
        $queryid = $this->id();
        $name = $this->name();
        self::tear_down($queryid, $this->record);
        $DB->delete_records(self::TABLE, ['id' => $queryid]);
        \local_reportsources\event\query_deleted::create_and_trigger($queryid, $name);
    }

    /**
     * Duplicate this query as a new draft owned by the current user.
     *
     * Copies the SQL, description, course scope, visibility and chart
     * settings. The copy starts as a draft: no VIEW, report or column metadata
     * is carried over (those are rebuilt on publish).
     *
     * @return int New query id.
     */
    public function duplicate(): int {
        global $DB, $USER;

        $now = time();
        $copy = (object) [
            'name'         => get_string('copyof', 'local_reportsources', $this->name()),
            'description'  => (string) ($this->record->description ?? ''),
            'querysql'     => $this->sql(),
            'courseid'     => $this->courseid(),
            'visible'      => (int) ($this->record->visible ?? 1),
            'chartmeta'    => $this->record->chartmeta ?: null,
            'ownerid'      => (int) $USER->id,
            'status'       => self::STATUS_DRAFT,
            'viewname'     => null,
            'reportid'     => null,
            'columnsmeta'  => null,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];

        $newid = $DB->insert_record(self::TABLE, $copy);
        \local_reportsources\event\query_created::create_and_trigger($newid, $copy->name);
        return $newid;
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
        if (
            $courseid && (
            has_capability('local/reportsources:view', $coursecontext) ||
            has_capability('local/reportsources:viewown', $coursecontext)
            )
        ) {
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
