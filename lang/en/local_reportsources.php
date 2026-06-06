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
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Report sources';
$string['reportsources'] = 'Report sources';
$string['reportsource'] = 'Report view';
$string['reportsourceheader'] = '{$a}';

$string['addnew'] = 'New report view';
$string['name'] = 'Name';
$string['description'] = 'Description';
$string['querysql'] = 'SQL (SELECT only)';
$string['querysql_help'] = 'A single SELECT or WITH...SELECT statement. Use Moodle table syntax (e.g. {course}). The plugin creates a database VIEW from this query and exposes its columns as a Reportbuilder source.

Always alias tables (e.g. FROM {user} u) since {user} resolves to mdl_user at runtime.

For the Moodle database schema see <a href="https://www.examulator.com/er/output/index.html" target="_blank">examulator.com/er</a>.

For sample queries and inspiration see <a href="https://docs.moodle.org/502/en/ad-hoc_contributed_reports" target="_blank">Moodle ad-hoc contributed reports</a>.';
$string['rowcap'] = 'Row cap';
$string['rowcap_help'] = 'Maximum rows the report will display.';
$string['status'] = 'Status';
$string['status_draft'] = 'Draft';
$string['status_published'] = 'Published';
$string['status_disabled'] = 'Disabled';

$string['publish'] = 'Publish';
$string['unpublish'] = 'Unpublish';
$string['delete'] = 'Delete';
$string['copy'] = 'Copy';
$string['copyof'] = 'Copy of {$a}';
$string['copysuccess'] = 'Report view copied. You are now editing the copy.';
$string['edit'] = 'Edit';
$string['actions'] = 'Actions';
$string['owner'] = 'Owner';

$string['queries'] = 'Saved report views';
$string['noqueries'] = 'No report views yet.';

$string['pluginexplained'] = 'About report sources';
$string['pluginexplained_help'] = 'This plugin lets you write a SQL SELECT query and publish it as a fully-configurable Report Builder report — no PHP required.

When you publish a query, the plugin creates a database VIEW from your SQL, reads its columns, and registers a Report Builder datasource pointing at that view. You can then build, filter and share the report like any other Report Builder report.

Only SELECT queries are allowed, and a denylist blocks access to sensitive tables. Editing the SQL of a published query rebuilds the view and report on the next publish.';

$string['errnotselect'] = 'Only SELECT queries are allowed.';
$string['errplaceholder'] = 'The SQL contains an unfilled placeholder "{$a}". Replace it with a real value before saving — e.g. change "l.userid = ##" to "l.userid = 2".';
$string['errquestionmark'] = 'SQL contains a ? character, which the database layer treats as a query parameter placeholder. If ? appears inside a URL string, replace it with CHAR(63) — e.g. CONCAT(\'…/view.php\', CHAR(63), \'id=\', course.id).';
$string['errmultistatement'] = 'Multiple statements are not allowed.';
$string['errdeniedkeyword'] = 'Disallowed keyword: {$a}';
$string['errdeniedtable'] = 'Disallowed table: {$a}';
$string['warnmysqldatefn'] = 'MySQL-only function {$a} may not work on PostgreSQL. Use a cross-database equivalent.';
$string['errpgsqldatefn'] = 'PostgreSQL-only function {$a} is not supported by MySQL. Use a cross-database equivalent.';
$string['errcreateview'] = 'Could not create database view: {$a}';
$string['errduplicatecolumn'] = 'Joined tables share duplicate column names (e.g. both have "id"). Replace SELECT * with explicit column aliases: SELECT u.id AS userid, fp.id AS postid, ...';
$string['errjoinnoon'] = 'A JOIN is missing its ON (or USING) condition. Each JOIN needs a join condition, e.g. JOIN {user_enrolments} ue ON ue.userid = u.id';
$string['errparse'] = 'The SQL could not be parsed: {$a}';
$string['errdropview'] = 'Could not drop database view: {$a}';

$string['runreport'] = 'Open report';
$string['editreport'] = 'Edit in Report Builder';
$string['newreport'] = 'New report from this view';

$string['privacy:metadata:query'] = 'Saved report views authored by users.';
$string['privacy:metadata:query:ownerid'] = 'User who authored the query.';
$string['privacy:metadata:query:querysql'] = 'The SQL of the query.';
$string['privacy:metadata:query:timecreated'] = 'When the query was created.';
$string['privacy:metadata:log'] = 'Audit log of query executions.';
$string['privacy:metadata:log:userid'] = 'User who ran or modified the query.';
$string['privacy:metadata:log:timeexecuted'] = 'Timestamp of the action.';

$string['settings:rowcapdefault'] = 'Default row cap';
$string['settings:rowcapdefault_desc'] = 'Default value for new queries.';
$string['settings:denycolumns'] = 'Sensitive column denylist';
$string['settings:denycolumns_desc'] = 'Comma-separated list of column names that will be stripped from any introspected SELECT result.';
$string['settings:syntaxhighlight'] = 'SQL syntax highlight and autocomplete';
$string['settings:syntaxhighlight_desc'] = 'Enable a CodeMirror 6 SQL editor on the query form. Suggests SQL keywords plus Moodle table and column names from the live database.';
$string['settings:aigenerate'] = 'AI SQL generation';
$string['settings:aigenerate_desc'] = 'Show an AI question box on the query edit form. Requires the local_sqlchat plugin to be installed and configured.';

$string['install:privilegeok'] = 'Report sources: the database user can create and drop views.';
$string['install:privilegefail'] = 'Report sources installed, but the database user cannot create or drop views. Publishing queries will fail until the grants are fixed. Error: {$a}';
$string['testview:title'] = 'Database view privilege test';
$string['testview:linklabel'] = 'Run database view privilege test';
$string['testview:ok'] = 'The database user can create and drop views. Publishing queries should work.';
$string['testview:fail'] = 'The database user cannot create or drop views. Error: {$a}';
$string['testview:grantshint'] = 'Grant the Moodle database user CREATE VIEW and DROP privileges on the schema (e.g. on MySQL/MariaDB: GRANT CREATE VIEW, DROP ON moodle.* TO \'mdluser\'@\'host\';).';

$string['reportsources:author'] = 'Author SQL report views';
$string['reportsources:approve'] = 'Approve and publish report views';
$string['reportsources:view'] = 'Run published report views';
$string['reportsources:viewall'] = 'View all report views regardless of audience';
$string['reportsources:viewown'] = 'Run report views in own course';

$string['visible'] = 'Visible';
$string['visible_help'] = 'Controls whether this published report appears in the query listing page. When unchecked, users with the view capability cannot see it. The underlying database view and report still exist — administrators and authors with the viewall capability can still see it.

For finer-grained access control, use the Audiences feature in Report Builder after publishing: open the report, go to the Audience tab, and restrict by cohort, role, or individual user.';

$string['cleanuplogs'] = 'Clean up report sources execution log';

$string['chartsettings'] = 'Chart settings';
$string['chartpublishrequired'] = 'Publish this query first to enable chart configuration.';
$string['charttype'] = 'Chart type';
$string['chartnone'] = 'No chart';
$string['chartbar'] = 'Bar chart';
$string['chartline'] = 'Line chart';
$string['chartpie'] = 'Pie chart';
$string['chartdoughnut'] = 'Doughnut chart';
$string['chartxcol'] = 'Label column (X axis / slices)';
$string['chartxcol_help'] = 'Column whose values label each bar, point, or pie slice.';
$string['chartycol'] = 'Value column (Y axis)';
$string['chartycol_help'] = 'Column whose values are plotted. Must contain numeric data.';
$string['chartrowlimit'] = 'Chart row limit';
$string['chartrowlimit_help'] = 'Maximum rows to plot. Keep small (≤ 200) for readable charts.';
$string['selectcolumn'] = '(select column)';
$string['viewchart'] = 'View chart';
$string['chartexportcsv'] = 'Export CSV';
$string['chartdownloadpng'] = 'Download PNG';
$string['chartprint'] = 'Print';
$string['errchartnotpublished'] = 'This query is not published. Publish it first before viewing the chart.';
$string['errchartnotconfigured'] = 'No chart is configured for this query. Edit the query to add chart settings.';

$string['ai:heading'] = 'Generate SQL with AI';
$string['ai:question'] = 'Describe the data you want';
$string['ai:placeholder'] = 'e.g. Show all students enrolled in more than 3 courses';
$string['ai:generate'] = 'Generate SQL';
$string['ai:latency'] = 'Generated in {$a} ms — review the SQL before saving.';
$string['ai:generatedname'] = 'Generated query';
