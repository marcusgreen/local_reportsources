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

declare(strict_types=1);

namespace local_reportsources\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\{
    approved_contextlist,
    approved_userlist,
    contextlist,
    userlist,
    writer,
};

/**
 * Privacy provider for ad-hoc reports.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_reportsources_query', [
            'ownerid'      => 'privacy:metadata:query:ownerid',
            'querysql'     => 'privacy:metadata:query:querysql',
            'timecreated'  => 'privacy:metadata:query:timecreated',
        ], 'privacy:metadata:query');

        $collection->add_database_table('local_reportsources_log', [
            'userid'       => 'privacy:metadata:log:userid',
            'timeexecuted' => 'privacy:metadata:log:timeexecuted',
        ], 'privacy:metadata:log');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_system_context();
        return $list;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_reportsources_log}', []);
        $userlist->add_from_sql('ownerid', 'SELECT DISTINCT ownerid FROM {local_reportsources_query}', []);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(\context_system::instance()->id, $contextlist->get_contextids(), true)) {
            return;
        }
        $userid = $contextlist->get_user()->id;

        $queries = $DB->get_records('local_reportsources_query', ['ownerid' => $userid]);
        if ($queries) {
            writer::with_context(\context_system::instance())
                ->export_data(['Ad-hoc reports', 'Queries'], (object) $queries);
        }
        $logs = $DB->get_records('local_reportsources_log', ['userid' => $userid]);
        if ($logs) {
            writer::with_context(\context_system::instance())
                ->export_data(['Ad-hoc reports', 'Audit log'], (object) $logs);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $DB->delete_records('local_reportsources_log');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(\context_system::instance()->id, $contextlist->get_contextids(), true)) {
            return;
        }
        $DB->delete_records('local_reportsources_log', ['userid' => $contextlist->get_user()->id]);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_reportsources_log', "userid {$insql}", $params);
    }
}
