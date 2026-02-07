<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use JsonException;
use WHMCS\Database\Capsule as DB;
use WHMCS\User\User;

class ConfigureService extends Module
{
    /**
     * @var array|false $cp
     */
    private array|bool $cp;

    public function __construct()
    {
        parent::__construct();
        $this->cp = $this->getCP(false, true);
    }

    /**
     * @param string $packageName
     * @return int|null
     * @throws JsonException
     */
    public function fetchPackageId(string $packageName): ?int
    {
        if (!$this->cp) return null;

        $request = $this->initCurl($this->cp['token']);

        $response = $request->get(
            sprintf("%s/packages", $this->cp['url'])
        );

        $packages = $this->decodeResponseFromJson($response);

        foreach ($packages['data'] as $package) {
            if ($package['name'] === $packageName && $package['enabled'] === true) {
                return $package['id'];
            }
        }

        return null;
    }


    /**
     * @param int $productId
     * @return int|null
     */
    public function fetchPackageByDbId(int $productId): ?int
    {
        $product = DB::table('tblproducts')->where('id', $productId)->first();

        if (is_null($product)) {
            return null;
        }

        return (int)$product->configoption2;
    }

    /**
     * @param int $serverPackageId
     * @return array|null
     * @throws JsonException
     */
    public function fetchTemplates(?int $serverPackageId): ?array
    {
        if (is_null($serverPackageId)) {
            return null;
        }

        if (!$this->cp) return null;

        $request = $this->initCurl($this->cp['token']);

        $response = $request->get(
            sprintf("%s/media/templates/fromServerPackageSpec/%d", $this->cp['url'], $serverPackageId)
        );

        return $this->decodeResponseFromJson($response);
    }

    /**
     * @param User|null $user
     * @return array|null
     * @throws JsonException
     */
    public function getUserSshKeys(?User $user): ?array
    {
        if (is_null($user)) {
            return null;
        }

        if (!$this->cp) return null;

        $request = $this->initCurl($this->cp['token']);

        $vfUser = $this->getVFUserDetails($user['id']);

        if (!$vfUser) return null;

        $response = $request->get(
            sprintf("%s/ssh_keys/user/%d", $this->cp['url'], $vfUser['id'])
        );

        return $this->decodeResponseFromJson($response);
    }

    /**
     * @param int $id
     * @return array|null
     * @throws JsonException
     */
    public function getVFUserDetails(int $id): ?array
    {
        if (!$this->cp) return null;

        $request = $this->initCurl($this->cp['token']);

        $response = $this->decodeResponseFromJson($request->get(
            sprintf("%s/users/%d/byExtRelation", $this->cp['url'], $id)
        ));

        return isset($response['msg']) && $response['msg'] === "ext_relation_id not found" ? null : $response['data'];
    }

    /**
     * @param int $id
     * @param array $vars
     * @param int|null $vfUserId VirtFusion user ID (for creating SSH keys from raw public key)
     * @return bool
     */
    public function initServerBuild(int $id, array $vars, ?int $vfUserId = null): bool
    {
        if (!$this->cp) return false;

        $request = $this->initCurl($this->cp['token']);

        // Generate a random 8 character hostname
        $hostname = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 8);

        $sshKeyValue = $vars['customfields']['Initial SSH Key'] ?? null;
        $sshKeyId = null;

        if (!empty($sshKeyValue)) {
            if (is_numeric($sshKeyValue)) {
                // Existing SSH key ID
                $sshKeyId = (int) $sshKeyValue;
            } elseif (preg_match('/^ssh-/', $sshKeyValue) && $vfUserId) {
                // Raw public key — create it via API
                $sshKeyId = $this->createUserSshKey($vfUserId, $sshKeyValue);
            }
        }

        $inputData = [
            "operatingSystemId" => $vars['customfields']['Initial Operating System'] ?? null,
            "name" => $hostname,
            'email' => true
        ];

        if ($sshKeyId) {
            $inputData['sshKeys'] = [$sshKeyId];
        }

        $request->addOption(CURLOPT_POSTFIELDS, json_encode($inputData));

        $response = $request->post(
            sprintf("%s/servers/%d/build", $this->cp['url'], $id)
        );

        $httpCode = $request->getRequestInfo('http_code');
        Log::insert(__FUNCTION__, $request->getRequestInfo(), $response);

        return ($httpCode == 200 || $httpCode == 201);
    }

    /**
     * Create an SSH key for a VirtFusion user from a raw public key string.
     *
     * @param int $userId VirtFusion user ID
     * @param string $publicKey Raw SSH public key (ssh-rsa ..., ssh-ed25519 ..., etc.)
     * @return int|null Created key ID or null on failure
     */
    public function createUserSshKey(int $userId, string $publicKey): ?int
    {
        if (!$this->cp) return null;

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
    }
}
