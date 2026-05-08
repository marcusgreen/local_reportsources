# local_reportsources — Report Sources

A Moodle local plugin that turns a SQL `SELECT` query into a Moodle Report Builder data source. Write SQL, publish it as a database view, then build and share fully-customisable reports without touching PHP.

## Requirements

- Moodle 4.5 – 5.0
- The Moodle database user must have `CREATE VIEW` and `DROP` privileges on the schema.

Verify the privilege from **Site admin → Local plugins → Report sources → Run database view privilege test**, or grant it manually:

```sql
-- MySQL / MariaDB
GRANT CREATE VIEW, DROP ON moodle.* TO 'mdluser'@'localhost';
```

## Installation

1. Copy the `reportsources` directory to `<moodleroot>/local/`.
2. Visit **Site admin → Notifications** to run the installer.
3. Run the privilege test (above) to confirm view creation works.

## Capabilities

| Capability | Default role | Purpose |
|---|---|---|
| `local/reportsources:author` | Manager | Write and save SQL queries |
| `local/reportsources:approve` | Manager | Publish / unpublish views |
| `local/reportsources:view` | Authenticated user | Run published reports |
| `local/reportsources:viewall` | Manager | See all queries regardless of ownership |

Assign via **Site admin → Users → Permissions → Define roles**.

## Usage

### 1. Create a report view

Navigate to **Report sources** and click **New report view**. Provide:

- **Name** — shown as the report title in Report Builder.
- **Description** — optional notes.
- **SQL** — a single `SELECT` or `WITH … SELECT` (see [Writing SQL](#writing-sql)).
- **Row cap** — maximum rows displayed.

### 2. Publish

Click **Publish** on a draft query. This creates a database view, introspects its columns, and generates a Report Builder custom report bound to that view.

### 3. Build in Report Builder

After publishing, use the action links:

- **Edit in Report Builder** — add columns, filters, sorting, audiences, and scheduled exports.
- **Run report** — open the report in viewer mode.
- **New report from this view** — create an additional Report Builder report backed by the same view.

### 4. Unpublish

Click **Unpublish** to drop the database view and remove bound Report Builder reports. The SQL is preserved as a draft.

## Writing SQL

- Single `SELECT` or `WITH … SELECT` only. No `INSERT`, `UPDATE`, `DELETE`, semicolons, or multiple statements.
- Use Moodle table syntax: `{tablename}` (e.g. `{user}`, `{course}`).
- Always alias tables — `{user}` resolves to `mdl_user` at runtime.
- Avoid `SELECT *` across joins; duplicate column names (both tables have `id`) cause publish to fail. Use explicit aliases instead:

```sql
SELECT
    u.id        AS userid,
    u.firstname,
    u.lastname,
    c.id        AS courseid,
    c.fullname  AS coursename
FROM {user} u
JOIN {user_enrolments} ue ON ue.userid = u.id
JOIN {enrol} e            ON e.id = ue.enrolid
JOIN {course} c           ON c.id = e.courseid
WHERE u.deleted = 0
```

The ER diagram for the Moodle database schema is at [examulator.com/er](https://www.examulator.com/er).

## Admin settings

**Site admin → Local plugins → Report sources**

| Setting | Description | Default |
|---|---|---|
| Default row cap | Default maximum rows for new queries | 5000 |
| Sensitive column denylist | Comma-separated column names stripped from introspection | `password,secret,sesskey` |
| SQL syntax highlight | Enable CodeMirror 6 editor with SQL autocomplete | On |

## License

GNU GPL v3 or later — see [COPYING](https://www.gnu.org/licenses/gpl-3.0.html).
