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

    static function is_dbmaintenance_enabled($configfile) {
        return isset($configfile->maintenance_enabled) ? $configfile->maintenance_enabled : false;
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

    static function is_db_readable(/* moodle_database */ $moodle_db) {
        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started');

        try {
            // Try to get the first record from the user table.
            if ($moodle_db->get_record_sql('SELECT id FROM {user} WHERE id > 0', null, IGNORE_MULTIPLE)) {
                return true;
            } else {
                error_log('FATAL: The Moodle DB user table has no users');
                return false;
            }
        } catch (Exception $e) {
            error_log('FATAL: The Moodle DB is not available');
            return false;
        } catch (Throwable $e) {
            error_log('FATAL: The Moodle DB is not available');
            return false;
        }
    }

    static function is_redis_readable($CFG) {
        try {
            $redis = new Redis();
            $redis->connect($CFG->session_redis_host, $CFG->session_redis_port);
            //This throws an exception if the ping fails and returns String '+PONG' on success
            if (!$redis->ping()) {
                //I put this in here in case the interface changes in the future
                $is_redis_ready = false;
            }
        } catch (Exception $unused) {
            $is_redis_ready = false;
            error_log('PHP Fatal error: Redis is not available at ' . __FILE__ . ':' . __LINE__);
        }

        return $is_redis_ready;
    }

    static function check_muc_config($CFG) {
        $result = false;
        
        try {
            require_once("{$CFG->dataroot}/muc/config.php");
            if( 
                    !isset($configuration) ||
                    (!isset($configuration['siteidentifier']) || !is_string($configuration['siteidentifier'])) ||
                    (!isset($configuration['stores']) || !is_array($configuration['stores']) || empty($configuration['stores'])) ||
                    (!isset($configuration['modemappings']) || !is_array($configuration['modemappings']) || empty($configuration['modemappings'])) ||
                    (!isset($configuration['definitions']) || !is_array($configuration['definitions']) || empty($configuration['definitions'])) ||
                    (!isset($configuration['definitionmappings']) || !is_array($configuration['definitionmappings']) /* It's OK if this is empty: || empty($configuration['definitionmappings']) */)
            ) {
                $result = false;
            } else {
                $result = true;
            }
        } catch (Exception $e) {
            $result = false;
            error_log('PHP Fatal error: MUC config is not available or corrupt at ' . __FILE__ . ':' . __LINE__);
        }
        
        return $result;
    }
}
