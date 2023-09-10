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
     * @param int $serverPackageId
     * @return array|null
     * @throws JsonException
     */
    public function fetchTemplates(int $serverPackageId): ?array
    {
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

        $request = $this->initCurl($this->cp['token']);

        $vfUser = $this->getVFUserDetails($user['id']);

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
        $request = $this->initCurl($this->cp['token']);

        $response = $this->decodeResponseFromJson($request->get(
            sprintf("%s/users/%d/byExtRelation", $this->cp['url'], $id)
        ));

        return isset($response['msg']) && $response['msg'] === "ext_relation_id not found" ? null : $response['data'];
    }
}