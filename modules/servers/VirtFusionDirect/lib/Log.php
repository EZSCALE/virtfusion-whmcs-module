<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Thin wrapper around the WHMCS logModuleCall() function.
 *
 * WHY A WRAPPER
 * -------------
 * Consolidating log writes lets us:
 *   - Pin the module name in one place (the LOG_MODULE constant). All entries
 *     go under "VirtFusionDirect" regardless of which caller inserted them,
 *     which keeps WHMCS Admin → Utilities → Logs → Module Log filterable.
 *   - Get a stable import path for every file that logs (Log::insert).
 *   - Add cross-cutting policy later (e.g. redaction, sampling) without
 *     touching every call site.
 *
 * OUTPUT SURFACE
 * --------------
 * Entries appear in WHMCS Admin → Utilities → Logs → Module Log. The request
 * and response parameters accept strings OR arrays — WHMCS serialises arrays
 * to readable form automatically. Pass structured data (["zone" => $z, "ip" => $ip])
 * rather than string-concatenated messages; the UI renders arrays as key/value
 * pairs which makes filtering and debugging much easier.
 *
 * REDACTION EXPECTATION
 * ---------------------
 * Callers are responsible for not passing secrets into logs. In particular:
 *   - Never log Authorization/X-API-Key headers
 *   - Never log full request_header info from the Curl class
 *   - Never log the decrypted VirtFusion bearer token or PowerDNS API key
 * The Curl class deliberately defaults CURLOPT_HEADER to off so header capture
 * doesn't accidentally populate a field that callers might log.
 */
class Log
{
    /** Keep this in sync with the WHMCS server module name, so filters work. */
    const LOG_MODULE = 'VirtFusionDirect';

    /**
     * Write an entry to the WHMCS module log.
     *
     * @param  string  $action  Short tag identifying the operation (used as the "Function" column in the log UI)
     * @param  string|array  $requestString  Outbound payload or context data. Arrays preferred — rendered as key/value pairs.
     * @param  string|array  $responseData  Inbound response or result. Same conventions as $requestString.
     */
    public static function insert($action, $requestString, $responseData)
    {
        logModuleCall(self::LOG_MODULE, $action, $requestString, $responseData);
    }
}
