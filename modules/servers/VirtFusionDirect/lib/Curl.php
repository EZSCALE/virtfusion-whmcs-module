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
}
