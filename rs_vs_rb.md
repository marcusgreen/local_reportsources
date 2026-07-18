# PHP datasources vs SQL-published reports

`local_reportsources` can surface a Report Builder report two ways. This note explains
the difference, when to reach for each, and the trade-offs.

## The two mechanisms

### 1. SQL-published report (the plugin's core feature)

An author writes a `SELECT`, clicks **Publish**. The plugin:

1. Validates the SQL (static denylist + live dry-run).
2. Creates a MySQL/Postgres **VIEW** (`mdl_local_reportsources_v_<id>`) wrapping the SQL.
3. Introspects the view's columns, freezes them as `columnsmeta` JSON.
4. Registers a Report Builder report bound to the hidden `adhoc_query` datasource,
   which resolves the view at runtime.

The whole report shape — every column, every join — is **baked into the SQL** at publish
time. Report Builder sees one flat table (the view) with dynamically-typed columns.

### 2. PHP datasource (e.g. the new `grades` source)

A developer writes a `datasource` class + `entity` classes in
`classes/reportbuilder/`. Core Report Builder auto-discovers it and lists it in
**Reports → Report builder → New report**. The report author then picks columns,
filters, conditions and aggregations from the entities — Report Builder builds the SQL
per selection.

The new example: `local_reportsources\reportbuilder\datasource\grades` +
`...\local\entities\grade`. Gated by a settings checkbox via `is_available()`.

## Side-by-side

| Dimension | SQL-published | PHP datasource |
|---|---|---|
| Who builds it | Any author with the capability, no code | Developer, ships in the plugin |
| Time to first report | Minutes | Code + test + release cycle |
| Report shape | Frozen at publish (columns/joins baked into the view) | Dynamic — RB assembles SQL from picked columns |
| Joins | All joins always run, even for unused columns | Only tables for selected columns are joined |
| Column types | Guessed from DB introspection (`map_db_type`) | Declared explicitly (`TYPE_FLOAT`, `TYPE_TIMESTAMP`, …) |
| Filters | Generic, derived from column type | Typed, purpose-built (date range, select-from-list, number range) |
| Display formatting | Raw value, unless a `%%TIMESTAMP()%%` token is used | Full per-column callbacks (grade display, scales, links, badges) |
| Aggregation / card view | Limited | Full RB aggregation + conditions |
| Cross-DB | Author must handle dialects (helped by `%%…%%` tokens) | `$DB` builds dialect-correct SQL |
| DB privileges | Needs `CREATE VIEW` + `DROP` | None — no DDL |
| Reuse across reports | One view per query | One entity reused/joined across many reports |
| Security model | Visibility bolted on after (`apply_report_visibility`) | Context + capability joins native to the entity |
| Maintenance | Lives in the DB, editable in the UI | Lives in code, versioned in git |

## Why a PHP datasource wins (when it does)

- **Dynamic joins.** RB only joins the tables behind the columns the author actually
  picked. A view joins everything, every run — even columns nobody selected.
- **Correct display.** The `grades` source runs each grade through the gradebook display
  API so scales, letters, percentages and decimals render exactly as the gradebook does —
  while the raw `finalgrade` stays underneath so the column still **sorts numerically**.
  Raw SQL over a view would show a bare number and skip scale/letter rendering.
- **Security done right.** The grade source gates hidden grades behind
  `moodle/grade:viewhidden` at the course context, per row. A view exposes whatever the
  SQL selected, to whoever can open the report.
- **Typed filters & aggregation.** "Grade between 40 and 60", "item type = quiz",
  "average grade per course" — all first-class. Over a view you get generic
  type-guessed filters.
- **Reusable entities.** The core `user` and `course` entities plug straight in, bringing
  full-name formatting, profile fields, links — no duplication in each query.
- **No DDL, no view constraints.** No `CREATE VIEW`/`DROP` grant needed, and no
  duplicate-column-name failures that bite `SELECT *` across joins in a view.

## Why SQL-published wins (when it does)

- **No code, no deploy.** Author writes a `SELECT` and publishes. No PHP, no review, no
  version bump, no git, no upgrade step. A non-developer ships a live report.
- **Speed.** Idea → running report in minutes. A PHP source is new classes, tests and a
  release.
- **Arbitrary shapes.** Window functions, recursive CTEs, cross-schema or unusual joins
  that don't fit the entity model — raw SQL just expresses them.
- **Iteration.** Tweak the SQL, re-publish. A PHP source means a code change and redeploy.

## Rule of thumb

- **PHP datasource** for the *universal* gaps used forever by many sites — grades,
  activity completion, logs, quiz attempts. Worth the engineering: reusable, performant,
  dynamic, correct.
- **SQL-published** for the *long tail* — one-off, site-specific, ad-hoc questions where
  writing and shipping a PHP source would be overkill. Thousands of reports nobody will
  ever package.

They are complementary, not competing. Ship a handful of solid PHP sources for the common
cases; let authors self-serve everything else with SQL.

## The concrete example in this plugin

The new **Gradebook grades** datasource is the reference implementation:

- `classes/reportbuilder/datasource/grades.php` — the datasource. Lives in the
  `reportbuilder\datasource` namespace **on purpose** (unlike the plugin's hidden
  `adhoc_query` source) so core auto-discovery lists it.
- `classes/reportbuilder/local/entities/grade.php` — the entity: columns, typed filters,
  the grade-display callback and the hidden-grade gate.
- `is_available()` reads `local_reportsources/enablegradessource`, so an admin can switch
  the source off from **Site admin → Plugins → Local plugins → Report sources** without
  removing the class. Disabling only hides it from *new* report creation; reports already
  built on it keep working.

Contrast with a SQL-published "grades" report: it would be one frozen view joining
`grade_grades → grade_items → user → course`, showing raw `finalgrade` numbers, with no
scale rendering and no hidden-grade protection — fine for admin-only raw analytics, wrong
for teacher- or student-facing reports.
