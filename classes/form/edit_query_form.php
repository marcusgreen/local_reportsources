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

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', 0);

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
            $mform->addElement('hidden', 'tablejson', json_encode($tableobject), ['id' => 'tablejson']);
            $mform->setType('tablejson', PARAM_RAW);
        }

        $mform->addElement('textarea', 'querysql',
            get_string('querysql', 'local_reportsources'),
            ['rows' => 14, 'cols' => 80, 'class' => 'local-reportsources-sql']);
        $mform->setType('querysql', PARAM_RAW);
        $mform->addRule('querysql', null, 'required', null, 'client');
        $mform->addHelpButton('querysql', 'querysql', 'local_reportsources');

        $mform->addElement('text', 'rowcap',
            get_string('rowcap', 'local_reportsources'), ['size' => 8]);
        $mform->setType('rowcap', PARAM_INT);
        $mform->setDefault('rowcap', (int) (get_config('local_reportsources', 'rowcapdefault') ?: 5000));
        $mform->addHelpButton('rowcap', 'rowcap', 'local_reportsources');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        try {
            validator::validate((string) ($data['querysql'] ?? ''));
        } catch (\moodle_exception $e) {
            $errors['querysql'] = $e->getMessage();
        }
        return $errors;
    }
}
