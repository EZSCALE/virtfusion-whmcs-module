<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class Log
{
    const LOG_MODULE = 'VirtFusionDirect';

    public static function insert($action, $requestString, $responseData)
    {
        /**
         * Log module call.
         *
         * @param string $module The name of the module
         * @param string $action The name of the action being performed
         * @param string|array $requestString The input parameters for the API call
         * @param string|array $responseData The response data from the API call
         * @param string|array $processedData The resulting data after any post processing (eg. json decode, xml decode, etc...)
         * @param array $replaceVars An array of strings for replacement
         */
        logModuleCall(self::LOG_MODULE, $action, $requestString, $responseData);
    }
}