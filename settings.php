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
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_reportsources',
        get_string('pluginname', 'local_reportsources'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_reportsources/rowcapdefault',
        get_string('settings:rowcapdefault', 'local_reportsources'),
        get_string('settings:rowcapdefault_desc', 'local_reportsources'),
        5000,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_reportsources/denycolumns',
        get_string('settings:denycolumns', 'local_reportsources'),
        get_string('settings:denycolumns_desc', 'local_reportsources'),
        'password,secret,sesskey',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_reportsources/syntaxhighlight',
        get_string('settings:syntaxhighlight', 'local_reportsources'),
        get_string('settings:syntaxhighlight_desc', 'local_reportsources'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_reportsources/aigenerate',
        get_string('settings:aigenerate', 'local_reportsources'),
        get_string('settings:aigenerate_desc', 'local_reportsources'),
        0
    ));

    $settings->add(new admin_setting_description(
        'local_reportsources/testviewlink',
        get_string('testview:title', 'local_reportsources'),
        html_writer::link(
            new moodle_url('/local/reportsources/testview.php'),
            get_string('testview:linklabel', 'local_reportsources')
        )
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_reportsources_testview',
        get_string('testview:title', 'local_reportsources'),
        new moodle_url('/local/reportsources/testview.php'),
        'moodle/site:config',
        true
    ));
}
