<?php
/**
 * This file contains MySQL queries for handling all AJAX calls made by jquery for performing user actions like
 * create/edit/clone/delete framework and tests. This file interacts with DB for making necessary changes in a
 * test/framework data as per action chosen by user
 */

require 'lib/auth.php';

/**
 * This function save all the framework details supplied by user from UI page create_edit_framework. It either inserts
 * new framework or update already existing framework
 *
 * @param $db - database handler
 * @return array - return array containing framework id and framework name
 */
function save_framework($db) {
    global $userId, $userIsAdmin;

    // Check for framework ID
    $frameworkId = getParam('f_frameworkid', 'POST');
    $isNewFramework = $frameworkId  ? false : true;
    $frameworkName = getParam('f_frameworkname', 'POST');
    if ($isNewFramework) {
	// Check if there are spaces in framework name
        if (preg_match('/\s/',$frameworkName)){
            returnError("Spaces are not allowed in framework name. Remove spaces");
        }
        // Check if framework name is unique
        $testFramework = getFrameworkByName($db, $frameworkName);
        if ($testFramework) {
            returnError("Framework '$frameworkName' already exists.");
        }
    } else {
        $frameworkData = getFrameworkById($db, $frameworkId);
        if (! $frameworkData) {
            returnError("Could not find framework ID: $frameworkId");
        }
        if (!$userIsAdmin && $userId != $frameworkData['frameworkowner']) {
            returnError("You are not the framework owner (" . $frameworkData['frameworkowner'] . ")");
        }
    }
    // Check if 'execution_script_location' is in valid format.
    $exec_script_location = getParam('f_execution_script_location', 'POST');
    $exec_script_location_dir = dirname($exec_script_location);
    if (($exec_script_location_dir == "/") || ($exec_script_location_dir == ".")){
        returnError("Execution script should contain <folder_name>/<script_name>");
    }

    // Four steps
    // 1) Insert or update base framework config
    // 2) Insert each test argument individually
    // 3) Delete all associated hosts (exec/reserved/statistics) for test (if any)
    // 4) Insert every host (exec/stat/reserved) individually
    // 5) Insert test report items

    if ($isNewFramework) {
        $query = "INSERT INTO ApplicationFrameworkMetadata ( argument_passing_format, creation_time, default_timeout, execution_script_location, frameworkname, frameworkowner, last_modified, productname, purpose, title) VALUES ( :argument_passing_format, NOW(), :default_timeout, :execution_script_location, :frameworkname, :frameworkowner, NOW(), :productname, :purpose, :title )";
    } else {
        $query = "UPDATE ApplicationFrameworkMetadata SET argument_passing_format = :argument_passing_format, default_timeout = :default_timeout, execution_script_location = :execution_script_location, frameworkname = :frameworkname, frameworkowner = :frameworkowner, last_modified = NOW(), productname = :productname, purpose = :purpose, title = :title WHERE frameworkid = :frameworkid";
    }

    try {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();

        $stmt = $db->prepare($query);
        // We could've created an array for all the bound values below, but I like
        // to be explicit with what we're inserting into the DB (eg. PDO::PARAM_STR)
        // For more details: http://wiki.hashphp.org/PDO_Tutorial_for_MySQL_Developers
        $stmt->bindValue(':argument_passing_format', getParam('f_argument_passing_format', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':default_timeout', getParam('f_default_timeout', 'POST') ?: 0, PDO::PARAM_INT);
        $stmt->bindValue(':execution_script_location', getParam('f_execution_script_location', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':frameworkname', getParam('f_frameworkname', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':frameworkowner', getParam('f_frameworkowner', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':productname', getParam('f_productname', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':purpose', getParam('f_purpose', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':title', getParam('f_title', 'POST'), PDO::PARAM_STR);

        if (!$isNewFramework) {
            $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
        }

        $stmt->execute();
        if ($isNewFramework) {
            $frameworkId = $db->lastInsertId();
        }

        if (!$frameworkId) {
            returnError("No framework ID generated.");
        }

        // Admin privileges (only on new framework)
        if ($isNewFramework) {
            $query = "INSERT INTO CommonFrameworkAuthentication ( administrator, frameworkid, username ) VALUES ( 1, :frameworkid, :username )";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
            $stmt->bindValue(':username', getParam('f_frameworkowner', 'POST'), PDO::PARAM_STR);
            $stmt->execute();
        }

        // Host associations
        // Execution
        if ($isNewFramework) {
            $query = "INSERT INTO HostAssociationType ( default_value, execution, frameworkid, name, statistics) VALUES ( :default_value, 1, :frameworkid, 'execution', 0 )";
        } else {
            $query = "UPDATE HostAssociationType SET default_value = :default_value WHERE frameworkid = :frameworkid AND name = 'execution'";
        }
        $stmt = $db->prepare($query);
        $stmt->bindValue(':default_value', getParam('f_execution_host', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
        $stmt->execute();
        $hostTypeIdExec = $db->lastInsertId();

        // Statistics
        if ($isNewFramework) {
            $query = "INSERT INTO HostAssociationType ( default_value, execution, frameworkid, name, statistics) VALUES ( :default_value, 0, :frameworkid, 'statistics', 1 )";
        } else {
            $query = "UPDATE HostAssociationType SET default_value = :default_value WHERE frameworkid = :frameworkid AND name = 'statistics'";
        }
        $stmt = $db->prepare($query);
        $stmt->bindValue(':default_value', getParam('f_statistics_host', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
        $stmt->execute();
        $hostTypeIdExec = $db->lastInsertId();

        // Test Report
        $query = "DELETE FROM TestResultFile WHERE frameworkid = :frameworkid";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
        $stmt->execute();

        $testReport = getParam('f_testreport', 'POST');
        if ($testReport) {
            // This count should be the same whether we look at 'filename' or 'row'
            $numItems = count($testReport['filename']);
            for ($x = 0; $x < $numItems; $x++) {
                $query = "INSERT INTO TestResultFile ( filename, filename_order, frameworkid, title ) VALUES ( :filename, :filename_order, :frameworkid, :title)";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':filename', $testReport['filename'][$x], PDO::PARAM_STR);
                $stmt->bindValue(':filename_order', $x, PDO::PARAM_INT);
                $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
                $stmt->bindValue(':title', $testReport['title'][$x], PDO::PARAM_STR);
                $stmt->execute();
            }
        }

        // Find all arguments with arg ID set (ie, not a new argument)
        $arguments = getParam('f_arguments', 'POST') ?: array();
        $argumentIds = array();
        foreach($arguments['arg_id'] as $argId) {
            // Ignore if empty
            if ($argId) {
                $argumentIds[] = $argId;
            }
        }

        // Delete all removed arguments
        $query = "DELETE FROM ApplicationFrameworkArgs WHERE frameworkid = ? ";
        if ($argumentIds) {
            $query .= "AND framework_arg_id NOT IN ( " . join(' , ', array_map(function () { return '?'; }, $argumentIds)) . " )";
        }
        $stmt = $db->prepare($query);
        $stmt->bindValue(1, $frameworkId, PDO::PARAM_INT);
        $idx = 1;
        foreach($argumentIds as $argId) {
            $stmt->bindValue(++$idx, $argId, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Insert each argument in order. If there is already an arg ID, update that
        // record.
        $argCount = count($arguments['argument_name']);
        for ($argIdx = 0; $argIdx < $argCount; $argIdx++) {
            if ($arguments['arg_id'][$argIdx]) {
                $query = "UPDATE ApplicationFrameworkArgs SET argument_default = :argument_default, argument_description = :argument_description, argument_name = :argument_name, argument_order = :argument_order, argument_values = :argument_values, frameworkid = :frameworkid, widget_type = :widget_type WHERE framework_arg_id = :framework_arg_id";
            } else {
                $query = "INSERT INTO ApplicationFrameworkArgs ( argument_default, argument_description, argument_name, argument_order, argument_values, frameworkid, widget_type ) VALUES ( :argument_default, :argument_description, :argument_name, :argument_order, :argument_values, :frameworkid, :widget_type )";
            }
            $stmt = $db->prepare($query);
            $stmt->bindValue(':argument_default', $arguments['argument_default'][$argIdx], PDO::PARAM_STR);
            $stmt->bindValue(':argument_description', $arguments['argument_description'][$argIdx], PDO::PARAM_STR);
            $stmt->bindValue(':argument_name', $arguments['argument_name'][$argIdx], PDO::PARAM_STR);
            $stmt->bindValue(':argument_order', $argIdx, PDO::PARAM_INT);
            $stmt->bindValue(':argument_values', $arguments['argument_values'][$argIdx], PDO::PARAM_STR);
            $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
            $stmt->bindValue(':widget_type', $arguments['widget_type'][$argIdx], PDO::PARAM_STR);
            if ($arguments['arg_id'][$argIdx]) {
                $stmt->bindValue(':framework_arg_id', $arguments['arg_id'][$argIdx], PDO::PARAM_INT);
            }
            $stmt->execute();
        }

        $db->commit();
    } catch(PDOException $e) {
        // All updates will be discarded if there's any fatal error
        $db->rollBack();
        returnError("MySQL error: " . $e->getMessage());
    }

    return array(
        'framework' => array(
            'frameworkid'   => $frameworkId,
            'frameworkname' => $frameworkName
        )
    );
}

/*  This function deletes framework from Daytona. Because of DB cascade setup, this framework deletion will delete all
    the data associated with that particular framework in other tables as well.
*/

/**
 * This function deletes framework from Daytona. Because of DB cascade setup, this framework deletion will delete all
 * the data associated with that particular framework in other tables as well
 *
 * @param $db - database handler
 * @return array - returns array containing framework id and framework name
 */

function delete_framework($db) {
    global $userId, $userIsAdmin;

    // Check for framework ID
    $frameworkId = getParam('f_frameworkid', 'POST');

    if (!$frameworkId) {
        returnError("No ID defined");
    }
    if (!is_numeric($frameworkId)) {
        returnError("frameworkid is not valid");
    }

    $frameworkData = getFrameworkById($db, $frameworkId);
    if (! $frameworkData) {
        returnError("Could not find framework ID: $frameworkId");
    }

    if (!$userIsAdmin && $frameworkData['frameworkowner'] !== $userId) {
        returnError("You are not an administrator or framework owner, you cannot delete this framework");
    }

    // DB is properly set up so if you delete the main framework configuration
    // data from ApplicationFrameworkMetadata, it will cascade down and delete the
    // associated entries in ApplicationFrameworkArgs, HostAssociationType, and
    // TestResultFile

    try {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();

        $query = "DELETE FROM ApplicationFrameworkMetadata WHERE frameworkid = :frameworkid";

        $stmt = $db->prepare($query);
        $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
        $stmt->execute();

	$framework_test_logs_path = "test_data/" . $frameworkData['frameworkname'];
        recursive_rmdir($framework_test_logs_path);

        $db->commit();
    } catch(PDOException $e) {
        // All updates will be discarded if there are any fatal errors
        $db->rollBack();
        returnError("MySQL error: " . $e->getMessage());
    }

    return array(
        'framework' => array(
            'frameworkid'   => $frameworkData['frameworkid'],
            'frameworkname' => $frameworkData['frameworkname']
        )
    );
}

/*  This function save all the test details supplied by user from UI page create_edit_test. It either inserts
    new test or update already existing test and save them with 'new' state */

/**
 * This function save all the test details supplied by user from UI page create_edit_test. It either inserts new test
 * or update already existing test and save them with 'new' state
 *
 * @param $db - database handle
 * @param string $state - State of a test
 * @return array - array containing basic test details
 */

function save_test($db,$state='new') {
    global $userId, $userIsAdmin;

    // Check for framework ID
    $frameworkId = getParam('f_frameworkid', 'POST');
    if (!$frameworkId) {
        returnError("POST data did not include a framework ID.");
    }
    if (!is_numeric($frameworkId)) {
        returnError("frameworkid (POST) is not valid.");
    }

    // Check for test ID
    $testId = getParam('f_testid', 'POST');
    $isNewTest = $testId ? false : true;

    // Validate test exists if updating
    $imported_test = false;
    if ($testId) {
        $testData = getTestById($db, $testId);
        if (!$testData) {
            returnError("Can't update test #$testId: test does not exist");
        }
        if (!$userIsAdmin && $testData['username'] != $userId) {
            returnError("You are not the test owner (" . $testData['username'] . ")");
	}
        if ($testData['end_status'] === "imported") {
            $imported_test = true;
        }
    }

    // Retrieve argument IDs for framework (for test argument insertion)
    $query = "SELECT framework_arg_id, frameworkid, argument_name, argument_values, argument_default, argument_order FROM ApplicationFrameworkArgs WHERE frameworkid = :frameworkid ORDER BY argument_order";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
    $stmt->execute();
    $argRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Four steps
    // 1) Insert or update base test config
    // 2) Insert each test argument individually
    // 3) Delete all associated hosts (exec/reserved/statistics) for test (if any)
    // 4) Insert every host (exec/stat/reserved) individually

    if ($isNewTest) {
	$query = "INSERT INTO TestInputData ( cc_list, start_time, end_time, creation_time, frameworkid, modified, priority, purpose, timeout, title, username, end_status) VALUES (:cc_list, NULL, NULL, NOW(), :frameworkid, NOW(), :priority, :purpose, :timeout, :title, :username, '$state') ";
    } else {
	$query = "UPDATE TestInputData SET cc_list = :cc_list, start_time = NULL, end_time = NULL, modified = NOW(), priority = :priority, purpose = :purpose, timeout = :timeout, title = :title, end_status = '$state'  WHERE testid = :testid";
    }

    try {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();

        $stmt = $db->prepare($query);
        // We could've created an array for all the bound values below, but I like
        // to be explicit with what we're inserting into the DB (eg. PDO::PARAM_STR)
        // For more details: http://wiki.hashphp.org/PDO_Tutorial_for_MySQL_Developers
        $stmt->bindValue(':cc_list', getParam('f_cc_list', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':priority', getParam('f_priority', 'POST'), PDO::PARAM_INT);
        $stmt->bindValue(':purpose', getParam('f_purpose', 'POST'), PDO::PARAM_STR);
        $stmt->bindValue(':timeout', getParam('f_timeout', 'POST') ?: 0, PDO::PARAM_INT);
        $stmt->bindValue(':title', getParam('f_title', 'POST'), PDO::PARAM_STR);
        if ($isNewTest) {
            $stmt->bindValue(':username', $userId, PDO::PARAM_STR);
            $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
        }

        $stmt->execute();
        if ($isNewTest) {
            $testId = $db->lastInsertId();
        }

        if (!$testId) {
            // Rollback?
            returnError("No test ID generated.");
        }

	// Delete all imported test arguments associated with this testid, if any
        $query = "DELETE FROM ImportedTestArgs WHERE testid = :testid";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
        $stmt->execute();
	
	// Delete all previous values of test arguments associated with this test as we are going to enter all new values with insert sql statement
	$query = "DELETE FROM TestArgs WHERE testid = :testid";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
        $stmt->execute();

        // Insert arguments
        foreach ($argRows as $arg) {
            $argId = $arg['framework_arg_id'];
            $argValue = getParam("f_arg_$argId", 'POST');
            $query = "INSERT INTO TestArgs ( argument_value, framework_arg_id, testid ) VALUES ( :argument_value, :framework_arg_id, :testid )";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':argument_value', $argValue, PDO::PARAM_STR);
            $stmt->bindValue(':framework_arg_id', $argId, PDO::PARAM_INT);
            $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
            $stmt->execute();
        }

        // Delete any previously associated hosts associated with testid
        $query = "DELETE FROM HostAssociation WHERE testid = :testid";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
        $stmt->execute();

        // Add each host
        $hostTypes = array('execution', 'statistics', 'reserved');
        foreach ($hostTypes as $hostType) {
            $hosts = getParam("f_$hostType", 'POST');
            if (!$hosts) {
                continue;
            }
            $hostsArray = explode(',', $hosts);
            foreach ($hostsArray as $host) {

                // Insert
                $query = "INSERT INTO HostAssociation ( hostassociationtypeid, testid, hostname ) SELECT hostassociationtypeid, :testid, :hostname FROM HostAssociationType WHERE frameworkid = :frameworkid AND name = :host_type";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
                $stmt->bindValue(':hostname', $host, PDO::PARAM_STR);
                $stmt->bindValue(':frameworkid', $frameworkId, PDO::PARAM_INT);
                $stmt->bindValue(':host_type', $hostType, PDO::PARAM_STR);
                $stmt->execute();
            }
        }

        // Adding strace configuration (if any)

        if (isset($_POST['f_strace'])){
            try {
                $strace_process = getParam('f_strace_process','POST');
                $strace_delay = getParam('f_strace_delay','POST');
                $strace_duration = getParam('f_strace_duration','POST');
            }catch (Exception $ex){
                returnError("Some values missing for STRACE configuration");
            }

            $query = "SELECT profiler_framework_id FROM ProfilerFramework WHERE testid = :testid AND profiler = :profiler";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
            $stmt->bindValue(':profiler', 'STRACE', PDO::PARAM_INT);
            $stmt->execute();
            $profilerID = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($profilerID){
                // Update previous strace configuration
                $query = "UPDATE ProfilerFramework SET processname = :processname, delay = :delay, duration = :duration WHERE testid = :testid AND profiler = :profiler";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
                $stmt->bindValue(':profiler', 'STRACE', PDO::PARAM_STR);
                $stmt->bindValue(':processname', $strace_process, PDO::PARAM_STR);
                $stmt->bindValue(':delay', $strace_delay, PDO::PARAM_INT);
                $stmt->bindValue(':duration', $strace_duration, PDO::PARAM_INT);
                $stmt->execute();
            }else{
                // Add new strace configuration
                $query = "INSERT INTO ProfilerFramework (profiler, testid, processname, delay, duration) VALUES (:profiler, :testid, :processname, :delay, :duration)";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
                $stmt->bindValue(':profiler', 'STRACE', PDO::PARAM_STR);
                $stmt->bindValue(':processname', $strace_process, PDO::PARAM_STR);
                $stmt->bindValue(':delay', $strace_delay, PDO::PARAM_INT);
                $stmt->bindValue(':duration', $strace_duration, PDO::PARAM_INT);
                $stmt->execute();
            }
        }else{
            if(!$isNewTest){
                // Delete any previous strace configuration
                $query = "DELETE FROM ProfilerFramework WHERE testid = :testid AND profiler = :profiler";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
                $stmt->bindValue(':profiler', 'STRACE', PDO::PARAM_INT);
                $stmt->execute();
            }
        }

        // Perf Configuration

        try {
            $perf_delay = getParam('f_perf_delay','POST');
            $perf_duration = getParam('f_perf_duration','POST');
            if (isset($_POST['f_perf'])){
                $perf_process = getParam('f_perf_process','POST');
            }
        }catch (Exception $ex){
            returnError("Some values missing for PERF configuration");
        }

        if ($isNewTest) {
            $query = "INSERT INTO ProfilerFramework (profiler, testid, processname, delay, duration) VALUES (:profiler, :testid, :processname, :delay, :duration)";
        } else {
            $query = "UPDATE ProfilerFramework SET processname = :processname, delay = :delay, duration = :duration WHERE testid = :testid AND profiler = :profiler";
        }

        $stmt = $db->prepare($query);
        $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
        $stmt->bindValue(':profiler', 'PERF', PDO::PARAM_INT);
        $stmt->bindValue(':delay', $perf_delay, PDO::PARAM_INT);
        $stmt->bindValue(':duration', $perf_duration, PDO::PARAM_INT);
        if (isset($_POST['f_perf'])){
            $stmt->bindValue(':processname', $perf_process, PDO::PARAM_STR);
        }else{
            $stmt->bindValue(':processname', NULL, PDO::PARAM_STR);
        }
        $stmt->execute();

        $db->commit();
    } catch(PDOException $e) {
        // All updates will be discarded if there's any fatal error
        $db->rollBack();
        returnError("MySQL error: " . $e->getMessage());
    }

    return array(
        'test' => array(
            'testid'      => $testId,
            'frameworkid' => $frameworkId,
            'new'         => $isNewTest,
            'running'     => false
        )
    );
}

/**
 * This function save all the test details supplied by user from UI page create_edit_test. It also trigger test
 * execution after saving test. It either inserts new test or update already existing test and save them
 * with 'scheduled' state to start test execution
 *
 * @param $db - database handle
 * @return array - array containing basic test details
 */

function save_run_test($db) {
    $testReturnData = save_test($db,'scheduled');

    try {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();

        // Adding test in CommonFrameworkSchedulerQueue table which is a list of currently executing test.
        $query = "INSERT INTO CommonFrameworkSchedulerQueue (testid, state, pid) VALUES ( :testid, 'scheduled', 0 )";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':testid', $testReturnData['test']['testid']);
        $stmt->execute();

        $db->commit();
    } catch(PDOException $e) {
        // All updates will be discarded if there are any fatal errors
        $db->rollBack();
        returnError("MySQL error: " . $e->getMessage());
    }

    $testReturnData['test']['running'] = true;
    return $testReturnData;
}

/**
 * This function deletes test from Daytona
 *
 * @param $db - database handle
 * @return array - array containing basic test details
 */

function delete_test($db) {
    global $userId, $userIsAdmin;

    // Check for test ID
    $testId = getParam('f_testid', 'POST');

    if (!$testId) {
        returnError("No ID defined");
    }
    if (!is_numeric($testId)) {
        returnError("testid is not valid");
    }

    $testData = getTestById($db, $testId);
    if (! $testData) {
        returnError("Could not find test ID: $testId");
    }
    $frameworkData = getFrameworkById($db, $testData['frameworkid']);

    if (!$userIsAdmin && $userId != $testData['username']) {
        returnError("You are not the test owner (" . $testData['username'] . ")");
    }

    // DB is properly set up so if you delete the main test configuration data
    // from TestInputData, it will cascade down and delete associated entries

    try {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();
        $query = "DELETE FROM TestInputData WHERE testid = :testid";

        $stmt = $db->prepare($query);
        $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
        $stmt->execute();

	$test_logs_path = "test_data/" . $frameworkData['frameworkname'] . "/" . $testId;
        recursive_rmdir($test_logs_path);

        $db->commit();
    } catch(PDOException $e) {
        // All updates will be discarded if there are any fatal errors
        $db->rollBack();
        returnError("MySQL error: " . $e->getMessage());
    }

    return array(
        'test' => array(
            'frameworkid' => $testData['frameworkid'],
            'testid'      => $testId,
            'deleted'     => true
        )
    );
}

/**
 * This function deletes multiple tests from Daytona
 *
 * @param $db - database handle
 */
function delete_tests($db) {
    global $userId, $userIsAdmin;

    $testIds = getParam('testids', 'POST');

    if (! $testIds) {
        returnError("No test IDs defined");
    }

    // Validate each test ID
    foreach($testIds as $testId) {
        if (!is_numeric($testId)) {
            returnError("One or more test IDs are not valid");
        }
        $testData = getTestById($db, $testId);
        if (!$testData) {
            returnError("Could not find test ID: $testId");
        }
        $frameworkData = getFrameworkById($db, $testData['frameworkid']);

        if (!$userIsAdmin && $userId != $testData['username']) {
            returnError("You are not the test owner for test: $testId");
        }
	$test_logs_path = "test_data/" . $frameworkData['frameworkname'] . "/" . $testId;
        recursive_rmdir($test_logs_path);
    }

    try {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();

	$query = "DELETE FROM TestInputData WHERE testid = :testid";
        $stmt = $db->prepare($query);
        foreach ($testIds as $testId) {
            $stmt->bindValue(':testid', $testId, PDO::PARAM_INT);
            $stmt->execute();
        }
        $db->commit();
    } catch(PDOException $e) {
        // All updates will be discarded if there are any fatal errors
        $db->rollBack();
        returnError("MySQL error: " . $e->getMessage());
    }
    return array(
        'test' => array(
            'frameworkid' => $testData['frameworkid'],
            'testid'      => $testId,
            'deleted'     => true
        )
    );
}

/**
 * A handler function for framework settings page where user can select/deselect framework for UI display
 *
 * @param $db - database handle
 *
 */
function set_user_frameworks($db) {
    global $userId;

    $frameworks = getParam('frameworks', 'POST');

    if (!$frameworks) {
        returnError("No frameworks are defined");
    }

    try {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();

        $ret = "";
        foreach ($frameworks as $framework) {

            if ($framework['checked'] == 'true') {
                $query = "INSERT INTO CommonFrameworkAuthentication (username, administrator, frameworkid) VALUES( :userid, 0, :frameworkid ) ON DUPLICATE KEY UPDATE frameworkid = :frameworkid";
            } else {
                $query = "DELETE FROM CommonFrameworkAuthentication WHERE username = :userid AND frameworkid = :frameworkid";
            }
            $stmt = $db->prepare($query);
            $stmt->bindValue(':userid', $userId, PDO::PARAM_STR);
            $stmt->bindValue(':frameworkid', $framework['frameworkid'], PDO::PARAM_INT);
            $stmt->execute();
        }

        $db->commit();
    } catch(PDOException $e) {
        // All updates will be discarded if there are any fatal errors
        $db->rollBack();
        returnError("MySQL error: " . $e->getMessage());
    }

    return;
}

// Script begins here

// Check for action type
$action = getParam('action', 'POST');
if (! $action) {
    returnError("No action defined");
}
if (! preg_match('/^(save_framework|delete_framework|save_test|save_run_test|delete_test|delete_tests|set_user_frameworks)$/', $action)) {
    returnError("Unknown action: $action");
}

if (! $userId) {
    returnError("No User defined");
}

$returnData = $action($db);

returnOk($returnData);
?>
