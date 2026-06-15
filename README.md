# Report Sources or

Reportbuilder ... the Sequel (Thanks to Adam Jenkins)

**Report Sources** lets you turn a SQL query into a fully configurable Moodle report — no programming required. Write your query, click **Publish**, and the plugin creates a Report Builder report your colleagues can run, filter, and export.

## Requirements

- Moodle 4.5 – 5.0
- Your Moodle database account needs permission to create and drop database views.

Your site administrator can check this from **Site admin → Local plugins → Report sources → Run database view privilege test**.

## Installation

1. Copy the `reportsources` folder into `<moodleroot>/local/`.
2. Go to **Site admin → Notifications** to complete the install.
3. Run the privilege test above to confirm everything is working.

## Who can do what

| Role | What they can do |
|---|---|
| **Manager** (site-wide) | Write queries, publish/unpublish reports, see all reports |
| **Authenticated user** | Run published reports they have been given access to |
| **Teacher** (within a course) | Run reports that have been published for their course |

Permissions are assigned at **Site admin → Users → Permissions → Define roles**.

## How to create a report

### Step 1 — Write your query

Go to **Report sources** in the site navigation (or open a course and look under the **More** menu for course-specific reports), then click **New report view**.

![The query edit form](docs/editform.png)

Fill in:

- **Name** — the title that will appear on the finished report.
- **Description** — optional notes for yourself or other authors.
- **SQL** — your query (see [Writing queries](#writing-queries) below). If the **AI SQL generation** feature is enabled, you can describe the report you want in plain English and have the SQL written for you — see [Generating SQL with AI](#generating-sql-with-ai).
- **Row cap** — the maximum number of rows to display. Prevents accidental very large result sets.
- **Visible** — when ticked, the report appears in the listing for anyone with access. Unticking hides it from the list without deleting it — useful while you are still refining the query. Administrators and managers can always see hidden reports.

Click **Save** to store it as a draft.

### Step 2 — Publish

When you are happy with the query, click **Publish**. The plugin runs the query, checks the columns, and creates a Report Builder report automatically.

> If the query has an error, you will see a message explaining what to fix before you can publish.

**Who can see the published report** is set for you at publish time, based on the report view's scope and the **Visible** setting:

| Report view | Who can open the report |
|---|---|
| Tied to a course | Participants enrolled in that course |
| Site-wide, Visible | All users on the site |
| Not visible (hidden) | Only you and site managers |

You can refine this any time on the **Audiences** tab in Report Builder (see Step 3) — for example to restrict a site-wide report to a specific role or cohort. Note that re-publishing the report view (or editing its SQL) resets the audience back to the default above, so re-apply any manual changes afterwards.

### Step 3 — Configure in Report Builder

After publishing, three action links appear:

- **Edit in Report Builder** — choose which columns to show, add filters, set sort order, and control who can see the report using the **Audiences** tab.
- **Run report** — open the report as your users will see it.
- **New report from this view** — create a second Report Builder report from the same underlying data (useful for different audiences or layouts).

#### Restricting a report to a role (e.g. Teacher)

To limit a report to a specific role, open **Edit in Report Builder → Audiences**, click **Add audience**, choose **Role**, and pick the role (for example **Teacher**). Only users holding that role will be able to open the report. Remember that re-publishing the report view (or editing its SQL) resets the audience to the default — re-apply the role restriction afterwards.

### Step 4 — Unpublish or edit

- **Unpublish** removes the live report. Your SQL is kept as a draft so you can re-publish later.
- Editing the SQL of a published report unpublishes it first — re-publish when you are ready to go live again.

## Generating SQL with AI

If your administrator has installed the **local_sqlchat** plugin and turned on **AI SQL generation** in Report Sources settings, a **Generate SQL with AI** panel appears at the top of the query editor.

Type a plain-English description of the data you want — for example:

> *Show all students enrolled in more than 3 courses*

Click **Generate SQL** and the AI writes a query for you. The result is loaded straight into the SQL editor so you can review and adjust it before saving. Always check the generated SQL makes sense for your data before publishing.

To enable this feature: install [local_sqlchat](https://github.com/marcusgreen/moodle-local_sqlchat), configure it with an API key, then go to **Site admin → Local plugins → Report sources** and turn on **AI SQL generation**.

---

## Writing queries

Queries must be a single `SELECT` (or `WITH … SELECT`). Updates, deletes, and multiple statements are not allowed.

**Table names** — you can write table names with or without braces. Both of these work:

```sql
SELECT * FROM user          -- braces added automatically on save
SELECT * FROM {user}        -- explicit braces also fine
```

**Joining tables** — always give tables a short alias, and name your columns explicitly when joining. If two tables both have a column called `id`, you must alias at least one of them or the report will fail to publish:

```sql
SELECT
    u.id        AS userid,
    u.firstname,
    u.lastname,
    c.id        AS courseid,
    c.fullname  AS coursename
FROM user u
JOIN user_enrolments ue ON ue.userid = u.id
JOIN enrol e            ON e.id = ue.enrolid
JOIN course c           ON c.id = e.courseid
WHERE u.deleted = 0
```

A searchable map of all Moodle database tables is at [examulator.com/er](https://www.examulator.com/er).

### Date function warnings

If you use date functions that only work on MySQL (e.g. `DATE_FORMAT`, `DAYOFWEEK`) or only on PostgreSQL (e.g. `DATE_TRUNC`), you will see a warning. The query will still save, but it may stop working if your site ever moves to a different database engine. The warning is a heads-up, not a block.

For the common case of turning a stored Unix-epoch column into a date, use the cross-database placeholders instead of a database-specific function. `%%TIMESTAMP(expr)%%` marks `expr` as a date column — it sorts chronologically and displays as `ddd-mmm-yyyy` by default, or pass your own format, e.g. `%%TIMESTAMP(timecreated, dd/mm/yyyy)%%`. `%%NOW%%` gives the current Unix time for date-window filters (e.g. `WHERE lastlogin > %%NOW%% - (120 * 86400)`). These work on whichever database the site runs on. See the [user docs](docs/userdocs.md#placeholders) for details.

## Admin settings

**Site admin → Local plugins → Report sources**

| Setting | What it does | Default |
|---|---|---|
| Default row cap | Starting limit for new queries | 5000 |
| Sensitive column block list | Column names never exposed in reports (comma-separated) | `password,secret,sesskey` |
| SQL syntax highlight | Enables the code editor with SQL colouring and autocomplete | On |
| AI SQL generation | Shows the AI query-generation panel on the edit form. Requires the **local_sqlchat** plugin to be installed and configured. | Off |

## License

GNU GPL v3 or later — see [COPYING](https://www.gnu.org/licenses/gpl-3.0.html).
