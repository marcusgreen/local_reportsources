# Plan: render the chart *through* the Report Builder API

## Goal
Today the chart is rendered on a standalone page (`chart.php`) and inside the companion
block (`block_reportsources`), both via `$OUTPUT->render_chart()` (client-side Chart.js).
The chart is **not** part of any Report Builder report.

Target: make the chart a first-class Report Builder artifact — an RB report whose **single
row / single cell** contains the whole graph as an image. Then the chart inherits everything
RB gives for free: the report viewer UI, context + audience access control, scheduling,
PDF/email export, and embedding via the existing block/report plumbing.

## Why do this — benefits and caveats
The chart already works today (`chart.php` + block, interactive Chart.js). The value of going
RB-native is **narrow but real** — it is an *enabling* change, not new on-screen capability.

**Benefits:**
- **Scheduled delivery** — RB reports email / export on a schedule; the chart now rides that
  pipe. `chart.php` never could. Biggest win.
- **One access model** — the chart report is gated by the same RB context + audience as the data
  report (`apply_report_visibility()`), so there is no second gate to keep in sync and fewer leak
  paths.
- **Embed anywhere RB embeds** — dashboards, `reportbuilder/view.php`, any block that takes a
  report id. The chart becomes a first-class report object, not a bespoke page.
- **No JS to view** — server-side SVG renders in email / PDF / no-script contexts; Chart.js
  cannot.
- **Free RB chrome** — permalink, report-management UI, view auditing via existing logging.

**Caveats:**
- **Not new for on-screen viewing** — `chart.php` / block already show a nicer *interactive*
  Chart.js chart; the SVG is static and plainer.
- **Spreadsheet export is ugly** — a CSV/Excel export of the chart report puts the raw base64
  blob in the cell. The chart report is a screen / PDF artifact only.
- **Unrealized until wired in** — phase 2 creates the report but no UI links to it yet
  (reachable only at `/reportbuilder/view.php?id=<chartreportid>`). Benefit lands at phase 4.
- **Two render paths** — Chart.js *and* server SVG coexist until phase 4 consolidates.

**Bottom line:** payoff = scheduling + unified access + embeddability. Thin if charts are never
scheduled / exported / embedded; otherwise this is the enabling change — worth completing only
if phase 4 (wire into the UI) is done too, else it is dead code.

## The crux: where do the image bytes come from?
`\core\chart_bar / chart_line / chart_pie` only **serialise to JSON** for the browser to draw
with Chart.js. Moodle core has **no** server-side chart→PNG/SVG rasterizer. So we cannot just
"ask Moodle for the PNG". Two ways to obtain image bytes:

| Path | How bytes are produced | Pros | Cons |
|---|---|---|---|
| **A. Client round-trip** | Reuse `chart_download.js` (`canvas.toDataURL('image/png')`), POST base64 to an AJAX endpoint, store in a table. | PNG looks identical to on-screen chart. | Chicken-and-egg: RB report is **empty/stale** until someone opens the chart page; scheduled export emails a stale or blank image. |
| **B. Server-side SVG** (recommended) | New small PHP SVG builder turns the same `labels`/`values` `chart.php` already computes into an `<svg>` string. | Self-contained: works in scheduled export / email / PDF with **no JS**, always current. | ~200–300 lines of hand-rolled SVG (bar/line/pie); no core helper. |

Recommendation: **Path B**. Going RB-native is only worth it if the chart survives export
and scheduling — Path A does not deliver that.

## Second decision: cache in a table, or generate live in the column callback?
The user's framing is "write base64 to a table, display a single row/cell". That splits into
the **generator** (fills the table) and the **display** (RB source reads one row). But the RB
column callback can just as easily generate the SVG **on the fly** per render, using the
existing `query::fetch_rows_for_viewer()`.

Critical wrinkle that decides this: **per-viewer scoping.** A query with `useridcolumn`,
`coursecolumn`, or `pagecoursecolumn` returns *different rows per viewer*. A single shared
cached image would leak/mismatch. So:

- **Shared queries** (no per-user/course filter) → one cached row is safe.
- **Scoped queries** → image depends on the viewer → must generate per viewer (live, or cache
  keyed by userid).

Cleanest: **generate live in the callback** for correctness (reuses `fetch_rows_for_viewer`,
inherently viewer-correct, zero staleness), and treat the table purely as an **optional cache**
for the shared, unscoped case (keyed by queryid, invalidated on publish). Start without the
table; add it only if profiling shows the SVG build is hot.

This plan documents the table design (per the request) **and** the live-callback variant, and
recommends live-first.

---

## Design

### Core precedent for the display mechanism (verified)
`reportbuilder/classes/local/entities/user.php:266-269` (core User entity, `picture` column)
proves the exact display contract this plan relies on:
- `add_callback(fn($value, $row) => $OUTPUT->user_picture($row, ...))` — callback returns **raw
  HTML** and RB does **not** escape it (callback owns safety).
- `->set_is_sortable(false)` — image columns can't be meaningfully sorted.
- `set_type()` left default (`TYPE_TEXT`) because the callback returns an HTML string.
- Data reaches the callback via `add_fields($userpictureselect)` → lands in `$row`.

**Key difference from user_picture (the custom part):** `user_picture` is **per-row** — one
avatar from *that row's* fields. Our chart is **one image aggregating ALL rows**, and a column
callback only ever receives a **single** `$row`. So we cannot pull N rows into one `$row` via
`add_fields`. Bridge: the `chart_query` base SQL yields **exactly one row** (the query record),
and the callback **ignores `$row` fields and fetches the dataset itself** via
`query::fetch_rows_for_viewer()`. Mechanically identical to user.php (callback → HTML img,
`set_is_sortable(false)`, default type); only the data source inside the callback differs
(in-callback fetch, not `add_fields`).

### New RB datasource + entity (the single-cell report)
Parallel to the existing data datasource, add a chart datasource that yields exactly one row.

- `classes/reportbuilder/source/chart_query.php` — mirror of `adhoc_query.php`, kept **outside**
  the `reportbuilder\datasource` namespace on purpose (same auto-discovery-hiding trick, see
  CLAUDE.md "Report Builder binding"). Base table: either the new cache table filtered to the
  queryid (Path B-cache) or a trivial 1-row `SELECT` (live variant).
- `classes/reportbuilder/local/entities/chart_view.php` — one column, `chart`, `TYPE_TEXT`,
  `set_is_sortable(false)`, with a `set_callback` that returns the **image HTML**
  (`<img src="data:image/svg+xml;base64,…">` or inline `<svg>`). RB does **not** escape column
  callback output (the callback owns safety — same contract core's user-picture column uses), so
  the markup renders. No filters/conditions.

### The image builder
- `classes/local/chart_svg.php` (new) — `render(string $type, array $labels, array $values,
  string $title): string` → SVG markup. Bar / line / pie / doughnut to match `chartmeta.type`.
  Fed by the *same* `$labels`/`$values` derivation already in `chart.php:104-133` — extract that
  into a reusable `query::chart_series()` so `chart.php`, the block, and this builder share one
  path (removes the current duplication between `chart.php` and `block_reportsources::render_chart`).
- Column callback: `$rows = $q->fetch_rows_for_viewer($rowlimit, $pagecourseid);` → build series →
  `chart_svg::render(...)` → base64 → `data:` URI. Viewer-correct because `fetch_rows_for_viewer`
  applies the per-user / teacher-course / page-course scoping.

### Optional cache table (Path B-cache, shared queries only)
`db/install.xml` new table `local_reportsources_chartimg`:
- `id`, `queryid` (FK), `imageformat` (`svg`/`png`), `imagedata` LONGTEXT (base64), `timemodified`.
- One row per **shared** query. Written at `query::publish()` and by a "Refresh chart" action;
  read by the chart source. Invalidate (delete row) on SQL edit / unpublish in `tear_down()`.
- **Never** cache scoped queries here — those stay live-in-callback.

### Report lifecycle / binding
- Reuse the existing one-query→many-reports machinery. `query::publish()` (or a new
  `create_chart_report()` alongside `create_additional_report()`) creates the chart RB report on
  the `chart_query` source, bound with its own `queryid_for_report_<rid>` config key, so
  `bound_report_ids()`, `tear_down()`, and `on_course_deleted()` already sweep it up — no new
  binding bookkeeping.
- `apply_report_visibility()` sets context (courseid) + audience on the chart report exactly as
  for the data report, so who-can-open stays in lockstep (CLAUDE.md "Report visibility").
- Only create the chart report when `chartmeta.type !== 'none'`; drop it if the author later
  sets type to none.

### Migrate the existing surfaces
- `chart.php` → either redirect to `/reportbuilder/view.php?id=<chartreportid>` or keep as-is and
  just add a link; simplest first cut: keep `chart.php`, add the RB chart report as an additional
  surface.
- `block_reportsources` chart mode → can point at the chart RB report id, or keep its live
  Chart.js render. Not required for phase 1.

---

## Caveats
- **RB tabular export** (CSV/Excel) of the chart report dumps the raw `<img>`/base64 into a cell —
  ugly but harmless; the chart report is meant for on-screen + PDF. Note in docs.
- **PNG fidelity**: server SVG will not be pixel-identical to Chart.js. Acceptable; it is the
  price of a JS-free, exportable chart.
- **Big base64** in a longtext / data-URI is fine for one cell; keep `rowlimit` capped (already
  1..5000) so the SVG stays bounded.
- Scoped queries cannot be pre-generated globally — enforced by live-in-callback (above).

## Phasing
1. **[DONE]** `chart_svg` builder + extract shared `query::chart_series()`; unit-test the builder.
2. **[DONE]** `chart_query` source + `chart_view` entity, **live-in-callback**, no table. Wired
   `create_chart_report()` (idempotent: reuse by source class; drop on "No chart") into `publish()`,
   reusing `apply_report_visibility()` and the `queryid_for_report_<rid>` binding so `tear_down()` /
   course-deletion already sweep it. Tests in `tests/chart_query_test.php`.
3. **[DONE]** Schema: added `chartreportid` column (+FK) to `local_reportsources_query`
   (`db/install.xml`, `db/upgrade.php` step `2026080300`, `version.php` → 0.1.9). `create_chart_report()`
   / `delete_chart_report()` keep it in sync; `chart_report_id()` prefers the column and falls back to
   the config-scan for pre-upgrade rows (`store_chart_report_id()` is the single writer). The SQL-edit
   teardown in `save()` nulls it alongside `reportid`. (Shared-query image **cache + Refresh** remains
   deferred — live-in-callback is correct and cheap; only add if generation profiles hot.)
4. **[DONE]** Point chart.php at the RB chart report; add a direct action.
   - `query::chart_report_id()` public; `chart.php` shows an **"Open as report (schedule / export)"**
     button → `/reportbuilder/view.php?id=<chartreportid>` when the chart report exists.
   - **Direct kebab action** in the system report (`queries.php`): `chartreportid` added as a base
     field, new action links straight to `/reportbuilder/view.php?id=:chartreportid`, shown when
     published and the chart report exists — no chart.php hop.
   - Deliberately **kept** the Chart.js render paths (chart.php / block) — a nicer *interactive*
     on-screen chart than the static SVG; the RB report is the schedulable / exportable / embeddable
     surface alongside it. Block chart-report deep-link still deferred (footer already links the data
     report).

## Files touched
- New: `classes/local/chart_svg.php`, `classes/reportbuilder/source/chart_query.php`,
  `classes/reportbuilder/local/entities/chart_view.php`, `tests/chart_svg_test.php`.
- Edit: `classes/local/query.php` (`chart_series()`, `create_chart_report()`, publish/tear_down/
  visibility hooks), `chart.php` + `block_reportsources.php` (dedupe series), `db/install.xml`
  + `db/upgrade.php` + `version.php` (only if adding the cache table), `lang/en/…`.
