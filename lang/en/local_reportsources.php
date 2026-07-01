<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English language strings for the Report sources plugin.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['actionsfor'] = 'Actions for {$a}';
$string['addnew'] = 'New report source';
$string['ai:generate'] = 'Generate SQL';
$string['ai:generatedname'] = 'Generated query';
$string['ai:generating'] = 'Generating…';
$string['ai:heading'] = 'Generate SQL with AI';
$string['ai:heading_help'] = 'Describe the data you want in plain English, then click **Generate SQL**. The AI writes a SELECT query into the SQL editor below.

For example: "Show all students enrolled in more than 3 courses".

You can also refer to the SQL already in the editor — prompts like "add a column to this", "also show the email address", or "fix this error" use your current query as the starting point rather than building a new one from scratch.

In particular, starting your prompt with the word **also** pulls in your existing SQL and builds on it — for example "also show the user\'s last login" adds to the current query instead of replacing it.

Always review the generated SQL before saving — the AI can make mistakes.';
$string['ai:latency'] = 'Generated in {$a} ms — review the SQL before saving.';
$string['ai:prompt'] = 'Prompt sent to the LLM';
$string['ai:placeholder'] = 'e.g. Show all students enrolled in more than 3 courses';
$string['ai:question'] = 'Describe the data you want';
$string['ai:sqldescription'] = 'Selects {$a->columns} from {$a->tables}.';
$string['ai:sqldescriptionnocols'] = 'Report over {$a}.';
$string['ai:sqlname'] = '{$a} report';
$string['audienceallusers'] = 'All site users';
$string['audiencecohort'] = 'Members of cohorts';
$string['audiencecohorts'] = 'Cohorts';
$string['audiencecourseparticipant'] = 'Course participants';
$string['audiencecourseparticipantdesc'] = 'Users with an active enrolment in the report\'s course.';
$string['audiencecourserole'] = 'Users with a role in the course';
$string['audiencecourseroledesc'] = 'Users holding one of the chosen roles in the report\'s course (or an ancestor context).';
$string['audiencedefault'] = 'Automatic (based on course and visibility)';
$string['audiencenone'] = 'Nobody (only you and site managers)';
$string['audienceroles'] = 'Roles';
$string['audiencesettings'] = 'Who can view the report';
$string['audiencetype'] = 'Audience';
$string['audiencetype_help'] = 'Controls who can open the published Report Builder report.

* **Automatic** — derived from the settings above: a course-scoped report is shown to that course\'s participants, a site-wide report to all users, and a hidden report only to you and site managers.
* **Course participants / Users with a role in the course** — require a course scope to be set above.
* **All site users**, **Members of cohorts**, **Nobody** — apply site-wide.

You can refine the audience further on the Audiences tab in Report Builder, but re-publishing the report resets it to this choice.';
$string['bulkactions'] = 'Bulk actions';
$string['chartbar'] = 'Bar chart';
$string['chartdoughnut'] = 'Doughnut chart';
$string['chartdownloadpng'] = 'Download PNG';
$string['chartexportcsv'] = 'Export CSV';
$string['chartline'] = 'Line chart';
$string['chartnone'] = 'No chart';
$string['chartpie'] = 'Pie chart';
$string['chartprint'] = 'Print';
$string['chartpublishrequired'] = 'Publish this query first to enable chart configuration.';
$string['chartrowlimit'] = 'Chart row limit';
$string['chartrowlimit_help'] = 'Maximum rows to plot. Keep small (≤ 200) for readable charts.';
$string['chartsettings'] = 'Chart settings';
$string['charttype'] = 'Chart type';
$string['chartxcol'] = 'Label column (X axis / slices)';
$string['chartxcol_help'] = 'Column whose values label each bar, point, or pie slice.';
$string['chartycol'] = 'Value column (Y axis)';
$string['chartycol_help'] = 'Column whose values are plotted. Must contain numeric data.';
$string['confirmdeletemany'] = 'Are you sure you want to delete these {$a} report source(s)? This drops each backing view and report and cannot be undone.';
$string['copyof'] = 'Copy of {$a}';
$string['copysuccess'] = 'Report source copied. You are now editing the copy.';
$string['coursecolumn'] = 'Restrict to courses the viewer teaches';
$string['coursecolumn_help'] = 'Optionally scope this report so each viewer sees only rows for courses they teach. Pick the output column holding a course id; at view time the report shows only rows where that column is one of the courses where the viewer has an editing teacher or teacher role.

A viewer who teaches no courses sees no rows. This lets you publish a single report to a wide audience (for example all staff) while each teacher still sees only their own courses. Leave as "Choose a column…" for no teacher-course filter.';
$string['pagecoursecolumn'] = 'Restrict to the course the block is on';
$string['pagecoursecolumn_help'] = 'Applies only when this report is shown through the Report sources block on a course page. Pick the output column holding a course id; the block then shows only rows for the course of the page it sits on, so one block (or a block added to every course) shows each course its own data.

Off a course page (Dashboard or the site front page) no page-course filter is applied. The standalone report viewer also ignores this, since it has no "current course". Leave as "Choose a column…" for no page-course filter.';
$string['coursescope'] = 'Course scope';
$string['coursescope_help'] = 'The course this report belongs to. Leave empty for a site-wide report.

The course determines two things when the report is published: the context its "View report" permission is checked in, and its default audience (course participants for a course-scoped report, all users for a site-wide one).

Change this to re-scope a query — for example an imported draft that was set site-wide because its original course did not exist on this site. You can only choose courses you are allowed to view reports in.';
$string['createrole:approve'] = 'Include "Approve and publish"';
$string['createrole:approve_desc'] = 'Also grant local/reportsources:approve, so holders can publish and unpublish report sources themselves. Leave unticked if a separate approver should publish their drafts.';
$string['createrole:aigenerate'] = 'Include "AI SQL generation"';
$string['createrole:aigenerate_desc'] = 'Also grant local/sqlchat:use, so holders can use the AI question box to generate SQL. Only shown when the local_sqlchat plugin is installed. Leave unticked if authors should write SQL themselves.';
$string['createrole:author'] = 'Author report sources';
$string['createrole:author_desc'] = 'Always included: local/reportsources:author lets holders write and save report sources (the purpose of the role). Also always granted are moodle/reportbuilder:view, moodle/reportbuilder:viewall and moodle/reportbuilder:editall, so holders can open and edit any published report at /reportbuilder/view.php regardless of its audience or owner.';
$string['createrole:create'] = 'Create role';
$string['createrole:done'] = 'The "Report author" role was created. Assign people to it below.';
$string['createrole:exists'] = 'A "Report author" role already exists. Submitting this form will update its capabilities to match your selection below.';
$string['createrole:intro'] = 'This creates a system-level role bundling the report-source capabilities, so you can let trusted non-administrators author reports without making them full site managers. Choose which capabilities to include, then create the role and assign people to it.';
$string['createrole:linklabel'] = 'Create the "Report author" role';
$string['createrole:title'] = 'Create the "Report author" role';
$string['createrole:updated'] = 'The "Report author" role capabilities were updated. Assign people to it below.';
$string['createrole:viewall'] = 'Include "View all report sources"';
$string['createrole:viewall_desc'] = 'Also grant local/reportsources:viewall, so holders can see and manage everyone\'s report sources, not only their own.';
$string['createrole:warning'] = 'Authoring a report means writing an arbitrary SQL SELECT, which can read almost any table in the database (only a small denylist such as config, sessions and password tables is blocked). This role is therefore effectively a site-wide data-read grant. Assign it only to people you would trust with direct read access to the database, and confirm any sensitive columns are covered by the column denylist in the plugin settings.';
$string['crimport:colname'] = 'Report';
$string['crimport:colnotes'] = 'Changes applied';
$string['crimport:colreason'] = 'Reason';
$string['crimport:coltype'] = 'Type';
$string['crimport:importableheading'] = 'Importable reports';
$string['crimport:importselected'] = 'Import selected';
$string['crimport:intro'] = 'These are the SQL reports found in the Configurable Reports block. Importable reports translate cleanly and will be created as drafts owned by you, ready to publish. Rejected reports use features that cannot be converted automatically — port those by hand.';
$string['crimport:linklabel'] = 'Import from Configurable Reports';
$string['crimport:noneimportable'] = 'No Configurable Reports SQL reports could be translated automatically. See the rejected list below for why.';
$string['crimport:noneselected'] = 'No reports were selected.';
$string['crimport:noteclean'] = 'No changes needed';
$string['crimport:notedatefn'] = 'Rewrote MySQL date function(s) to portable %%TIMESTAMP%% / %%EPOCH%% / %%NOW%% tokens';
$string['crimport:noteqmark'] = 'Rewrote literal ? in a string to chr(63)';
$string['crimport:notequotes'] = 'Converted "double-quoted" string literals to \'single-quoted\'';
$string['crimport:notetoken'] = 'Substituted Configurable Reports token {$a}';
$string['crimport:reasondatefn'] = 'Uses MySQL-only date function {$a} that has no portable equivalent';
$string['crimport:reasonfilter'] = 'Uses an interactive filter token {$a}; rebuild this as a Report Builder filter after importing';
$string['crimport:reasonnosql'] = 'No SQL could be decoded from this report';
$string['crimport:reasonnotsql'] = 'Not a SQL report (type: {$a})';
$string['crimport:reasontoken'] = 'Uses an unsupported token {$a}';
$string['crimport:reasonuserid'] = 'Uses {$a}; use the "Restrict to viewing user" setting on the imported draft instead';
$string['crimport:rejectedheading'] = 'Rejected reports';
$string['crimport:title'] = 'Import from Configurable Reports';
$string['crimport:title_help'] = 'Imports the SQL reports stored in the Configurable Reports block (block_configurable_reports) as draft report sources.

Each report is decoded and run through a fixed translation: MySQL date functions become portable %%TIMESTAMP%% / %%EPOCH%% / %%NOW%% tokens, double-quoted strings become single-quoted, and a literal ? in a string is rebuilt with chr(63). Reports using features that cannot be converted (such as %%USERID%% or interactive %%FILTER%% tokens) are listed as rejected with a reason.

Imported reports land as drafts owned by you and must be published before they go live. No AI is used — every conversion is a fixed rule.';
$string['crimport:unavailable'] = 'The Configurable Reports block (block_configurable_reports) is not installed, so there is nothing to import.';
$string['delete'] = 'Delete';
$string['deleteselected'] = 'Delete selected';
$string['deleteselecthelp'] = 'Tick the report sources to delete. Deleting drops each backing database view and report and cannot be undone.';
$string['description'] = 'Description';
$string['duplicate'] = 'Duplicate';
$string['edit'] = 'Edit';
$string['editfor'] = 'Edit: {$a}';
$string['editreport'] = 'Edit in Report Builder';
$string['erraudiencecohortsempty'] = 'Choose at least one cohort.';
$string['erraudiencecourse'] = 'This audience applies to a course. Choose a course scope above before selecting it.';
$string['erraudiencerolesempty'] = 'Choose at least one role.';
$string['errchartdata'] = 'The report data for this chart could not be loaded. Contact the report owner if this persists.';
$string['errchartnotconfigured'] = 'No chart is configured for this query. Edit the query to add chart settings.';
$string['errchartnotpublished'] = 'This query is not published. Publish it first before viewing the chart.';
$string['errcourseidplaceholder'] = 'The SQL uses %%COURSEID%%, so this report needs a fixed course scope. Choose a course above before saving — or, to show each course its own data in a block, remove the %%COURSEID%% filter from the SQL, output the course id column, and set "Restrict to the course the block is on" instead.';
$string['errcreateview'] = 'Could not create database view: {$a}';
$string['errdeniedcolumn'] = 'Disallowed column: {$a}';
$string['errdeniedkeyword'] = 'Disallowed keyword: {$a}';
$string['errdeniedtable'] = 'Disallowed table: {$a}';
$string['errdropview'] = 'Could not drop database view: {$a}';
$string['errduplicatecolumn'] = 'Joined tables share duplicate column names (e.g. both have "id"). Replace SELECT * with explicit column aliases: SELECT u.id AS userid, fp.id AS postid, ...';
$string['errgroupnotconfigured'] = 'No grouping is configured for this query. Edit the query, publish it, then choose a break column under Grouping.';
$string['errgroupnotpublished'] = 'This query is not published. Publish it first before viewing the grouped report.';
$string['errimportempty'] = 'The export file contains no report sources.';
$string['errimportformat'] = 'This file is not a valid Report sources export.';
$string['errjoinnoon'] = 'A JOIN is missing its ON (or USING) condition. Each JOIN needs a join condition, e.g. JOIN {user_enrolments} ue ON ue.userid = u.id';
$string['errmultistatement'] = 'Multiple statements are not allowed.';
$string['errnodeleteselection'] = 'Select at least one report source to delete.';
$string['errnoexportselection'] = 'Select at least one report source to export.';
$string['errnoimportselection'] = 'Select at least one report source to import.';
$string['errnotselect'] = 'Only SELECT queries are allowed.';
$string['errparse'] = 'The SQL could not be parsed: {$a}';
$string['errpgsqldatefn'] = 'PostgreSQL-only function {$a} is not supported by MySQL. Use a cross-database equivalent.';
$string['errplaceholder'] = 'The SQL contains an unfilled placeholder "{$a}". Replace it with a real value before saving — e.g. change "l.userid = ##" to "l.userid = 2".';
$string['errplaceholderuserid'] = 'The SQL contains "{$a}", which is not a supported placeholder. There is no per-viewer placeholder because the report runs from a fixed database view. To restrict the report to the rows for whoever opens it, remove "{$a}" from the SQL, select the user-id column in the "Restrict to viewing user" field at the end of this form, and the per-user filter is applied automatically at run time.';
$string['errquestionmark'] = 'SQL contains a ? character, which the database layer treats as a query parameter placeholder. If ? appears inside a URL string, replace it with CHAR(63) — e.g. CONCAT(\'…/view.php\', CHAR(63), \'id=\', course.id).';
$string['event:querycreated'] = 'Ad-hoc query created';
$string['event:querydeleted'] = 'Ad-hoc query deleted';
$string['event:querypublished'] = 'Ad-hoc query published';
$string['event:queryunpublished'] = 'Ad-hoc query unpublished';
$string['event:queryupdated'] = 'Ad-hoc query updated';
$string['export'] = 'Export';
$string['exportselected'] = 'Export selected';
$string['exportselecthelp'] = 'Tick the report sources to include in the export file, then download the JSON.';
$string['formatsql'] = 'Format SQL';
$string['formatsqltooltip'] = 'Reformat SQL to standard layout (Shift+Ctrl+F)';
$string['groupbreakcol'] = 'Break on column';
$string['groupbreakcol_help'] = 'The column that starts a new group each time its value changes. Rows must be about the same thing per group — e.g. break on a user id so each user gets one header line followed by their rows. Leave as "Choose a column" to turn grouping off.';
$string['groupdetailcols'] = 'Detail line columns';
$string['groupdetailcols_help'] = 'Columns shown on each detail line beneath a group header — e.g. the course name. Leave empty to show every column that is not on the header line.';
$string['groupedview'] = 'Grouped view';
$string['groupexportexcel'] = 'Export spreadsheet';
$string['groupheadercols'] = 'Header line columns';
$string['groupheadercols_help'] = 'Columns shown once per group on the header line — e.g. first name and last name. Leave empty to use the break column itself.';
$string['groupnorows'] = 'This report returned no rows.';
$string['groupperpage'] = 'Groups per page';
$string['groupperpage_help'] = 'How many groups (e.g. users) to show per page in the grouped view. The pager appears once the report has more groups than this. Each group keeps all its detail rows together on the same page.';
$string['grouprowlimit'] = 'Row limit';
$string['grouprowlimit_help'] = 'Maximum rows to read from the report before grouping. Increase for reports with many groups.';
$string['groupsettings'] = 'Grouping';
$string['import'] = 'Import';
$string['importdemoted'] = 'Set to site-wide because their course was not found on this site. Edit each draft and set its Course scope before publishing: {$a}.';
$string['importdone'] = 'Imported {$a} report source(s) as drafts.';
$string['importfile'] = 'Export file';
$string['importselected'] = 'Import selected';
$string['importselecthelp'] = 'Tick the report sources to import. Each is created as a new draft owned by you and must be published before use.';
$string['importskipped'] = 'Skipped (failed SQL validation): {$a}.';
$string['importupload'] = 'Upload and choose';
$string['importuploadhelp'] = 'Upload a JSON file previously produced by the Export action. You will then choose which report sources to import.';
$string['install:createrole'] = 'Optionally create a "Report author" role so non-administrators can author reports. Review the security implications first: {$a}';
$string['install:loadsamples'] = 'Report sources ships sample report sources you can load to get started: {$a}';
$string['install:privilegefail'] = 'Report sources installed, but the database user cannot create or drop views. Publishing queries will fail until the grants are fixed. Error: {$a}';
$string['install:privilegeok'] = 'Report sources: the database user can create and drop views.';
$string['lastmodified'] = 'Last modified';
$string['name'] = 'Name';
$string['newreport'] = 'New report from this source';
$string['noqueries'] = 'No report sources yet.';
$string['owner'] = 'Owner';
$string['pluginexplained'] = 'About report sources';
$string['pluginexplained_help'] = 'This plugin lets you write a SQL SELECT query and publish it as a fully-configurable Report Builder report — no PHP required.

When you publish a query, the plugin creates a database VIEW from your SQL, reads its columns, and registers a Report Builder datasource pointing at that view. You can then build, filter and share the report like any other Report Builder report.

Only SELECT queries are allowed, and a denylist blocks access to sensitive tables. Editing the SQL of a published query rebuilds the view and report on the next publish.';
$string['pluginname'] = 'Report sources';
$string['privacy:metadata:query'] = 'Saved report sources authored by users.';
$string['privacy:metadata:query:ownerid'] = 'User who authored the query.';
$string['privacy:metadata:query:querysql'] = 'The SQL of the query.';
$string['privacy:metadata:query:timecreated'] = 'When the query was created.';
$string['publish'] = 'Publish';
$string['queries'] = 'Saved report sources';
$string['querysql'] = 'SQL (SELECT only)';
$string['querysql_help'] = 'A single SELECT or WITH...SELECT statement. Use Moodle table syntax (e.g. {course}). The plugin creates a database VIEW from this query and exposes its columns as a Reportbuilder source.

Always alias tables (e.g. FROM {user} u) since {user} resolves to mdl_user at runtime.

For the Moodle database schema see <a href="https://www.examulator.com/er/output/index.html" target="_blank">examulator.com/er</a>.

For sample queries and inspiration see <a href="https://docs.moodle.org/502/en/ad-hoc_contributed_reports" target="_blank">Moodle ad-hoc contributed reports</a>.';
$string['reportsource'] = 'Report source';
$string['reportsourcecount'] = 'Showing {$a} report source(s).';
$string['reportsourceheader'] = '{$a}';
$string['reportsources'] = 'Report sources';
$string['reportsources:approve'] = 'Approve and publish report sources';
$string['reportsources:author'] = 'Author SQL report sources';
$string['reportsources:view'] = 'Run published report sources';
$string['reportsources:viewall'] = 'View all report sources regardless of audience';
$string['reportsources:viewown'] = 'Run report sources in own course';
$string['roledescription'] = 'Create, edit and publish report sources (local_reportsources) site-wide. NOTE: authoring allows arbitrary SQL SELECT against the database, so this role grants effectively site-wide data read. Assign only to trusted report builders.';
$string['rolename'] = 'Report author';
$string['runreport'] = 'Open report';
$string['runreportfor'] = 'Open report: {$a}';
$string['samples:duplicates'] = 'Skipped (already present): {$a}.';
$string['samples:intro'] = '{$a} sample report sources are bundled with this plugin. Loading creates each as a draft owned by you that you must publish before use.';
$string['samples:linklabel'] = 'Load sample report sources';
$string['samples:load'] = 'Load samples';
$string['samples:none'] = 'No bundled sample report sources were found.';
$string['samples:title'] = 'Load sample report sources';
$string['saveandpublish'] = 'Save and publish';
$string['savedandpublished'] = 'Changes saved and report published';
$string['savedpublishfailed'] = 'Changes saved, but publishing failed: {$a}';

$string['schedule'] = 'Schedule emails';
$string['selectcolumn'] = '(select column)';
$string['settings:aigenerate'] = 'AI SQL generation';
$string['settings:aigenerate_desc'] = 'Show an AI question box on the query edit form. Requires the local_sqlchat plugin to be installed and configured.';
$string['settings:denycolumns'] = 'Sensitive column denylist';
$string['settings:denycolumns_desc'] = 'Comma-separated list of column names that will be stripped from any introspected SELECT result.';
$string['settings:showlastmodified'] = 'Show last modified column';
$string['settings:showlastmodified_desc'] = 'Show a sortable "Last modified" column in the report sources list.';
$string['settings:syntaxhighlight'] = 'SQL syntax highlight and autocomplete';
$string['settings:syntaxhighlight_desc'] = 'Enable a CodeMirror 6 SQL editor on the query form. Suggests SQL keywords plus Moodle table and column names from the live database.';
$string['status'] = 'Status';
$string['status_disabled'] = 'Disabled';
$string['status_draft'] = 'Draft';
$string['status_published'] = 'Published';

$string['testview:fail'] = 'The database user cannot create or drop views. Error: {$a}';
$string['testview:grantshint'] = 'Grant the Moodle database user CREATE VIEW and DROP privileges on the schema (e.g. on MySQL/MariaDB: GRANT CREATE VIEW, DROP ON moodle.* TO \'mdluser\'@\'host\';).';
$string['testview:linklabel'] = 'Run database view privilege test';
$string['testview:ok'] = 'The database user can create and drop views. Publishing queries should work.';
$string['testview:title'] = 'Database view privilege test';
$string['tourdesc'] = 'A short guided tour of the report sources list page.';
$string['tourname'] = 'Report sources tour';
$string['tourstep1content'] = 'Start here to create a report source. Write a SQL <em>SELECT</em> query, then publish it to build a fully configurable Report Builder report — no PHP required.';
$string['tourstep1title'] = 'Create a report source';
$string['tourstep2content'] = 'Every report source you have saved is listed here, with its owner and status.';
$string['tourstep2title'] = 'Your report sources';
$string['tourstep3content'] = 'The status shows whether a report source is still a <strong>Draft</strong> or has been <strong>Published</strong> as a live report.';
$string['tourstep3title'] = 'Draft or published';
$string['tourstep4content'] = 'Open the live Report Builder report to view, filter, sort and export its data.';
$string['tourstep4title'] = 'Open the report';
$string['tourstep5content'] = 'This menu holds the rest of the actions: edit in Report Builder, schedule emails, duplicate, publish and delete.';
$string['tourstep5title'] = 'More actions';
$string['unpublish'] = 'Unpublish';
$string['unpublishfor'] = 'Unpublish: {$a}';




$string['useridcolumn'] = 'Restrict to viewing user';
$string['useridcolumn_help'] = 'Optionally scope this report so each person sees only rows that belong to them. Pick the output column holding a user id; at view time the report shows only rows where that column equals the id of the logged-in user. Leave as "Choose a column…" to show all rows to everyone in the audience.';
$string['useridfilter'] = 'Per-user filter';
$string['userdocs'] = 'User documentation';
$string['viewchart'] = 'View chart';
$string['visible'] = 'Visible';
$string['visible_help'] = 'Controls whether this published report appears in the query listing page. When unchecked, users with the view capability cannot see it. The underlying database view and report still exist — administrators and authors with the viewall capability can still see it.

For finer-grained access control, use the Audiences feature in Report Builder after publishing: open the report, go to the Audience tab, and restrict by cohort, role, or individual user.';
$string['warnmysqldatefn'] = 'MySQL-only function {$a} may not work on PostgreSQL. Use a cross-database equivalent.';
