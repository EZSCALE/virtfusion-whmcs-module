<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class Module
{
    public function __construct()
    {
        Database::schema();
    }

    /**
     * @param bool $exitOnError
     * @return string
     */
    public function validateAction($exitOnError = true)
    {
        if (!isset($_GET['action'])) {
            $this->output(['success' => false, 'errors' => 'no action specified'], true, $exitOnError, 400);
        }
        return preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['action']);
    }

    /**
     * @param bool $exitOnError
     * @return int
     */
    public function validateServiceID($exitOnError = true)
    {
        if (!isset($_GET['serviceID']) || !is_numeric($_GET['serviceID'])) {
            $this->output(['success' => false, 'errors' => 'no valid serviceID specified'], true, $exitOnError, 400);
        }
        return (int) $_GET['serviceID'];
    }

    /**
     * @param int $serviceID
     * @param bool $exitOnError
     * @return int|false
     */
    public function validateUserOwnsService($serviceID, $exitOnError = true)
    {
        $serviceID = (int) $serviceID;
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
     * @param int $serviceID
     * @return false|string
     */
    public function fetchLoginTokens($serviceID)
    {
        $serviceID = (int) $serviceID;
        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);

            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->post($cp['url'] . '/users/' . (int) $whmcsService->userid . '/serverAuthenticationTokens/' . (int) $service->server_id);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') == '200') {
                $data = json_decode($data);
                if (isset($data->data->authentication->endpoint_complete)) {
                    return $cp['base_url'] . $data->data->authentication->endpoint_complete;
                }
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
        $serviceID = (int) $serviceID;
        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);
            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/servers/' . (int) $service->server_id);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') == '200') {
                return json_decode($data);
            }
        }
        return false;
    }

    /**
     * Execute a power action on a server.
     *
     * @param int $serviceID
     * @param string $action One of: boot, shutdown, restart, poweroff
     * @return object|false
     */
    public function serverPowerAction($serviceID, $action)
    {
        $serviceID = (int) $serviceID;
        $allowedActions = ['boot', 'shutdown', 'restart', 'poweroff'];
        if (!in_array($action, $allowedActions, true)) {
            return false;
        }

        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);
            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->post($cp['url'] . '/servers/' . (int) $service->server_id . '/power/' . $action);

            Log::insert(__FUNCTION__ . ':' . $action, $request->getRequestInfo(), $data);

            $httpCode = $request->getRequestInfo('http_code');
            if ($httpCode == 200 || $httpCode == 204) {
                return json_decode($data) ?: (object) ['success' => true];
            }
        }
        return false;
    }

    /**
     * Rebuild/reinstall a server with a new OS.
     *
     * @param int $serviceID
     * @param int $osId Operating system template ID
     * @param string|null $hostname Optional new hostname
     * @return object|false
     */
    public function rebuildServer($serviceID, $osId, $hostname = null)
    {
        $serviceID = (int) $serviceID;
        $osId = (int) $osId;

        if ($osId <= 0) {
            return false;
        }

        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);
            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);

            $buildData = [
                'operatingSystemId' => $osId,
                'email' => true,
            ];

            if ($hostname !== null && $hostname !== '') {
                $buildData['hostname'] = $hostname;
            }

            $request->addOption(CURLOPT_POSTFIELDS, json_encode($buildData));
            $data = $request->post($cp['url'] . '/servers/' . (int) $service->server_id . '/build');

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            $httpCode = $request->getRequestInfo('http_code');
            if ($httpCode == 200 || $httpCode == 201) {
                return json_decode($data) ?: (object) ['success' => true];
            }
        }
        return false;
    }

    /**
     * Rename a server.
     *
     * @param int $serviceID
     * @param string $newName
     * @return bool
     */
    public function renameServer($serviceID, $newName)
    {
        $serviceID = (int) $serviceID;
        $newName = trim($newName);

        if (empty($newName) || strlen($newName) > 255) {
            return false;
        }

        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);
            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);

            $request->addOption(CURLOPT_POSTFIELDS, json_encode(['name' => $newName]));
            $data = $request->patch($cp['url'] . '/servers/' . (int) $service->server_id . '/name');

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            $httpCode = $request->getRequestInfo('http_code');
            return ($httpCode == 200 || $httpCode == 204);
        }
        return false;
    }

    /**
     * Fetch available OS templates for a server's package.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function fetchOsTemplates($serviceID)
    {
        $serviceID = (int) $serviceID;
        $service = Database::getSystemService($serviceID);

        if ($service) {
            $whmcsService = Database::getWhmcsService($serviceID);
            $cp = $this->getCP($whmcsService->server);

            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $whmcsService->packageid)->first();
            if (!$product || !$product->configoption2) {
                return false;
            }

            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/media/templates/fromServerPackageSpec/' . (int) $product->configoption2);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') == '200') {
                $templates = json_decode($data, true);
                $result = [];
                if (isset($templates['data'])) {
                    foreach ($templates['data'] as $osCategory) {
                        foreach ($osCategory['templates'] as $template) {
                            $result[] = [
                                'id' => $template['id'],
                                'name' => $template['name'] . ' ' . $template['version'] . ' ' . $template['variant'],
                            ];
                        }
                    }
                    usort($result, function ($a, $b) {
                        return strcmp($a['name'], $b['name']);
                    });
                }
                return $result;
            }
        }
        return false;
    }

    public function resetUserPassword($serviceID, $clientID)
    {
        $serviceID = (int) $serviceID;
        $clientID = (int) $clientID;
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

        $this->output(['success' => false, 'errors' => 'unauthenticated'], true, true, 401);
    }

    /**
     * @return bool|void
     */
    public function isAuthenticated()
    {
        if ((new \WHMCS\Authentication\CurrentUser)->isAuthenticatedUser()) {
            return true;
        }

        $this->output(['success' => false, 'errors' => 'unauthenticated'], true, true, 401);
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

    /**
     * Decodes a response from JSON into an associative array.
     *
     * @param string $response
     *
     * @return array
     * @throws \JsonException
     */
    public function decodeResponseFromJson(string $response): array
    {
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }
}
