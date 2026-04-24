<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;

/**
 * Base class providing VirtFusion API integration, authentication checks, and all
 * server feature methods (power, network, VNC, backup, resource modification,
 * self-service billing, traffic, rename, password reset).
 *
 * INHERITANCE SHAPE
 * -----------------
 * Extended by:
 *   - ModuleFunctions — service lifecycle (create, suspend, unsuspend, terminate, change package)
 *   - ConfigureService — order-time operations (package/template discovery, server build init)
 *
 * Most business logic lives HERE, not in the subclasses. Subclasses are intentionally
 * thin — they orchestrate sequences of calls to methods defined on this base, which
 * lets us unit-exercise any single feature (e.g. "what happens during rename when
 * the VirtFusion API returns 423?") without standing up a full WHMCS lifecycle.
 *
 * THE resolveServiceContext() PATTERN
 * -----------------------------------
 * Almost every method follows the same preamble: look up the module table row,
 * look up the WHMCS tblhosting row, resolve the control panel credentials, build
 * a Curl client with the bearer token. That preamble is consolidated into
 * resolveServiceContext() which returns everything as an array or false on any
 * missing piece. Every feature method starts with "$ctx = $this->resolveServiceContext($id);
 * if (! $ctx) return false;" and can then use $ctx['request'], $ctx['serverId'], etc.
 *
 * This pattern is the most important abstraction in the module — violating it
 * (e.g. reading tblservers directly in a feature method) leads to drift where
 * some features handle missing servers gracefully and others don't.
 *
 * ENDPOINT OUTPUT CONVENTION
 * --------------------------
 * client.php and admin.php call $this->output() to emit JSON responses. Every
 * output() call in a switch case MUST be followed by a `break` — the module
 * deliberately does NOT rely on exit() inside output() for flow control because
 * that couples the HTTP response format to the control-flow mechanism and makes
 * refactoring fragile.
 *
 * SECURITY HELPERS
 * ----------------
 * Five guards callers compose in front of sensitive actions:
 *   - isAuthenticated() — client session required
 *   - adminOnly()       — admin session required
 *   - requirePost()     — HTTP method gate (mutations only)
 *   - requireSameOrigin() — CSRF origin check
 *   - requireServiceStatus() — filter by tblhosting.domainstatus
 *
 * Each exits on failure with the appropriate HTTP status — callers treat them
 * as "throw on failure" style assertions rather than having to check return values.
 */
class Module
{
    /**
     * @var array|false|null Memoised catalogue-level CP connection used by fetchPackage/fetchGroupResources.
     *                       Resolved via getCP(false, true) — "any available VirtFusion server" — on first use.
     *                       Kept on the instance so a cron loop recalculating 20 products doesn't hit
     *                       tblservers 20×N times when N stock helpers are called per product.
     */
    private $catalogueCp = null;

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
     *
     * This is the most-called method in the module. Every feature action begins
     * by calling it, so think of the return value as "everything you need to
     * touch VirtFusion for this service":
     *
     *   service      — row from mod_virtfusion_direct (has server_id, server_object)
     *   whmcsService — row from tblhosting (has server, userid, domain, etc.)
     *   cp           — ['url', 'base_url', 'token'] for the VirtFusion API
     *   request      — a fresh Curl instance pre-configured with the bearer token
     *   serverId     — (int) of service.server_id — used in every URL downstream
     *
     * Returning false on ANY missing piece lets callers write a single
     * "if (! $ctx) return false;" check at the top of each feature method
     * rather than threading nullability through three separate lookups.
     *
     * @param  int  $serviceID
     * @return array{service: object, whmcsService: object, cp: array, request: Curl, serverId: int}|false
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

            // Capture old hostname + server object from stored state so we can sync rDNS
            // after the rename. We read from the cached server_object rather than a fresh
            // fetch; this is the hostname the PTR would be set to (if module-managed).
            $oldHostname = null;
            $serverObject = null;
            if (! empty($ctx['service']->server_object)) {
                $serverObject = json_decode($ctx['service']->server_object, true);
                if (is_array($serverObject)) {
                    $oldHostname = PowerDns\PtrManager::extractHostname($serverObject);
                }
            }

            $ctx['request']->addOption(CURLOPT_POSTFIELDS, json_encode(['name' => $newName]));
            $data = $ctx['request']->patch($ctx['cp']['url'] . '/servers/' . $ctx['serverId'] . '/name');
            Log::insert(__FUNCTION__, $ctx['request']->getRequestInfo(), $data);

            $httpCode = $ctx['request']->getRequestInfo('http_code');
            $success = $httpCode == 200 || $httpCode == 204;

            if ($success && $serverObject !== null && PowerDns\Config::isEnabled()) {
                // Sync PTRs: only records whose current content equals the old hostname
                // will be rewritten; client-customized PTRs are preserved automatically.
                // Non-blocking: rDNS failures log but never fail the rename.
                try {
                    (new PowerDns\PtrManager)->syncServer($serverObject, $oldHostname, $newName);
                } catch (\Throwable $e) {
                    Log::insert('PowerDns:renameServer', ['serviceID' => $serviceID], $e->getMessage());
                }
            }

            return $success;
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
     * OUTPUT SHAPE
     * ------------
     *   url      — full API base like "https://vf.example.com/api/v1". Append
     *              path components to this for every VirtFusion call.
     *   base_url — scheme + host only, "https://vf.example.com". Used for SSO
     *              redirects where we need to hit the panel UI, not the API.
     *   token    — decrypted bearer token. Pass to initCurl() to get an
     *              authenticated Curl handle.
     *
     * $any=true is an unusual behaviour: when a WHMCS product doesn't have a
     * specific server pinned (allowed if the module is the only VF module on
     * the install), we fall back to any enabled VirtFusion server. This mostly
     * exists for the "Test Connection" button which doesn't know which server
     * to use until after a successful connection. Normal provisioning always
     * passes a real server ID.
     *
     * The token is stored encrypted in tblservers.password and decrypted here
     * via WHMCS's global decrypt() — the same encryption key used for addon
     * module password fields.
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
     * Enforce POST as the HTTP method. Emits a 405 JSON response and exits otherwise.
     *
     * WHY THIS EXISTS
     * ---------------
     * The REST principle says mutations should be POST, and PHP's $_POST / $_GET
     * separation means a mutation that reads from $_POST would fail quietly when
     * called via GET. But "fail quietly" isn't what we want — an attacker probing
     * endpoints via crafted <img src="?action=...&ip=...&ptr=..."> tags shouldn't
     * even reach our input-validation code. This gate kills that path with a 405
     * before any per-endpoint logic runs.
     *
     * Combined with requireSameOrigin() below, this closes the most common
     * cross-site request forgery vectors (form POST, image GET) without needing
     * explicit CSRF tokens threaded through every AJAX call.
     *
     * @return bool|void
     */
    public function requirePost()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            return true;
        }

        $this->output(['success' => false, 'errors' => 'method not allowed'], true, true, 405);
    }

    /**
     * Verify the request's Origin/Referer belongs to this WHMCS install.
     *
     * THREAT MODEL
     * ------------
     * A logged-in WHMCS user visits a malicious page. That page makes a POST
     * to our rDNS endpoint; because the session cookie is tied to our domain,
     * the browser attaches it automatically. Without this check, the attacker
     * could silently rewrite the user's PTRs.
     *
     * The defence: browsers attach an Origin header on cross-origin fetch/XHR
     * and a Referer on cross-origin form POST. Those headers carry the
     * attacker's origin, not ours — so we compare them against our own
     * hostname and reject mismatches with a 403.
     *
     * This is NOT a full CSRF token scheme. It defends against the common
     * cross-site-POST and cross-site-form-submit vectors but a same-site XSS
     * that can read the user's DOM could still circumvent it. For that you'd
     * need per-request tokens bound to the session — out of scope for the
     * current module, but the helper stays here ready to be composed with
     * a token check if one's added later.
     *
     * IMPLEMENTATION
     * --------------
     *   1. Collect our "known good" host set from HTTP_HOST (what the browser
     *      connected to) plus the SystemURL host from tblconfiguration (what
     *      WHMCS thinks its canonical URL is). Behind a reverse proxy these
     *      can differ; accepting either closes the false-positive gap.
     *   2. Parse HTTP_ORIGIN and HTTP_REFERER and pull out their host:port.
     *   3. Require at least one of those headers to match.
     *
     * Fails closed: if we can't determine our own host OR if neither Origin
     * nor Referer is present, we reject. A legitimate same-origin AJAX call
     * from the module's own JS always sets Origin (fetch API) or Referer
     * (form submit), so the "both absent" case only happens with scripted
     * non-browser clients — which are exactly who we want to filter out.
     *
     * @return bool|void true on success; emits 403 JSON and exits otherwise
     */
    public function requireSameOrigin()
    {
        $expected = [];

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '') {
            $expected[] = strtolower($host);
        }

        $systemUrl = Database::getSystemUrl();
        if ($systemUrl) {
            $parsed = parse_url($systemUrl);
            if (! empty($parsed['host'])) {
                $expected[] = strtolower($parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : ''));
                $expected[] = strtolower($parsed['host']);
            }
        }
        $expected = array_unique(array_filter($expected));
        if (empty($expected)) {
            // Can't determine our own host; fail closed rather than silently allow.
            $this->output(['success' => false, 'errors' => 'cross-origin check failed'], true, true, 403);
        }

        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        $candidates = [];
        foreach ([$origin, $referer] as $raw) {
            if ($raw === '') {
                continue;
            }
            $parsed = parse_url($raw);
            if (! empty($parsed['host'])) {
                $candidates[] = strtolower($parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : ''));
                $candidates[] = strtolower($parsed['host']);
            }
        }

        if (empty($candidates)) {
            $this->output(['success' => false, 'errors' => 'cross-origin check failed (missing origin)'], true, true, 403);
        }

        foreach ($candidates as $c) {
            if (in_array($c, $expected, true)) {
                return true;
            }
        }

        Log::insert('csrf:origin-mismatch', ['origin' => $origin, 'referer' => $referer, 'expected' => $expected], 'cross-origin request rejected');
        $this->output(['success' => false, 'errors' => 'cross-origin check failed'], true, true, 403);
    }

    /**
     * Ensure the WHMCS service is in a status where client-initiated writes make sense.
     *
     * tblhosting.domainstatus can be: Active, Suspended, Terminated, Pending,
     * Cancelled, Fraud. Not every action makes sense in every status:
     *   - Reads (rdnsList, serverData) usually allow Active + Suspended so a
     *     suspended user can still see their current config.
     *   - Writes (rdnsUpdate, power, etc.) typically require Active only —
     *     mutating a cancelled service's rDNS has no sensible business meaning.
     *
     * Pass the allowed set explicitly per endpoint rather than trying to encode
     * a global policy here. Some endpoints (admin reconcile) don't call this at
     * all because the admin is allowed to touch any service.
     *
     * Fails with 404 if the service doesn't exist, 400 otherwise — keeping the
     * two conditions distinct in the response code helps client-side error
     * handling (a 404 usually means "link is stale", a 400 means "not right now").
     *
     * @param  int  $serviceID  WHMCS service ID
     * @param  string[]  $allowedStatuses  Service statuses that permit the operation
     * @return bool|void true on success; emits 400/404 JSON and exits otherwise
     */
    public function requireServiceStatus(int $serviceID, array $allowedStatuses = ['Active'])
    {
        $row = Database::getWhmcsService($serviceID);
        if (! $row) {
            $this->output(['success' => false, 'errors' => 'service not found'], true, true, 404);
        }
        if (! in_array((string) $row->domainstatus, $allowedStatuses, true)) {
            $this->output(
                ['success' => false, 'errors' => 'service status "' . (string) $row->domainstatus . '" does not permit this action'],
                true,
                true,
                400,
            );
        }

        return true;
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

    // =========================================================================
    // Catalogue helpers — used by StockControl to size the WHMCS inventory from
    // live VirtFusion data. Pre-order code path: CP is resolved via "any
    // available server" since no service context exists yet.
    // =========================================================================

    /**
     * Resolve the catalogue-level CP (any available VirtFusion server) and memoise.
     *
     * Stock calculations run from a cron loop or product-detail page view — there's
     * no WHMCS service yet, so we can't dereference a specific panel via
     * resolveServiceContext. "Any enabled server" is the correct fallback for read-only
     * catalogue operations (package + hypervisor-group endpoints return the same data
     * from every VirtFusion node on the same cluster).
     *
     * @return array{url: string, base_url: string, token: string}|false
     */
    private function getCatalogueCp()
    {
        if ($this->catalogueCp === null) {
            $this->catalogueCp = $this->getCP(false, true);
        }

        return $this->catalogueCp;
    }

    /**
     * Fetch a VirtFusion package by ID — the authoritative source for "how much RAM,
     * CPU, and disk does one VPS of this product cost?".
     *
     * Return values distinguish confirmed-missing from transient failure:
     *   array  — package data (fields: memory, cpuCores, primaryStorage, primaryStorageProfile, enabled, …)
     *   false  — HTTP 404: package has been deleted in VirtFusion. Callers treat as OOS.
     *   null   — Transient failure (no CP, network error, 5xx, malformed body). Callers must
     *            NOT overwrite WHMCS qty on a null — that would zero out inventory during a blip.
     *
     * Success responses are cached 10 min (key "pkg:{id}") since package definitions
     * rarely change; 404 responses get a short 60 s cache so an admin re-creating a
     * deleted package doesn't have to wait ten minutes for stock to pick it up again.
     *
     * @param  int  $packageId  VirtFusion package ID (from tblproducts.configoption2).
     * @return array|false|null
     */
    public function fetchPackage($packageId)
    {
        try {
            $packageId = (int) $packageId;
            if ($packageId <= 0) {
                return null;
            }

            $cacheKey = 'pkg:' . $packageId;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                // Sentinel marker for a previously-confirmed 404.
                if (is_array($cached) && ! empty($cached['__notFound'])) {
                    return false;
                }

                return $cached;
            }

            $cp = $this->getCatalogueCp();
            if (! $cp) {
                return null;
            }

            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/packages/' . $packageId);
            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            $httpCode = (int) $request->getRequestInfo('http_code');

            if ($httpCode === 200) {
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    $package = $decoded['data'] ?? $decoded;
                    if (is_array($package)) {
                        Cache::set($cacheKey, $package, 600);

                        return $package;
                    }
                }

                return null;
            }

            if ($httpCode === 404) {
                Cache::set($cacheKey, ['__notFound' => true], 60);

                return false;
            }

            return null;
        } catch (\Throwable $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }

    /**
     * Fetch free/allocated resources for every hypervisor in a group — the live picture
     * of how much headroom remains to place more VPSes.
     *
     * Same tri-state return contract as fetchPackage():
     *   array  — decoded response with a 'data' array of per-hypervisor resource breakdowns.
     *   false  — HTTP 404: group has been deleted. Callers may treat as "zero capacity from this group".
     *   null   — Transient failure. Callers must NOT overwrite WHMCS qty on a null.
     *
     * Cache TTL is 120 s — short enough that customers don't see stale OOS labels for
     * long after capacity frees up, and long enough to amortise the upstream call across
     * bursty product-page traffic. Matches the traffic-stats TTL in getTrafficStats().
     *
     * @param  int  $groupId  VirtFusion hypervisor group ID.
     * @return array|false|null
     */
    public function fetchGroupResources($groupId)
    {
        try {
            $groupId = (int) $groupId;
            if ($groupId <= 0) {
                return null;
            }

            $cacheKey = 'grpres:' . $groupId;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                if (is_array($cached) && ! empty($cached['__notFound'])) {
                    return false;
                }

                return $cached;
            }

            $cp = $this->getCatalogueCp();
            if (! $cp) {
                return null;
            }

            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/compute/hypervisors/groups/' . $groupId . '/resources');
            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            $httpCode = (int) $request->getRequestInfo('http_code');

            if ($httpCode === 200) {
                $decoded = json_decode($data, true);
                if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                    Cache::set($cacheKey, $decoded, 120);

                    return $decoded;
                }

                return null;
            }

            if ($httpCode === 404) {
                Cache::set($cacheKey, ['__notFound' => true], 60);

                return false;
            }

            return null;
        } catch (\Throwable $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }
}
