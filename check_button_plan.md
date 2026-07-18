# Plan — "Check" button (report-source feedback)

Add a **Check** button to the query edit form that gives the author actionable
feedback on a report source, using only the Moodle DB API (`$DB`).

## Three checks

### 1. Date-like columns → suggest `%%TIMESTAMP()%%`
- Build the probe VIEW (reuse `validate_sql` create/drop pattern), introspect
  with `view::columns()`.
- Candidate column = `meta_type` Integer **AND** name matches
  `/time|date|created|modified|start|end|expiry|lastaccess|due/i` **AND** not
  already recovered by `view::timestamp_columns($sql)`.
- Strengthen with one sampled row (`get_records_sql(... LIMIT 1)`): int value
  that looks like a plausible epoch (~9–10 digits, plausible year) → higher
  confidence.
- Emit: "Column `X` looks like a date — wrap its expression in
  `%%TIMESTAMP(expr)%%` for a sortable formatted date."

### 2. Record count
- `$DB->count_records_sql("SELECT COUNT(*) FROM (<resolved>) rs_count")`.
- Report the number. Threshold warn (>50k) → "large result, add filters or a
  LIMIT; report may render slowly."

### 3. Indexes / slow report — **Portable + EXPLAIN**
- Referenced base tables extracted by regex `/\{(\w+)\}/` on the auto-braced
  validated SQL (braces are explicit → reliable).
- Per table: `$DB->get_indexes($table)` + `$DB->count_records($table)` →
  list indexes + row count.
- Deeper flag: `$DB->get_records_sql("EXPLAIN <resolved>")`, branch on
  `$DB->get_dbfamily()`:
  - MySQL/MariaDB — flag rows with `type=ALL`, null `key`, high `rows`.
  - Postgres — best-effort parse of the plan text for `Seq Scan`.
  - Other families — skip EXPLAIN, keep portable listing.

## Out of scope (not DB API)
No `SHOW PROFILE`, no query timing, no raw `information_schema` beyond what
`get_indexes()`/`get_columns()` wrap. EXPLAIN is the boundary — raw SQL through
`$DB`, dialect-specific.

## Files

| File | Change |
|---|---|
| `classes/local/sql/analyser.php` | new — logic (dates/count/index), testable |
| `classes/external/check_query.php` | new — thin AJAX wrapper (read, cap author) |
| `db/services.php` | register `local_reportsources_check_query` |
| `amd/src/check.js` (+ build) | Check button wiring + results render |
| `classes/form/edit_query_form.php` | Check button + results div + js init |
| `lang/en/local_reportsources.php` | strings |
| `tests/analyser_test.php` | PHPUnit (date detection + row count) |
| `version.php` | bump (new WS only registers after upgrade) |

## Return structure
```
{ ok:bool, error:str, rowcount:int, suggestions:[str], warnings:[str], indexinfo:[str] }
```
