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

namespace local_reportsources\local;

/**
 * Create / maintain the optional "Report author" custom role.
 *
 * This role bundles the system-context report-source capabilities so non-administrators can author
 * (and optionally publish) report views without being full site managers. It is NOT created at
 * install: authoring runs arbitrary SQL, so the role is effectively a site-wide data-read grant and
 * must be created deliberately by an admin from the confirm page (createrole.php).
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roles {
    /** @var string Shortname of the optional report-author role. */
    public const REPORT_AUTHOR_SHORTNAME = 'reportauthor';

    /**
     * Create — or update in place — the "Report author" role and grant it the chosen capabilities at
     * the system context. Always grants :author (the role's purpose); :approve and :viewall are
     * optional. Idempotent: re-running with different options re-grants/removes the optional caps and
     * never creates a duplicate role (matched by shortname).
     *
     * @param bool $approve Also grant local/reportsources:approve (publish/unpublish).
     * @param bool $viewall Also grant local/reportsources:viewall (see all report views).
     * @param bool $aigenerate Also grant local/sqlchat:use (AI SQL generation), if that plugin is installed.
     * @return array{created: bool, roleid: int} 'created' is false when the role already existed.
     */
    public static function create_report_author_role(
        bool $approve = true,
        bool $viewall = true,
        bool $aigenerate = false
    ): array {
        global $DB;

        $syscontext = \context_system::instance();
        $existing = $DB->get_record('role', ['shortname' => self::REPORT_AUTHOR_SHORTNAME]);

        if ($existing) {
            $roleid  = (int) $existing->id;
            $created = false;
        } else {
            $roleid = create_role(
                get_string('rolename', 'local_reportsources'),
                self::REPORT_AUTHOR_SHORTNAME,
                get_string('roledescription', 'local_reportsources')
            );
            $created = true;
        }

        // The role is meaningful only at the system context (where the capabilities are defined).
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);

        // The :author capability is the reason the role exists — always granted.
        assign_capability('local/reportsources:author', CAP_ALLOW, $roleid, $syscontext->id, true);

        // Optional capabilities: grant when chosen, otherwise clear any previous grant so re-running
        // the form with a box unticked actually removes that permission.
        foreach (['approve' => $approve, 'viewall' => $viewall] as $cap => $wanted) {
            $capname = 'local/reportsources:' . $cap;
            if ($wanted) {
                assign_capability($capname, CAP_ALLOW, $roleid, $syscontext->id, true);
            } else {
                unassign_capability($capname, $roleid, $syscontext->id);
            }
        }

        // AI SQL generation is gated by a separate plugin (local_sqlchat). Grant its capability only
        // when that plugin is installed (the capability exists); otherwise silently skip so the role
        // still works on sites without it.
        if (get_capability_info('local/sqlchat:use')) {
            if ($aigenerate) {
                assign_capability('local/sqlchat:use', CAP_ALLOW, $roleid, $syscontext->id, true);
            } else {
                unassign_capability('local/sqlchat:use', $roleid, $syscontext->id);
            }
        }

        $syscontext->mark_dirty();

        return ['created' => $created, 'roleid' => $roleid];
    }
}
