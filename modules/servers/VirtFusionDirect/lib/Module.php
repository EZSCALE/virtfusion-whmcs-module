<?php

namespace WHMCS\Module\Server\VirtFusionDirect;


class Module
{
    public function __construct()
    {
        error_reporting(0);
        Database::schema();
    }

    /**
     * @param bool $exitOnError
     * @return mixed
     */
    public function validateAction($exitOnError = true)
    {
        if (!isset($_GET['action'])) {
            $this->output(['errors' => 'no action specified'], true, $exitOnError, 200);
        }
        return $_GET['action'];
    }

    /**
     * @param bool $exitOnError
     * @return mixed
     */
    public function validateServiceID($exitOnError = true)
    {
        if (!isset($_GET['serviceID'])) {
            $this->output(['errors' => 'no serviceID specified'], true, $exitOnError, 200);
        }
        return $_GET['serviceID'];
    }

    /**
     * @param $serviceID
     * @param bool $exitOnError
     * @return bool
     */
    public function validateUserOwnsService($serviceID, $exitOnError = true)
    {
        $currentUser = new \WHMCS\Authentication\CurrentUser;
        $client = $currentUser->client();

        if (!$client) {
            return false;
        }

        if (Database::userWhmcsService($serviceID, $client->id)) {
            return $client->id;
        }

        return false;
    }

    /**
     * @param $serviceID
     * @return false|string
     */
    public function fetchLoginTokens($serviceID)
    {
        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);

            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->post($cp['url'] . '/users/' . $whmcsService->userid . '/serverAuthenticationTokens/' . $service->server_id);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') == '200') {
                $data = json_decode($data);
                return $cp['base_url'] . $data->data->authentication->endpoint_complete;
            }
        }
        return false;
    }

    public function updateWhmcsServiceParamsOnServerObject($serviceId, $data)
    {
        $output = [];

        $serverResource = (new ServerResource())->process($data);

        $dedicatedIpv4 = null;

        if (count($serverResource['primaryNetwork']['ipv4Unformatted'])) {
            $dedicatedIpv4 = $serverResource['primaryNetwork']['ipv4Unformatted'][0];
        }

        if ($serverResource['hostname'] == '-') {
            if ($serverResource['name'] == '-') {
                $name = '';
            } else {
                $name = $serverResource['name'];
            }
        } else {
            $name = $serverResource['hostname'];
        }

        $output['tblhosting'] = ["dedicatedip" => $dedicatedIpv4, "domain" => $name, "username" => $serverResource['username'], "password" => $serverResource['password']];

        Database::updateWhmcsServiceParams($serviceId, $output);
    }

    public function updateWhmcsServiceParamsOnDestroy($serviceId)
    {
        $output['tblhosting'] = ["dedicatedip" => null];

        Database::updateWhmcsServiceParams($serviceId, $output);
    }

    public function fetchServerData($serviceID)
    {
        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);
            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/servers/' . $service->server_id);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') == '200') {
                return json_decode($data);
            }
        }
        return false;
    }

    public function resetUserPassword($serviceID, $clientID)
    {
        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);
            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->post($cp['url'] . '/users/' . $clientID . '/byExtRelation/resetPassword');

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') == '201') {
                return json_decode($data);
            }
        }
        return false;
    }

    /**
     * @param $data
     * @param bool $json
     * @param bool $exit
     * @param int $rspCode
     */
    public function output($data, $json = true, $exit = true, $rspCode = 200)
    {
        http_response_code($rspCode);

        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data);
        } else {
            echo $data;
        }

        if ($exit) {
            exit();
        }
    }

    /**
     * @param $server
     * @return array|false
     */
    public function getCP($server, $any = false)
    {
        $cp = Database::getWhmcsServer($server, $any);

        if ($cp) {
            return [
                'url' => 'https://' . $cp->hostname . '/api/v1',
                'base_url' => 'https://' . $cp->hostname,
                'token' => decrypt($cp->password)];
        }
        return false;
    }

    /**
     * @return bool|void
     */
    public function adminOnly()
    {
        if ((new \WHMCS\Authentication\CurrentUser)->isAuthenticatedAdmin()) {
            return true;
        }

        $this->output(['errors' => 'unauthenticated'], true, true, 200);
    }

    /**
     * @return bool|void
     */
    public function isAuthenticated()
    {
        if ((new \WHMCS\Authentication\CurrentUser)->isAuthenticatedUser()) {
            return true;
        }

        $this->output(['errors' => 'unauthenticated'], true, true, 200);
    }

    /**
     * @param $token
     * @return \WHMCS\Module\Server\VirtFusionDirect\Curl
     */
    public function initCurl($token)
    {
        $curl = new Curl();

        $curl->addOption(CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-type: application/json; charset=utf-8',
            'authorization: Bearer ' . $token
        ]);

        return $curl;
    }
}
