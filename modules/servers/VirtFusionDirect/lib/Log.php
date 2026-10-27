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
 *   - Apply secret redaction consistently without relying on every caller.
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
 * Structured values, legacy JSON and recognizable header/credential strings are
 * redacted as a second defense. Rescue handlers still log only status metadata:
 * pattern matching cannot promise to recognize every possible secret format.
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
        logModuleCall(self::LOG_MODULE, $action, self::redact($requestString), self::redact($responseData));
    }

    /**
     * Project WHMCS module $params down to the fields lifecycle logs need.
     *
     * WHMCS hands every module function the server password and access hash, the
     * client's contact details, custom fields and the service model. None of that
     * belongs in the module log, so callers log this allow-listed projection
     * instead of the raw array. Unknown keys are dropped rather than redacted.
     *
     * @param  array  $params  WHMCS module parameters.
     * @return array Service identifiers, status and configurable options only.
     */
    public static function params(array $params): array
    {
        $out = [];
        foreach (['serviceid', 'userid', 'serverid', 'pid', 'status'] as $key) {
            if (array_key_exists($key, $params)) {
                $out[$key] = $params[$key];
            }
        }
        foreach ($params as $key => $value) {
            if (preg_match('/^configoption\d+$/', (string) $key)) {
                $out[$key] = $value;
            }
        }
        if (isset($params['configoptions']) && is_array($params['configoptions'])) {
            $out['configoptions'] = $params['configoptions'];
        }

        return self::redact($out);
    }

    /**
     * Redact structured secret fields and recognizable credentials in legacy strings.
     *
     * HTTP/WebSocket URLs, cookie headers and session identifiers are sensitive.
     * JSON strings become structured arrays so nested secret keys are checked.
     * This helper protects new logs and snapshot display; it does not rewrite
     * historical log rows or expire/revoke previously issued native sessions.
     *
     * @param  mixed  $value  Structured payload, object, JSON string or scalar.
     * @return mixed Redacted payload; non-secret scalar types are preserved.
     */
    public static function redact($value)
    {
        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (preg_match('/password|passwd|secret|token|authorization|api.?key|cookie|session|PHPSESSID|^WHMCS[a-f0-9]{6,}$|request_header|endpoint_complete|authentication|wss|download|(^|_)url$|(^|_)file$|(^|_)path$|accesshash|(^|_)hash$|notes|credential|private/i', (string) $key)) {
                    $out[$key] = '[redacted]';
                } else {
                    $out[$key] = self::redact($item);
                }
            }

            return $out;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::redact($decoded);
            }
            $value = preg_replace('~Bearer\s+[^\s"]+~i', 'Bearer [redacted]', $value);
            $value = preg_replace('~(?:https?|wss?)://[^\s<>"]+~i', '[redacted URL]', $value);
            $value = preg_replace('~\b(?:set-cookie|cookie|authorization)\s*:[^\r\n]*~i', '[redacted header]', $value);
            $value = preg_replace('~\b(password|passwd|secret|token|accesshash|api[_-]?key|PHPSESSID|WHMCS[a-f0-9]{6,}|session[_-]?id)\s*[=:]\s*(?:"[^"\r\n]*"|\x27[^\x27\r\n]*\x27|[^\s,;]+)~i', '$1=[redacted]', $value);
        }

        return $value;
    }
}
