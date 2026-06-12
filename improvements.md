# local_reportsources — improvement suggestions

Review of the whole plugin, ranked by impact. File:line references point at the code discussed.
Last refreshed 2026-06-12; previously fixed items (alias denylist bypass, dead `rowcap`,
missing lifecycle events) have been removed.

## Security / privacy

### 1. Privacy delete retains authored queries — GDPR design decision pending
`classes/privacy/provider.php:39` declares `query.querysql` and `query.ownerid` as personal
data, but all three delete methods (`provider.php:77-89`) are deliberate no-ops: queries
back live RB reports + DB views, so deletion is destructive. The retention rationale is now
documented in code (done as part of the #2 cleanup), which satisfies the minimum bar.

Remaining decision: whether a deletion request should anonymise `ownerid` (reassign to
admin/guest) so the row stops being personal data while the report survives.

### 2. `local_reportsources_log` table ✅ FIXED (removed)
Lifecycle events had already replaced it (`classes/event/*`, logged to
`logstore_standard_log`), but the never-written custom table and its plumbing remained.

Fixed: dropped the table (`db/upgrade.php` 2026061202 step + version bump), removed it from
`db/install.xml`, deleted the `cleanup_logs` task + `db/tasks.php`, removed the
`local_adhocreports_log` migration from `db/install.php`, stripped all `_log` references
from the privacy provider (delete methods are now documented no-ops — queries retained, see
#1), removed `STATUS_DISABLED` and the `cleanuplogs` / `privacy:metadata:log:*` lang
strings. (`status_disabled` kept: reachable via dynamic `'status_' . $rec->status` in
`index.php:150` for rows migrated from local_adhocreports.)

## Correctness

### 3. `get_contexts_for_userid` always returns system context
`classes/privacy/provider.php:53` returns the system context for *every* user, even those
with no data. Should check the user actually owns a query row first, else the privacy UI
shows phantom data for everyone.

## Architecture / maintainability

### 4. Report-access check duplicated — and the drift already happened
The original concern was a copy-pasted `$canmanage || can_view_report` block. It is now
worse: `index.php:115-145` hand-mirrors core `permission::can_view_report()` internals
(`can_view_reports_list()` + a pre-fetched audience-id map, to avoid N queries on the list
page), while `chart.php:72-82` calls `can_view_report()` directly. Two implementations of
the same rule; a core RB permission change now breaks them differently.

Fix: extract one method (e.g. `query::current_user_can_view_report(?array $prefetchedaudiences = null)`)
that uses the pre-fetched map when given one and falls back to the core call otherwise.

### 5. `query.php` is 846 lines with mixed concerns — and still growing
CRUD + audience derivation + AI naming heuristics + RB plumbing in one class. The audience
logic has grown since the last review (now `allusers` / `courseparticipant` / `courserole` /
`cohortmember` branches around `query.php:528-557`).

Fix: split audience logic (`apply_report_visibility`, `build_audiencemeta`,
`explode_audiencemeta`, `staff_role_ids`) into a dedicated `audience_resolver`. Move naming
heuristics (`name_from_sql`, `extract_tables`, `extract_select_columns`) into a `naming` helper.

### 6. `queryid_for_report_<id>` config glue is fragile
The binding lives in `config_plugins` (`classes/local/query.php:437`) and is torn down by a
`LIKE 'queryid\_for\_report\_%'` scan (`query.php:734`). Works, but a real column
(`reportid` already exists on the query) or a small mapping table would be clearer,
indexable, and FK-enforceable.

## Performance

### 7. `tablejson` rebuilt every form load, uncached
`classes/form/edit_query_form.php:86` loops `$DB->get_tables()` + `get_columns()` for
*every* table on each edit-page render. On a large Moodle (hundreds of tables) this is
heavy. `fkmap` is already version-cached (`build_fk_map`, `edit_query_form.php:185`) — give
`tablejson` the same treatment.

## i18n / standards

### 8. Hard-coded English in privacy export
`classes/privacy/provider.php:78` and `:83` use the literal strings `'Ad-hoc reports'`,
`'Queries'`, `'Audit log'` as export path keys. Should be `get_string()`.

## Testing

### 9. Thin coverage
Still only 3 unit files (validator, audience, transfer). No tests for the core `publish()`
lifecycle, `view::create_or_replace`, `auto_brace()` (complex tokeniser, untested directly),
or per-user filter scoping. The new event classes are also untested — no assertions that
`query_published` etc. fire with the right `objectid`/`other` payload
(`assertEventLegacyData` / `redirectEvents` pattern). No Behat.

Fix: add `auto_brace` data-provider tests, event-trigger tests via `redirectEvents()`, and a
publish → view → teardown integration test.

## New ideas

### 10. Repo hygiene: session scratch file at plugin root
`SESSION-report-visibility.md` is a dated working log ("Session log — report visibility
(2026-06-09)") sitting in the plugin root. It will ship in any release zip and confuse
moodle.org reviewers. Fold anything durable into `docs/` or CLAUDE.md and delete it.

### 11. SQL change history
Events record *who* changed a query and *when*, but not *what* the SQL was before. For a
plugin whose whole risk surface is the SQL text, storing the previous `querysql` (e.g. in
the `query_updated` event's `other` payload, or a small history table) would let an admin
answer "what did this report expose last month?". Cheap to add at the existing trigger
point (`query.php:373`/`:395`).

### 12. Fail fast on unsupported DB families
View column introspection special-cases Postgres (`classes/local/sql/view.php:149`) and
relies on native `get_columns()` for everything else — which is known-good on MySQL/MariaDB
but unverified on sqlsrv/Oracle. `privilege_check::probe()` (and ideally `settings.php`)
should detect an unsupported `$DB->get_dbfamily()` and warn up front instead of letting
publish fail mid-flow.

---

## Priority

Highest: **#1 (privacy delete)** — data-retention exposure; the log-table removal (#2, done)
has already shrunk it to the single `_query` retention question.

Bounded mechanical edits: #3, #8, #10. Need a design decision first: #1, #6, #11.
