<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class Curl
{
    private $ch;
    private $data;
    private $customOptions = [];
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


    public function __construct()
    {
        $this->ch = curl_init();
    }

    public function useCookies()
    {
        $cookiesFile = tempnam(sys_get_temp_dir(), 'virtfusion_cookies');
        $this->defaultOptions[CURLOPT_COOKIEFILE] = $cookiesFile;
        $this->defaultOptions[CURLOPT_COOKIEJAR] = $cookiesFile;
    }

    public function setLog()
    {
        $log = fopen(__DIR__ . '/CURL.log', 'a');
        if ($log) {
            fwrite($log, str_repeat('=', 80) . PHP_EOL);
            $this->addOption(CURLOPT_STDERR, $log);
            $this->addOption(CURLOPT_VERBOSE, true);
        }
    }

    /**
     * @param $name
     * @param $value
     */
    public function addOption($name, $value)
    {
        $this->customOptions[$name] = $value;
    }

    /**
     * @param null $url
     * @return bool|string|void
     */
    public function put($url = null)
    {
        return $this->send('PUT', $url);
    }

    /**
     * @param null $url
     * @return bool|string|void
     */
    public function patch($url = null)
    {
        return $this->send('PATCH', $url);
    }

    /**
     * @param $method
     * @param $url
     * @return bool|string|void
     */
    private function send($method, $url)
    {
        if ($url === null) {
            if (!isset($this->customOptions[CURLOPT_URL]) || empty($this->customOptions[CURLOPT_URL])) {
                exit('empty url');
            }
        }
        $this->addOption(CURLOPT_CUSTOMREQUEST, $method);
        $this->addOption(CURLOPT_URL, $url);

        return $this->exec();
    }

    /**
     * @return bool|string
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

    private function setOptions()
    {
        if (isset($this->customOptions[CURLOPT_HEADER]) && $this->customOptions[CURLOPT_HEADER]) {
            $this->addOption(CURLINFO_HEADER_OUT, true);
        }

        $options = $this->customOptions + $this->defaultOptions;
        curl_setopt_array($this->ch, $options);
    }

    /**
     * @param $data
     */
    private function processHeaders(&$data)
    {
        $tmp = explode("\r\n\r\n", $data, 2);

        $this->data['info']['response_header'] = $tmp[0];
        $this->data['info']['response_body'] = $data = trim($tmp[1]);

        $tmp = explode("\r\n", $this->data['info']['response_header']);
        $this->data['data']['Message'] = $tmp[0];
        for ($i = 1, $size = count($tmp); $i < $size; ++$i) {
            $string = explode(': ', $tmp[$i], 2);
            $this->data['data'][$string[0]] = $string[1];
        }
    }

    /**
     * @param null $url
     * @return bool|string|void
     */
    public function get($url = null)
    {
        return $this->send('GET', $url);
    }

    /**
     * @param null $url
     * @return bool|string|void
     */
    public function delete($url = null)
    {
        return $this->send('DELETE', $url);
    }

    /**
     * @param null $url
     * @return bool|string|void
     */
    public function post($url = null)
    {
        return $this->send('POST', $url);
    }

    /**
     * @param null $url
     * @return bool|string|void
     */
    public function head($url = null)
    {
        return $this->send('HEAD', $url);
    }

    /**
     * @param false $param
     * @return mixed|null
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
     * @param $what
     * @param $name
     * @return mixed|null
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
     * @param false $param
     * @return mixed|null
     */
    public function getHeadersData($param = false)
    {
        if ($param) {
            return $this->getDataItem('data', $param);
        }

        return $this->data['data'];
    }
}
