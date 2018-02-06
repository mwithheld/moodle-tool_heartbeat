<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *  Heartbeat tool plugin settings
 *
 * @package    tool_heartbeat
 * @author     Brendan Heywood <brendan@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {

    $settings = new admin_settingpage('tool_heartbeat', get_string('pluginname', 'tool_heartbeat'));

    $ADMIN->add('tools', $settings);
    if (!during_initial_install()) {

        $options = array(
            '' => new lang_string('normal', 'tool_heartbeat'),
            'warn' => new lang_string('testwarning', 'tool_heartbeat'),
            'error' => new lang_string('testerror', 'tool_heartbeat'),
        );
        $settings->add(new admin_setting_configselect('tool_heartbeat/testing',
            new lang_string('testing', 'tool_heartbeat'),
            new lang_string('testingdesc', 'tool_heartbeat'),
            'error',
            $options));
    }

    // Login Checker Settings.

    $options = array(
        '' => new lang_string('lognormal', 'tool_heartbeat'),
        'logwarn' => new lang_string('logtestwarning', 'tool_heartbeat'),
        'logerror' => new lang_string('logtesterror', 'tool_heartbeat'),
    );

    $settings->add(new admin_setting_configselect('tool_heartbeat/logtesting',
        new lang_string('logtesting', 'tool_heartbeat'),
        new lang_string('logtestingdesc', 'tool_heartbeat'),
        'logerror',
        $options));


    $settings->add(new admin_setting_configtextarea('tool_heartbeat/logwarningthresh',
        new lang_string('logwarningthresh', 'tool_heartbeat'),
        new lang_string('logwarningthresh_desc', 'tool_heartbeat'),
        10, PARAM_INTEGER, 1, 1
        ));

    $settings->add(new admin_setting_configtextarea('tool_heartbeat/logcriticalthresh',
        new lang_string('logcriticalthresh', 'tool_heartbeat'),
        new lang_string('logcriticalthresh_desc', 'tool_heartbeat'),
        500, PARAM_INTEGER, 1, 1
    ));

    $settings->add(new admin_setting_configduration('tool_heartbeat/logtimecheck',
        new lang_string('logtimecheck', 'tool_heartbeat'),
        new lang_string('logtimecheck_desc', 'tool_heartbeat'),
        300
    ));

}


