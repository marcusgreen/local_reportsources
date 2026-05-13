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

Unpublish / SQL edit reverses steps 2-6 via `query::tear_down()`.

### Report Builder binding

The datasource class `classes/reportbuilder/source/adhoc_query.php` is placed **outside** the `reportbuilder\datasource` namespace on purpose — Moodle's auto-discovery would otherwise surface it in the "new report" UI. Reports are created exclusively by `query::publish()`.

The datasource resolves its VIEW at runtime via:
```php
$queryid = get_config('local_reportsources', 'queryid_for_report_' . $reportid);
```

If that config key is absent the datasource falls back to a placeholder (single dummy column) so RB validation doesn't crash.

Column/filter objects are built dynamically in `classes/reportbuilder/local/entities/adhoc_view.php` from `columnsmeta` — a JSON blob cached on the query record at publish time. Type mapping: `R/I→int`, `N/D→float`, `L→bool`, `T→timestamp`, everything else `→text`.

### SQL validation — two layers

**Static** (`classes/local/sql/validator.php`):
- Strips comments and string literals before scanning so embedded `DROP` strings don't evade the denylist
- Enforces SELECT/WITH-only; blocks multi-statement; blocks a table denylist (`config`, `sessions`, etc.)
- `auto_brace()` wraps bare table names in `{}`—users don't need to type braces

**Live** (`classes/external/validate_sql.php` AJAX endpoint):
- First runs static validation, then `$DB->get_records_sql("... LIMIT 0")` to catch bad table/column names
- Then issues `CREATE OR REPLACE VIEW ... / DROP VIEW` to catch duplicate column names (a VIEW constraint that `LIMIT 0` misses)

The JS editor (`amd/src/editor.es6.js`) mirrors the static denylist client-side and calls the AJAX endpoint on form submit before allowing the form through.

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

`query::visible_to_current_user()` implements all five visibility rules.

## Key constraints

- DB user needs `CREATE VIEW` and `DROP` privileges — `privilege_check::probe()` tests this; run it from **Site admin → Local plugins → Report sources → Run database view privilege test**
- `SELECT *` across joins fails at publish time (duplicate column names in VIEWs); the validator's live check catches this before saving
- The `adhoc_query` datasource class must stay in `classes/reportbuilder/source/` (not `classes/reportbuilder/datasource/`) to stay hidden from RB's source picker
- `columnsmeta` is frozen at publish time; editing SQL while published drops and rebuilds the view+report on next publish
