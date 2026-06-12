# local_reportsources — improvement suggestions

Review of the whole plugin, ranked by impact. File:line references point at the code discussed.

## Security

### 1. Column denylist bypassable via alias — real info-leak ✅ FIXED
`view::columns()` strips denied column *output names* (`classes/local/sql/view.php:147`); denylist default is `password,secret,sesskey`. A user could alias the source column to dodge it:

```sql
SELECT password AS pw FROM {user}
```

Output column = `pw`, so the denylist never matched `password` and the hash leaked. The output-name filter checks final view column names, not the underlying source columns.

Fixed: `validator::validate()` now scans the comment/string-stripped SQL and rejects any reference to a denied column name as a bare identifier token (`errdeniedcolumn`), so aliased or qualified references are caught at save/import. The output-name strip remains as a second layer (covers `SELECT *`, where no denied name appears in the SQL text). Tests in `tests/sql_validator_test.php`.

### 2. Privacy delete is incomplete — GDPR gap
`classes/privacy/provider.php:38` declares `query.querysql` and `query.ownerid` as personal data. But `delete_data_for_user` (`provider.php:95`) only deletes from `_log`, never `_query`. A user-deletion request leaves their authored SQL behind.

Fix: delete/anonymise owned queries, or document why they are retained (they back live reports → deletion is destructive). At minimum reassign `ownerid`.

## Dead code / unfinished

### 3. `rowcap` does nothing
Stored, form field (`classes/form/edit_query_form.php:107`), exported, imported, migrated — but there is zero enforcement anywhere. The RB report uses its own pagination; the chart uses a separate `chart_rowlimit`.

Fix: wire `rowcap` into the datasource/chart as a real cap, or remove the field + column. Dead config misleads authors.

### 4. Logging uses a dead custom table instead of the Moodle log
Two problems, both wrong-by-design.

**(a) Custom table, never written.** The plugin ships its own audit table `local_reportsources_log` (`db/install.xml:47`; columns `queryid|userid|action|status|durationms|errortext|timeexecuted`, action vocab `validate|preview|publish|drop|run`). Nothing inserts rows outside the install migration (`db/install.php:65`); the cleanup task (`classes/task/cleanup_logs.php:36`) only deletes from it. Privacy exports an empty table. `STATUS_DISABLED` is likewise defined and unused.

**(b) Wrong channel.** Even if populated, a custom table is invisible to Site admin → Reports → Logs, the Logs report filters, core retention policy, and external logstores. Lifecycle actions should fire `\core\event\*` subclasses, which Moodle routes to `logstore_standard_log` automatically.

No event coverage exists today: no `classes/event/`, no `\core\event` triggers, no `db/events.php`. Nothing reaches `logstore_standard_log`. For a plugin tagged `RISK_PERSONAL | RISK_DATALOSS` (`db/access.php:29`) that executes arbitrary SQL and creates DB views, the absence of any audit trail for *who published/edited/deleted which query* is the real weakness — not the empty custom table.

Actions that currently log nothing: save (`query.php:330`), publish (`query.php:407`), unpublish (`query.php:627`), delete (`query.php:671`), duplicate (`query.php:687`), create-extra-report (`query.php:650`), validate-SQL AJAX (`external\validate_sql`), import/export (`transfer::*`). (Running a report is logged by core RB itself.)

Fix:
1. Add `classes/event/` subclasses (`query_published`, `query_unpublished`, `query_deleted`, `query_created`, `query_updated`) extending `\core\event\base` (`crud`, `edulevel = LEVEL_OTHER`, `objecttable = local_reportsources_query`).
2. `::create([...])->trigger()` at each lifecycle point in `query.php`. Events land in `logstore_standard_log` (default logstore), queryable in the normal Logs report and honouring core retention + privacy.
3. Then delete the redundant `local_reportsources_log` table + `cleanup_logs` task + its `db/tasks.php` entry + its privacy-provider entries + `STATUS_DISABLED`.

Minimal high-value subset: just `query_published` + `query_deleted` — the two data-exposure transitions an auditor cares about.

## Correctness

### 5. `get_contexts_for_userid` always returns system context
`classes/privacy/provider.php:53` returns the system context for *every* user, even those with no data. Should check the user actually owns a query or log row first, else the privacy UI shows phantom data for everyone.

## Architecture / maintainability

### 6. `query.php` is 834 lines with mixed concerns
CRUD + audience derivation + AI naming heuristics + RB plumbing in one class.

Fix: split audience logic (`apply_report_visibility`, `build_audiencemeta`, `explode_audiencemeta`, `staff_role_ids`) into a dedicated `audience_resolver`. Move naming heuristics (`name_from_sql`, `extract_tables`, `extract_select_columns`) into a `naming` helper.

### 7. Duplicated report-access check
The `$canmanage || can_view_report` block exists in both `index.php:123` and `chart.php:71`. Extract to one method (e.g. `query::current_user_can_view_report()`) — drift risk if RB permission logic changes.

### 8. `queryid_for_report_<id>` config glue is fragile
The binding lives in `config_plugins` and is torn down by a `LIKE 'queryid\_for\_report\_%'` scan (`classes/local/query.php:721`). Works, but a real column (`reportid` already exists on the query) or a small mapping table would be clearer, indexable, and FK-enforceable.

## Performance

### 9. `tablejson` rebuilt every form load, uncached
`classes/form/edit_query_form.php:85` loops `$DB->get_tables()` + `get_columns()` for *every* table on each edit-page render. On a large Moodle (hundreds of tables) this is heavy. `fkmap` is already version-cached (`build_fk_map`, `edit_query_form.php:198`) — give `tablejson` the same treatment.

## i18n / standards

### 10. Hard-coded English in privacy export
`classes/privacy/provider.php:78` uses the literal strings `'Ad-hoc reports'`, `'Queries'`, `'Audit log'` as export path keys. Should be `get_string()`.

## Testing

### 11. Thin coverage
Only 3 unit files (validator, audience, transfer). No tests for the core `publish()` lifecycle, `view::create_or_replace`, `auto_brace()` (complex tokeniser, untested directly), per-user filter scoping, or the alias-denylist gap (#1). No Behat.

Fix: add `auto_brace` data-provider tests and a publish → view → teardown integration test.

---

## Priority

Highest: **#1 (alias leak)** and **#2 (privacy delete)** — both data-exposure. Then **#3 / #4** (dead `rowcap` / log) to cut confusion.

Bounded mechanical edits: #3, #4, #7, #10. Need a design decision first: #1, #2.
