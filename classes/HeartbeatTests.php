<?php

class HeartbeatTests {

    /**
     * Checks if the command line maintenance mode has been enabled
     *
     * @param string $configfile The relative path for config.php
     * @return bool True if climaintenance.html is found.
     */
    static function is_climaintenance_enabled($configfile) {
        $content = preg_replace(array(
            '#[^!:]//#', /* Set comments to be on newlines, replace '//' with '\n//', where // does not start with : */
            '/;/', /* Split up statements, replace ';' with ';\n' */
            '/^[\s]+/m' /* Removes all initial whitespace and newlines. */
                ), array("\n//", ";\n"), file_get_contents($configfile));

        $re = '/^\$CFG->dataroot\s+=\s+["\'](.*?)["\'];/m';  // Lines starting with $CFG->dataroot
        $matches = array();
        preg_match($re, $content, $matches);
        if (!empty($matches)) {
            $climaintenance = $matches[count($matches) - 1] . '/climaintenance.html';

            if (file_exists($climaintenance)) {
                return true;
            }
        }

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
        global $CFG;

        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started - empty($CFG)=' . empty($CFG));

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

    static function check_muc_config($CFG) {
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

}
