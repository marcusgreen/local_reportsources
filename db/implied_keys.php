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
 * Curated implied foreign-key map — Moodle relationships not declared in install.xml.
 *
 * GENERATED from upstream morekeys.xml by tools/xml_to_keys.php. DO NOT HAND-EDIT —
 * edit the upstream morekeys.xml and regenerate. Shape matches schema::build_fk_map():
 * [table => [column => [reftable => ..., refcol => ...]]].
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

return [
    'backup_courses' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'block_instances' => [
        'blockname' => ['reftable' => 'block', 'refcol' => 'name'],
    ],
    'block_recent_activity' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'block_rss_client' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'book_chapters' => [
        'bookid' => ['reftable' => 'book', 'refcol' => 'id'],
    ],
    'competency' => [
        'competencyframeworkid' => ['reftable' => 'competency_framework', 'refcol' => 'id'],
    ],
    'competency_evidence' => [
        'usercompetencyid' => ['reftable' => 'competency_usercomp', 'refcol' => 'id'],
    ],
    'competency_modulecomp' => [
        'competencyid' => ['reftable' => 'competency', 'refcol' => 'id'],
    ],
    'competency_plan' => [
        'templateid' => ['reftable' => 'competency_template', 'refcol' => 'id'],
    ],
    'competency_plancomp' => [
        'competencyid' => ['reftable' => 'competency', 'refcol' => 'id'],
    ],
    'competency_relatedcomp' => [
        'competencyid' => ['reftable' => 'competency', 'refcol' => 'id'],
    ],
    'competency_templatecohort' => [
        'templateid' => ['reftable' => 'competency_template', 'refcol' => 'id'],
    ],
    'competency_templatecomp' => [
        'competencyid' => ['reftable' => 'competency', 'refcol' => 'id'],
    ],
    'competency_usercomp' => [
        'competencyid' => ['reftable' => 'competency', 'refcol' => 'id'],
    ],
    'competency_usercompcourse' => [
        'competencyid' => ['reftable' => 'competency', 'refcol' => 'id'],
    ],
    'competency_usercompplan' => [
        'competencyid' => ['reftable' => 'competency', 'refcol' => 'id'],
    ],
    'competency_userevidence' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'competency_userevidencecomp' => [
        'competencyid' => ['reftable' => 'competency', 'refcol' => 'id'],
    ],
    'course' => [
        'category' => ['reftable' => 'course_categories', 'refcol' => 'id'], // what category is this course in
    ],
    'course_categories' => [
        'parent' => ['reftable' => 'course_categories', 'refcol' => 'id'], // same table relationship
    ],
    'course_completion_aggr_methd' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'course_completion_crit_compl' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'course_completion_criteria' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'course_completions' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'course_modules' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'course_modules_completion' => [
        'coursemoduleid' => ['reftable' => 'course_modules', 'refcol' => 'id'], // completion status of course module instance
    ],
    'course_published' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'course_sections' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'enrol_paypal' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'folder' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'forum_read' => [
        'discussionid' => ['reftable' => 'forum_discussions', 'refcol' => 'id'],
        'forumid' => ['reftable' => 'forum', 'refcol' => 'id'],
        'postid' => ['reftable' => 'forum_posts', 'refcol' => 'id'],
    ],
    'forum_track_prefs' => [
        'userid' => ['reftable' => 'forum', 'refcol' => 'id'],
    ],
    'grade_letters' => [
        'contextid' => ['reftable' => 'context', 'refcol' => 'id'],
    ],
    'imscp' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'label' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'log' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'lti' => [
        'typeid' => ['reftable' => 'lti_types', 'refcol' => 'id'],
    ],
    'lti_submission' => [
        'ltiid' => ['reftable' => 'lti', 'refcol' => 'id'],
    ],
    'lti_types_config' => [
        'typeid' => ['reftable' => 'lti', 'refcol' => 'id'],
    ],
    'message_contacts' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'message_popup' => [
        'messageid' => ['reftable' => 'message', 'refcol' => 'id'],
    ],
    'message_read' => [
        'useridfrom' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'mnet_log' => [
        'hostid' => ['reftable' => 'mnet_host', 'refcol' => 'id'],
    ],
    'mnet_service2rpc' => [
        'serviceid' => ['reftable' => 'mnet_service', 'refcol' => 'id'],
    ],
    'mnet_session' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'my_pages' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'page' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'question_statistics' => [
        'questionid' => ['reftable' => 'question', 'refcol' => 'id'],
    ],
    'repository_instance_config' => [
        'instanceid' => ['reftable' => 'repository_instances', 'refcol' => 'id'],
    ],
    'resource' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'stats_daily' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'stats_monthly' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'stats_user_daily' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'stats_user_monthly' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'stats_user_weekly' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'stats_weekly' => [
        'courseid' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'tool_cohortroles' => [
        'cohortid' => ['reftable' => 'cohort', 'refcol' => 'id'],
    ],
    'url' => [
        'course' => ['reftable' => 'course', 'refcol' => 'id'],
    ],
    'user_info_data' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'user_lastaccess' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'user_preferences' => [
        'userid' => ['reftable' => 'user', 'refcol' => 'id'],
    ],
    'wiki_locks' => [
        'pageid' => ['reftable' => 'wiki_pages', 'refcol' => 'id'],
    ],
    'wiki_synonyms' => [
        'pageid' => ['reftable' => 'wiki_pages', 'refcol' => 'id'],
        'subwikiid' => ['reftable' => 'wiki_subwikis', 'refcol' => 'id'],
    ],
    'workshopform_rubric' => [
        'workshopid' => ['reftable' => 'workshop', 'refcol' => 'id'],
    ],
    'workshopform_rubric_config' => [
        'workshopid' => ['reftable' => 'workshop', 'refcol' => 'id'],
    ],
];
