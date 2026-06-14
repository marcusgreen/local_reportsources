# Improvement ideas — local_reportsources

Code review findings, 2026-06-12. Ordered by category, priority noted inline.
Biggest wins: items 1, 2 (user-facing breakage), 4, 5 (access control), 7 (every-page perf).

## Bugs

### 1. ~~Top-level UNION rejected as "multi-statement"~~ — DONE 2026-06-13
Fixed in `count_top_level_selects()`: SELECTs introduced by UNION/EXCEPT/INTERSECT
(optionally ALL/DISTINCT) no longer count as separate statements; bare `SELECT 1 SELECT 2`
still rejected. UNION cases added to `tests/sql_validator_test.php`.

Original finding (high):
`classes/local/sql/validator.php:471` — `count_top_level_selects()` counts SELECT keywords
at parenthesis depth 0. `SELECT a FROM {user} UNION SELECT b FROM {course}` contains two,
so it throws `errmultistatement`, even though `check_statement_type()` (line 217) explicitly
allows UNION/EXCEPT/INTERSECT. A documented feature is unreachable.
**Fix:** don't count a SELECT that directly follows UNION/EXCEPT/INTERSECT. Add a UNION case
to `tests/sql_validator_test.php::valid_provider()` (none exists today).

### 2. ~~`REPLACE` denylist blocks the legitimate `REPLACE()` string function~~ — DONE 2026-06-13
Fixed in both validator.php (keyword scan uses `\bREPLACE\b(?!\s*\()`) and editor.js client
mirror; AMD rebuilt. REPLACE-function and REPLACE-statement cases added to tests.

Original finding (high):
`classes/local/sql/validator.php:49` — keyword scan matches `\bREPLACE\b`, so
`SELECT REPLACE(name, 'x', 'y') FROM {course}` is rejected. Common in reporting SQL.
The parser already rejects REPLACE *statements* via `check_statement_type()`.
**Fix:** allow `REPLACE` when immediately followed by `(`. Mirror the change in the
client-side denylist at `amd/src/editor.js:233`.

### 3. ~~Transaction around DDL is illusory~~ — DONE 2026-06-14
Removed the delegated transaction in `save()`. Record is now demoted to draft and persisted
*before* `tear_down()` runs, so a partial teardown leaves the record reading draft instead of
falsely published over a destroyed view/report.

Original finding (medium):
`classes/local/query.php:362` — `save()` wraps `tear_down()` in a delegated transaction,
but `tear_down()` issues `DROP VIEW` via `change_database_structure()`. MySQL DDL implicitly
commits, so the atomicity claimed by the comment at line 359 does not hold: a failed
`update_record` after the drop still leaves view + report destroyed while the record claims
published status.
**Fix:** update the record to draft first, then tear down outside any transaction.

## Security

### 4. Draft SQL disclosure via copy action (high)
`run.php:57` — `action=copy` only checks the system-wide `author` capability; no ownership
or visibility check on the target query. Any author can duplicate another author's hidden
draft by guessing its id, exposing its SQL, name and description in their own edit form.
**Fix:** apply the same owner-or-viewall check used in `edit.php:49` and `delete.php:37`.

### 5. Import bypasses course-scope access check (high)
`classes/local/transfer.php:166` — import only checks the courseid *exists*, while
`edit.php:125` requires the author to hold view/viewown in that course. A crafted import
file lets an author bind a query to any course they cannot access.
**Fix:** re-run the edit.php capability check during import; demote to site-wide (0) on
failure, same as for unknown course ids, and report it in the `demoted` list.

### 6. Cross-owner delete gated by a read capability (medium)
`index.php:222` / `delete.php:37` allow deleting other users' queries with `viewall`,
declared `captype: read` with no `RISK_DATALOSS` (`db/access.php:61`). Deleting a published
query destroys a live RB report and its view.
**Fix:** gate cross-owner delete on `approve` (or a dedicated manage capability).

## Performance

### 7. ~~Full schema dump on every edit-page render~~ — DONE 2026-06-14
Schema (tables+columns) and the install.xml FK map now live in `local\schema::get()`, cached
in a MUC application cache (`db/caches.php`, keyed by Moodle version). The edit form no longer
builds or embeds them; `editor.js` fetches them lazily via a new `local_reportsources_get_schema`
external function (`db/services.php`) after init, degrading to keyword-only autocomplete on
failure. Tests in `tests/schema_test.php`. Version bumped to 2026061400.

Original finding (high):
`classes/form/edit_query_form.php:85-89` — loops `$DB->get_tables()` and calls
`get_columns()` per table (~450+ tables), embedding the whole schema as a JSON hidden form
field. Runs on every GET *and* POST of edit.php. Page weight likely 1MB+; cold-cache render
slow.
**Fix:** serve schema from a dedicated AJAX endpoint backed by MUC; fetch lazily from
`editor.js` after init.

### 8. ~~FK map cached in config_plugins~~ — DONE 2026-06-14
Resolved together with item 7: the FK map moved out of `config_plugins` into the MUC `schema`
cache. `db/upgrade.php` step 2026061400 unsets the orphaned `fkmapcache` / `fkmapcache_ver`
config entries.

Original finding (medium):
`classes/form/edit_query_form.php:240` — `set_config()` of a large JSON blob, written
during a GET render. The config table is not a cache.
**Fix:** MUC application cache with a `db/caches.php` definition, invalidated on upgrade.

### 9. N+1 queries in index listing (medium)
`index.php:132` — `report::get_record()` per row; `index.php:149` — `core_user::get_user()`
per row.
**Fix:** batch with one `get_records_list()` for reports and one for owners.

### 10. No runtime guard on validation dry-run (medium)
`classes/external/validate_sql.php:79` — executes arbitrary SELECT with no execution-time
cap; a pathological cross join can pin the DB before LIMIT 1 applies.
**Fix:** MySQL — prepend `/*+ MAX_EXECUTION_TIME(5000) */` hint; Postgres —
`SET LOCAL statement_timeout`.

### 11. Shared probe view name races (low)
`classes/external/validate_sql.php:89` — every concurrent validation uses the same
`..._probe_col` view; two authors validating simultaneously can drop each other's view
mid-check, producing a spurious failure.
**Fix:** suffix the probe name with `$USER->id` or a random token.

## Code quality

### 12. Dead code: `validator::placeholders()` (low)
`classes/local/sql/validator.php:519` — only caller is its own unit test. Named
placeholders cannot work in views anyway (`?` rejected; dry-run passes `[]`).
**Fix:** delete the method and `test_placeholders()`.

### 13. AI naming helpers misplaced (low)
`classes/local/query.php:91-229` — `name_from_question`, `is_error_fix_prompt`,
`name_from_sql`, `description_from_sql`, `extract_tables`, `extract_select_columns` are
~140 lines of AI-prompt heuristics inside the CRUD/lifecycle class.
**Fix:** extract to e.g. `local\ai\naming`.

### 14. Hardcoded English strings in editor.js (medium)
`amd/src/editor.js:193,227,267-280,352` — 'SQL is required.', 'Only a single statement…',
'Query must start with…', 'Keyword not allowed: ', 'Checking query…',
'Could not format SQL: ', initial 'Format SQL' button label.
**Fix:** load via `core/str`; align client messages with server lang strings.

### 15. Repeated capability boilerplate (low)
`index.php:30-51` and `chart.php:43-60` duplicate the same four-way capability block, in a
convoluted `if (!A && !B && !C) require_capability(A)` shape.
**Fix:** extract a `local\access::require_viewer(int $courseid)` helper.

### 16. Missing format_string on page title/heading (low)
`chart.php:185-186` — `set_title($rec->name)` / `set_heading($rec->name)` pass the raw name.
**Fix:** wrap both in `format_string()`.

### 17. ~~Dead CodeMirror 5 CSS shipped from amd/src~~ — DONE 2026-06-12
Was `amd/src/codemirror.css`, loaded at `edit_query_form.php:82`. The file was stock
CodeMirror 5 CSS; the editor is CodeMirror 6, which injects its own styles via JS. No CM6
selector matched anything in the file. Deleted the file and the `$PAGE->requires->css()`
call instead of relocating.

### 18. Privacy metadata incomplete (medium)
`classes/privacy/provider.php:40` — declares only ownerid/querysql/timecreated; the table
also stores user-authored `name`, `description` and `timemodified`.
**Fix:** declare the missing fields in `get_metadata()`.

### 19. Audience wipe clobbers manual RB edits (medium)
`classes/local/query.php:508` — `apply_report_visibility()` deletes *all* audiences on
every re-save. Admins can reach `/reportbuilder/edit.php` (link offered at `index.php:177`)
and add audiences there; the next query save silently destroys them.
**Fix:** either delete only plugin-owned audience classnames, or surface a prominent
warning in the RB UI / docs.

## Docs / tests

### 20. CLAUDE.md stale (low)
Still documents the dropped `local_reportsources_log` table (removed in upgrade step
2026061202), a two-table schema, and `amd/src/editor.es6.js` (file is now `editor.js`).
**Fix:** refresh CLAUDE.md against current code.

### 21. Test coverage gaps (medium)
No tests for: `query::publish()/unpublish()/tear_down()` lifecycle,
`visible_to_current_user()` capability matrix (5 rules), `auto_brace()` (complex tokenizer,
zero direct tests), privacy provider, `validate_sql` external function, top-level UNION
(item 1). Roughly 300 test lines against ~3k plugin lines.
