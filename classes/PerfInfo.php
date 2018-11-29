<?php
require_once(dirname(__DIR__).'/lib.php');

/**
 * Holds one performance info item
 * Outputs the performance info in Nagios-compliant format
 * Ref https://nagios-plugins.org/doc/guidelines.html#AEN200
 */
class PerfInfo {

    public $label = 'unknown_measure';
    //Unit of measure
    public $uom = '';
    public $warn = '';
    public $crit = '';
    public $min = '';
    public $max = '';
    private $optional_params = ['uom', 'warn', 'crit', 'min', 'max'];

    public function __construct($label, $value, $uom = null, $warn = null, $crit = null, $min = null, $max = null) {
        $debug = false;
        if (empty(trim($label))) {
            throw new InvalidArgumentException('The measure name must not be empty');
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('The measure value must be numeric');
        }

        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::After the param checks');
        $this->label = $label;
        $this->value = $value;

        foreach ($this->optional_params as $param) {
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . "::Looking at \$param=$param; value={$$param}");
            $param_value = $$param;
            if ($param_value) {
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Found value=' . $param_value);
                $this->$param = $$param;
            }
        }
    }

    static function get_usermicrotime() {
        return new self('usermicrotimee', getrusage()['ru_utime.tv_usec'], 'us');
    }

    function __toString() {
        $debug = false;
        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Started with data=' . print_r($this, true));

        $str = "$this->label=$this->value";

        foreach ($this->optional_params as $param) {
            $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . "::Looking at param=$param; isset=" . isset($this->$param) . "; value=" . $this->$param);
            $param_value = $this->$param;
            if ($param_value) {
                $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::Found value=' . $param_value);
                $str .= "[$param_value];";
            }
        }

        $str = rtrim($str, ';');

        $debug && error_log(__CLASS__ . '::' . __FUNCTION__ . '::About to return str=' . $str);
        return $str;
    }

}