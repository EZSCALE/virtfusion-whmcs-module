<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * HTTP client wrapper with Bearer token auth, SSL verification, and a 30s timeout.
 * Single-use — each instance makes one request.
 */
class Curl
{
    /** @var resource|\CurlHandle cURL handle */
    private $ch;

    /** @var array Response info and parsed header data collected after exec */
    private $data;

    /** @var array User-supplied cURL options that override defaults */
    private $customOptions = [];

    /**
     * @var array Default cURL options applied to every request.
     *
     * Rationale:
     *   VERIFYPEER/VERIFYHOST: Full TLS chain + hostname validation. Disabling
     *     either is a common source of MITM bugs, so we never do it silently.
     *   RETURNTRANSFER: We always want the response body back as a string.
     *   HEADER off: Callers almost never need headers. Saves a parse cycle.
     *   NOBODY off: Default to GET-style body-returning requests.
     *   TIMEOUT 30s: Covers slow API endpoints without letting a hung connection
     *     block a whole WHMCS request indefinitely.
     *   CONNECTTIMEOUT 10s: Separate from the total timeout so a failed TCP
     *     handshake (firewall black-hole) fails fast rather than burning 30s.
     */
    private $defaultOptions = [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'VirtFusion-WHMCS/2.0',
        CURLOPT_HEADER => false,
        CURLOPT_NOBODY => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];

    /** Initialise the cURL handle. */
    public function __construct()
    {
        $this->ch = curl_init();
    }

    /**
     * Set a custom cURL option, overriding the defaults.
     *
     * @param  int  $name  A CURLOPT_* constant
     * @param  mixed  $value  The option value
     */
    public function addOption($name, $value)
    {
        $this->customOptions[$name] = $value;
    }

    /**
     * Execute a PUT request.
     *
     * @param  string|null  $url  Target URL, or null to use a previously set CURLOPT_URL
     * @return bool|string Response body, or false on failure
     */
    public function put($url = null)
    {
        return $this->send('PUT', $url);
    }

    /**
     * Execute a PATCH request.
     *
     * @param  string|null  $url  Target URL, or null to use a previously set CURLOPT_URL
     * @return bool|string Response body, or false on failure
     */
    public function patch($url = null)
    {
        return $this->send('PATCH', $url);
    }

    /**
     * Set the HTTP method and URL, then execute the request.
     *
     * @param  string  $method  HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param  string|null  $url  Target URL, or null to use a previously set CURLOPT_URL
     * @return bool|string Response body, or false on failure
     *
     * @throws \RuntimeException If no URL is available
     */
    private function send($method, $url)
    {
        if ($url === null) {
            if (! isset($this->customOptions[CURLOPT_URL]) || empty($this->customOptions[CURLOPT_URL])) {
                throw new \RuntimeException('Curl: empty URL provided');
            }
        }
        $this->addOption(CURLOPT_CUSTOMREQUEST, $method);
        $this->addOption(CURLOPT_URL, $url);

        return $this->exec();
    }

    /**
     * Apply options, run the cURL handle, collect response info, and close the handle.
     *
     * @return bool|string Response body, or false on cURL error
     */
    private function exec()
    {
        $this->setOptions();
        $response = curl_exec($this->ch);

        $this->data['info'] = curl_getinfo($this->ch);

        if ($response === false) {
            $this->data['info']['curl_error'] = curl_error($this->ch);
            $this->data['info']['curl_errno'] = curl_errno($this->ch);
        }

        if (isset($this->customOptions[CURLOPT_HEADER]) && $this->customOptions[CURLOPT_HEADER]) {
            $this->data['info']['request_header'] = trim($this->data['info']['request_header']);
            $this->processHeaders($response);
        }

        curl_close($this->ch);

        return $response;
    }

    /** Merge custom and default cURL options and apply them to the handle. */
    private function setOptions()
    {
        if (isset($this->customOptions[CURLOPT_HEADER]) && $this->customOptions[CURLOPT_HEADER]) {
            $this->addOption(CURLINFO_HEADER_OUT, true);
        }

        $options = $this->customOptions + $this->defaultOptions;
        curl_setopt_array($this->ch, $options);
    }

    /**
     * Split a response containing headers into header and body parts and store them.
     *
     * @param  string  $data  Raw response string (headers + body); replaced with body only
     */
    private function processHeaders(&$data)
    {
        $tmp = explode("\r\n\r\n", $data, 2);

        $this->data['info']['response_header'] = $tmp[0];
        $this->data['info']['response_body'] = $data = trim($tmp[1]);

        $tmp = explode("\r\n", $this->data['info']['response_header']);
        $this->data['data']['Message'] = $tmp[0];
        for ($i = 1, $size = count($tmp); $i < $size; $i++) {
            $string = explode(': ', $tmp[$i], 2);
            $this->data['data'][$string[0]] = $string[1];
        }
    }

    /**
     * Execute a GET request.
     *
     * @param  string|null  $url  Target URL, or null to use a previously set CURLOPT_URL
     * @return bool|string Response body, or false on failure
     */
    public function get($url = null)
    {
        return $this->send('GET', $url);
    }

    /**
     * Execute a DELETE request.
     *
     * @param  string|null  $url  Target URL, or null to use a previously set CURLOPT_URL
     * @return bool|string Response body, or false on failure
     */
    public function delete($url = null)
    {
        return $this->send('DELETE', $url);
    }

    /**
     * Execute a POST request.
     *
     * @param  string|null  $url  Target URL, or null to use a previously set CURLOPT_URL
     * @return bool|string Response body, or false on failure
     */
    public function post($url = null)
    {
        return $this->send('POST', $url);
    }

    /**
     * Return curl_getinfo data for the completed request.
     *
     * @param  string|false  $param  A specific info key to retrieve, or false for the full array
     * @return mixed|null The requested info value, the full info array, or null if the key is absent
     */
    public function getRequestInfo($param = false)
    {
        if ($param) {
            return $this->getDataItem('info', $param);
        } else {
            return $this->data['info'];
        }
    }

    /**
     * Retrieve a single item from the internal data store by section and key.
     *
     * @param  string  $what  Top-level section key (e.g. 'info', 'data')
     * @param  string  $name  Item key within that section
     * @return mixed|null The stored value, or null if not found
     */
    private function getDataItem($what, $name)
    {
        if (isset($this->data[$what][$name])) {
            return $this->data[$what][$name];
        } else {
            return null;
        }
    }

    /**
     * Execute many GET requests concurrently with a bounded parallelism window.
     *
     * WHY THIS EXISTS
     * ---------------
     * The per-instance API above is deliberately single-use (one handle, one
     * request) because the vast majority of call sites fire exactly one request.
     * But the daily UsageUpdate cron fans out one or two reads PER SERVER, and an
     * operator with thousands of VPSes turns that into thousands of *sequential*
     * HTTP round-trips — each potentially stalling near the 30s timeout — so the
     * job never finishes inside a day. This helper runs them through a rolling
     * curl_multi window so the wall-clock collapses to roughly
     * (count / concurrency) * avg-latency instead of count * avg-latency.
     *
     * Same TLS hardening as the single-request path: full peer + hostname
     * verification, never disabled silently.
     *
     * @param  array<string|int,string>  $requests  Map of caller key => absolute URL.
     * @param  array<int,string>  $headers  Request headers applied to every handle
     *                                      (e.g. the Bearer auth + Accept lines).
     * @param  int  $concurrency  Maximum simultaneous in-flight requests (>=1).
     * @param  int  $timeout  Per-request total timeout in seconds.
     * @return array<string|int,array{body:?string,http_code:int,error:?string}>
     *                                                                           Results keyed by the SAME keys as $requests. A transport failure
     *                                                                           yields body=null, http_code=0, error set — callers treat that the
     *                                                                           same as a non-200 and leave the corresponding record untouched.
     */
    public static function multiGet(array $requests, array $headers = [], $concurrency = 10, $timeout = 25)
    {
        $results = [];
        if ($requests === []) {
            return $results;
        }

        $concurrency = max(1, (int) $concurrency);
        $timeout = max(1, (int) $timeout);

        $keys = array_keys($requests);
        $urls = array_values($requests);
        $total = count($keys);
        $next = 0;

        $mh = curl_multi_init();
        // Map of (int) handle id => ['key' => caller key, 'ch' => handle], so
        // completed transfers can be matched back to their request regardless of
        // completion order AND any still-attached handles can be torn down on an
        // error break without leaking.
        $handles = [];

        $addHandle = function () use (&$next, &$handles, $keys, $urls, $total, $headers, $timeout, $mh) {
            if ($next >= $total) {
                return;
            }
            $key = $keys[$next];
            $url = $urls[$next];
            $next++;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => 'VirtFusion-WHMCS/2.0',
                CURLOPT_HEADER => false,
                CURLOPT_NOBODY => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = ['key' => $key, 'ch' => $ch];
        };

        // Prime the window.
        $window = min($concurrency, $total);
        for ($i = 0; $i < $window; $i++) {
            $addHandle();
        }

        // Drive transfers until every handle has completed and been processed.
        // The loop condition is "handles still tracked" — a handle is only
        // removed from $handles once we have recorded its result below.
        while ($handles !== []) {
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($status !== CURLM_OK) {
                break; // unrecoverable multi-handle error — bail, partial results
            }

            // Harvest every transfer that finished since the last pass and
            // immediately refill the freed slot with the next pending request.
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $id = (int) $ch;
                $key = $handles[$id]['key'] ?? null;

                $body = curl_multi_getcontent($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = ($info['result'] !== CURLE_OK) ? curl_error($ch) : null;

                if ($key !== null) {
                    $results[$key] = [
                        // On a transport error (timeout, connection refused, …)
                        // libcurl may hand back an empty string rather than false;
                        // normalise all of those to null so the contract "failure
                        // => body null" holds. A completed HTTP error (e.g. 500)
                        // keeps result==CURLE_OK, so its body is preserved.
                        'body' => ($error !== null || $body === false || $body === null || $body === '') ? null : $body,
                        'http_code' => $httpCode,
                        'error' => $error,
                    ];
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($handles[$id]);

                $addHandle(); // backfill the window from the pending queue
            }

            // Block until there is activity on any handle (or 1s elapses) so we
            // are not busy-spinning curl_multi_exec while requests are in flight.
            if ($handles !== [] && $running) {
                // PHP's curl_multi_select maps to libcurl curl_multi_wait, which
                // returns 0 IMMEDIATELY (not after the timeout) when there are no
                // descriptors to wait on — e.g. during the threaded-resolver DNS
                // phase or the pre-socket expire-timer window — and -1 on a select
                // error. Either case would let the drive loop re-enter
                // curl_multi_exec with no delay and peg a CPU core, so we throttle
                // on any non-positive return. A genuine 1s timeout (also 0) only
                // eats an extra 1ms here, which is negligible.
                if (curl_multi_select($mh, 1.0) <= 0) {
                    usleep(1000);
                }
            }
        }

        // Defensive cleanup: detach and close any handles still attached (only
        // reachable via the CURLM error break above) so no easy handles leak —
        // curl_multi_close does NOT free easy handles that are still added.
        foreach ($handles as $id => $entry) {
            curl_multi_remove_handle($mh, $entry['ch']);
            curl_close($entry['ch']);
            unset($handles[$id]);
        }
        curl_multi_close($mh);

        return $results;
    }
}
