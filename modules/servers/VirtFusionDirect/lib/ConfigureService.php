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
 * service ID, ConfigureService accepts an explicit WHMCS panel ID for
 * provisioning and service-bound operations. Catalogue/checkout callers without
 * a bound service may select the first enabled panel. Cache entries remain
 * scoped to the selected URL/token; catalogues must not be assumed identical.
 */
class ConfigureService extends Module
{
    /**
     * The selected VirtFusion control panel connection, as returned by
     * getCP(). Holds server URL and API token used for all API calls in this
     * class. False if no active VirtFusion server is configured in WHMCS.
     *
     * @var array|false
     */
    private array|bool $cp;

    /**
     * Initialize with an explicit panel, or the first enabled panel for catalogue use.
     *
     * Calls the parent Module constructor then resolves the control panel connection
     * so all methods in this class have a ready API endpoint.
     *
     * @param  int|null  $panelId  Trusted WHMCS control-server ID when bound to a service.
     */
    public function __construct($panelId = null)
    {
        parent::__construct();
        $this->cp = $panelId === null ? $this->getCP(false, true) : $this->getCP((int) $panelId);
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
            $cacheKey = $this->panelCacheKey($this->cp ?: ['url' => '', 'token' => ''], 'pkg_name:' . md5($packageName));
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

            $cacheKey = $this->panelCacheKey($this->cp ?: ['url' => '', 'token' => ''], 'tpl:' . $serverPackageId);
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
     * Select an enabled template offered for the package from category-grouped or flat data.
     *
     * fromServerPackageSpec returns categories, each with a templates[] list; a
     * row with an id and no templates key is treated as a template itself.
     *
     * @param  array|null  $response  Decoded fromServerPackageSpec response.
     * @param  int|string  $osId  Requested template ID.
     * @return array|null Template row, or null when not offered/disabled/malformed.
     */
    public static function eligibleTemplate(?array $response, $osId): ?array
    {
        if (! is_array($response['data'] ?? null) || (! is_int($osId) && ! is_string($osId))) {
            return null;
        }

        try {
            $wanted = ServiceTools::positiveId($osId);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        $templates = [];
        foreach ($response['data'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (array_key_exists('templates', $row)) {
                foreach (is_array($row['templates']) ? $row['templates'] : [] as $template) {
                    if (is_array($template)) {
                        $templates[] = $template;
                    }
                }
            } elseif (isset($row['id'])) {
                $templates[] = $row;
            }
        }

        foreach ($templates as $template) {
            if (ServiceTools::matchesId($template['id'] ?? null, $wanted)) {
                return ($template['enabled'] ?? true) === false ? null : $template;
            }
        }

        return null;
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
    /** Backward-compatible wrapper; never use a login-user ID as a client relation. */
    public function getUserSshKeys(?User $user): ?array
    {
        try {
            return $this->getClientSshKeys(Access::client());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve the native account from a WHMCS client ID before listing SSH keys.
     *
     * The caller must authorize this selected client or use trusted provisioning
     * parameters. This ID is never the authenticated login-user ID.
     *
     * @return array|null Enabled user-scoped key response, or null on verification failure.
     */
    public function getClientSshKeys(int $clientId): ?array
    {
        try {
            if (! $this->cp) {
                return null;
            }
            $vfUser = $this->getVFUserDetails($clientId);
            if (! $vfUser || empty($vfUser['id'])) {
                return null;
            }

            return $this->getKeysForVfUser((int) $vfUser['id']);
        } catch (\Throwable $e) {
            Log::insert(__FUNCTION__, ['client' => $clientId], 'SSH key lookup failed.');

            return null;
        }
    }

    /**
     * Read enabled SSH keys only from an already verified native user inventory.
     *
     * @param  int  $userId  Native user ID resolved for the authorized account.
     * @return array|null Native response containing an enabled data list.
     *
     * @throws \RuntimeException A returned ownership field names another user.
     */
    public function getKeysForVfUser(int $userId): ?array
    {
        $request = $this->initCurl($this->cp['token']);
        $raw = $request->get($this->cp['url'] . '/ssh_keys/user/' . $userId);
        if ((int) $request->getRequestInfo('http_code') !== 200) {
            return null;
        }
        $response = json_decode((string) $raw, true);
        if (! is_array($response['data'] ?? null)) {
            return null;
        }
        foreach ($response['data'] as $key) {
            if ((isset($key['ownerId']) || isset($key['userId'])) && (int) ($key['ownerId'] ?? $key['userId']) !== $userId) {
                throw new \RuntimeException('SSH key ownership mismatch.', 403);
            }
        }

        $response['data'] = array_values(array_filter($response['data'], fn ($key) => ($key['enabled'] ?? true) !== false));

        return $response;
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
                $this->cp['url'] . '/users/' . $this->extRelationRef($id) . '/byExtRelation' . $this->extRelationQuery(),
            );

            if ((int) $request->getRequestInfo('http_code') !== 200) {
                return null;
            }

            $response = $this->decodeResponseFromJson($raw);

            $vfUser = $response['data'] ?? null;
            if (! is_array($vfUser)) {
                return null;
            }
            $this->verifyUserRelation($vfUser, $id, true);

            return $vfUser;
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

            $osId = $vars['customfields']['Initial Operating System'] ?? null;
            if (empty($osId)) {
                Log::insert(__FUNCTION__, [], 'Skipped build: Initial Operating System custom field is empty');

                return false;
            }

            // The order-time custom field is client-supplied; only a template the
            // package actually offers (and that is enabled) may be built.
            $template = self::eligibleTemplate($this->fetchTemplates((int) ($vars['configoption2'] ?? 0)), $osId);
            if (! $template) {
                Log::insert(__FUNCTION__, ['serviceid' => $vars['serviceid'] ?? null, 'osId' => is_scalar($osId) ? (string) $osId : null], 'Skipped build: Initial Operating System is not offered for this package');

                return false;
            }

            $sshKeyValue = $vars['customfields']['Initial SSH Key'] ?? null;
            $sshKeyId = null;

            if (! empty($sshKeyValue)) {
                if (is_numeric($sshKeyValue)) {
                    // Existing SSH key ID — must belong to the service owner.
                    $key = null;

                    try {
                        $keys = $vfUserId ? $this->getKeysForVfUser($vfUserId) : $this->getClientSshKeys((int) ($vars['userid'] ?? 0));
                        $key = ServiceTools::select($keys['data'] ?? [], ServiceTools::positiveId((string) $sshKeyValue), ['id']);
                    } catch (\Throwable $e) {
                        $key = null;
                    }
                    if (! $key) {
                        Log::insert(__FUNCTION__, ['serviceid' => $vars['serviceid'] ?? null], 'Skipped build: Initial SSH Key does not belong to the service owner');

                        return false;
                    }
                    $sshKeyId = (int) $key['id'];
                } elseif (preg_match('/^(ssh-|ecdsa-sha2-|sk-ssh-|sk-ecdsa-)/', $sshKeyValue) && $vfUserId) {
                    // Raw public key — create it via API under the resolved owner
                    $sshKeyId = $this->createUserSshKey($vfUserId, $sshKeyValue);
                }
            }

            $request = $this->initCurl($this->cp['token']);

            // Generate a hostname with sufficient entropy to avoid collisions
            $hostname = 'vps-' . bin2hex(random_bytes(4));

            $inputData = [
                'operatingSystemId' => (int) $template['id'],
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

    /** Shown when a client has no VirtFusion user yet (created with the first server). */
    public const NO_PANEL_ACCOUNT = 'Your control panel account is created with your first server; add keys after it is active.';

    /**
     * Add a named SSH key to the VirtFusion user of a WHMCS client.
     *
     * The VF user is resolved from the client ID (never from request input), the
     * name and key are re-validated here, and the refreshed list is returned
     * through SshKeys::present() so the key body never reaches the browser.
     *
     * @param  int  $clientId  Authorized WHMCS client-account ID.
     * @return array<int, array{id: int, name: string, fingerprint: string|null}>
     *
     * @throws \InvalidArgumentException Invalid name/key, or VirtFusion rejected the key.
     * @throws \RuntimeException 409 no VF user yet, 502/503 panel failure.
     */
    public function addClientSshKey(int $clientId, string $name, string $publicKey): array
    {
        $name = SshKeys::name($name);
        $publicKey = SshKeys::publicKey($publicKey);
        $vfUserId = $this->panelUserId($clientId);

        $request = $this->initCurl($this->cp['token']);
        $request->addOption(CURLOPT_POSTFIELDS, json_encode(['userId' => $vfUserId, 'name' => $name, 'publicKey' => $publicKey]));
        $response = $request->post($this->cp['url'] . '/ssh_keys');
        $code = (int) $request->getRequestInfo('http_code');
        Log::insert(__FUNCTION__, ['client' => $clientId, 'vfUser' => $vfUserId, 'status' => $code], $code === 200 || $code === 201 ? 'created' : $response);

        if ($code === 400 || $code === 422) {
            throw new \InvalidArgumentException('VirtFusion rejected this key. It may already be on your account.');
        }
        if ($code !== 200 && $code !== 201) {
            throw new \RuntimeException('Unable to add the SSH key right now. Try again later.', 502);
        }

        return $this->presentKeys($vfUserId);
    }

    /**
     * Delete one SSH key, only when it is in the client's own VF key inventory.
     *
     * Ownership is proven by getKeysForVfUser() for the client's VF user (which
     * also fails closed on a foreign ownerId). An unknown or foreign ID is a 404
     * and no DELETE is sent.
     *
     * @param  int  $clientId  Authorized WHMCS client-account ID.
     * @param  int  $keyId  Requested VirtFusion key ID.
     * @return array<int, array{id: int, name: string, fingerprint: string|null}>
     *
     * @throws \RuntimeException 404 not owned, 409 no VF user yet, 403 inventory mismatch, 502/503 panel failure.
     */
    public function deleteClientSshKey(int $clientId, int $keyId): array
    {
        if ($keyId <= 0) {
            throw new \RuntimeException('SSH key not found.', 404);
        }
        $vfUserId = $this->panelUserId($clientId);
        $keys = $this->getKeysForVfUser($vfUserId);
        if ($keys === null) {
            throw new \RuntimeException('Unable to load your SSH keys right now. Try again later.', 502);
        }
        $owned = false;
        foreach ($keys['data'] as $key) {
            if (is_array($key) && ServiceTools::matchesId($key['id'] ?? null, $keyId)) {
                $owned = true;
                break;
            }
        }
        if (! $owned) {
            Log::insert(__FUNCTION__ . ':notOwned', ['client' => $clientId, 'vfUser' => $vfUserId, 'key' => $keyId], 'SSH key is not in the client inventory; no DELETE sent.');

            throw new \RuntimeException('SSH key not found.', 404);
        }

        $request = $this->initCurl($this->cp['token']);
        $response = $request->delete($this->cp['url'] . '/ssh_keys/' . $keyId);
        $code = (int) $request->getRequestInfo('http_code');
        Log::insert(__FUNCTION__, ['client' => $clientId, 'vfUser' => $vfUserId, 'key' => $keyId, 'status' => $code], $response);
        if (! in_array($code, [200, 204, 404], true)) {
            throw new \RuntimeException('Unable to delete the SSH key right now. Try again later.', 502);
        }

        return $this->presentKeys($vfUserId);
    }

    /**
     * Resolve the VF user ID for a client, or explain that it does not exist yet.
     *
     * @throws \RuntimeException 503 without a panel, 409 when the VF user is missing.
     */
    private function panelUserId(int $clientId): int
    {
        if (! $this->cp) {
            throw new \RuntimeException('SSH key management is unavailable right now.', 503);
        }
        $vfUser = $this->getVFUserDetails($clientId);
        if (! $vfUser || empty($vfUser['id'])) {
            throw new \RuntimeException(self::NO_PANEL_ACCOUNT, 409);
        }

        return (int) $vfUser['id'];
    }

    /** Refreshed, browser-safe key list for a verified VF user (empty when unreadable). */
    private function presentKeys(int $vfUserId): array
    {
        $keys = $this->getKeysForVfUser($vfUserId);

        return SshKeys::present($keys['data'] ?? []);
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
