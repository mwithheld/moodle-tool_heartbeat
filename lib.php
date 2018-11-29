<?php

define('BRNL', '<br>' . PHP_EOL);
//Use nagios return codes ref https://nagios-plugins.org/doc/guidelines.html#AEN78
define('STATUS_OK', 'OK');
define('STATUS_WARNING', 'WARNING');
define('STATUS_CRITICAL', 'CRITICAL');

function getArrayFiltered($aFilterKey, $aFilterValue, $array) {
    $filtered_array = array();
    foreach ($array as $value) {
        if (isset($value->$aFilterKey)) {
            if ($value->$aFilterKey == $aFilterValue) {
                $filtered_array[] = $value;
            }
        }
    }

    return $filtered_array;
}

/**
 * Output one test's result in various formats
 *
 * @param TestResult $test_result
 * @param string $format Optional. Output format.  Valid values are: json, text, nagios.  Default is nagios.
 * @throws InvalidArgumentException
 */
function heartbeat_print_test_result(TestResult $test_result, $format = 'nagios') {
    $debug = true;
    $debug && error_log(__FUNCTION__ . '::Started with $test_result=' . print_r($test_result, true));

    if (empty($test_result)) {
        print '';
        return false;
    }

    $test_result->format = $format;
    echo $test_result->__toString();
}

function heartbeat_print_test_results(Array $all_test_results, $format = 'nagios') {
    $debug = true;
    $debug && error_log(__FUNCTION__ . '::Started with $all_test_results=' . print_r($all_test_results, true));

    $label = 'HEARTBEAT';
    $overallTestResult = new TestResult($label, STATUS_OK, 'success', PerfInfo::get_usermicrotime());
    $reason = 'Because of this test: ';

    //If we have a warning, show the first one as our overall failure message
    $overallTestResultArr = getArrayFiltered('status', STATUS_WARNING, $all_test_results);
    if (!empty($overallTestResultArr)) {
        $overallTestResult->status = $overallTestResultArr[0]->status;
        $overallTestResult->info = $reason . $overallTestResultArr[0]->label;
    }

    //If we have a critical, use the first one as our overall failure message instead
    $overallTestResultArr = getArrayFiltered('status', STATUS_CRITICAL, $all_test_results);
    if (!empty($overallTestResultArr)) {
        $overallTestResult->status = $overallTestResultArr[0]->status;
        $overallTestResult->info = $reason . $overallTestResultArr[0]->label;
    }

    //Add the overall test result so it is output at the top
    array_unshift($all_test_results, $overallTestResult);

    // - Return a Nagios-compliant header: 503 HTTP code with a reason
    if ($overallTestResult->info != STATUS_OK) {
        header('HTTP/1.0 503 Service unavailable to end-users: ' . STATUS_WARNING . ': ' . $reason);
    }

    foreach ($all_test_results as $test_result) {
        heartbeat_print_test_result($test_result, $format = 'nagios');
    }
}
