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
     * @return bool
     */
    public function initServerBuild(int $id, array $vars): bool
    {
        if (!$this->cp) return false;

        $request = $this->initCurl($this->cp['token']);

        // Generate a random 8 character hostname
        $hostname = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 8);

        $inputData = [
            "operatingSystemId" => $vars['customfields']['Initial Operating System'] ?? null,
            "name" => $hostname,
            "sshKeys" => [
                $vars['customfields']['Initial SSH Key'] ?? null
            ],
            'email' => true
        ];

        if (empty($vars['customfields']['Initial SSH Key'] ?? null)) {
            unset($inputData['sshKeys']);
        }

        $request->addOption(CURLOPT_POSTFIELDS, json_encode($inputData));

        $response = $request->post(
            sprintf("%s/servers/%d/build", $this->cp['url'], $id)
        );

        $httpCode = $request->getRequestInfo('http_code');
        Log::insert(__FUNCTION__, $request->getRequestInfo(), $response);

        return ($httpCode == 200 || $httpCode == 201);
    }
}
