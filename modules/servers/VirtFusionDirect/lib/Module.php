<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;

/**
 * Base class providing VirtFusion API integration, authentication checks, and all
 * server feature methods (power, network, VNC, backup, resource modification,
 * self-service billing, traffic, rename, password reset).
 *
 * Extended by ModuleFunctions (service lifecycle) and ConfigureService (order-time
 * operations). Most business logic lives here; subclasses delegate to these methods.
 */
class Module
{
    /**
     * Initialises the module and ensures the database schema is up to date.
     */
    public function __construct()
    {
        Database::schema();
    }

    /**
     * @param  bool  $exitOnError
     * @return string
     */
    public function validateAction($exitOnError = true)
    {
        if (! isset($_GET['action'])) {
            $this->output(['success' => false, 'errors' => 'no action specified'], true, $exitOnError, 400);
        }

        return preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['action']);
    }

    /**
     * @param  bool  $exitOnError
     * @return int
     */
    public function validateServiceID($exitOnError = true)
    {
        if (! isset($_GET['serviceID']) || ! is_numeric($_GET['serviceID'])) {
            $this->output(['success' => false, 'errors' => 'no valid serviceID specified'], true, $exitOnError, 400);
        }

        return (int) $_GET['serviceID'];
    }

    /**
     * @param  int  $serviceID
     * @param  bool  $exitOnError
     * @return int|false
     */
    public function validateUserOwnsService($serviceID, $exitOnError = true)
    {
        $serviceID = (int) $serviceID;
        $currentUser = new CurrentUser;
        $client = $currentUser->client();

        if (! $client) {
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
     * @param  int  $serviceID
     * @return array{service: object, whmcsService: object, cp: array, request: Curl}|false
     */
    protected function resolveServiceContext($serviceID)
    {
        try {
            $serviceID = (int) $serviceID;
            $service = Database::getSystemService($serviceID);
            if (! $service) {
                return false;
            }

            $whmcsService = Database::getWhmcsService($serviceID);
            if (! $whmcsService) {
                return false;
            }

            $cp = $this->getCP($whmcsService->server);
            if (! $cp) {
                return false;
            }

            return [
                'service' => $service,
                'whmcsService' => $whmcsService,
                'cp' => $cp,
                'request' => $this->initCurl($cp['token']),
                'serverId' => (int) $service->server_id,
            ];
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * @param  int  $serviceID
     * @return false|string
     */
    public function fetchLoginTokens($serviceID)
    {
        try {
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $data = $ctx['request']->post($ctx['cp']['url'] . '/users/' . (int) $ctx['whmcsService']->userid . '/serverAuthenticationTokens/' . $ctx['serverId']);
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            if ($ctx['request']->getRequestInfo('http_code') == '200') {
                $data = json_decode($data);
                if (isset($data->data->authentication->endpoint_complete)) {
                    return $ctx['cp']['base_url'] . $data->data->authentication->endpoint_complete;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Extract IP address and hostname from a VirtFusion server object and persist
     * them to the corresponding tblhosting record (dedicatedip, domain, username,
     * password).
     *
     * @param  int  $serviceId  WHMCS service ID
     * @param  object  $data  Raw server object returned by the VirtFusion API
     * @return void
     */
    public function updateWhmcsServiceParamsOnServerObject($serviceId, $data)
    {
        try {
            $output = [];

            $serverResource = (new ServerResource)->process($data);

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

            $output['tblhosting'] = ['dedicatedip' => $dedicatedIpv4, 'domain' => $name, 'username' => $serverResource['username'], 'password' => $serverResource['password']];

            Database::updateWhmcsServiceParams($serviceId, $output);
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }
    }

    /**
     * Clear the dedicated IP on the tblhosting record when a server is terminated.
     *
     * @param  int  $serviceId  WHMCS service ID
     * @return void
     */
    public function updateWhmcsServiceParamsOnDestroy($serviceId)
    {
        try {
            $output['tblhosting'] = ['dedicatedip' => null];

            Database::updateWhmcsServiceParams($serviceId, $output);
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }
    }

    /**
     * Fetch full server details from the VirtFusion API for a given service.
     *
     * @param  int  $serviceID  WHMCS service ID
     * @return object|false Decoded API response object, or false on failure
     */
    public function fetchServerData($serviceID)
    {
        try {
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $data = $ctx['request']->get($ctx['cp']['url'] . '/servers/' . $ctx['serverId']);
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            if ($ctx['request']->getRequestInfo('http_code') == '200') {
                return json_decode($data);
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Execute a power action on a server.
     *
     * @param  int  $serviceID
     * @param  string  $action  One of: boot, shutdown, restart, poweroff
     * @return object|false
     */
    public function serverPowerAction($serviceID, $action)
    {
        try {
            $allowedActions = ['boot', 'shutdown', 'restart', 'poweroff'];
            if (! in_array($action, $allowedActions, true)) {
                return false;
            }

            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $data = $ctx['request']->post($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/power/' . $action);
            Log::insert(__FUNCTION__ . ':' . $action, $ctx['request']->getRequestInfo(), $data);

            $httpCode = $ctx['request']->getRequestInfo('http_code');
            if ($httpCode == 200 || $httpCode == 204) {
                return json_decode($data) ?: (object) ['success' => true];
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Rebuild/reinstall a server with a new OS.
     *
     * @param  int  $serviceID
     * @param  int  $osId  Operating system template ID
     * @param  string|null  $hostname  Optional new hostname
     * @return object|false
     */
    public function rebuildServer($serviceID, $osId, $hostname = null)
    {
        try {
            $osId = (int) $osId;
            if ($osId <= 0) {
                return false;
            }

            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

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
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Rename a server.
     *
     * @param  int  $serviceID
     * @param  string  $newName
     * @return bool
     */
    public function renameServer($serviceID, $newName)
    {
        try {
            $newName = trim($newName);
            if (empty($newName) || strlen($newName) > 255) {
                return false;
            }

            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode(['name' => $newName]));
            $data = $ctx['request']->patch($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/name');
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            $httpCode = $ctx['request']->getRequestInfo('http_code');

            return $httpCode == 200 || $httpCode == 204;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Fetch available OS templates for a server's package.
     *
     * @param  int  $serviceID
     * @return array|false
     */
    public function fetchOsTemplates($serviceID)
    {
        try {
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $product = Capsule::table('tblproducts')->where('id', $ctx['whmcsService']->packageid)->first();
            if (! $product || ! $product->configoption2) {
                return false;
            }

            $cacheKey = 'os:' . (int) $product->configoption2;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

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
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Group OS template data into categories. Categories with only 1 template
     * are merged into an "Other" category.
     *
     * @param  array  $data  Raw template data from VirtFusion API
     * @param  bool  $htmlEscape  Whether to escape names for HTML output
     */
    public static function groupOsTemplates(array $data, bool $htmlEscape = false): array
    {
        $categories = [];
        $otherTemplates = [];
        $esc = fn ($v) => $htmlEscape ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;

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

        if (! empty($otherTemplates)) {
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
     * @param  int  $serviceID
     * @return array|false
     */
    public function getTrafficStats($serviceID)
    {
        try {
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $cacheKey = 'traffic:' . $ctx['serverId'];
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $data = $ctx['request']->get($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/traffic');
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            if ($ctx['request']->getRequestInfo('http_code') == 200) {
                $result = json_decode($data, true);
                Cache::set($cacheKey, $result, 120);

                return $result;
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    // =========================================================================
    // Backup Management
    // =========================================================================

    /**
     * Get backup list for a server.
     *
     * @param  int  $serviceID
     * @return array|false
     */
    public function getServerBackups($serviceID)
    {
        try {
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $cacheKey = 'backups:' . $ctx['serverId'];
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $data = $ctx['request']->get($ctx['cp']['url'] . '/backups/server/' . $ctx['serverId']);
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            if ($ctx['request']->getRequestInfo('http_code') == 200) {
                $result = json_decode($data, true);
                Cache::set($cacheKey, $result, 120);

                return $result;
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    // =========================================================================
    // VNC Console
    // =========================================================================

    /**
     * Get VNC console connection details for a server.
     *
     * @param  int  $serviceID
     * @return array|false
     */
    public function getVncConsole($serviceID)
    {
        try {
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $data = $ctx['request']->get($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/vnc');
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            if ($ctx['request']->getRequestInfo('http_code') == 200) {
                return json_decode($data, true);
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Toggle VNC on/off for a server.
     *
     * @param  int  $serviceID
     * @param  bool  $enabled
     * @return array|false
     */
    public function toggleVnc($serviceID, $enabled)
    {
        try {
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode(['enabled' => (bool) $enabled]));
            $data = $ctx['request']->post($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/vnc');
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            $httpCode = $ctx['request']->getRequestInfo('http_code');
            if ($httpCode == 200 || $httpCode == 204) {
                return json_decode($data, true) ?: ['success' => true];
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    // =========================================================================
    // Resource Modification
    // =========================================================================

    /**
     * Modify a server resource (memory, cpuCores, or traffic).
     *
     * @param  int  $serviceID
     * @param  string  $resource  One of: memory, cpuCores, traffic
     * @param  int  $value  New value for the resource
     * @return object|false
     */
    public function modifyResource($serviceID, $resource, $value)
    {
        try {
            $allowedResources = ['memory', 'cpuCores', 'traffic'];
            if (! in_array($resource, $allowedResources, true)) {
                return false;
            }

            $value = (int) $value;
            if ($value < 0) {
                return false;
            }

            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode([$resource => $value]));
            $data = $ctx['request']->put($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/modify/' . $resource);
            Log::insert(__FUNCTION__ . ':' . $resource, $ctx['request']->getRequestInfo(), $data);

            $httpCode = $ctx['request']->getRequestInfo('http_code');
            if ($httpCode == 200 || $httpCode == 204) {
                return json_decode($data) ?: (object) ['success' => true];
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    // =========================================================================
    // Dry Run Validation
    // =========================================================================

    /**
     * Validate server creation parameters without actually creating a server.
     *
     * @param  array  $options  Server creation options
     * @param  int  $serverId  WHMCS server ID for API credentials
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateServerCreation($options, $serverId)
    {
        try {
            $cp = $this->getCP($serverId, ! $serverId);
            if (! $cp) {
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
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return ['valid' => false, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Reset the server's root password.
     *
     * @param  int  $serviceID
     * @return array|false
     */
    public function resetServerPassword($serviceID)
    {
        try {
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $data = $ctx['request']->post($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/resetPassword');
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            $httpCode = $ctx['request']->getRequestInfo('http_code');
            if ($httpCode == 200 || $httpCode == 201) {
                return json_decode($data, true);
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Reset the VirtFusion panel login password for a user identified by their
     * WHMCS client ID (used as the external relation ID in VirtFusion).
     *
     * @param  int  $serviceID  WHMCS service ID
     * @param  int  $clientID  WHMCS client ID (mapped to VirtFusion external relation ID)
     * @return object|false Decoded API response object, or false on failure
     */
    public function resetUserPassword($serviceID, $clientID)
    {
        try {
            $clientID = (int) $clientID;
            $ctx = $this->resolveServiceContext($serviceID);
            if (! $ctx) {
                return false;
            }

            $data = $ctx['request']->post($ctx['cp']['url'] . '/users/' . $clientID . '/byExtRelation/resetPassword');
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            if ($ctx['request']->getRequestInfo('http_code') == '201') {
                return json_decode($data);
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Send a JSON or raw response to the client and optionally terminate execution.
     *
     * @param  mixed  $data  Response payload; encoded as JSON when $json is true
     * @param  bool  $json  Whether to JSON-encode $data and set the Content-Type header
     * @param  bool  $exit  Whether to call exit() after sending the response
     * @param  int  $rspCode  HTTP status code to send
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
     * Resolve a WHMCS server record into an API base URL and decrypted Bearer token.
     *
     * @param  int|object  $server  WHMCS server ID or server object
     * @param  bool  $any  When true, fall back to any available server if the given one is not found
     * @return array{url: string, base_url: string, token: string}|false
     */
    public function getCP($server, $any = false)
    {
        try {
            $cp = Database::getWhmcsServer($server, $any);

            if ($cp) {
                return [
                    'url' => 'https://' . $cp->hostname . '/api/v1',
                    'base_url' => 'https://' . $cp->hostname,
                    'token' => decrypt($cp->password)];
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }

        return false;
    }

    /**
     * Enforce WHMCS admin authentication. Returns true if the current user is an
     * authenticated admin; otherwise sends a 401 JSON response and exits.
     *
     * @return bool|void
     */
    public function adminOnly()
    {
        if ((new CurrentUser)->isAuthenticatedAdmin()) {
            return true;
        }

        $this->output(['success' => false, 'errors' => 'unauthenticated'], true, true, 401);
    }

    /**
     * Enforce WHMCS client authentication. Returns true if the current user is an
     * authenticated client; otherwise sends a 401 JSON response and exits.
     *
     * @return bool|void
     */
    public function isAuthenticated()
    {
        if ((new CurrentUser)->isAuthenticatedUser()) {
            return true;
        }

        $this->output(['success' => false, 'errors' => 'unauthenticated'], true, true, 401);
    }

    /**
     * Create a pre-configured Curl instance with JSON Accept/Content-Type headers
     * and a Bearer token for authenticating against the VirtFusion API.
     *
     * @param  string  $token  VirtFusion API Bearer token
     * @return Curl
     */
    public function initCurl($token)
    {
        $curl = new Curl;

        $curl->addOption(CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-type: application/json; charset=utf-8',
            'authorization: Bearer ' . $token,
        ]);

        return $curl;
    }

    // =========================================================================
    // Self Service — Credit & Usage
    // =========================================================================

    /**
     * Get self-service usage data for a WHMCS client.
     *
     * @param  int  $serviceID
     * @return array|false
     */
    public function getSelfServiceUsage($serviceID)
    {
        try {
            $serviceID = (int) $serviceID;
            $whmcsService = Database::getWhmcsService($serviceID);
            if (! $whmcsService) {
                return false;
            }

            $cp = $this->getCP($whmcsService->server);
            if (! $cp) {
                return false;
            }

            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/selfService/usage/byUserExtRelationId/' . (int) $whmcsService->userid);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') == 200) {
                return json_decode($data, true);
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Get self-service billing report for a WHMCS client.
     *
     * @param  int  $serviceID
     * @return array|false
     */
    public function getSelfServiceReport($serviceID)
    {
        try {
            $serviceID = (int) $serviceID;
            $whmcsService = Database::getWhmcsService($serviceID);
            if (! $whmcsService) {
                return false;
            }

            $cp = $this->getCP($whmcsService->server);
            if (! $cp) {
                return false;
            }

            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/selfService/report/byUserExtRelationId/' . (int) $whmcsService->userid);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') == 200) {
                return json_decode($data, true);
            }

            return false;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Add self-service credit for a WHMCS client.
     *
     * @param  int  $serviceID
     * @param  float  $tokens  Amount of credit tokens to add
     * @param  string  $reference  Reference text for the transaction
     * @return array|false
     */
    public function addSelfServiceCredit($serviceID, $tokens, $reference = '')
    {
        try {
            $serviceID = (int) $serviceID;
            $tokens = (float) $tokens;

            if ($tokens <= 0) {
                return false;
            }

            $whmcsService = Database::getWhmcsService($serviceID);
            if (! $whmcsService) {
                return false;
            }

            $cp = $this->getCP($whmcsService->server);
            if (! $cp) {
                return false;
            }

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
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Decodes a response from JSON into an associative array.
     *
     *
     * @throws \JsonException
     */
    public function decodeResponseFromJson(string $response): array
    {
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }
}
