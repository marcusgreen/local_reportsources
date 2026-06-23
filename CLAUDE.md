# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin does

`local_reportsources` lets a Moodle author write a SQL `SELECT` query, click **Publish**, and get a fully-configurable Moodle Report Builder report — no PHP required. Publishing creates a MySQL VIEW backed by the SQL, introspects its columns, and registers a Report Builder datasource pointing at that view.

## Commands

### Tests
```bash
# Run the plugin's PHPUnit suite from the Moodle root
cd /var/www/mdl52/public
vendor/bin/phpunit --filter local_reportsources local/reportsources/tests/

# Run a single test class
vendor/bin/phpunit local/reportsources/tests/sql_validator_test.php

# Run one test method
vendor/bin/phpunit --filter test_invalid local/reportsources/tests/sql_validator_test.php
```

### JS build
```bash
# From the Moodle root — compiles amd/src/editor.es6.js → amd/build/editor.min.js
grunt amd --root=local/reportsources
```

### Upgrade / install
After changing `db/install.xml` or adding `db/upgrade.php` steps, run:
```
Site admin → Notifications
```
or via CLI: `php admin/cli/upgrade.php`

## Architecture

### Publish lifecycle

The central flow lives in `classes/local/query.php → query::publish()`:

1. `validator::validate()` — static denylist + keyword check (no live DB)
2. `view::create_or_replace()` — issues `CREATE OR REPLACE VIEW mdl_local_reportsources_v_<id> AS <sql>`
3. `view::columns()` — calls `$DB->get_columns()` on the new view, strips denylist columns
4. `reporthelper::create_report()` — creates a `reportbuilder_report` row with source `adhoc_query`
5. `set_config('queryid_for_report_<reportid>', $queryid)` — the binding key (see below)
6. `adhoc_query::add_default_columns/filters()` — hydrates RB defaults using the bound query
7. `apply_report_visibility()` — sets the report's RB **context** and **audience** from the query's scope/visibility (see below)

Unpublish / SQL edit reverses steps 2-6 via `query::tear_down()` (which deletes the report, cascading its audiences).

### Report Builder binding

The datasource class `classes/reportbuilder/source/adhoc_query.php` is placed **outside** the `reportbuilder\datasource` namespace on purpose — Moodle's auto-discovery would otherwise surface it in the "new report" UI. Reports are created exclusively by `query::publish()`.

The datasource resolves its VIEW at runtime via:
```php
$queryid = get_config('local_reportsources', 'queryid_for_report_' . $reportid);
```

If that config key is absent the datasource falls back to a placeholder (single dummy column) so RB validation doesn't crash.

Column/filter objects are built dynamically in `classes/reportbuilder/local/entities/adhoc_view.php` from `columnsmeta` — a JSON blob cached on the query record at publish time. `query::map_db_type()` maps the introspected Moodle meta_type char: `R/I→int`, `N/D→float`, `L→bool`, `T→timestamp`, everything else `→text`.

**Timestamp columns** (`%%TIMESTAMP()%%`, see [SQL validation](#sql-validation--two-layers)) are *not* typed from introspection — the token resolves to a bare epoch integer, which would read back as `int`. Instead `query::publish()` calls `view::timestamp_columns()` on the saved SQL to recover which output columns came from `%%TIMESTAMP()%%` (keyed by `AS` alias, else the expression's trailing identifier) and their optional display format, and forces `columnsmeta` `type=timestamp` + `dateformat` for them. The entity then renders each timestamp column with a `userdate()` **callback** (`adhoc_view::strftime_format()` translates the neutral format e.g. `dd/mm/yyyy` → strftime `%d/%m/%Y`; empty → `%d-%b-%Y` i.e. `dd-mmm-yyyy`). Because the field stays a raw epoch, the column **sorts chronologically** while displaying the formatted string.

### Report visibility (who can open the report)

The plugin's `visible_to_current_user()` only gates the **plugin's own** index/run pages. The actual report data lives at `/reportbuilder/view.php?id=<reportid>`, gated by **core RB** `permission::can_view_report()`:

```
moodle/reportbuilder:view at report context  AND  (viewall  OR  can_edit  OR  user ∈ audience)
```

`apply_report_visibility()` (called from `publish()` and `create_additional_report()`) drives the two core levers from existing query fields — no extra config:

- **Context** — `courseid > 0` places the report in that course context (so `reportbuilder:view` is evaluated there); site-wide queries stay at system context.
- **Audience** — `visible = 0` → none (owner + `reportbuilder:viewall` only); `courseid > 0` → a `courseparticipant` audience (active enrolments in that course); visible site-wide → `allusers`.

The method is **idempotent**: it deletes existing audiences for the report before re-adding, so re-publishing or toggling visibility never accumulates duplicates. These reports are created solely by this plugin, so wiping their audiences is safe.

`courseparticipant` (`classes/reportbuilder/audience/courseparticipant.php`) is a custom RB audience — core ships no "enrolled in course X" audience. It carries the bound course id in `configdata` (`['courseid' => int]`) and is generated programmatically only, never offered in the RB audience UI.

The edit form's **Audience** picker (`edit_query_form::add_audience_elements()`) always lists every audience type, including the course-scoped ones (Course participants / Users with a role in the course), and always builds the role picker — using the bound course context for role display names when a course is set, otherwise system context. The course-scoped options are no longer conditionally rendered on `courseid`, so changing the course scope no longer requires saving and reopening the form to reveal them. Choosing a course-scoped audience without a course is caught in `validation()` (`erraudiencecourse`) rather than hidden, since the selected course is only known at submit time.

### SQL validation — two layers

**Static** (`classes/local/sql/validator.php`):
- Strips comments and string literals before scanning so embedded `DROP` strings don't evade the denylist
- Enforces SELECT/WITH-only; blocks multi-statement; blocks a table denylist (`config`, `sessions`, etc.)
- `auto_brace()` wraps bare table names in `{}`—users don't need to type braces
- Rejects unknown `%%…%%` tokens via `is_supported_token()`. Supported tokens (`%%WWWROOT%%`, `%%COURSEID%%`, `%%COURSECONTEXT%%`, `%%NOW%%`, `%%CONTEXT_*%%` level constants via `view::context_level_tokens()`, `%%TIMESTAMP(expr[, format])%%`, `%%EPOCH(datetime)%%`) are exempt because they are substituted later in `view::resolve_placeholders()`, not at validate time

**Placeholder substitution** (`view::resolve_placeholders()`, the single substitution point — used by both publish and the live AJAX check): `{table}`→prefixed name, `%%WWWROOT%%`→site URL, `%%COURSEID%%`→bound course id, `%%NOW%%`→current epoch int (`UNIX_TIMESTAMP()` on MySQL / `EXTRACT(EPOCH FROM now())::int` on Postgres, chosen by `$DB->get_dbfamily()`), and `%%TIMESTAMP(expr[, format])%%`→the **bare epoch expression** `(expr)` — no DB date function, so the column is portable and sorts chronologically; the date typing and `format` are applied later from `columnsmeta` (see [Report Builder binding](#report-builder-binding)). `expr` cannot contain `%` (the token scan stops at `%`).

`%%EPOCH(datetime)%%` resolves in the same `resolve_placeholders()` pass: a datetime literal/expression → Unix epoch int in the live dialect — `UNIX_TIMESTAMP(arg)` on MySQL, `EXTRACT(EPOCH FROM <arg>)::int` on Postgres. String literals get Postgres's explicit `TIMESTAMP` cast (`%%EPOCH('2015-01-01 00:00:00')%%` → `EXTRACT(EPOCH FROM TIMESTAMP '2015-01-01 00:00:00')::int`); other expressions are wrapped in parens. Native `UNIX_TIMESTAMP()` is **not** rewritten — it stays in the validator's `MYSQL_DATE_FUNCTIONS` warn list, so authors are steered to the token. (Use `%%NOW%%`, not `%%EPOCH%%`, for the current time.)

**Live** (`classes/external/validate_sql.php` AJAX endpoint):
- First runs static validation, then `$DB->get_records_sql("... LIMIT 1")` to catch bad table/column names and row-dependent runtime errors (the single fetched row forces select-list expressions to be evaluated, e.g. `to_char()` on a bigint with a date mask)
- Then issues `CREATE OR REPLACE VIEW ... / DROP VIEW` to catch duplicate column names (a VIEW constraint that the dry-run misses)

The JS editor (`amd/src/editor.es6.js`) mirrors the static denylist client-side and calls the AJAX endpoint on form submit before allowing the form through.

### Import / export & bundled samples

`classes/local/transfer.php` moves queries as portable JSON (`export()`/`parse()`/`import()`). Only portable fields travel (name, description, SQL, course scope, visibility, chart config); derived state is regenerated, so every import lands as a fresh **draft** owned by the importer and must be re-published. `import()` re-validates each SQL and demotes unknown courseids to site-wide.

The plugin ships sample report views in `samples/reportsources.json`, loadable two ways, both via `transfer`:
- **CLI** — `cli/import.php` (defaults to `reportsources.json` in the CWD).
- **Post-install** — `db/install.php` raises a notification linking to `samples.php`, a confirm page (also registered as the `local_reportsources_samples` admin external page) that calls `transfer::import_bundled()`. That helper reads `samples/reportsources.json` and skips any sample whose name already exists, so it is idempotent across repeat clicks / reinstalls.

The shipped samples are cross-DB: date handling uses the `%%TIMESTAMP()%%` / `%%NOW%%` tokens rather than dialect-specific functions, so all of them import and publish on both MySQL/MariaDB and PostgreSQL.

### DB schema

Two tables:
- `local_reportsources_query` — stores SQL, status (`draft|published|disabled`), `viewname`, `reportid`, `columnsmeta` (JSON), `courseid` (0 = site-wide), `visible`
- `local_reportsources_log` — audit log of `validate|preview|publish|drop|run` actions

The `queryid_for_report_<id>` config entries in `config_plugins` are the foreign-key glue between RB reports and query records. They are cleaned up in `tear_down()`.

### Capability model

| Capability | Scope | Who |
|---|---|---|
| `author` | system | Write/save queries |
| `approve` | system | Publish/unpublish |
| `viewall` | system | See all queries |
| `view` | system or course | See published queries |
| `viewown` | course | Course-level teacher view |

`query::visible_to_current_user()` implements all five visibility rules — but only for the plugin's own pages. Who can open the generated RB report is enforced separately by core RB's context + audience, set at publish via `apply_report_visibility()` (see [Report visibility](#report-visibility-who-can-open-the-report)).

**Admin tree registration** (`settings.php`): the index `admin_externalpage` (under `reports`) is registered **outside** the `if ($hassiteconfig)` guard with cap `local/reportsources:author`, so the **Site administration → Reports → Report sources** menu entry shows for the author role without `moodle/site:config`. The settings page itself (denylist, AI toggle, etc.) and the admin-only externalpages (testview, samples, createrole) stay **inside** the guard.

## Key constraints

- The plugin's capabilities gate the **plugin UI only**; the RB report viewer (`/reportbuilder/view.php`) is gated by core RB context + audience, set at publish from `courseid`/`visible`. A query hidden at the plugin level but published with a wide audience would still be reachable via RB — keep the two in sync through `apply_report_visibility()`

- DB user needs `CREATE VIEW` and `DROP` privileges — `privilege_check::probe()` tests this; run it from **Site admin → Local plugins → Report sources → Run database view privilege test**
- `SELECT *` across joins fails at publish time (duplicate column names in VIEWs); the validator's live check catches this before saving
- The `adhoc_query` datasource class must stay in `classes/reportbuilder/source/` (not `classes/reportbuilder/datasource/`) to stay hidden from RB's source picker
- `columnsmeta` is frozen at publish time; editing SQL while published drops and rebuilds the view+report on next publish
