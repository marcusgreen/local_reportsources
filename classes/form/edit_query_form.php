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

    protected function definition() {
        global $DB, $PAGE;

        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Course scope: which course this report is bound to. Leaving it empty means site-wide
        // (courseid 0). Authors can re-scope an existing query here — e.g. an imported draft that
        // landed site-wide because its original course id does not exist on this site. The chosen
        // course is access-checked on save (see edit.php), so listing all courses here is safe.
        $mform->addElement('course', 'courseid', get_string('coursescope', 'local_reportsources'),
            ['multiple' => false, 'includefrontpage' => false]);
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', 0);
        $mform->addHelpButton('courseid', 'coursescope', 'local_reportsources');

        $mform->addElement('advcheckbox', 'visible',
            get_string('visible', 'local_reportsources'),
            ' ',
            null, [0, 1]);
        $mform->setDefault('visible', 1);
        $mform->addHelpButton('visible', 'visible', 'local_reportsources');

        $mform->addElement('text', 'name', get_string('name', 'local_reportsources'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('textarea', 'description',
            get_string('description', 'local_reportsources'),
            ['rows' => 3, 'cols' => 80]);
        $mform->setType('description', PARAM_TEXT);

        if (get_config('local_reportsources', 'syntaxhighlight')) {
            $PAGE->requires->css('/local/reportsources/amd/src/codemirror.css');
            $PAGE->requires->js_call_amd('local_reportsources/editor', 'init', ['id_querysql']);

            $tableobject = new \stdClass();
            foreach ($DB->get_tables() as $table) {
                $tableobject->$table = array_keys($DB->get_columns($table));
            }
            $jsonflags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
            $mform->addElement('hidden', 'tablejson', json_encode($tableobject, $jsonflags), ['id' => 'tablejson']);
            $mform->setType('tablejson', PARAM_RAW);

            $mform->addElement('hidden', 'fkjson', json_encode(self::build_fk_map(), $jsonflags), ['id' => 'fkjson']);
            $mform->setType('fkjson', PARAM_RAW);
        }

        $mform->addElement('textarea', 'querysql',
            get_string('querysql', 'local_reportsources'),
            ['rows' => 10, 'cols' => 80, 'class' => 'local-reportsources-sql']);
        $mform->setType('querysql', PARAM_RAW);
        $mform->addRule('querysql', null, 'required', null, 'client');
        $mform->addHelpButton('querysql', 'querysql', 'local_reportsources');

        $mform->addElement('text', 'rowcap',
            get_string('rowcap', 'local_reportsources'), ['size' => 8]);
        $mform->setType('rowcap', PARAM_INT);
        $mform->setDefault('rowcap', (int) (get_config('local_reportsources', 'rowcapdefault') ?: 5000));
        $mform->addHelpButton('rowcap', 'rowcap', 'local_reportsources');

        $this->add_audience_elements($mform);

        $this->add_action_buttons(true, get_string('savechanges'));
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

        $typeopts = ['default' => get_string('audiencedefault', 'local_reportsources')];
        if ($courseid > 0) {
            $typeopts['courseparticipant'] = get_string('audiencecourseparticipant', 'local_reportsources');
            $typeopts['courserole'] = get_string('audiencecourserole', 'local_reportsources');
        }
        $typeopts['allusers'] = get_string('audienceallusers', 'local_reportsources');
        $typeopts['cohort']   = get_string('audiencecohort', 'local_reportsources');
        $typeopts['none']     = get_string('audiencenone', 'local_reportsources');

        $mform->addElement('select', 'audiencetype',
            get_string('audiencetype', 'local_reportsources'), $typeopts);
        $mform->setType('audiencetype', PARAM_ALPHA);
        $mform->setDefault('audiencetype', 'default');
        $mform->addHelpButton('audiencetype', 'audiencetype', 'local_reportsources');

        // A stored courseid may point at a course that no longer exists (course deleted, or a stale
        // id carried in from an import on another site). Skip the course-role picker rather than
        // fatalling on context_course::instance() when loading the form.
        $coursecontext = $courseid > 0 ? \context_course::instance($courseid, IGNORE_MISSING) : false;
        if ($coursecontext) {
            $roleopts = [];
            foreach (role_fix_names(get_all_roles(), $coursecontext, ROLENAME_BOTH) as $role) {
                $roleopts[$role->id] = $role->localname;
            }
            $mform->addElement('autocomplete', 'audienceroles',
                get_string('audienceroles', 'local_reportsources'), $roleopts, ['multiple' => true]);
            $mform->setType('audienceroles', PARAM_INT);
            $mform->hideIf('audienceroles', 'audiencetype', 'neq', 'courserole');
        }

        $cohortopts = $DB->get_records_menu('cohort', null, 'name', 'id, name');
        $mform->addElement('autocomplete', 'audiencecohorts',
            get_string('audiencecohorts', 'local_reportsources'), $cohortopts, ['multiple' => true]);
        $mform->setType('audiencecohorts', PARAM_INT);
        $mform->hideIf('audiencecohorts', 'audiencetype', 'neq', 'cohort');
    }

    /**
     * Build a FK map from all installed plugin install.xml files.
     *
     * Returns: ['tablename' => ['colname' => ['reftable' => '...', 'refcol' => '...'], ...], ...]
     * Result is cached in config keyed by Moodle version and invalidated on upgrade.
     */
    private static function build_fk_map(): array {
        global $CFG;

        $cachedver = get_config('local_reportsources', 'fkmapcache_ver');
        if ($cachedver == $CFG->version) {
            $cached = get_config('local_reportsources', 'fkmapcache');
            if ($cached !== false) {
                return json_decode($cached, true) ?? [];
            }
        }

        $map = [];
        $xmlfiles = [];

        $corefile = $CFG->libdir . '/db/install.xml';
        if (file_exists($corefile)) {
            $xmlfiles[] = $corefile;
        }

        foreach (\core_component::get_plugin_types() as $type => $unused) {
            foreach (\core_component::get_plugin_list($type) as $plugin => $plugindir) {
                $xmlfile = $plugindir . '/db/install.xml';
                if (file_exists($xmlfile)) {
                    $xmlfiles[] = $xmlfile;
                }
            }
        }

        foreach ($xmlfiles as $file) {
            $xml = @simplexml_load_file($file);
            if (!$xml || !isset($xml->TABLES)) {
                continue;
            }
            foreach ($xml->TABLES->TABLE as $table) {
                $tablename = strtolower((string) $table['NAME']);
                if (!isset($table->KEYS)) {
                    continue;
                }
                foreach ($table->KEYS->KEY as $key) {
                    if (strtolower((string) $key['TYPE']) !== 'foreign') {
                        continue;
                    }
                    $fields = array_map('trim', explode(',', strtolower((string) $key['FIELDS'])));
                    $reftable = strtolower((string) $key['REFTABLE']);
                    $reffields = array_map('trim', explode(',', strtolower((string) $key['REFFIELDS'])));
                    foreach ($fields as $i => $field) {
                        $map[$tablename][$field] = [
                            'reftable' => $reftable,
                            'refcol'   => $reffields[$i] ?? $reffields[0],
                        ];
                    }
                }
            }
        }

        set_config('fkmapcache', json_encode($map), 'local_reportsources');
        set_config('fkmapcache_ver', $CFG->version, 'local_reportsources');

        return $map;
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
                $mform->addElement('static', 'chart_unpublished_note', '',
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
        $mform->addElement('select', 'useridcolumn',
            get_string('useridcolumn', 'local_reportsources'), $xopts);
        $mform->setType('useridcolumn', PARAM_ALPHANUMEXT);
        $mform->setDefault('useridcolumn', $record->useridcolumn ?? '');
        $mform->addHelpButton('useridcolumn', 'useridcolumn', 'local_reportsources');

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

        $mform->addElement('select', 'chart_xcol', get_string('chartxcol', 'local_reportsources'), $xopts);
        $mform->setType('chart_xcol', PARAM_ALPHANUMEXT);
        $mform->setDefault('chart_xcol', $chartmeta['xcol'] ?? '');
        $mform->addHelpButton('chart_xcol', 'chartxcol', 'local_reportsources');

        $mform->addElement('select', 'chart_ycol', get_string('chartycol', 'local_reportsources'), $xopts);
        $mform->setType('chart_ycol', PARAM_ALPHANUMEXT);
        $mform->setDefault('chart_ycol', $chartmeta['ycol'] ?? '');
        $mform->addHelpButton('chart_ycol', 'chartycol', 'local_reportsources');

        $mform->addElement('text', 'chart_rowlimit', get_string('chartrowlimit', 'local_reportsources'), ['size' => 6]);
        $mform->setType('chart_rowlimit', PARAM_INT);
        $mform->setDefault('chart_rowlimit', (int) ($chartmeta['rowlimit'] ?? 200));
        $mform->addHelpButton('chart_rowlimit', 'chartrowlimit', 'local_reportsources');
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        try {
            validator::validate((string) ($data['querysql'] ?? ''));
        } catch (\moodle_exception $e) {
            $errors['querysql'] = $e->getMessage();
        }

        $audiencetype = (string) ($data['audiencetype'] ?? 'default');
        if ($audiencetype === 'courserole' && empty($data['audienceroles'])) {
            $errors['audienceroles'] = get_string('erraudiencerolesempty', 'local_reportsources');
        }
        if ($audiencetype === 'cohort' && empty($data['audiencecohorts'])) {
            $errors['audiencecohorts'] = get_string('erraudiencecohortsempty', 'local_reportsources');
        }

        return $errors;
    }
}
