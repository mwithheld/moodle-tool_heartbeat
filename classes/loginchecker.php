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
 * CRON health check
 *
 * @package    tool_loginchecker
 * @copyright  2015 Brendan Heywood <brendan@catalyst-au.net>
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

class loginchecker
{

    public static function parse_log_data($tablequery)
    {
        $count = 0;
        $topip;
        $topuser;
        $topcount = 0;

        foreach ($tablequery as $row) {

            $currentcount = $row->logincount;

            if ($currentcount > $topcount) {
                $topcount = $currentcount;
                $topip = $row->ip;
            }

            $count += $currentcount;

        }

        return $count;
    }

    public function send_good($msg)
    {
        global $now;
        printf("OK: $msg\n(Checked $now)\n");
    }

    public function send_warning($msg)
    {
        global $now;
        printf("WARNING: $msg\n(Checked $now)\n");
    }

    public function send_critical($msg)
    {
        global $now;
        printf("CRITICAL: $msg\n(Checked $now)\n");
    }

    public static function test_log_data($count, $criticalthreshold, $warningthreshhold, $logindelay)
    {

        if ($count > $criticalthreshold) {
            $timeinmins = $logindelay / 60;
            send_critical("$count failed logins in the last $timeinmins minute(s).");
        } else if ($count > $warningthreshhold) {
            $timeinmins = $logindelay / 60;

            send_warning("$count Failed logins in the last $timeinmins minute(s).");
        } else {
            echo "$logindelay\n";
            echo "$criticalthreshold\n";
            echo "$warningthreshhold\n";

            send_good("Normal Login behaivour\n");
        }

    }
}
