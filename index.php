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
error_reporting(E_ALL | E_STRICT);

require_once('lib.php');

$debug = false;
$debug && error_log(__FILE__ . '::' . __LINE__ . '::Started');

//Collect test results here
//For each test result:
//  - The testname is required to meet Nagios specifications
//  - Testname will be converted to ALL_CAPS and ONE_WORD for easier automated log parsing/processing
$test_results = array();

require_once(__DIR__ . '/classes/TestResult.php');
require_once(__DIR__ . '/classes/PerfInfo.php');
require_once(__DIR__ . '/classes/HeartbeatTests.php');

//------------------------------------------------------------------------------
//Test: The most basic health check possible - is this health check started?
//This tells us PHP, the underlying httpd (apache, nginx etc), and the server itself (OS down to hardware) is working
$label = 'HEARTBEAT_START';
$test_results[] = new TestResult($label, STATUS_OK, 'started', PerfInfo::get_usermicrotime());
$debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$label} \$test_results=" . print_r($test_results, true));
//------------------------------------------------------------------------------

$is_cli = HeartbeatTests::is_cli();

try {

    //GET params defaults
    $fullcheck = false;
    if ($is_cli) {
        //Make sure Moodle knows this is CLI
        define('CLI_SCRIPT', true);
        $fullcheck = count($argv) > 1 && $argv[1] === 'fullcheck';
    } else {
        define('NO_MOODLE_COOKIES', true);
        $fullcheck = isset($_GET['fullcheck']);
    }

    define('NO_UPGRADE_CHECK', true);
    define('ABORT_AFTER_CONFIG', true);
    define('HEARTBEAT', true);
    $debug && error_log(__FILE__ . '::' . __LINE__ . '::Done CLI check');

    //Point this at Moodle's config.php
    define('PATH_TO_MOODLE_CONFIG', __DIR__ . '/../../../config.php');

    //------------------------------------------------------------------------------
    //Test: Is Moodle in maintenance mode?
    $label = 'CLI_MAINTENANCE_MODE';
    $heartbeat_test = !HeartbeatTests::is_climaintenance_enabled(PATH_TO_MOODLE_CONFIG);
    $test_results[] = new TestResult($label, ($heartbeat_test ? STATUS_OK : STATUS_WARNING), ($heartbeat_test ? 'not enabled' : 'enabled'), PerfInfo::get_usermicrotime());
    $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$label} \$test_results=" . print_r($test_results, true));
    //------------------------------------------------------------------------------

    $debug && error_log(__FILE__ . '::' . __LINE__ . '::About to require_once ' . PATH_TO_MOODLE_CONFIG);
    try {
        require_once(__DIR__ . '/../../../config.php');
    } catch (Exception $e) {
        die('FAILED to load Moodle config.php');
    }
    $debug && error_log(__FILE__ . '::' . __LINE__ . '::Loaded Moodle config.php');

    global $CFG;
    if (empty($CFG)) {
        throw new Exception('Moodle $CFG must be defined; make sure you have includes config.php');
    }
    if (empty($CFG->dataroot)) {
        throw new Exception('Moodle $CFG must define dataroot');
    }

    //------------------------------------------------------------------------------
    //Test: Is the dataroot writable?
    $label = 'MOODLEDATA';
    $heartbeat_test = HeartbeatTests::is_moodledata_writable($CFG->dataroot);
    $test_results[] = new TestResult($label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'writable' : $CFG->dataroot . ' not writable'), PerfInfo::get_usermicrotime());
    $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$label} \$test_results=" . print_r($test_results, true));
    //------------------------------------------------------------------------------
    // Optionally check database configuration and access (slower).
    if ($fullcheck) {
        //------------------------------------------------------------------------------
        //Test: Is the DB available?
        define('ABORT_AFTER_CONFIG_CANCEL', true);
        require_once($CFG->dirroot . '/lib/setup.php');
        global $DB;

        $label = 'MOODLE_DB';
        $heartbeat_test = HeartbeatTests::is_db_writable($DB);
        $test_results[] = new TestResult($label, ($heartbeat_test ? STATUS_OK : STATUS_CRITICAL), ($heartbeat_test ? 'available' : ' not available'), PerfInfo::get_usermicrotime());
        $debug && error_log(__FILE__ . '::' . __LINE__ . "::Done test={$label} \$test_results=" . print_r($test_results, true));
    }
    //------------------------------------------------------------------------------
    //If we get here we probably have not hit a fatal exception
    heartbeat_print_test_results($test_results, $label);

    $debug && error_log(__FILE__ . '::' . __LINE__ . '::Done all tests');
    //------------------------------------------------------------------------------
} catch (Exception $e) {
    if ($is_cli) {
        error_log(print_r($e, true));
    } else {
        error_log(print_r($e, true));

        echo 'Hit an exception' . BRNL;
        echo '<PRE>' . print_r($e, true) . '</PRE>';
    }

    //Exit with error code
    exit(1);
}