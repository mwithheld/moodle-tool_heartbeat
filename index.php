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
 * Are you Ok? heartbeat for load balancers
 *
 * @package    tool_heartbeat
 * @copyright  2014 Brendan Heywood <brendan@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// Make sure varnish doesn't cache this. But it still might so go check it!
header('Pragma: no-cache');
header('Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate, proxy-revalidate');
header('Expires: Tue, 04 Sep 2012 05:32:29 GMT');

function setup_error_display() {
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', '/moodle_static/error_log.' . date('Ymd'));
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL | E_STRICT);
}

setup_error_display();

require_once(__DIR__ . '/lib.php');

$debug = true;
$debug && error_log(__FILE__ . '::' . __LINE__ . '::Started');

//Collect test results here
//For each test result:
//  - The testname is required to meet Nagios specifications
//  - Testname will be converted to ALL_CAPS and ONE_WORD for easier automated log parsing/processing
$heartbeat_test_results = array();
require_once(__DIR__ . '/classes/HeartbeatTestResult.php');
require_once(__DIR__ . '/classes/HeartbeatPerfInfo.php');
require_once(__DIR__ . '/classes/HeartbeatTests.php');


$heartbeat_is_cli = heartbeat_is_cli();

//------------------------------------------------------------------------------
//Test: The most basic health check possible - is this health check started?
//This tells us PHP, the underlying httpd (apache, nginx etc), and the server itself (OS down to hardware) is working
$heartbeat_label = 'HEARTBEAT_START';
if ((!$heartbeat_is_cli && (empty($_GET) || ( isset($_GET[$heartbeat_label]) && ($_GET[$heartbeat_label] || $_GET['ALL']))) ) ||
        ( $heartbeat_is_cli && !empty(getopt('', array("$heartbeat_label::"))[$heartbeat_label]) )
) {
    $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, STATUS_OK, 'started', HeartbeatPerfInfo::get_usermicrotime());
    $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label} "/* \$heartbeat_test_results=" . print_r($heartbeat_test_results, true) */);
}
//------------------------------------------------------------------------------


try {
    define('NO_UPGRADE_CHECK', true);
    define('ABORT_AFTER_CONFIG', true);

    //Point this at Moodle's config.php
    define('HEARTBEAT_MOODLE_ROOT_DIR', realpath(dirname(dirname(dirname(__DIR__)))));

    $debug && error_log(__FILE__ . '::' . __LINE__ . '::Done CLI check');

    //Params and defaults
    //Makes a copy of the array by default
    $tests_to_run = array(
        'ALL'                  => true,
        'HEARTBEAT_START'      => false,
        'CLI_MAINTENANCE_MODE' => false,
        'MOODLEDATA'           => false,
        'MOODLE_DB_READABLE'   => false,
        'REDIS'                => false,
        'CACHE_CONFIG'         => false,
        'DB_MAINTENANCE_MODE'  => false,
        'UPGRADE_PENDING'      => false,
        'HEARTBEAT'            => true,
    );

    //Do not setup cookies
    !$heartbeat_is_cli && define('NO_MOODLE_COOKIES', true);
    //Make sure Moodle knows this is CLI
    $heartbeat_is_cli && define('CLI_SCRIPT', true);
    //config.php is requires for moodlelib.php to load correctly
    require_once(HEARTBEAT_MOODLE_ROOT_DIR . '/config.php');
    $debug && $CFG->debug = (E_ALL | E_STRICT);   // === DEBUG_DEVELOPER
    $debug && error_log(__FILE__ . '::' . __LINE__ . '::Loaded Moodle config.php');

    if ($heartbeat_is_cli) {

        require_once(HEARTBEAT_MOODLE_ROOT_DIR . '/lib/clilib.php');

        //Allow the help option
        $tests_to_run['help'] = false;

        //Psrse CLI arguments into $_GET
        //parse_str(implode('&', array_slice($argv, 1)), $_GET);
        // now get cli options: options, unrecognised as optionlongname=>value
        list($tests_to_run, $unrecognized_options) = cli_get_params($tests_to_run, array('h' => 'help'));


        if ($unrecognized_options) {
            $unrecognized_options = implode("\n  ", $unrecognized_options);
            cli_error(get_string('cliunknowoption', 'admin', $unrecognized_options));
        }

        if ($tests_to_run['help']) {
            echo
            "Run the Moodle heartbeat, which checks various parts for proper functionality.
Output is nagios-compliant on each line
Options:
  --ALL                      (default true) Run all tests regardless of the below parameters
  --HEARTBEAT_START          (default false) Just make sure this script can run, then exit
  --CLI_MAINTENANCE_MODE     (default false) Check if the CLI-set maintenance mode (.maintenance file in the root) is enabled
  --MOODLEDATA               (default false) Check if the moodledata folder is writable
  --MOODLE_DB_READABLE       (default false) Check the if the Moodle DB is readable
  --REDIS                    (default false) Check if Redis is pingable
  --CACHE_CONFIG             (default false) Check the cache config file is present and sensible
  --DB_MAINTENANCE_MODE      (default false) Check if the DB-set maintenance mode is enabled
  --UPGRADE_PENDING          (default false) Check if Moodle thinks an upgrade is pending
  --HEARTBEAT                (default true) Check if this heartbeat script finished and shows overall result as the top line

Example:
\$ sudo -u www-data /usr/bin/php /admin/tool/heartbeat/index.php  --ALL=0 --HEARTBEAT_START=1
";
            die();
        }
    } else {
        require_once(HEARTBEAT_MOODLE_ROOT_DIR . '/lib/moodlelib.php');
        $debug && error_log(__FILE__ . '::' . __LINE__ . '::Done require moodlelib.php');

        foreach ($tests_to_run as $test_name => $default_value) {
            $tests_to_run[$test_name] = optional_param($test_name, $default_value, PARAM_BOOL);
        }
    }

    if ($tests_to_run['ALL']) {
        foreach ($tests_to_run as $test_name => $value) {
            $tests_to_run[$test_name] = 1;
        }
    }

    //die('Built $tests_to_run=<PRE>' . print_r($tests_to_run, true) . '</PRE>');
    $debug && error_log(__FILE__ . '::' . __LINE__ . "::Built \$tests_to_run=" . print_r($tests_to_run, true));

    //------------------------------------------------------------------------------
    //Test: Is Moodle in maintenance mode?
    $heartbeat_label = 'CLI_MAINTENANCE_MODE';
    if ($tests_to_run[$heartbeat_label]) {
        $heartbeat_test = !HeartbeatTests::is_climaintenance_enabled(HEARTBEAT_MOODLE_ROOT_DIR);
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_WARNING), ($heartbeat_test ? 'not enabled' : 'enabled'), HeartbeatPerfInfo::get_usermicrotime());
        $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label} "/* \$heartbeat_test_results=" . print_r($heartbeat_test_results, true) */);
    }
    //------------------------------------------------------------------------------


    global $CFG;
    //Sanity checks
    if (empty($CFG)) {
        throw new Exception('Moodle $CFG must be defined; make sure you have includes config.php');
    }
    if (empty($CFG->dataroot)) {
        throw new Exception('Moodle $CFG must define dataroot');
    }

    //------------------------------------------------------------------------------
    //Test: Is the dataroot writable?
    $heartbeat_label = 'MOODLEDATA';
    if ($tests_to_run[$heartbeat_label]) {
        $heartbeat_test = HeartbeatTests::is_moodledata_writable($CFG->dataroot);
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'writable' : $CFG->dataroot . ' not writable'), HeartbeatPerfInfo::get_usermicrotime());
        $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label} "/* \$heartbeat_test_results=" . print_r($heartbeat_test_results, true) */);
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is the DB available?
    define('ABORT_AFTER_CONFIG_CANCEL', true);
    require_once($CFG->dirroot . '/lib/setup.php');
    global $DB;

    $heartbeat_label = 'MOODLE_DB_READABLE';
    if ($tests_to_run[$heartbeat_label]) {
        $heartbeat_test = HeartbeatTests::is_db_readable($DB);
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'available' : 'not available'), HeartbeatPerfInfo::get_usermicrotime());
        $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label} "/* \$heartbeat_test_results=" . print_r($heartbeat_test_results, true) */);
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: If Redis is used, is it available?
    $heartbeat_redis_class_exists = class_exists('Redis') && is_readable($CFG->dirroot . '/lib/classes/session/redis.php');
    $heartbeat_label = 'REDIS';
    if ($tests_to_run[$heartbeat_label] && isset($CFG->session_handler_class) && ($CFG->session_handler_class === '\core\session\redis') && $heartbeat_redis_class_exists) {
        $heartbeat_test = HeartbeatTests::is_redis_readable($CFG);
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'available' : 'not available'), HeartbeatPerfInfo::get_usermicrotime());
        $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label} "/* \$heartbeat_test_results=" . print_r($heartbeat_test_results, true) */);
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is a Moodle upgrade pending?
    $heartbeat_label = 'CACHE_CONFIG';
    if ($tests_to_run[$heartbeat_label]) {
        $heartbeat_test = HeartbeatTests::check_muc_config($CFG);
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'cache config OK' : 'cache config missing or corrupt'), HeartbeatPerfInfo::get_usermicrotime());
        $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label} "/* \$heartbeat_test_results=" . print_r($heartbeat_test_results, true) */);
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is the DB maintenance mode is enabled?
    $heartbeat_label = 'DB_MAINTENANCE_MODE';
    if ($tests_to_run[$heartbeat_label]) {
        $heartbeat_test = HeartbeatTests::is_dbmaintenance_enabled($CFG);
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_WARNING), ($heartbeat_test ? 'not enabled' : 'enabled'), HeartbeatPerfInfo::get_usermicrotime());
        $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label} "/* \$heartbeat_test_results=" . print_r($heartbeat_test_results, true) */);
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is a Moodle upgrade pending?
    $heartbeat_label = 'UPGRADE_PENDING';
    if ($tests_to_run[$heartbeat_label]) {
        require_once($CFG->dirroot . '/lib/moodlelib.php');
        $heartbeat_test = moodle_needs_upgrading();
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_WARNING), ($heartbeat_test ? 'no upgrade needed' : 'upgrade is pending'), HeartbeatPerfInfo::get_usermicrotime());
        $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label} "/* \$heartbeat_test_results=" . print_r($heartbeat_test_results, true) */);
    }
    //------------------------------------------------------------------------------
    //
    //If we get here we probably have not hit a fatal exception
    if ($heartbeat_test_results) {
        heartbeat_print_test_results($heartbeat_test_results, $heartbeat_label);
    } else {
        echo 'No results' . ($heartbeat_is_cli ? '' : BRNL);
    }

    $debug && error_log(__FILE__ . '::' . __LINE__ . '::Done all tests');
    //------------------------------------------------------------------------------
} catch (Exception $e) {
    $debug && error_log(__FILE__ . '::' . __LINE__ . '::In the main Exception handler');
    error_log(print_r($e, true));

    if (!$heartbeat_is_cli) {
        echo 'Hit an exception' . BRNL;
        echo '<PRE>' . print_r($e, true) . '</PRE>';
    }

    //Exit with error code
    exit(1);
}
unset($debug);
