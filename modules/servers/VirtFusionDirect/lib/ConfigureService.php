<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;
use WHMCS\User\User;

/**
 * Handles order-time and provisioning-time operations for VirtFusion servers.
 *
 * WHY A SIBLING OF ModuleFunctions RATHER THAN METHODS ON IT
 * ----------------------------------------------------------
 * ModuleFunctions handles the WHMCS LIFECYCLE (create, suspend, terminate, etc.)
 * — operations driven by WHMCS service-state transitions.
 *
 * ConfigureService handles ORDER-TIME logic — package lookups, template fetching,
 * SSH key creation, initial build triggering. These run during checkout (via the
 * ClientAreaFooterOutput hook that populates dropdowns on the order form) and
 * immediately after account creation (initServerBuild is called from
 * ModuleFunctions::createAccount once the VirtFusion server exists).
 *
 * Splitting the concerns keeps ModuleFunctions focused on lifecycle state machines
 * and ConfigureService focused on catalogue/discovery calls. They share the base
 * Module's API plumbing via inheritance.
 *
 * CACHING
 * -------
 * Package/template lookups use the module's Cache class with 10-minute TTLs.
 * These values change rarely (a package list is typically edited once per
 * month at most) but the endpoints are on the checkout hot path, so aggressive
 * caching matters for page-load performance.
 *
 * CP RESOLVED IN CONSTRUCTOR
 * --------------------------
 * Unlike ModuleFunctions which resolves the control panel per-request via the
 * service ID, ConfigureService resolves it ONCE in the constructor via
 * getCP(false, true) — "any available VirtFusion server". Order-time operations
 * happen BEFORE a WHMCS service exists, so we can't dereference a specific
 * server through mod_virtfusion_direct. "Any enabled server" is the pragmatic
 * default for catalogue operations that typically return the same data
 * regardless of which panel you hit.
 */
class ConfigureService extends Module
{
    /**
     * The first available VirtFusion control panel connection, as returned by
     * getCP(). Holds server URL and API token used for all API calls in this
     * class. False if no active VirtFusion server is configured in WHMCS.
     *
     * @var array|false
     */
    private array|bool $cp;

    /**
     * Initialize the service configurator with the first available VirtFusion server.
     *
     * Calls the parent Module constructor then resolves the control panel connection
     * so all methods in this class have a ready API endpoint.
     */
    public function __construct()
    {
        parent::__construct();
        $this->cp = $this->getCP(false, true);
    }

    /**
     * Find a VirtFusion package ID by its name via the API.
     *
     * Searches the packages list for an enabled package whose name matches
     * exactly. Result is cached for 10 minutes. Returns null if not found
     * or if no control panel is available.
     *
     * @param  string  $packageName  Exact package name as configured in VirtFusion.
     * @return int|null Package ID, or null if not found.
     */
    public function fetchPackageId(string $packageName): ?int
    {
        try {
            $cacheKey = 'pkg_name:' . md5($packageName);
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            if (! $this->cp) {
                return null;
            }

            $request = $this->initCurl($this->cp['token']);

            $response = $request->get(
                sprintf('%s/packages', $this->cp['url']),
            );

            $packages = $this->decodeResponseFromJson($response);

            foreach ($packages['data'] as $package) {
                if ($package['name'] === $packageName && $package['enabled'] === true) {
                    Cache::set($cacheKey, $package['id'], 600);

                    return $package['id'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }

    /**
     * Get the VirtFusion package ID from a WHMCS product's config option.
     *
     * Reads configoption2 directly from the tblproducts database record for
     * the given WHMCS product ID. Returns null if the product does not exist.
     *
     * @param  int  $productId  WHMCS product (tblproducts) ID.
     * @return int|null VirtFusion package ID, or null if the product is not found.
     */
    public function fetchPackageByDbId(int $productId): ?int
    {
        try {
            $product = DB::table('tblproducts')->where('id', $productId)->first();

            if (is_null($product)) {
                return null;
            }

            return (int) $product->configoption2;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }

    /**
     * Fetch the available OS templates for a given VirtFusion server package.
     *
     * Queries the VirtFusion API for templates compatible with the specified
     * package spec ID. Result is cached for 10 minutes. Returns null if no
     * package ID is provided or no control panel is available.
     *
     * @param  int|null  $serverPackageId  VirtFusion server package spec ID.
     * @return array|null Template list from the API, or null on failure.
     */
    public function fetchTemplates(?int $serverPackageId): ?array
    {
        try {
            if (is_null($serverPackageId)) {
                return null;
            }

            $cacheKey = 'tpl:' . $serverPackageId;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            if (! $this->cp) {
                return null;
            }

            $request = $this->initCurl($this->cp['token']);

            $response = $request->get(
                sprintf('%s/media/templates/fromServerPackageSpec/%d', $this->cp['url'], $serverPackageId),
            );

            $result = $this->decodeResponseFromJson($response);
            Cache::set($cacheKey, $result, 600);

            return $result;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }

    /**
     * Get the SSH keys registered for a VirtFusion user.
     *
     * Looks up the VirtFusion account for the given WHMCS user via external
     * relation ID, then fetches their SSH key list from the API. Returns null
     * if the user is not found in VirtFusion or no control panel is available.
     *
     * @param  User|null  $user  WHMCS User object.
     * @return array|null SSH key list from the API, or null on failure.
     */
    public function getUserSshKeys(?User $user): ?array
    {
        try {
            if (is_null($user)) {
                return null;
            }

            if (! $this->cp) {
                return null;
            }

            $request = $this->initCurl($this->cp['token']);

            $vfUser = $this->getVFUserDetails($user['id']);

            if (! $vfUser) {
                return null;
            }

            $response = $request->get(
                sprintf('%s/ssh_keys/user/%d', $this->cp['url'], $vfUser['id']),
            );

            return $this->decodeResponseFromJson($response);
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }

    /**
     * Look up a VirtFusion user by WHMCS external relation ID.
     *
     * Calls the VirtFusion API's byExtRelation endpoint using the WHMCS client
     * ID. Returns null if the user does not exist in VirtFusion or no control
     * panel is available.
     *
     * @param  int  $id  WHMCS client ID used as the VirtFusion external relation ID.
     * @return array|null VirtFusion user data array, or null if not found.
     */
    public function getVFUserDetails(int $id): ?array
    {
        try {
            if (! $this->cp) {
                return null;
            }

            $request = $this->initCurl($this->cp['token']);

            $raw = $request->get(
                sprintf('%s/users/%d/byExtRelation', $this->cp['url'], $id),
            );

            if ($request->getRequestInfo('http_code') == 404) {
                return null;
            }

            $response = $this->decodeResponseFromJson($raw);

            return $response['data'] ?? null;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }

    /**
     * Trigger OS installation on a newly created VirtFusion server.
     *
     * Posts a build request to the VirtFusion API with the selected OS template
     * and optionally an SSH key. If the custom field contains a numeric value it
     * is treated as an existing key ID; if it is a raw public key string, the key
     * is created first via createUserSshKey(). Returns true on HTTP 200/201.
     *
     * @param  int  $id  VirtFusion server ID to build.
     * @param  array  $vars  WHMCS order vars, including customfields for OS and SSH key.
     * @param  int|null  $vfUserId  VirtFusion user ID, required when creating a new SSH key from a raw public key.
     * @return bool True if the build request was accepted, false otherwise.
     */
    public function initServerBuild(int $id, array $vars, ?int $vfUserId = null): bool
    {
        try {
            if (! $this->cp) {
                return false;
            }

            $request = $this->initCurl($this->cp['token']);

            // Generate a hostname with sufficient entropy to avoid collisions
            $hostname = 'vps-' . bin2hex(random_bytes(4));

            $sshKeyValue = $vars['customfields']['Initial SSH Key'] ?? null;
            $sshKeyId = null;

            if (! empty($sshKeyValue)) {
                if (is_numeric($sshKeyValue)) {
                    // Existing SSH key ID
                    $sshKeyId = (int) $sshKeyValue;
                } elseif (preg_match('/^(ssh-|ecdsa-sha2-|sk-ssh-|sk-ecdsa-)/', $sshKeyValue) && $vfUserId) {
                    // Raw public key — create it via API
                    $sshKeyId = $this->createUserSshKey($vfUserId, $sshKeyValue);
                }
            }

            $osId = $vars['customfields']['Initial Operating System'] ?? null;
            if (empty($osId)) {
                Log::insert(__FUNCTION__, [], 'Skipped build: Initial Operating System custom field is empty');

                return false;
            }

            $inputData = [
                'operatingSystemId' => (int) $osId,
                'name' => $hostname,
                'email' => true,
            ];

            if ($sshKeyId) {
                $inputData['sshKeys'] = [$sshKeyId];
            }

            $request->addOption(CURLOPT_POSTFIELDS, json_encode($inputData));

            $response = $request->post(
                sprintf('%s/servers/%d/build', $this->cp['url'], $id),
            );

            $httpCode = $request->getRequestInfo('http_code');
            Log::insert(__FUNCTION__, $request->getRequestInfo(), $response);

            return $httpCode == 200 || $httpCode == 201;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Create an SSH key for a VirtFusion user from a raw public key string.
     *
     * @param  int  $userId  VirtFusion user ID
     * @param  string  $publicKey  Raw SSH public key (ssh-rsa ..., ssh-ed25519 ..., etc.)
     * @return int|null Created key ID or null on failure
     */
    public function createUserSshKey(int $userId, string $publicKey): ?int
    {
        try {
            if (! $this->cp) {
                return null;
            }

            $request = $this->initCurl($this->cp['token']);

            $keyData = [
                'userId' => $userId,
                'name' => 'WHMCS-' . date('Y-m-d'),
                'publicKey' => trim($publicKey),
            ];

            $request->addOption(CURLOPT_POSTFIELDS, json_encode($keyData));
            $response = $request->post($this->cp['url'] . '/ssh_keys');

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $response);

            $httpCode = $request->getRequestInfo('http_code');
            if ($httpCode == 200 || $httpCode == 201) {
                $data = json_decode($response, true);

                return $data['data']['id'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }
}
