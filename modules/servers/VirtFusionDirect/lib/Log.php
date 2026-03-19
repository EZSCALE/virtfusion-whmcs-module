<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Thin wrapper around the WHMCS logModuleCall() function for module-level logging.
 */
class Log
{
    const LOG_MODULE = 'VirtFusionDirect';

    /**
     * Write an entry to the WHMCS module log.
     *
     * @param  string  $action  Name of the action being logged (e.g. 'CreateAccount')
     * @param  string|array  $requestString  Request data sent to the API
     * @param  string|array  $responseData  Response data received from the API
     */
    public static function insert($action, $requestString, $responseData)
    {
        logModuleCall(self::LOG_MODULE, $action, $requestString, $responseData);
    }
}
