<?php

class HeartbeatTests {

    /**
     * Check if PHP is running from the command line
     * @return true if PHP is running from the command line
     */
    static function is_cli() {
        //Note cli-server is PHP's built-in webserver
        return php_sapi_name() == 'cli';
    }

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

    static function is_db_writable(/* moodle_database */ $moodle_db) {
        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started');

        try {
            // Try to get the first record from the user table.
            if (false) {// && $moodle_db->get_record_sql('SELECT id FROM {user} WHERE id > 0', null, IGNORE_MULTIPLE)) {
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

}
