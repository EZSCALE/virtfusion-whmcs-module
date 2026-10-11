<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests\Support;

use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\ModuleFunctions;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\PtrManager;

/**
 * ModuleFunctions with every network and DNS collaborator replaced by a recorder.
 *
 * Routes are keyed 'METHOD path' (path relative to the panel API root, query kept)
 * and map to [httpCode, decodedBodyOrNull]. Unrouted requests answer 404.
 */
class LifecycleGateway extends ModuleFunctions
{
    public const API = 'https://panel.example.test/api/v1';

    /** @var array<int,array{0:string,1:string,2:mixed}> */
    public array $calls = [];

    /** @var array<string,array{0:int,1:array|null}> */
    public array $routes = [];

    /** @var array<string,array<int,array{0:int,1:array|null}>> Per-route responses consumed in order before falling back to routes. */
    public array $sequences = [];

    public bool $dns = false;

    /** @var array<int,array{0:string,1:array}> */
    public array $ptr = [];

    public ?ConfigureService $cs = null;

    public function __construct() {}

    public function getCP($server, $any = false)
    {
        return ['url' => self::API, 'base_url' => 'https://panel.example.test', 'panel_id' => 1, 'token' => 't'];
    }

    public function initCurl($token)
    {
        $owner = $this;

        return new class($owner) extends Curl
        {
            private $body = null;

            private int $code = 0;

            public function __construct(private LifecycleGateway $owner) {}

            public function addOption($name, $value)
            {
                if ($name === CURLOPT_POSTFIELDS) {
                    $this->body = $value;
                }
            }

            private function route(string $method, $url)
            {
                $path = (string) $url;
                if (str_starts_with($path, LifecycleGateway::API)) {
                    $path = substr($path, strlen(LifecycleGateway::API));
                }
                $this->owner->calls[] = [$method, $path, $this->body === null ? null : json_decode((string) $this->body, true)];
                $key = $method . ' ' . $path;
                if (! empty($this->owner->sequences[$key])) {
                    [$code, $body] = array_shift($this->owner->sequences[$key]);
                } else {
                    [$code, $body] = $this->owner->routes[$key] ?? [404, ['msg' => 'not routed']];
                }
                $this->code = (int) $code;

                return $body === null ? '' : json_encode($body);
            }

            public function get($url = null)
            {
                return $this->route('GET', $url);
            }

            public function post($url = null)
            {
                return $this->route('POST', $url);
            }

            public function put($url = null)
            {
                return $this->route('PUT', $url);
            }

            public function patch($url = null)
            {
                return $this->route('PATCH', $url);
            }

            public function delete($url = null)
            {
                return $this->route('DELETE', $url);
            }

            public function getRequestInfo($p = false)
            {
                return $p === false ? ['http_code' => $this->code] : $this->code;
            }
        };
    }

    protected function powerDnsEnabled(): bool
    {
        return $this->dns;
    }

    protected function ptrManager(): PtrManager
    {
        $owner = $this;

        return new class($owner) extends PtrManager
        {
            public function __construct(private LifecycleGateway $owner) {}

            public function syncServer($serverObject, ?string $oldHostname, string $newHostname): array
            {
                $this->owner->ptr[] = ['syncServer', [$serverObject, $oldHostname, $newHostname]];

                return [];
            }

            public function deleteForServer($serverObject, ?string $onlyMatchingHostname = null): array
            {
                $this->owner->ptr[] = ['deleteForServer', [$serverObject, $onlyMatchingHostname]];

                return [];
            }
        };
    }

    protected function configureService(int $panelId): ConfigureService
    {
        return $this->cs ?? new class extends ConfigureService
        {
            public function __construct() {}

            public function initServerBuild(int $id, array $vars, ?int $vfUserId = null): bool
            {
                return true;
            }
        };
    }
}
