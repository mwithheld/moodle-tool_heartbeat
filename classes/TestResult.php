<?php

require_once(dirname(__DIR__) . '/lib.php');

/**
 * Holds one test's info: label, status, perfinfo, etc
 */
class TestResult {

    public $label = 'UNKNOWN_LABEL';
    public $status = STATUS_CRITICAL;
    public $info = '';
    public $perfinfo = null;
    public $format = 'nagios';

    public function __construct($label, $status = STATUS_CRITICAL, $info = null, $perfinfo = null) {
        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . "::Started with \$label=$label; \$status=$status; \$info=$info; \$perfinfo=$perfinfo;");

        $this->label = preg_replace('/\s+/', '_', strtoupper(trim($label ?? $this->label)));
        if (empty($label)) {
            throw new InvalidArgumentException('The label must not be empty');
        }
        $this->status = $status ?? $this->status;
        $this->info = $info ?? $this->info ?? '';
        $this->perfinfo = $perfinfo ?? '';
    }

    public function __toString() {
        switch ($this->format) {
            case 'json':
                $this_arr = (array) ($this);
                unset($this_arr['format']);
                print json_encode($this_arr);
                break;
            case 'text':
            case 'nagios':
            default:
                print "{$this->status}: {$this->label}" . ($this->info ? " $this->info" : '') . ($this->perfinfo ? " | {$this->perfinfo}" : '') . BRNL;
        }
    }

}
