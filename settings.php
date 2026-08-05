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
 * Admin settings for the Report sources plugin.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The index page lives under Site administration → Reports. Register it OUTSIDE the
// $hassiteconfig guard so the node's own capability governs visibility — otherwise the
// guard (moodle/site:config) hides it from non-admins who only hold the author capability.
$ADMIN->add('reports', new admin_externalpage(
    'local_reportsources_index',
    get_string('reportsources', 'local_reportsources'),
    new moodle_url('/local/reportsources/index.php'),
    'local/reportsources:author',
    false
));

// Usage overview (read-only, viewall-gated). Registered outside the $hassiteconfig guard for the
// same reason as the index — its own capability governs visibility. Hidden from the tree; reached
// via the link on the index page.
$ADMIN->add('reports', new admin_externalpage(
    'local_reportsources_usage',
    get_string('usage:title', 'local_reportsources'),
    new moodle_url('/local/reportsources/usage.php'),
    'local/reportsources:viewall',
    true
));

// Sample browser (import bundled sample report views as drafts). Registered outside the
// $hassiteconfig guard with the author capability so any author — not only site admins — can
// browse and import samples. Hidden from the tree; reached via the link on the index page.
$ADMIN->add('reports', new admin_externalpage(
    'local_reportsources_samples',
    get_string('samples:title', 'local_reportsources'),
    new moodle_url('/local/reportsources/samples.php'),
    'local/reportsources:author',
    true
));

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_reportsources',
        get_string('pluginname', 'local_reportsources')
    );
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtextarea(
        'local_reportsources/denycolumns',
        get_string('settings:denycolumns', 'local_reportsources'),
        get_string('settings:denycolumns_desc', 'local_reportsources'),
        'password,passwordhash,password_hash,secret,client_secret,sesskey,sid,apikey,api_key,token,accesstoken,refreshtoken,sharekey,salt,hash,signature,privatekey,private_key,clientid,client_id',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_reportsources/syntaxhighlight',
        get_string('settings:syntaxhighlight', 'local_reportsources'),
        get_string('settings:syntaxhighlight_desc', 'local_reportsources'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_reportsources/showlastmodified',
        get_string('settings:showlastmodified', 'local_reportsources'),
        get_string('settings:showlastmodified_desc', 'local_reportsources'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_reportsources/aigenerate',
        get_string('settings:aigenerate', 'local_reportsources'),
        get_string('settings:aigenerate_desc', 'local_reportsources'),
        0
    ));

    // Companion switch for the shared repository below. Off by default — nothing reaches out to the
    // remote repo, and its browse page/links stay hidden, until an admin deliberately enables it.
    $settings->add(new admin_setting_configcheckbox(
        'local_reportsources/sharedrepositoryenabled',
        get_string('settings:sharedrepositoryenabled', 'local_reportsources'),
        get_string('settings:sharedrepositoryenabled_desc', 'local_reportsources'),
        0
    ));

    // Remote repository of shared report sources. Authors can browse and import from it as drafts.
    $settings->add(new admin_setting_configtext(
        'local_reportsources/sharedrepository',
        get_string('settings:sharedrepository', 'local_reportsources'),
        get_string('settings:sharedrepository_desc', 'local_reportsources'),
        'https://github.com/marcusgreen/moodle-reportsources_repository',
        PARAM_URL,
        50
    ));

    // Retention window (days) for the report-view audit rows; 0 keeps them forever. Enforced by the
    // purge_views scheduled task.
    $settings->add(new admin_setting_configtext(
        'local_reportsources/viewretaindays',
        get_string('settings:viewretaindays', 'local_reportsources'),
        get_string('settings:viewretaindays_desc', 'local_reportsources'),
        '365',
        PARAM_INT
    ));

    $settings->add(new admin_setting_description(
        'local_reportsources/testviewlink',
        get_string('testview:title', 'local_reportsources'),
        html_writer::link(
            new moodle_url('/local/reportsources/testview.php'),
            get_string('testview:linklabel', 'local_reportsources')
        )
    ));

    $settings->add(new admin_setting_description(
        'local_reportsources/sampleslink',
        get_string('samples:title', 'local_reportsources'),
        html_writer::link(
            new moodle_url('/local/reportsources/samples.php'),
            get_string('samples:linklabel', 'local_reportsources')
        )
    ));

    $settings->add(new admin_setting_description(
        'local_reportsources/repositorylink',
        get_string('repository:title', 'local_reportsources'),
        html_writer::link(
            new moodle_url('/local/reportsources/samples.php', ['remote' => 1]),
            get_string('repository:linklabel', 'local_reportsources')
        )
    ));

    $settings->add(new admin_setting_description(
        'local_reportsources/createrolelink',
        get_string('createrole:title', 'local_reportsources'),
        html_writer::link(
            new moodle_url('/local/reportsources/createrole.php'),
            get_string('createrole:linklabel', 'local_reportsources')
        )
    ));

    $settings->add(new admin_setting_description(
        'local_reportsources/importcrlink',
        get_string('crimport:title', 'local_reportsources') .
            $OUTPUT->help_icon('crimport:title', 'local_reportsources'),
        html_writer::link(
            new moodle_url('/local/reportsources/import_cr.php'),
            get_string('crimport:linklabel', 'local_reportsources')
        )
    ));

    $settings->add(new admin_setting_description(
        'local_reportsources/importcustomsqllink',
        get_string('customsqlimport:title', 'local_reportsources') .
            $OUTPUT->help_icon('customsqlimport:title', 'local_reportsources'),
        html_writer::link(
            new moodle_url('/local/reportsources/import_customsql.php'),
            get_string('customsqlimport:linklabel', 'local_reportsources')
        )
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_reportsources_testview',
        get_string('testview:title', 'local_reportsources'),
        new moodle_url('/local/reportsources/testview.php'),
        'moodle/site:config',
        true
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_reportsources_createrole',
        get_string('createrole:title', 'local_reportsources'),
        new moodle_url('/local/reportsources/createrole.php'),
        'moodle/site:config',
        true
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_reportsources_importcr',
        get_string('crimport:title', 'local_reportsources'),
        new moodle_url('/local/reportsources/import_cr.php'),
        'moodle/site:config',
        true
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_reportsources_importcustomsql',
        get_string('customsqlimport:title', 'local_reportsources'),
        new moodle_url('/local/reportsources/import_customsql.php'),
        'moodle/site:config',
        true
    ));
}
