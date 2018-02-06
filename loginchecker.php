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
 * Failed Login Checker
 *
 * @package    tool_heartbeat
 * @copyright  2018 Paul Damiani <pauldamiani@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * This can be run either as a web api, or on the CLI. When run on the
 * CLI it conforms to the Nagios plugin standard.
 *
 * See also:
 *  - http://nagios.sourceforge.net/docs/3_0/pluginapi.html
 *  - https://nagios-plugins.org/doc/guidelines.html#PLUGOUTPUT
 *
 */

define('NO_UPGRADE_CHECK', true);
require_once(__DIR__ . '/../../../config.php');

$warningthreshhold = get_config('tool_heartbeat', 'logwarningthresh'); // Logins.
$criticalthreshold = get_config('tool_heartbeat', 'logcriticalthresh'); // Logins.
$logindelay = get_config('tool_heartbeat', 'logtimecheck'); // Minutes.

$dirroot = '../../../';

$checktime = time() - $logindelay;

if (isset($argv)) {
    // If run from the CLI.
    define('CLI_SCRIPT', true);

    $last = $argv[count($argv) - 1];
    if (preg_match("/(.*):(.+)/", $last, $matches)) {
        $last = $matches[1];
    }
    if ($last && is_dir($last)) {
        $dirroot = $last . '/';
        array_pop($_SERVER['argv']);
    }

    require($dirroot . 'config.php');
    require_once($CFG->libdir . '/clilib.php');

    list($options, $unrecognized) = cli_get_params(
        array(
            'help' => false,
            'critthresh' => $criticalthreshold,
            'warnthresh' => $warningthreshhold,
            'logtime' => $logindelay,
        ),
        array(
            'h' => 'help',
        )
    );

    if ($unrecognized) {
        $unrecognized = implode("\n  ", $unrecognized);
        cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
    }

    if ($options['help']) {
        print "Check the moodle cron system for when it last ran and any task fail delays

        croncheck.php [options] [moodle path]

        Options:
        -h, --help            Print out this help
            --critthresh=n    Threshold for number of failed logins to trigger a critical error (default $criticalthreshold)
            --warnthresh=n    Threshold for number of failed logins to trigger a warning (default $warningthreshhold)
            --logtime=n       Time in minutes to check back for a critical error (default $logindelay)

        Example:
        \$sudo -u www-data /usr/bin/php admin/tool/heartbeal_heartbeat.php";

        die;
    }

} else {
    // If run from the web.
    define('NO_MOODLE_COOKIES', true);
    $options = array(
        'critthresh' => optional_param('critthresh', $criticalthreshold, PARAM_NUMBER),
        'warnthresh' => optional_param('warnthresh', $warningthreshhold, PARAM_NUMBER),
        'logtime' => optional_param('logtime', $logindelay, PARAM_NUMBER),
    );
    header("Content-Type: text/plain");

    // Make sure varnish doesn't cache this. But it still might so go check it!
    header('Pragma: no-cache');
    header('Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate, proxy-revalidate');
    header('Expires: Tue, 04 Sep 2012 05:32:29 GMT');
}


$format = '%b %d %H:%M:%S';

$now = userdate(time(), $format);

function send_good($msg) {
    global $now;
    printf("OK: $msg\n(Checked $now)\n");
    exit(0);
}

function send_warning($msg) {
    global $now;
    printf("WARNING: $msg\n(Checked $now)\n");
    exit(1);
}

function send_critical($msg) {
    global $now;
    printf("CRITICAL: $msg\n(Checked $now)\n");
    exit(2);
}

if (moodle_needs_upgrading()) {
    send_critical("Moodle upgrade pending, login checker execution suspended");
}

if ($CFG->branch < 27) {
    send_good("MOODLE LOGIN CHECKER RUNNING\n");
}

$testing = get_config('tool_heartbeat', 'logtesting');
if ($testing == 'error') {
    send_critical("Moodle this is a test $CFG->wwwroot/admin/settings.php?section=tool_heartbeat\n");
} else if ($testing == 'warn') {
    send_warning("Moodle this is a test $CFG->wwwroot/admin/settings.php?section=tool_heartbeat\n");
}

    $sqlstring = 'SELECT count(*) AS logincount,other, origin, ip FROM mdl_logstore_standard_log WHERE target = "user_login" 
    AND timecreated > ' . $checktime . ' GROUP BY other, origin, ip order BY logincount desc';
    $tablequery = $DB->get_records_sql($sqlstring);

    $faildlogcount = parse_log_data($tablequery);

function parse_log_data($tablequery) {
    $count = 0;
    $topip;
    $topuser;
    $topcount = 0;

    foreach ($tablequery as $row) {

        $currentcount = $row->logincount;

        $count += $currentcount;
    }

    return $count;
}

    test_log_data($faildlogcount, $criticalthreshold, $warningthreshhold, $logindelay);

function test_log_data($count, $criticalthreshold, $warningthreshhold, $logindelay) {

    if ($count > $criticalthreshold) {
        $timeinmins = $logindelay / 60;
        send_critical("$count failed logins in the last $timeinmins minute(s).");
    } else if ($count > $warningthreshhold) {
        $timeinmins = $logindelay / 60;
        send_warning("$count Failed logins in the last $timeinmins minute(s).");
    } else {
        send_good("Normal Login behaivour\n");
    }

}
