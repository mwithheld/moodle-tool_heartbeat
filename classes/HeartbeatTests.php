<?php

class HeartbeatTests {

    /**
     * Checks if the command line maintenance mode has been enabled
     *
     * @param string $configfile The relative path for config.php
     * @return bool True if climaintenance.html is found.
     */
    static function is_climaintenance_enabled($configfile) {
        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started with $configfile=' . $configfile);

        $content = preg_replace(array(
            '#[^!:]//#', /* Set comments to be on newlines, replace '//' with '\n//', where // does not start with : */
            '/;/', /* Split up statements, replace ';' with ';\n' */
            '/^[\s]+/m' /* Removes all initial whitespace and newlines. */
                ), array("\n//", ";\n"), file_get_contents($configfile));

        $re = '/^\$CFG->dataroot\s+=\s+["\'](.*?)["\'];/m';  // Lines starting with $CFG->dataroot
        $matches = array();
        preg_match($re, $content, $matches);
        if (!empty($matches)) {
            $dataroot = $matches[count($matches) - 1];
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Found dataroot=' . $dataroot);
            $climaintenance = $dataroot . '/climaintenance.html';

            if (file_exists($climaintenance)) {
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Found $climaintenance=true');
                return true;
            }
        }

        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Found $climaintenance=false');
        return false;
    }

    static function is_dbmaintenance_enabled() {
        global $CFG;

        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started - empty($CFG)=' . empty($CFG));

        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Checking $CFG->maintenance_enabled=' . print_r($CFG->maintenance_enabled, true));
        $result = isset($CFG->maintenance_enabled) && !empty($CFG->maintenance_enabled);
        return $result;
    }

    static function is_upgrade_pending() {
        return moodle_needs_upgrading();
    }

    static function is_moodledata_writable($path_to_moodledata) {
        $testFile = "{$path_to_moodledata}/tool_heartbeat.test";

        //Create/overwrite the file, write one byte, and check it got written
        $result = file_put_contents($testFile, '1') === 1;

        //Returns TRUE if the filename exists and is writable
        $result &= is_writable($testFile);

        //Cleanup
        unlink($testFile);

        return $result;
    }

    static function is_db_readable() {
        global $DB;

        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started - empty($DB)=' . empty($DB));

        try {
            // Try to get the first record from the user table.
            if ($DB->get_record_sql('SELECT id FROM {user} WHERE id > 0', null, IGNORE_MULTIPLE)) {
                return true;
            } else {
                error_log('FATAL error: The Moodle DB user table has no users');
                return false;
            }
        } catch (Exception $e) {
            error_log('FATAL Exception: The Moodle DB is not available');
            $debug && error_log(print_r($e, true));
            return false;
        } catch (Throwable $e) {
            error_log('FATAL Throwable: The Moodle DB is not available');
            $debug && error_log(print_r($e, true));
            return false;
        }
    }

    static function is_redis_readable() {
        global $CFG;

        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started - empty($CFG)=' . empty($CFG));

        try {
            $redis = new Redis();
            $success = $redis->connect($CFG->session_redis_host, $CFG->session_redis_port);
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . "::Host={$CFG->session_redis_host}; Port=${$CFG->session_redis_port}; Connection success=" . $success);

            if (!$success || !$redis->ping()) {
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Connect failed');
                $is_redis_ready = false;
            } else if (!$redis->ping()) {
                //This throws an exception if the ping fails and returns String '+PONG' on success
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Ping failed');
                $is_redis_ready = false;
            } else {
                $is_redis_ready = true;
            }
        } catch (Exception $unused) {
            $is_redis_ready = false;
            error_log('PHP Fatal error: Redis is not available at ' . __FILE__ . ':' . __LINE__);
        }

        return $is_redis_ready;
    }

    /**
     * Returns STATUS_WARNING if the matching redis $key_pattern has object idletime > $time_limit_seconds
     * Note that idletime = number of seconds since the object stored at the specified key's last *read or write* operation +/- 10 seconds.
     * So...
     *    THIS METHOD CHANGES THE IDLETIME of they key just by reading it!!!
     *
     * @global type $CFG
     * @param Array $items_and_limits - array($key_pattern, $time_limit_seconds)
     * @return STATUS_*
     */
    static function is_redis_item_stuck($items_and_limits) {
        global $CFG;
        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started with $items_and_limits=' . print_r($items_and_limits, true));

        $redis = new Redis();
        $redis->connect($CFG->session_redis_host, $CFG->session_redis_port);

        //Look for each item with single backslashes (e.g. \mod_forum\task\cron_task) and double (e.g. \\mod_forum\\task\\cron_task)
        foreach ($items_and_limits as $key => $value) {
            $items_and_limits[preg_replace('/\\\+/', '\\\\\\', $key)] = $value;
        }
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Built $items_and_limits=' . print_r($items_and_limits, true));

        $stuck_items = array();
        foreach ($items_and_limits as $key_pattern => $time_limit_seconds) {
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Looking at $key_pattern=' . $key_pattern . '; $time_limit_seconds (minutes)=' . $time_limit_seconds / 60);
            foreach ($redis->keys($key_pattern) as $key) {
                //In seconds, with precision 10 seconds
                $idletime = $redis->object('idletime', $key);
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Looking at key=' . $key . '; $idletime=' . $idletime);

                if ($idletime > $time_limit_seconds) {
                    $stuck_items[] = "Key {$key} idle since {$idletime} seconds (limit={$time_limit_seconds}); value=" . $redis->get($key);
                }
            }
        }

        if (!empty($stuck_items)) {
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::About to return STATUS_WARNING');
            return array(STATUS_WARNING, implode('; ', $stuck_items));
        }

        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::About to return STATUS_OK');
        return array(STATUS_OK, 'OK');
    }

    static function check_muc_config() {
        global $CFG;

        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started - empty($CFG)=' . empty($CFG));

        $result = false;

        try {
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . "::About to require_once={$CFG->dataroot}/muc/config.php");
            //Do not require_once here b/c Moodle has already loaded this config, and require_once will mean $configuration is empty
            require("{$CFG->dataroot}/muc/config.php");
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Loaded the MUC config; $configuration=' . print_r($configuration, true));

            if (
                    !isset($configuration) ||
                    (!isset($configuration['siteidentifier']) || !is_string($configuration['siteidentifier'])) ||
                    (!isset($configuration['stores']) || !is_array($configuration['stores']) || empty($configuration['stores'])) ||
                    (!isset($configuration['modemappings']) || !is_array($configuration['modemappings']) || empty($configuration['modemappings'])) ||
                    (!isset($configuration['definitions']) || !is_array($configuration['definitions']) || empty($configuration['definitions'])) ||
                    (!isset($configuration['definitionmappings']) || !is_array($configuration['definitionmappings']) /* It's OK if this is empty: || empty($configuration['definitionmappings']) */)
            ) {
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::MUC config sanity check failed');
                $result = false;
            } else {
                $result = true;
            }
        } catch (Exception $e) {
            error_log('PHP Fatal error: MUC config is not available or corrupt at ' . __FILE__ . ':' . __LINE__);
            $result = false;
        }

        return $result;
    }

    function check_cron_tasks($skip_cron_tasks = array(), $include_disabled_tasks = array()) {
        global $DB;
        $debug = false;
        if ($debug) {
            error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started with $skip_cron_tasks=' . print_r($skip_cron_tasks, true));
            error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started with $include_disabled_tasks=' . print_r($include_disabled_tasks, true));
        }

        $cron_critical_time = 6;   // Hours. Threshold for no cron run error in hours
        $cron_warn_time = 2;   // Hours. Threshold for no cron run warn in hours
        $delayerror = 600; // Minutes. Threshold for fail delay cron error in minutes
        $delaywarn = 60;  // Minutes. Threshold for fail delay cron warn in minutes

        $date_format = '%b %d %H:%M:%S';

        //What is the last time the cron ran?
        $lastruntime = $DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
        $currenttime = time();
        $difference = $currenttime - $lastruntime;
        $when = userdate($lastruntime, $date_format);
        if ($difference > $cron_critical_time * 60 * 60) {
            return array(STATUS_CRITICAL, "Moodle cron ran > {$cron_critical_time} hours ago at {$when}");
        }
        if ($difference > $cron_warn_time * 60 * 60) {
            return array(STATUS_WARNING, "Moodle cron ran > {$cron_warn_time} hours ago at {$when}");
        }

        $delay_info_arr = array();
        $max_task_delay_secs = 0;
        $tasks = core\task\manager::get_all_scheduled_tasks();

        foreach ($tasks as $task) {
            $task_classname = '\\' . ltrim(get_class($task), '\\');
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Looking at task=' . print_r($task, true));

            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::In skip array=' . in_array($task_classname, $skip_cron_tasks));
            if (in_array($task_classname, $skip_cron_tasks)) {
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Task is in the should_skip array, so skip it; name=' . $task_classname);
                continue;
            }

            $in_disabled_tasks = in_array($task_classname, $include_disabled_tasks);
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::In disabled array=' . $in_disabled_tasks);
            if (!$in_disabled_tasks && $task->get_disabled()) {
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Skipping task=' . $task_classname);
                continue;
            }

            //Only skip those with faildelay==0 if they are *not* in the include_disabled array
            if ($in_disabled_tasks) {
                $lastruntime = $task->get_last_run_time();
                $task_frequency_sec = $task->get_next_scheduled_time() - $currenttime;
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . "'::For this \$in_disabled_tasks task, comparing \$currenttime=$currenttime vs lastruntime=" . $lastruntime . '; $task_frequency (min)=' . $task_frequency_sec / 60);

                //This task should run every $task_frequency_sec seconds
                //"Should have run" == It is later than the $lastruntime + $task_frequency_sec + ($delaywarn in seconds)
                if ($currenttime > $lastruntime + $task_frequency_sec + $delaywarn * 60) {
                    $max_task_delay_secs = $currenttime - $lastruntime;
                } else {
                    continue;
                }
            } else {
                $faildelay = $task->get_fail_delay();
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Got $faildelay=' . $faildelay);

                if ($faildelay == 0) {
                    $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Not in_disabled_tasks and faildelay=0, so continue');
                    continue;
                }
                if ($faildelay > $max_task_delay_secs) {
                    $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::$faildelay > $max_task_delay_secs');
                    $max_task_delay_secs = $faildelay;
                }
            }

            $delay_info_arr[] = $task_classname;
        }
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Done the for loop; $max_task_delay_secs=' . $max_task_delay_secs . '; $delay_info_arr=' . print_r($delay_info_arr, true));

        $delay_info = implode('; ', $delay_info_arr);
        $maxminsdelay = $max_task_delay_secs / 60;
        if ($maxminsdelay > $delayerror) {
            return array(STATUS_CRITICAL, "Moodle task not run since > {$delayerror} mins: {$delay_info}");
        } else if ($maxminsdelay > $delaywarn) {
            return array(STATUS_WARNING, "Moodle task not run since > {$delaywarn} mins: {$delay_info}");
        }

        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::About to return STATUS_OK');
        return array(STATUS_OK, 'cron tasks are running normally');
    }

}
