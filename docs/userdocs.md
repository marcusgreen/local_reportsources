# Report Sources — User Documentation

## What it does

Report Sources lets you write a SQL `SELECT` query, turn it into a database view, and expose that view as a Moodle Report Builder data source. Once published, the view's columns appear in Report Builder, where you can build fully customisable reports: choose columns, add filters, sort, schedule exports, etc.

---

## Requirements

- Moodle 4.5 or later (Report Builder API).
- The Moodle database user must have `CREATE VIEW` and `DROP` privileges on the schema.  
  Run the privilege test from **Site admin → Report sources → Run database view privilege test** to confirm.

---

## Capabilities

| Capability | Purpose |
|---|---|
| `local/reportsources:author` | Write and save SQL queries |
| `local/reportsources:approve` | Publish / unpublish views |
| `local/reportsources:view` | Run published reports |
| `local/reportsources:viewall` | See all queries regardless of ownership |

Assign these to roles in **Site admin → Users → Permissions → Define roles**.

---

## Workflow

### 1. Create a report view

Go to **Report sources** (navigation link or `/local/reportsources/index.php`) and click **New report view**.

Fill in:

- **Name** — displayed as the report title in Report Builder.
- **Description** — optional notes.
- **SQL** — a single `SELECT` or `WITH … SELECT` statement (see [Writing SQL](#writing-sql)).
- **Row cap** — maximum rows the report will display (default from admin settings).

Click **Save**. The query is validated client-side and against the live database before saving.

### 2. Publish

On the index page, click **Publish** next to a draft query. Publishing:

1. Creates a database view (`mdl_local_reportsources_v_<id>`).
2. Introspects the view's columns and caches their types.
3. Creates a Report Builder custom report bound to the view.
4. Sets the view's status to **Published**.

If the SQL is invalid or produces duplicate column names, an error is shown and nothing is changed.

### 3. Build the report in Report Builder

After publishing, three links appear:

- **Edit in Report Builder** — opens the Report Builder editor where you add/remove columns, configure filters and conditions, set sorting, manage audiences, and schedule exports.
- **Run report** — opens the report in viewer mode to see data.
- **New report from this view** — creates an additional blank Report Builder report backed by the same view (useful for multiple report layouts from the same SQL).

In the Report Builder editor the left-hand panel lists all columns exposed by the view. Drag them in or click **Add column**.

### 4. Unpublish

Click **Unpublish** to drop the database view and remove the bound Report Builder report(s). The SQL is preserved as a draft and can be re-published at any time.

---

## Writing SQL

### Basics

- Must be a single `SELECT` or `WITH … SELECT`. No `INSERT`, `UPDATE`, `DELETE`, etc.
- Use Moodle table syntax: `{tablename}` (e.g. `{user}`, `{course}`). The plugin resolves these to prefixed table names at runtime.
- No semicolons.

### Always alias tables

`{user}` resolves to `mdl_user` at runtime. Use short aliases so column references are unambiguous:

```sql
SELECT u.id, u.firstname, u.lastname, u.email
FROM {user} u
WHERE u.deleted = 0
```

### Multi-table joins — avoid `SELECT *`

Database views cannot have duplicate column names. When joining tables that share a column (both have `id`, for example), use explicit aliases:

```sql
-- WRONG: both tables have 'id' — publish will fail
SELECT *
FROM {user} u
JOIN {forum_posts} fp ON fp.userid = u.id

-- CORRECT: alias every ambiguous column
SELECT u.id       AS userid,
       u.firstname,
       u.lastname,
       fp.id      AS postid,
       fp.subject,
       fp.created AS postcreated
FROM {user} u
JOIN {forum_posts} fp ON fp.userid = u.id
```

### CTEs (WITH … SELECT)

```sql
WITH active AS (
    SELECT id, firstname, lastname
    FROM {user}
    WHERE deleted = 0 AND suspended = 0
)
SELECT a.id, a.firstname, a.lastname, c.fullname AS course
FROM active a
JOIN {user_enrolments} ue ON ue.userid = a.id
JOIN {enrol} e            ON e.id = ue.enrolid
JOIN {course} c           ON c.id = e.courseid
```

### Moodle schema reference

Full ER diagram: [examulator.com/er](https://www.examulator.com/er)

---

## SQL editor

When **SQL syntax highlight and autocomplete** is enabled (Site admin → Report sources), the SQL field becomes a CodeMirror 6 editor with:

- Syntax highlighting (MySQL dialect).
- Keyword autocomplete — uppercase keywords.
- **Table name autocomplete** — type `{` to get a popup of all Moodle table names. The selected name is wrapped in `{curly braces}` automatically.
- **Column autocomplete** — type `{tablename}.` to see that table's column names.
- **Alias-aware column autocomplete** — write `FROM {user} u`, then type `u.` to see user columns.
- **Tab** accepts the highlighted completion. Space does not (so you can type aliases freely after a table name).

---

## Validation

Clicking **Save** triggers three checks in sequence:

1. **Static** — keyword denylist, single-statement check, SELECT-only enforcement.
2. **Live dry-run** — runs `<your SQL> LIMIT 0` against the real database to catch unknown tables or column names.
3. **View compatibility** — creates and immediately drops a test view to detect duplicate column names before publish.

Errors appear inline below the editor.

---

## Admin settings

**Site admin → Report sources**

| Setting | Default | Purpose |
|---|---|---|
| Default row cap | 5000 | Pre-filled row cap on new queries |
| Sensitive column denylist | (empty) | Comma-separated column names stripped from all introspected results (e.g. `password,secret`) |
| SQL syntax highlight and autocomplete | On | Enable/disable the CodeMirror editor |

---

## Database privilege check

The plugin requires the Moodle DB user to have `CREATE VIEW` and `DROP` on the Moodle schema.

Check: **Site admin → Report sources → Run database view privilege test**

On MySQL / MariaDB, grant the privileges:

```sql
GRANT CREATE VIEW, DROP ON moodle.* TO 'mdluser'@'localhost';
```

Replace `moodle`, `mdluser`, and `localhost` with your actual schema name, DB user, and host.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Publish fails with DDL error | DB user lacks CREATE VIEW privilege | Grant privileges (see above) |
| "Duplicate column name" on publish | `SELECT *` across joined tables with shared column names | Use explicit column aliases |
| Report shows no columns in Report Builder | Report opened outside the publish flow | Publish first, then use **Edit in Report Builder** |
| Columns not updating after SQL edit | Editing a published query reverts it to draft | Re-publish after editing |
| Autocomplete not appearing | Syntax highlight disabled, or JS cache stale | Enable setting, purge caches |
