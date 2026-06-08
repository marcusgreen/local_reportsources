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

use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload step of the import flow: choose a JSON export file.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends moodleform {

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'importfile', get_string('importfile', 'local_reportsources'),
            null, ['accepted_types' => ['.json']]);
        $mform->addRule('importfile', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('importupload', 'local_reportsources'));
    }
}
