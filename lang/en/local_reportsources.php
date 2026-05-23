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

For the Moodle database schema see <a href="https://www.examulator.com/er" target="_blank">examulator.com/er</a>.';
$string['rowcap'] = 'Row cap';
$string['rowcap_help'] = 'Maximum rows the report will display.';
$string['status'] = 'Status';
$string['status_draft'] = 'Draft';
$string['status_published'] = 'Published';
$string['status_disabled'] = 'Disabled';

$string['publish'] = 'Publish';
$string['unpublish'] = 'Unpublish';
$string['delete'] = 'Delete';
$string['edit'] = 'Edit';
$string['actions'] = 'Actions';
$string['owner'] = 'Owner';

$string['queries'] = 'Saved report views';
$string['noqueries'] = 'No report views yet.';

$string['errnotselect'] = 'Only SELECT queries are allowed.';
$string['errmultistatement'] = 'Multiple statements are not allowed.';
$string['errdeniedkeyword'] = 'Disallowed keyword: {$a}';
$string['errdeniedtable'] = 'Disallowed table: {$a}';
$string['warnmysqldatefn'] = 'MySQL-only function {$a} may not work on PostgreSQL. Use a cross-database equivalent.';
$string['errpgsqldatefn'] = 'PostgreSQL-only function {$a} is not supported by MySQL. Use a cross-database equivalent.';
$string['errcreateview'] = 'Could not create database view: {$a}';
$string['errduplicatecolumn'] = 'Joined tables share duplicate column names (e.g. both have "id"). Replace SELECT * with explicit column aliases: SELECT u.id AS userid, fp.id AS postid, ...';
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
