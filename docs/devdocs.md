# local_reportsources — Developer documentation

This document explains how `local_reportsources` works internally, with particular focus on how
it connects a hand-written SQL `SELECT` to a fully-configurable **core Report Builder** report.
It is aimed at developers extending or maintaining the plugin, not at report authors (see
`docs/userdocs.md` for the end-user guide).

---

## 1. What the plugin does, in one sentence

An author writes a SQL `SELECT`, clicks **Publish**, and the plugin creates a database **VIEW**
from that SQL and registers a core Report Builder **report + datasource** pointing at the view —
no PHP required. The resulting report behaves like any other Report Builder report: sortable
columns, filters, conditions, audiences, card/download support, scheduling, etc.

The key idea: **the plugin never renders report data itself.** It only *manufactures* a standard
RB report and gets out of the way. Everything the user sees at `/reportbuilder/view.php?id=<id>`
is core Report Builder driving the plugin's datasource class.

---

## 2. Data model

Two pieces of persistent state:

| Store | Holds |
|---|---|
| `local_reportsources_query` table | The query record: `sql`, `status` (`draft\|published\|disabled`), `viewname`, `reportid`, `columnsmeta` (JSON), `courseid` (0 = site-wide), `visible`, `audiencemeta` (JSON), per-user / per-course filter column choices |
| `config_plugins` rows | `queryid_for_report_<reportid> = <queryid>` — the binding between an RB report and the query that backs it |

There is **no** separate audit table. Lifecycle auditing is done through Moodle's standard event
log (`logstore_standard_log`) — see §8.

The `queryid_for_report_<id>` config entries are the foreign-key glue. They are the single source
of truth the datasource uses at runtime to find its VIEW, and they are cleaned up in `tear_down()`.

---

## 3. The publish lifecycle (the heart of the plugin)

All of this lives in `classes/local/query.php → query::publish()`.

```
publish()
 ├─ view::create_or_replace($id, $sql, $courseid)   → CREATE OR REPLACE VIEW mdl_local_reportsources_v_<id>
 ├─ view::columns($viewname)                          → introspect the new VIEW's columns
 ├─ view::timestamp_columns($sql)                     → recover %%TIMESTAMP()%% columns + formats
 ├─ build $meta (columnsmeta)                          → per-column {type,label[,dateformat]}
 ├─ reporthelper::create_report(... source: adhoc_query ...)  → a reportbuilder_report row (defaults OFF)
 ├─ set_config('queryid_for_report_<reportid>', $queryid)     → the binding (BEFORE hydrating defaults)
 ├─ persist status=published, viewname, reportid, columnsmeta on the query record
 ├─ $datasource->add_default_columns()/filters()/conditions()  → hydrate RB defaults
 ├─ apply_report_visibility($reportid)                → set RB context + audience
 └─ event\query_published::create_and_trigger(...)
```

Ordering matters in two places:

1. **`set_config(queryid_for_report_…)` happens before** `add_default_columns()`. The datasource
   resolves its VIEW *from that config key*, so the binding must exist before RB asks the datasource
   what columns it has. The report is created with `defaults = false` precisely because the
   datasource cannot resolve its columns until the mapping is in place.
2. **`apply_report_visibility()` happens last**, once the report and its columns exist.

`query::unpublish()` / editing the SQL while published reverses steps via `tear_down()`
(`classes/local/query.php:843`), which deletes the RB report (cascading its columns/filters/
audiences), removes the `queryid_for_report_*` config rows, and drops the VIEW.

---

## 4. How the plugin connects to core Report Builder

This is the part most worth understanding. Three classes do the work.

### 4.1 The datasource — `classes/reportbuilder/source/adhoc_query.php`

`adhoc_query extends \core_reportbuilder\datasource`.

**It is deliberately placed in `classes/reportbuilder/source/` and NOT in
`classes/reportbuilder/datasource/`.** Core RB auto-discovers any class under
`\<component>\reportbuilder\datasource` and offers it in the "New report" source picker. By living
one namespace away (`…\reportbuilder\source`), the class stays invisible to that picker, so the
only way a report of this source can exist is via `query::publish()`. **Do not move this file** —
it would leak the datasource into the generic RB UI, where a user could create a report with no
backing query.

At runtime `initialise()` does the wiring:

```php
$reportid = $this->get_report_persistent()->get('id');
$queryid  = get_config('local_reportsources', 'queryid_for_report_' . $reportid);
$query    = query::get($queryid);
$entity   = new adhoc_view($query->viewname(), $visiblemeta, $query->name());
$this->set_main_table($viewname, $alias);
$this->add_entity($entity);
$this->add_all_from_entity($entity->get_entity_name());
```

So: report id → config lookup → query record → VIEW name → an entity built from `columnsmeta`.
The VIEW becomes the report's **main table**.

**Placeholder fallback.** If the binding config key is missing, the query record is gone, or
`columnsmeta` is empty, `initialise()` falls back to `initialise_placeholder()` — a single dummy
`user.id` column. This exists purely so core RB validation doesn't crash when it instantiates the
datasource for a report that isn't fully wired yet (e.g. during admin listing before publish).

**Row-level filtering** is also applied here as RB *base conditions* (always-on SQL, invisible to
the user):

- *Per-user* (`useridcolumn`): `WHERE <col> = :currentuserid`. The column is also stripped from
  the visible entity (its value would always equal the viewer's own id — noise), unless it is the
  only column.
- *Teacher-course* (`coursecolumn`): `WHERE <col> IN (courses the viewer teaches)`, or `1 = 0`
  when the viewer teaches nothing. This column stays visible (a teacher may teach several courses).

`get_default_columns()` / `get_default_filters()` cap the auto-shown set at 6 / 4 columns.

### 4.2 The entity — `classes/reportbuilder/local/entities/adhoc_view.php`

`adhoc_view extends \core_reportbuilder\local\entities\base`. It turns `columnsmeta` into RB
`column` and `filter`/`condition` objects **dynamically at runtime** — there is no hand-written
column list, because the columns depend entirely on the author's SQL.

For each entry in `columnsmeta`:

- A **column** is created: `->add_field("{alias}.{name}")`, typed via `rb_column_type()`, sortable.
- A **filter** *and* a matching **condition** are created, typed via `rb_filter_class()`.

Type mapping (`adhoc_view::rb_column_type()` / `rb_filter_class()`):

| `columnsmeta` type | RB column type | RB filter class |
|---|---|---|
| `int` | `TYPE_INTEGER` | `number` |
| `float` | `TYPE_FLOAT` | `number` |
| `bool` | `TYPE_BOOLEAN` | `boolean_select` |
| `timestamp` | `TYPE_TIMESTAMP` | `date` |
| everything else | `TYPE_TEXT` | `text` |

Column **titles** are arbitrary author-chosen strings, so they cannot have a lang entry each.
They are routed through one parametrised string, `reportsourceheader = '{$a}'`, via `raw_title()`.

### 4.3 Column metadata — where `columnsmeta` comes from

`columnsmeta` is built in `publish()` and **frozen** on the query record. It is a JSON map of
`columnname → {type, label[, dateformat]}`.

Types are derived two ways:

1. **Introspection.** `view::columns($viewname)` runs `$DB->get_columns()` on the freshly created
   VIEW and reads each column's Moodle `meta_type` char. `query::map_db_type()` maps it:
   `R/I → int`, `N/D → float`, `L → bool`, `T → timestamp`, everything else → `text`.

2. **Timestamp recovery (the exception).** A `%%TIMESTAMP()%%` token resolves to a *bare epoch
   integer* in the VIEW (see §6), so introspection would type it as `int`. To recover the intended
   `timestamp` type and any display format, `publish()` calls `view::timestamp_columns($sql)` on
   the saved SQL, which finds which output columns came from `%%TIMESTAMP()%%` (keyed by `AS`
   alias, else the expression's trailing identifier) and forces `type=timestamp` + `dateformat`.

   Why keep the field as a raw epoch instead of a DB date? Because then the column **sorts
   chronologically** (it is numerically an integer) while *displaying* a formatted date. The entity
   renders it with a `userdate()` **callback** (`adhoc_view::build_columns()`); the strftime format
   comes from `strftime_format()`, which translates a neutral format like `dd/mm/yyyy` →
   `%d/%m/%Y`, defaulting to `dd-mmm-yyyy` when none was given.

**Consequence:** editing the SQL after publish does not retype columns on the fly — `columnsmeta`
is regenerated only on the next publish, which drops and rebuilds the VIEW + report.

---

## 5. Report visibility — who can open the generated report

There are **two independent gates**, and keeping them in sync is a core design constraint.

| Gate | Controls | Enforced by |
|---|---|---|
| Plugin pages (index / run / edit) | The plugin's own UI | `query::visible_to_current_user()` using the plugin capabilities |
| The RB report viewer (`/reportbuilder/view.php`) | The actual report data | **core RB** `permission::can_view_report()` |

The report data does **not** live behind the plugin's capabilities. It lives behind core RB:

```
moodle/reportbuilder:view at the report context  AND  (viewall  OR  can_edit  OR  user ∈ audience)
```

`apply_report_visibility()` (`classes/local/query.php:601`) drives the two core RB levers from the
query's own fields — there is no extra config:

- **Context** — `courseid > 0` places the report in that course's context, so `reportbuilder:view`
  is evaluated there. Site-wide queries stay at system context. A stale/deleted course id silently
  degrades to system context rather than fatalling.
- **Audience** — driven by `audiencemeta` (the form's Audience picker) or, when that is the
  automatic default, derived from scope + visibility:
  - `visible = 0` → **no audience** (owner + `reportbuilder:viewall` only)
  - course-scoped + visible → **course staff** (`courserole` for teacher/non-editing teacher/
    manager archetypes), falling back to `courseparticipant` if the site defines no staff roles
  - site-wide + visible → **all users** (`allusers`)
  - explicit picker choices: `allusers`, `courseparticipant`, `courserole`, `cohort`, or `none`

`apply_report_visibility()` is **idempotent**: it deletes existing audiences for the report before
re-adding, so re-publishing or toggling visibility never accumulates duplicates. These reports are
created solely by this plugin, so wiping their audiences is safe.

### Custom audiences

Core ships no "enrolled in course X" or "has role X in course X" audience, so the plugin adds two,
both generated **programmatically only** (never offered in the RB audience UI):

- `classes/reportbuilder/audience/courseparticipant.php` — active enrolments in a course; carries
  `configdata = ['courseid' => int]`.
- `classes/reportbuilder/audience/courserole.php` — users holding given roles in a course.

> **Critical invariant:** a query hidden at the plugin level but published with a wide audience is
> still reachable through `/reportbuilder/view.php`. Always change visibility through
> `apply_report_visibility()` so both gates stay consistent.

---

## 6. SQL validation and placeholder substitution

Two layers, both rooted in `classes/local/sql/`.

### 6.1 Static validation — `validator.php`

- Strips comments and string literals *before* scanning, so an embedded `'DROP …'` string can't
  evade the denylist.
- Enforces `SELECT`/`WITH`-only; blocks multi-statement; blocks a table denylist (`config`,
  `sessions`, etc.).
- `auto_brace()` wraps bare table names in `{}` so authors don't have to type Moodle braces.
- Rejects unknown `%%…%%` tokens via `is_supported_token()`. Supported tokens are exempt because
  they are substituted later, not at validate time.

### 6.2 Placeholder substitution — `view::resolve_placeholders()`

This is the **single** substitution point, used by both publish and the live AJAX check. It
resolves:

| Token | Becomes |
|---|---|
| `{table}` | prefixed table name (`mdl_table`) |
| `%%WWWROOT%%` | site URL |
| `%%COURSEID%%` | bound course id |
| `%%COURSECONTEXT%%` / `%%CONTEXT_*%%` | context ids / context-level constants (`view::context_level_tokens()`) |
| `%%NOW%%` | current epoch int — `UNIX_TIMESTAMP()` (MySQL) / `EXTRACT(EPOCH FROM now())::int` (Postgres) |
| `%%TIMESTAMP(expr[, format])%%` | the **bare epoch expression** `(expr)` — no DB date function (so it sorts; typing/format applied later from `columnsmeta`) |
| `%%EPOCH(datetime)%%` | epoch int in the live dialect (string literals get an explicit Postgres `TIMESTAMP` cast) |

The dialect is chosen via `$DB->get_dbfamily()`. `normalise_aliases()` then makes quoted column
aliases identifier-safe (spaces → underscores; lowercases double-quoted aliases on Postgres so RB's
case-folded SQL can reference them).

### 6.3 Live validation — `classes/external/validate_sql.php` (AJAX)

1. Runs static validation.
2. Runs `$DB->get_records_sql("… LIMIT 1")` to catch bad table/column names and runtime errors
   (the single fetched row forces select-list expressions to actually evaluate).
3. Issues `CREATE OR REPLACE VIEW … / DROP VIEW` to catch **duplicate column names** — a VIEW
   constraint the dry-run `LIMIT 1` misses (this is why `SELECT *` across joins fails at publish).

The JS editor (`amd/src/editor.es6.js`) mirrors the denylist client-side and calls this endpoint on
submit before allowing the form through.

---

## 7. AI SQL generation (optional, via `local_sqlchat`)

AI generation is **not** implemented in this plugin. It is delegated to the separate
`local_sqlchat` plugin when that is installed. `edit.php` checks `class_exists('\local_sqlchat\api')`
and, on an AI request, calls:

```php
$airesult = \local_sqlchat\api::generate_sql($prompt, $context->id);
```

`local_sqlchat` builds the prompt (compressed schema + question), sends it to the configured AI
backend via `tool_ai_bridge`, and returns a `result` object (`sql`, `raw_response`, `prompt`,
`latency_ms`). `edit.php` loads the generated SQL into the edit form and, when the
`local_sqlchat/showprompt` admin setting is on, renders the prompt sent to the LLM (for reuse on a
different model). `classes/local/query_naming.php` provides helpers that derive a query name /
description from either the question or the generated SQL.

The plugin's own `local/sqlchat:use` capability is granted to the report-author role (via
`classes/local/roles.php`) only when that plugin is present.

---

## 8. Auditing — standard events

`classes/event/` defines five query-lifecycle events, all extending `query_event_base`
(→ `\core\event\base`):

`query_created`, `query_updated`, `query_published`, `query_unpublished`, `query_deleted`.

- Raised at `context_system` (query records are site-level).
- `objectid` = query id; `objecttable` = `local_reportsources_query`; `edulevel = LEVEL_OTHER`.
- The query **name** is carried in `other['name']` so a delete event can still render a description
  after the record is gone.
- Triggered from `classes/local/query.php` (save / publish / unpublish / delete / duplicate).
- Viewable at **Site admin → Reports → Logs**.

---

## 9. Admin tree & capabilities

### Capabilities (`db/access.php`)

| Capability | Scope | Who / what |
|---|---|---|
| `local/reportsources:author` | system | Write/save queries |
| `local/reportsources:approve` | system | Publish / unpublish |
| `local/reportsources:viewall` | system | See all queries |
| `local/reportsources:view` | system or course | See published queries |
| `local/reportsources:viewown` | course | Course-level teacher view |

`query::visible_to_current_user()` implements all five rules — **but only for the plugin's own
pages**. Who can open the generated RB report is enforced separately by core RB context + audience
(see §5).

### Admin tree registration (`settings.php`)

The index `admin_externalpage` (under **Reports**) is registered **outside** the `if
($hassiteconfig)` guard, with cap `local/reportsources:author`, so the
**Site administration → Reports → Report sources** menu entry shows for the author role *without*
`moodle/site:config`. The settings page (denylist, AI toggle, etc.) and the admin-only externalpages
(`testview`, `samples`, `createrole`) stay **inside** the guard.

---

## 10. Import / export & bundled samples

`classes/local/transfer.php` moves queries as portable JSON (`export()` / `parse()` / `import()`).
Only portable fields travel (name, description, SQL, course scope, visibility, chart config);
derived state (view name, report id, `columnsmeta`) is regenerated, so every import lands as a fresh
**draft** owned by the importer and must be re-published. `import()` re-validates each SQL and
demotes unknown course ids to site-wide.

Bundled samples (`samples/reportsources.json`) load two ways, both via `transfer`:

- **CLI** — `cli/import.php`.
- **Post-install** — `db/install.php` raises a notification linking to `samples.php`, which calls
  `transfer::import_bundled()`; that helper skips any sample whose name already exists, so it is
  idempotent across reinstalls.

The shipped samples are cross-DB: date handling uses `%%TIMESTAMP()%%` / `%%NOW%%` rather than
dialect-specific functions, so they import and publish on both MySQL/MariaDB and PostgreSQL.

---

## 11. Operational requirements & gotchas

- **DB privileges.** The DB user needs `CREATE VIEW` and `DROP`. Test via
  `classes/local/sql/privilege_check.php → probe()`, surfaced at
  **Site admin → Local plugins → Report sources → Run database view privilege test**.
- **`SELECT *` across joins fails at publish** — duplicate column names are illegal in a VIEW. The
  live validator's CREATE-VIEW step catches this before saving.
- **`columnsmeta` is frozen at publish.** Editing SQL while published drops and rebuilds the
  VIEW + report on the next publish.
- **Never move `adhoc_query.php`** out of `classes/reportbuilder/source/` — see §4.1.
- **Keep the two visibility gates in sync** through `apply_report_visibility()` — see §5.

---

## 12. File map (quick reference)

```
classes/local/query.php                          Publish lifecycle, visibility, tear-down (the core)
classes/local/sql/view.php                       VIEW create/drop, placeholder substitution, introspection
classes/local/sql/validator.php                  Static SQL validation + denylist
classes/local/sql/privilege_check.php            CREATE VIEW / DROP probe
classes/local/transfer.php                       Import/export, bundled samples
classes/local/query_naming.php                   Derive name/description from question or SQL
classes/local/roles.php                          Report-author role creation
classes/reportbuilder/source/adhoc_query.php     RB datasource (hidden from source picker)
classes/reportbuilder/local/entities/adhoc_view.php  Dynamic columns/filters from columnsmeta
classes/reportbuilder/audience/courseparticipant.php Custom "enrolled in course" audience
classes/reportbuilder/audience/courserole.php    Custom "role in course" audience
classes/external/validate_sql.php                Live AJAX SQL validation
classes/external/get_schema.php                  Schema lookup for the editor
classes/event/*                                  Standard lifecycle events
edit.php                                          Edit form + AI generation entry point
settings.php                                      Admin tree registration + settings
db/access.php                                     Capabilities
db/install.xml                                    local_reportsources_query schema
```
