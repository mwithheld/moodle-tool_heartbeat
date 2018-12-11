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

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/moodle_static/error_log.' . date('Ymd'));
ini_set('display_startup_errors', 1);
error_reporting(E_ALL | E_STRICT);

$heartbeat_debug = false;
$heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . '::Started');

//Collect test results here
//For each test result:
//  - The testname is required to meet Nagios specifications
//  - Testname will be converted to ALL_CAPS and ONE_WORD for easier automated log parsing/processing
$heartbeat_test_results = array();
require_once(__DIR__ . '/lib.php');
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
    $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, STATUS_OK, 'started on ' . gethostname(), HeartbeatPerfInfo::get_usermicrotime());
    $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
}
//------------------------------------------------------------------------------


try {
    //Point this at the root Moodle folder
    define('HEARTBEAT_MOODLE_ROOT_DIR', realpath(dirname(dirname(dirname(__DIR__)))));

    $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . '::Done CLI check');

    //Params and defaults
    //Makes a copy of the array by default
    $tests_to_run = array(
        'ALL'                  => true,
        'HEARTBEAT_START'      => false,
        'CLI_MAINTENANCE_MODE' => false,
        'MOODLEDATA'           => false,
        'CACHE_CONFIG'         => false,
        'REDIS_CONNECTION'     => false,
        'REDIS_ITEM_STUCK'     => false,
        'MOODLE_DB_READABLE'   => false,
        'DB_MAINTENANCE_MODE'  => false,
        'UPGRADE_PENDING'      => false,
        'CRON_TASKS'           => false,
        'HEARTBEAT'            => true, //This is the top line that summarizes all test results.
    );

    if ($heartbeat_is_cli) {
        //Make sure Moodle knows this is CLI
        //CLI_SCRIPT means we do not have session and we do not output HTML
        defined('CLI_SCRIPT') || define('CLI_SCRIPT', true);

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
  --REDIS_CONNECTION         (default false) Check if Redis is pingable
  --REDIS_ITEM_STUCK         (default false) Check if certain Redis items are hanging around too long
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
        //This is not a CLI script
        //
        //Do not setup cookies
        defined('NO_MOODLE_COOKIES') || define('NO_MOODLE_COOKIES', true);

        foreach ($tests_to_run as $test_name => $default_value) {
            //Adapted from moodlelib.php::optional_param()
            //I did not want to depend on loading moodlelib until some basic checks are passed.
            // POST has precedence.
            if (isset($_POST[$test_name])) {
                $param = $_POST[$test_name];
            } else if (isset($_GET[$test_name])) {
                $param = $_GET[$test_name];
            } else {
                $param = $default_value;
            }

            $tests_to_run[$test_name] = !empty($param);
        }
    }

    if ($tests_to_run['ALL']) {
        foreach ($tests_to_run as $test_name => $value) {
            $tests_to_run[$test_name] = 1;
        }
    }

    $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Built \$tests_to_run=" . print_r($tests_to_run, true));

    //------------------------------------------------------------------------------
    //Test: Is Moodle in maintenance mode?
    $heartbeat_cli_maintenance_enabled = false;
    $heartbeat_label = 'CLI_MAINTENANCE_MODE';
    if ($tests_to_run[$heartbeat_label]) {
        $heartbeat_test = !HeartbeatTests::is_climaintenance_enabled(HEARTBEAT_MOODLE_ROOT_DIR . '/config.php');
        $heartbeat_cli_maintenance_enabled = !$heartbeat_test;
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_WARNING), ($heartbeat_test ? 'not enabled' : 'enabled'), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------

    $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . '::About to load config.php');

    //Do not error out in lib/setup.php if an upgrade is running
    defined('NO_UPGRADE_CHECK') || define('NO_UPGRADE_CHECK', true);
    //Do not load libraries or DB connection
    defined('ABORT_AFTER_CONFIG') || define('ABORT_AFTER_CONFIG', true);

    if ($heartbeat_cli_maintenance_enabled) {
        /*
         * If CLI maintenance mode is enabled, we need to prevent lib/setup.php from doing a 503 header then die().
         * To achieve this we need to set CLI_SCRIPT=true and unset($_SERVER['REMOTE_ADDR'])
         */
        defined('CLI_SCRIPT') || define('CLI_SCRIPT', true);
        unset($_SERVER['REMOTE_ADDR']);
    }

    //Suppress any warnings/errors on loading config.php so we do not mess up nagios-compliant output
    @require_once(HEARTBEAT_MOODLE_ROOT_DIR . '/config.php');

    if ($heartbeat_debug) {
        $CFG->debug = (E_ALL | E_STRICT);   // === DEBUG_DEVELOPER
        error_log(__FILE__ . '::' . __LINE__ . '::Loaded Moodle config.php');
    }

    //Sanity checks
    if (empty($CFG)) {
        throw new Exception('Moodle $CFG must be defined; make sure you have includes config.php');
    }
    if (empty($CFG->dataroot)) {
        throw new Exception('Moodle $CFG must define dataroot');
    }
    $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . '::Done loading config.php');

    //------------------------------------------------------------------------------
    //Test: Is the dataroot writable?
    $heartbeat_label = 'MOODLEDATA';
    if ($tests_to_run[$heartbeat_label]) {
        $heartbeat_test = HeartbeatTests::is_moodledata_writable($CFG->dataroot);
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'writable' : $CFG->dataroot . ' is not writable'), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is a Moodle upgrade pending?
    $heartbeat_label = 'CACHE_CONFIG';
    if ($tests_to_run[$heartbeat_label]) {
        defined('MOODLE_INTERNAL') || define('MOODLE_INTERNAL', true);
        $heartbeat_test = HeartbeatTests::check_muc_config();
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'OK' : "{$CFG->dataroot}/muc/config.php is missing or corrupt"), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: If Redis is used, is it available?
    $heartbeat_redis_class_exists = class_exists('Redis') && is_readable($CFG->dirroot . '/lib/classes/session/redis.php');
    $heartbeat_redisconnection_success = false;
    $heartbeat_label = 'REDIS_CONNECTION';
    if ($heartbeat_redis_class_exists && $tests_to_run[$heartbeat_label] && isset($CFG->session_handler_class) && ($CFG->session_handler_class === '\core\session\redis')) {
        $heartbeat_test = $heartbeat_redisconnection_success = HeartbeatTests::is_redis_readable();
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'available' : "{$CFG->session_redis_host}:{$CFG->session_redis_port} is not available"), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is the DB available?
    try {
        //Start by assuming we do not have a DB connection
        $heartbeat_dbconnection_success = false;
        $heartbeat_label = 'MOODLE_DB_READABLE';

        //Tell Moodle to load libraries and DB - cancels the above ABORT_AFTER_CONFIG
        define('ABORT_AFTER_CONFIG_CANCEL', true);
        require($CFG->dirroot . '/lib/setup.php');

        if ($tests_to_run[$heartbeat_label]) {
            $heartbeat_test = HeartbeatTests::is_db_readable();
            $heartbeat_dbconnection_success = true;
        }
    } catch (Exception $e) {
        if ($e->errorcode == 'dbconnectionfailed') {
            $heartbeat_test = false;
            $heartbeat_dbconnection_success = false;
        }
    }
    if ($tests_to_run[$heartbeat_label]) {
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'available' : "{$CFG->dbhost}:{$CFG->dbname} is not available"), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is the DB maintenance mode is enabled?
    $heartbeat_label = 'DB_MAINTENANCE_MODE';
    if ($heartbeat_dbconnection_success && $tests_to_run[$heartbeat_label]) {
        $heartbeat_test = !HeartbeatTests::is_dbmaintenance_enabled();
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_WARNING), ($heartbeat_test ? 'not enabled' : 'enabled'), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is a Moodle upgrade pending?
    //Requires: DB.
    $heartbeat_label = 'UPGRADE_PENDING';
    if ($heartbeat_dbconnection_success && $tests_to_run[$heartbeat_label]) {
        require_once($CFG->dirroot . '/lib/moodlelib.php');
        $heartbeat_test = !HeartbeatTests::is_upgrade_pending();
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, ($heartbeat_test ? STATUS_OK : STATUS_WARNING), ($heartbeat_test ? 'no upgrade needed' : 'upgrade is pending'), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is a Moodle upgrade pending?
    //Requires: DB.
    $heartbeat_label = 'CRON_TASKS';
    if ($heartbeat_dbconnection_success && $tests_to_run[$heartbeat_label]) {
        //List any scheduled tasks that are known-bad using their class name e.g. \core\task\question_cron_task
        $heartbeat_skip_cron_tasks = array(
            '\core\task\question_cron_task',
        );
        //Check the last run on these tasks even if they have status=disabled
        //E.g. if you run the scheduled task manually separate from the usual Moodle-cron
        //"Plugin disabled" tasks are still ignored regardless of this setting.
        $heartbeat_include_disabled_tasks = array(
            '\mod_forum\task\cron_task',
            '\mod_hsuforum\task\cron_task',
        );

        list($heartbeat_test, $heartbeat_test_msg) = HeartbeatTests::check_cron_tasks($heartbeat_skip_cron_tasks, $heartbeat_include_disabled_tasks);
        error_log(__FILE__ . '::' . __LINE__ . "::{$heartbeat_label}::Got result \$heartbeat_test={$heartbeat_test}; message={$heartbeat_test_msg}");
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, $heartbeat_test, ($heartbeat_test === STATUS_OK ? 'cron is fine' : $heartbeat_test_msg), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------
    //
    //------------------------------------------------------------------------------
    //Test: Is there Redis tasks hanging around too long?
    //Requires: Redis.
    $heartbeat_redis_class_exists = class_exists('Redis') && is_readable($CFG->dirroot . '/lib/classes/session/redis.php');
    $heartbeat_label = 'REDIS_ITEM_STUCK';
    if ($heartbeat_redisconnection_success && $tests_to_run[$heartbeat_label]) {
        $heartbeat_redis_items_and_limits = array(
            '*modinfo_build_course_cache_*'  => 10 * 60 /* 10 minutes */,
            '*\mod_forum\task\cron_task*'    => 1.5 * 60 * 60 /* 1.5 hours */,
            '*\mod_hsuforum\task\cron_task*' => 1.5 * 60 * 60 /* 1.5 hours */,
        );

        list($heartbeat_test, $heartbeat_test_msg) = HeartbeatTests::is_redis_item_stuck($heartbeat_redis_items_and_limits);
        $heartbeat_test_results[] = new HeartbeatTestResult($heartbeat_label, $heartbeat_test, ($heartbeat_test === STATUS_OK ? 'OK' : $heartbeat_test_msg), HeartbeatPerfInfo::get_usermicrotime());
        $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$heartbeat_label}");
    }
    //------------------------------------------------------------------------------
    //
    //If we get here we probably have not hit a fatal exception
    if ($heartbeat_test_results) {
        heartbeat_print_test_results($heartbeat_test_results, $heartbeat_label);
    } else {
        echo 'No results' . ($heartbeat_is_cli ? '' : BRNL);
    }

    $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . '::Done all tests');
    //------------------------------------------------------------------------------
} catch (Exception $e) {
    $heartbeat_debug && error_log(__FILE__ . '::' . __LINE__ . '::In the main Exception handler');
    error_log(print_r($e, true));

    if (!$heartbeat_is_cli) {
        echo 'Hit an exception' . BRNL;

        if ($e->errorcode == 'dbconnectionfailed') {
            die('dbconnectionfailed');
        }

        echo '<PRE>' . print_r($e, true) . '</PRE>';
    }

    //Exit with error code
    exit(1);
}
unset($heartbeat_debug);
