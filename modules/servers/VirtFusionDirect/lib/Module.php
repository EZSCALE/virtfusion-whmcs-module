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
     * Resolve service context: system service, WHMCS service, control panel, and curl client.
     * Returns false if any lookup fails.
     *
     * @param int $serviceID
     * @return array{service: object, whmcsService: object, cp: array, request: Curl}|false
     */
    protected function resolveServiceContext($serviceID)
    {
        $serviceID = (int) $serviceID;
        $service = Database::getSystemService($serviceID);
        if (!$service) return false;

        $whmcsService = Database::getWhmcsService($serviceID);
        if (!$whmcsService) return false;

        $cp = $this->getCP($whmcsService->server);
        if (!$cp) return false;

        return [
            'service' => $service,
            'whmcsService' => $whmcsService,
            'cp' => $cp,
            'request' => $this->initCurl($cp['token']),
            'serverId' => (int) $service->server_id,
        ];
    }

    /**
     * @param int $serviceID
     * @return false|string
     */
    public function fetchLoginTokens($serviceID)
    {
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $data = $ctx['request']->post($ctx['cp']['url'] . '/users/' . (int) $ctx['whmcsService']->userid . '/serverAuthenticationTokens/' . $ctx['serverId']);
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        if ($ctx['request']->getRequestInfo('http_code') == '200') {
            $data = json_decode($data);
            if (isset($data->data->authentication->endpoint_complete)) {
                return $ctx['cp']['base_url'] . $data->data->authentication->endpoint_complete;
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
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $data = $ctx['request']->get($ctx['cp']['url'] . '/servers/' . $ctx['serverId']);
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        if ($ctx['request']->getRequestInfo('http_code') == '200') {
            return json_decode($data);
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
        $allowedActions = ['boot', 'shutdown', 'restart', 'poweroff'];
        if (!in_array($action, $allowedActions, true)) {
            return false;
        }

        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $data = $ctx['request']->post($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/power/' . $action);
        Log::insert(__FUNCTION__ . ':' . $action, $ctx['request']->getRequestInfo(), $data);

        $httpCode = $ctx['request']->getRequestInfo('http_code');
        if ($httpCode == 200 || $httpCode == 204) {
            return json_decode($data) ?: (object) ['success' => true];
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
        $osId = (int) $osId;
        if ($osId <= 0) return false;

        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $buildData = ['operatingSystemId' => $osId, 'email' => true];
        if ($hostname !== null && $hostname !== '') {
            $buildData['hostname'] = $hostname;
        }

        $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode($buildData));
        $data = $ctx['request']->post($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/build');
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        $httpCode = $ctx['request']->getRequestInfo('http_code');
        if ($httpCode == 200 || $httpCode == 201) {
            Cache::forget('backups:' . $ctx['serverId']);
            return json_decode($data) ?: (object) ['success' => true];
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
        $newName = trim($newName);
        if (empty($newName) || strlen($newName) > 255) return false;

        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode(['name' => $newName]));
        $data = $ctx['request']->patch($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/name');
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        $httpCode = $ctx['request']->getRequestInfo('http_code');
        return ($httpCode == 200 || $httpCode == 204);
    }

    /**
     * Fetch available OS templates for a server's package.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function fetchOsTemplates($serviceID)
    {
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $ctx['whmcsService']->packageid)->first();
        if (!$product || !$product->configoption2) return false;

        $cacheKey = 'os:' . (int) $product->configoption2;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) return $cached;

        $data = $ctx['request']->get($ctx['cp']['url'] . '/media/templates/fromServerPackageSpec/' . (int) $product->configoption2);
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        if ($ctx['request']->getRequestInfo('http_code') == '200') {
            $templates = json_decode($data, true);
            $baseUrl = rtrim(str_replace('/api/v1', '', $ctx['cp']['url']), '/');

            $result = [
                'baseUrl' => $baseUrl,
                'categories' => self::groupOsTemplates($templates['data'] ?? []),
            ];

            Cache::set($cacheKey, $result, 600);
            return $result;
        }
        return false;
    }

    /**
     * Group OS template data into categories. Categories with only 1 template
     * are merged into an "Other" category.
     *
     * @param array $data Raw template data from VirtFusion API
     * @param bool $htmlEscape Whether to escape names for HTML output
     * @return array
     */
    public static function groupOsTemplates(array $data, bool $htmlEscape = false): array
    {
        $categories = [];
        $otherTemplates = [];
        $esc = fn($v) => $htmlEscape ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;

        foreach ($data as $osCategory) {
            $catTemplates = [];
            foreach ($osCategory['templates'] as $template) {
                $catTemplates[] = [
                    'id' => $template['id'],
                    'name' => $esc($template['name']),
                    'version' => $esc($template['version'] ?? ''),
                    'variant' => $esc($template['variant'] ?? ''),
                    'icon' => $template['icon'] ?? null,
                    'eol' => $template['eol'] ?? false,
                    'type' => $template['type'] ?? '',
                    'description' => $esc($template['description'] ?? ''),
                ];
            }

            if (count($catTemplates) <= 1) {
                $otherTemplates = array_merge($otherTemplates, $catTemplates);
            } else {
                $catName = $osCategory['name'] ?? 'Unknown';
                $categories[] = [
                    'name' => $esc($catName),
                    'icon' => ($catName === 'Other') ? null : ($osCategory['icon'] ?? null),
                    'templates' => $catTemplates,
                ];
            }
        }

        if (!empty($otherTemplates)) {
            $categories[] = ['name' => 'Other', 'icon' => null, 'templates' => $otherTemplates];
        }

        return $categories;
    }

    // =========================================================================
    // Traffic Statistics
    // =========================================================================

    /**
     * Get traffic statistics for a server.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function getTrafficStats($serviceID)
    {
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $cacheKey = 'traffic:' . $ctx['serverId'];
        $cached = Cache::get($cacheKey);
        if ($cached !== null) return $cached;

        $data = $ctx['request']->get($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/traffic');
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        if ($ctx['request']->getRequestInfo('http_code') == 200) {
            $result = json_decode($data, true);
            Cache::set($cacheKey, $result, 120);
            return $result;
        }
        return false;
    }

    // =========================================================================
    // Backup Management
    // =========================================================================

    /**
     * Get backup list for a server.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function getServerBackups($serviceID)
    {
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $cacheKey = 'backups:' . $ctx['serverId'];
        $cached = Cache::get($cacheKey);
        if ($cached !== null) return $cached;

        $data = $ctx['request']->get($ctx['cp']['url'] . '/backups/server/' . $ctx['serverId']);
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        if ($ctx['request']->getRequestInfo('http_code') == 200) {
            $result = json_decode($data, true);
            Cache::set($cacheKey, $result, 120);
            return $result;
        }
        return false;
    }

    /**
     * Assign a backup plan to a server.
     *
     * @param int $serviceID
     * @param int $planId Backup plan ID (0 to remove)
     * @return object|false
     */
    public function assignBackupPlan($serviceID, $planId)
    {
        $planId = (int) $planId;
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode(['planId' => $planId]));
        $endpoint = $ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/backup/plan';
        $data = $planId > 0 ? $ctx['request']->post($endpoint) : $ctx['request']->delete($endpoint);
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        $httpCode = $ctx['request']->getRequestInfo('http_code');
        if ($httpCode == 200 || $httpCode == 204) {
            return json_decode($data) ?: (object) ['success' => true];
        }
        return false;
    }

    // =========================================================================
    // VNC Console
    // =========================================================================

    /**
     * Get VNC console connection details for a server.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function getVncConsole($serviceID)
    {
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $data = $ctx['request']->get($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/vnc');
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        if ($ctx['request']->getRequestInfo('http_code') == 200) {
            return json_decode($data, true);
        }
        return false;
    }

    /**
     * Toggle VNC on/off for a server.
     *
     * @param int $serviceID
     * @param bool $enabled
     * @return array|false
     */
    public function toggleVnc($serviceID, $enabled)
    {
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode(['enabled' => (bool) $enabled]));
        $data = $ctx['request']->post($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/vnc');
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        $httpCode = $ctx['request']->getRequestInfo('http_code');
        if ($httpCode == 200 || $httpCode == 204) {
            return json_decode($data, true) ?: ['success' => true];
        }
        return false;
    }

    // =========================================================================
    // Resource Modification
    // =========================================================================

    /**
     * Modify a server resource (memory, cpuCores, or traffic).
     *
     * @param int $serviceID
     * @param string $resource One of: memory, cpuCores, traffic
     * @param int $value New value for the resource
     * @return object|false
     */
    public function modifyResource($serviceID, $resource, $value)
    {
        $allowedResources = ['memory', 'cpuCores', 'traffic'];
        if (!in_array($resource, $allowedResources, true)) return false;

        $value = (int) $value;
        if ($value < 0) return false;

        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode([$resource => $value]));
        $data = $ctx['request']->put($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/modify/' . $resource);
        Log::insert(__FUNCTION__ . ':' . $resource, $ctx['request']->getRequestInfo(), $data);

        $httpCode = $ctx['request']->getRequestInfo('http_code');
        if ($httpCode == 200 || $httpCode == 204) {
            return json_decode($data) ?: (object) ['success' => true];
        }
        return false;
    }

    // =========================================================================
    // Dry Run Validation
    // =========================================================================

    /**
     * Validate server creation parameters without actually creating a server.
     *
     * @param array $options Server creation options
     * @param int $serverId WHMCS server ID for API credentials
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateServerCreation($options, $serverId)
    {
        $cp = $this->getCP($serverId, !$serverId);
        if (!$cp) {
            return ['valid' => false, 'errors' => ['No control server found']];
        }

        $request = $this->initCurl($cp['token']);
        $request->addOption(CURLOPT_POSTFIELDS, json_encode($options));
        $data = $request->post($cp['url'] . '/servers?dryRun=true');

        Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

        $httpCode = $request->getRequestInfo('http_code');
        $response = json_decode($data, true);

        if ($httpCode == 200 || $httpCode == 201) {
            return ['valid' => true, 'errors' => []];
        }

        $errors = [];
        if (isset($response['errors']) && is_array($response['errors'])) {
            $errors = $response['errors'];
        } elseif (isset($response['msg'])) {
            $errors = [$response['msg']];
        } else {
            $errors = ['Validation failed with HTTP ' . $httpCode];
        }

        return ['valid' => false, 'errors' => $errors];
    }

    /**
     * Reset the server's root password.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function resetServerPassword($serviceID)
    {
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $data = $ctx['request']->post($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/resetPassword');
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        $httpCode = $ctx['request']->getRequestInfo('http_code');
        if ($httpCode == 200 || $httpCode == 201) {
            return json_decode($data, true);
        }
        return false;
    }

    public function resetUserPassword($serviceID, $clientID)
    {
        $clientID = (int) $clientID;
        $ctx = $this->resolveServiceContext($serviceID);
        if (!$ctx) return false;

        $data = $ctx['request']->post($ctx['cp']['url'] . '/users/' . $clientID . '/byExtRelation/resetPassword');
        Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

        if ($ctx['request']->getRequestInfo('http_code') == '201') {
            return json_decode($data);
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

    // =========================================================================
    // Self Service — Credit & Usage
    // =========================================================================

    /**
     * Get self-service usage data for a WHMCS client.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function getSelfServiceUsage($serviceID)
    {
        $serviceID = (int) $serviceID;
        $whmcsService = Database::getWhmcsService($serviceID);
        if (!$whmcsService) return false;

        $cp = $this->getCP($whmcsService->server);
        if (!$cp) return false;

        $request = $this->initCurl($cp['token']);
        $data = $request->get($cp['url'] . '/selfService/usage/byUserExtRelationId/' . (int) $whmcsService->userid);

        Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

        if ($request->getRequestInfo('http_code') == 200) {
            return json_decode($data, true);
        }
        return false;
    }

    /**
     * Get self-service billing report for a WHMCS client.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function getSelfServiceReport($serviceID)
    {
        $serviceID = (int) $serviceID;
        $whmcsService = Database::getWhmcsService($serviceID);
        if (!$whmcsService) return false;

        $cp = $this->getCP($whmcsService->server);
        if (!$cp) return false;

        $request = $this->initCurl($cp['token']);
        $data = $request->get($cp['url'] . '/selfService/report/byUserExtRelationId/' . (int) $whmcsService->userid);

        Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

        if ($request->getRequestInfo('http_code') == 200) {
            return json_decode($data, true);
        }
        return false;
    }

    /**
     * Add self-service credit for a WHMCS client.
     *
     * @param int $serviceID
     * @param float $tokens Amount of credit tokens to add
     * @param string $reference Reference text for the transaction
     * @return array|false
     */
    public function addSelfServiceCredit($serviceID, $tokens, $reference = '')
    {
        $serviceID = (int) $serviceID;
        $tokens = (float) $tokens;

        if ($tokens <= 0) {
            return false;
        }

        $whmcsService = Database::getWhmcsService($serviceID);
        if (!$whmcsService) return false;

        $cp = $this->getCP($whmcsService->server);
        if (!$cp) return false;

        $request = $this->initCurl($cp['token']);
        $request->addOption(CURLOPT_POSTFIELDS, json_encode([
            'tokens' => $tokens,
            'reference_1' => $reference ?: 'WHMCS Top-up',
            'reference_2' => 'Service #' . $serviceID,
        ]));
        $data = $request->post($cp['url'] . '/selfService/credit/byUserExtRelationId/' . (int) $whmcsService->userid);

        Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

        $httpCode = $request->getRequestInfo('http_code');
        if ($httpCode == 200 || $httpCode == 201) {
            return json_decode($data, true);
        }
        return false;
    }

    /**
     * Get available self-service currencies.
     *
     * @param int $serviceID
     * @return array|false
     */
    public function getSelfServiceCurrencies($serviceID)
    {
        $cacheKey = 'ss_currencies';
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $serviceID = (int) $serviceID;
        $whmcsService = Database::getWhmcsService($serviceID);
        if (!$whmcsService) return false;

        $cp = $this->getCP($whmcsService->server);
        if (!$cp) return false;

        $request = $this->initCurl($cp['token']);
        $data = $request->get($cp['url'] . '/selfService/currencies');

        Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

        if ($request->getRequestInfo('http_code') == 200) {
            $result = json_decode($data, true);
            Cache::set($cacheKey, $result, 1800);
            return $result;
        }
        return false;
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
