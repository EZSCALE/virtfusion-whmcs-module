<?php

/**
 * Test bootstrap.
 *
 * The module is loaded by WHMCS, not by Composer, so there is no autoloader to lean on
 * and no WHMCS runtime present. This file supplies the two things the unit tests need:
 * the library classes, and stand-ins for the WHMCS globals the module calls out to.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

const VFD_LIB = __DIR__ . '/../modules/servers/VirtFusionDirect/lib';

/**
 * Records every Log::insert() call so tests can assert on diagnostics.
 *
 * Log::insert() is a thin wrapper around WHMCS's global logModuleCall(). Defining it
 * here keeps the production wrapper free of test-only guards while making log output
 * observable — several of the behaviours under test (a truncated page set, an unbounded
 * group) are reported *only* through the log, so it needs to be inspectable.
 *
 * @var array<int,array{module:string,action:string,request:mixed,response:mixed}>
 */
$GLOBALS['vfd_test_log'] = [];

if (! function_exists('logModuleCall')) {
    function logModuleCall($module, $action, $requestString, $responseData, $processors = null, $replaceVars = null)
    {
        $GLOBALS['vfd_test_log'][] = [
            'module' => $module,
            'action' => $action,
            'request' => $requestString,
            'response' => $responseData,
        ];
    }
}

require_once VFD_LIB . '/Log.php';
require_once VFD_LIB . '/Curl.php';
require_once VFD_LIB . '/Cache.php';
require_once VFD_LIB . '/Module.php';
require_once VFD_LIB . '/StockControl.php';
