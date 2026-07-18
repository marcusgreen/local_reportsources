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

namespace local_reportsources\form;

use local_reportsources\local\sql\validator;
use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Edit/create form for an ad-hoc query.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_query_form extends moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        global $PAGE;

        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Course scope: which course this report is bound to. Leaving it empty means site-wide
        // (courseid 0). Authors can re-scope an existing query here — e.g. an imported draft that
        // landed site-wide because its original course id does not exist on this site. The chosen
        // course is access-checked on save (see edit.php), so listing all courses here is safe.
        $mform->addElement(
            'course',
            'courseid',
            get_string('coursescope', 'local_reportsources'),
            ['multiple' => false, 'includefrontpage' => false]
        );
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', 0);
        $mform->addHelpButton('courseid', 'coursescope', 'local_reportsources');

        $mform->addElement(
            'advcheckbox',
            'visible',
            get_string('visible', 'local_reportsources'),
            ' ',
            null,
            [0, 1]
        );
        $mform->setDefault('visible', 1);
        $mform->addHelpButton('visible', 'visible', 'local_reportsources');

        $mform->addElement('text', 'name', get_string('name', 'local_reportsources'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'description',
            get_string('description', 'local_reportsources'),
            ['rows' => 3, 'cols' => 80]
        );
        $mform->setType('description', PARAM_TEXT);

        if (get_config('local_reportsources', 'syntaxhighlight')) {
            // The editor fetches the (large) schema + FK map lazily over AJAX; see
            // local_reportsources\external\get_schema and local_reportsources\local\schema.
            $PAGE->requires->js_call_amd('local_reportsources/editor', 'init', ['id_querysql']);
        }

        $mform->addElement(
            'textarea',
            'querysql',
            get_string('querysql', 'local_reportsources'),
            ['rows' => 10, 'cols' => 80, 'class' => 'local-reportsources-sql']
        );
        $mform->setType('querysql', PARAM_RAW);
        $mform->addRule('querysql', null, 'required', null, 'client');
        $mform->addHelpButton('querysql', 'querysql', 'local_reportsources');

        // Advisory "Check" button — analyses date columns, row count and indexes over AJAX
        // (see local_reportsources\external\check_query). Convenience only, never a publish gate.
        $mform->addElement('static', 'checkbtn', '', \html_writer::tag(
            'button',
            get_string('checkquery', 'local_reportsources'),
            ['type' => 'button', 'id' => 'rs-check-btn', 'class' => 'btn btn-secondary']
        ) . \html_writer::div('', '', ['id' => 'rs-check-results', 'class' => 'mt-2']));
        $PAGE->requires->js_call_amd('local_reportsources/check', 'init',
            ['rs-check-btn', 'id_querysql', 'id_courseid', 'rs-check-results']);

        $this->add_audience_elements($mform);

        // Authors get a plain Save (draft); approvers additionally get a one-click Save & publish so
        // they don't have to round-trip through the index page to publish. The capability is also
        // re-checked in edit.php before publishing — the button is convenience, not the gate.
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('savechanges'));
        if (!empty($this->_customdata['canpublish'])) {
            $buttonarray[] = $mform->createElement(
                'submit',
                'saveandpublish',
                get_string('saveandpublish', 'local_reportsources')
            );
        }
        $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Add the "who can view the report" audience picker.
     *
     * Course-scoped types (course participants / course roles) are only offered when the query is
     * bound to a course (courseid passed in as custom data). The role and cohort pickers are shown
     * conditionally via hideIf based on the selected type.
     *
     * @param \MoodleQuickForm $mform
     */
    private function add_audience_elements(\MoodleQuickForm $mform): void {
        global $DB;

        $courseid = (int) ($this->_customdata['courseid'] ?? 0);

        $mform->addElement('header', 'audienceheader', get_string('audiencesettings', 'local_reportsources'));

        // The course-scoped options are always offered so changing the course scope does not require
        // saving and reopening the form to reveal them. Choosing one without a course is rejected in
        // validation() rather than hidden, since the selected course is only known at submit time.
        $typeopts = [
            'default'           => get_string('audiencedefault', 'local_reportsources'),
            'courseparticipant' => get_string('audiencecourseparticipant', 'local_reportsources'),
            'courserole'        => get_string('audiencecourserole', 'local_reportsources'),
            'allusers'          => get_string('audienceallusers', 'local_reportsources'),
            'cohort'            => get_string('audiencecohort', 'local_reportsources'),
            'none'              => get_string('audiencenone', 'local_reportsources'),
        ];

        $mform->addElement(
            'select',
            'audiencetype',
            get_string('audiencetype', 'local_reportsources'),
            $typeopts
        );
        $mform->setType('audiencetype', PARAM_ALPHA);
        $mform->setDefault('audiencetype', 'default');
        $mform->addHelpButton('audiencetype', 'audiencetype', 'local_reportsources');

        // A stored courseid may point at a course that no longer exists (course deleted, or a stale
        // id carried in from an import on another site), so fall back to system context for the role
        // display names rather than fatalling on context_course::instance(). The role picker is always
        // built so the courserole audience is usable without saving and reopening the form first.
        $coursecontext = $courseid > 0 ? \context_course::instance($courseid, IGNORE_MISSING) : false;
        $rolecontext = $coursecontext ?: \context_system::instance();
        $roleopts = [];
        foreach (role_fix_names(get_all_roles(), $rolecontext, ROLENAME_BOTH) as $role) {
            $roleopts[$role->id] = $role->localname;
        }
        $mform->addElement(
            'autocomplete',
            'audienceroles',
            get_string('audienceroles', 'local_reportsources'),
            $roleopts,
            ['multiple' => true]
        );
        $mform->setType('audienceroles', PARAM_INT);
        $mform->hideIf('audienceroles', 'audiencetype', 'neq', 'courserole');

        $cohortopts = $DB->get_records_menu('cohort', null, 'name', 'id, name');
        $mform->addElement(
            'autocomplete',
            'audiencecohorts',
            get_string('audiencecohorts', 'local_reportsources'),
            $cohortopts,
            ['multiple' => true]
        );
        $mform->setType('audiencecohorts', PARAM_INT);
        $mform->hideIf('audiencecohorts', 'audiencetype', 'neq', 'cohort');
    }

    /**
     * Add chart configuration fields once column metadata is available (published queries only).
     */
    public function definition_after_data() {
        global $DB;

        $mform  = $this->_form;
        $idval  = $mform->getElementValue('id');
        $id     = is_array($idval) ? (int) $idval[0] : (int) $idval;
        if (!$id) {
            return;
        }

        $record = $DB->get_record('local_reportsources_query', ['id' => $id]);
        if (!$record || empty($record->columnsmeta)) {
            if ($record) {
                $mform->addElement('header', 'chartheader', get_string('chartsettings', 'local_reportsources'));
                $mform->addElement(
                    'static',
                    'chart_unpublished_note',
                    '',
                    \html_writer::div(
                        get_string('chartpublishrequired', 'local_reportsources'),
                        'alert alert-warning',
                        ['role' => 'alert']
                    )
                );
            }
            return;
        }

        $meta = json_decode($record->columnsmeta, true);
        if (!is_array($meta) || !$meta) {
            return;
        }

        $chartmeta = $record->chartmeta ? json_decode($record->chartmeta, true) : [];
        $colnames  = array_keys($meta);
        $colopts   = array_combine($colnames, $colnames);
        $xopts     = ['' => get_string('selectcolumn', 'local_reportsources')] + $colopts;

        // Per-user filter: restrict the report to rows whose chosen column matches the viewing
        // user's id. Offered only once published, since the column list comes from the live view.
        $mform->addElement('header', 'useridfilterheader', get_string('useridfilter', 'local_reportsources'));
        $mform->addElement(
            'select',
            'useridcolumn',
            get_string('useridcolumn', 'local_reportsources'),
            $xopts
        );
        $mform->setType('useridcolumn', PARAM_ALPHANUMEXT);
        $mform->setDefault('useridcolumn', $record->useridcolumn ?? '');
        $mform->addHelpButton('useridcolumn', 'useridcolumn', 'local_reportsources');

        // Teacher-course filter: restrict the report to rows whose course the viewer teaches.
        $mform->addElement(
            'select',
            'coursecolumn',
            get_string('coursecolumn', 'local_reportsources'),
            $xopts
        );
        $mform->setType('coursecolumn', PARAM_ALPHANUMEXT);
        $mform->setDefault('coursecolumn', $record->coursecolumn ?? '');
        $mform->addHelpButton('coursecolumn', 'coursecolumn', 'local_reportsources');

        // Page-course filter: when shown in a block on a course page, restrict rows to that course.
        $mform->addElement(
            'select',
            'pagecoursecolumn',
            get_string('pagecoursecolumn', 'local_reportsources'),
            $xopts
        );
        $mform->setType('pagecoursecolumn', PARAM_ALPHANUMEXT);
        $mform->setDefault('pagecoursecolumn', $record->pagecoursecolumn ?? '');
        $mform->addHelpButton('pagecoursecolumn', 'pagecoursecolumn', 'local_reportsources');

        $mform->addElement('header', 'chartheader', get_string('chartsettings', 'local_reportsources'));

        $mform->addElement('select', 'chart_type', get_string('charttype', 'local_reportsources'), [
            'none'     => get_string('chartnone', 'local_reportsources'),
            'bar'      => get_string('chartbar', 'local_reportsources'),
            'line'     => get_string('chartline', 'local_reportsources'),
            'pie'      => get_string('chartpie', 'local_reportsources'),
            'doughnut' => get_string('chartdoughnut', 'local_reportsources'),
        ]);
        $mform->setType('chart_type', PARAM_ALPHA);
        $mform->setDefault('chart_type', $chartmeta['type'] ?? 'none');

        // The per-user filter column is hidden from all output (its value always equals the
        // viewer's own id), so don't offer it as a chart axis. Based on the saved choice; a
        // change to the filter select above takes effect after save.
        $chartxopts = $xopts;
        $useridcol = (string) ($record->useridcolumn ?? '');
        if ($useridcol !== '' && count($colopts) > 1) {
            unset($chartxopts[$useridcol]);
        }

        $mform->addElement('select', 'chart_xcol', get_string('chartxcol', 'local_reportsources'), $chartxopts);
        $mform->setType('chart_xcol', PARAM_ALPHANUMEXT);
        $mform->setDefault('chart_xcol', $chartmeta['xcol'] ?? '');
        $mform->addHelpButton('chart_xcol', 'chartxcol', 'local_reportsources');

        $mform->addElement('select', 'chart_ycol', get_string('chartycol', 'local_reportsources'), $chartxopts);
        $mform->setType('chart_ycol', PARAM_ALPHANUMEXT);
        $mform->setDefault('chart_ycol', $chartmeta['ycol'] ?? '');
        $mform->addHelpButton('chart_ycol', 'chartycol', 'local_reportsources');

        $mform->addElement('text', 'chart_rowlimit', get_string('chartrowlimit', 'local_reportsources'), ['size' => 6]);
        $mform->setType('chart_rowlimit', PARAM_INT);
        $mform->setDefault('chart_rowlimit', (int) ($chartmeta['rowlimit'] ?? 200));
        $mform->addHelpButton('chart_rowlimit', 'chartrowlimit', 'local_reportsources');
    }

    /**
     * Validate the submitted SQL and course scope.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $sql = (string) ($data['querysql'] ?? '');
        try {
            validator::validate($sql);
        } catch (\moodle_exception $e) {
            $errors['querysql'] = $e->getMessage();
        }

        // The %%COURSEID%% token is baked into the static VIEW at publish, so the query must carry a
        // course scope to substitute. Reject it site-wide rather than silently bake in courseid 0.
        // The same applies to %%COURSECONTEXT%% (resolves to the course's mdl_context.id).
        $needscourse = stripos($sql, '%%COURSEID%%') !== false || stripos($sql, '%%COURSECONTEXT%%') !== false;
        if ($needscourse && (int) ($data['courseid'] ?? 0) <= 0) {
            $errors['courseid'] = get_string('errcourseidplaceholder', 'local_reportsources');
        }

        $audiencetype = (string) ($data['audiencetype'] ?? 'default');
        // Course-scoped audiences need a course to resolve against; the options are always shown, so
        // reject the combination here rather than hiding them based on the (submit-time) course value.
        if (
            in_array($audiencetype, ['courseparticipant', 'courserole'], true) &&
            (int) ($data['courseid'] ?? 0) <= 0
        ) {
            $errors['audiencetype'] = get_string('erraudiencecourse', 'local_reportsources');
        }
        if ($audiencetype === 'courserole' && empty($data['audienceroles'])) {
            $errors['audienceroles'] = get_string('erraudiencerolesempty', 'local_reportsources');
        }
        if ($audiencetype === 'cohort' && empty($data['audiencecohorts'])) {
            $errors['audiencecohorts'] = get_string('erraudiencecohortsempty', 'local_reportsources');
        }

        return $errors;
    }
}
