<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

final class SshOwnershipTest extends TestCase
{
    private function configurator(array $keys): ConfigureService
    {
        $cs = new class($keys) extends ConfigureService
        {
            public array $keys;

            public array $lookups = [];

            public function __construct($keys)
            {
                $this->keys = $keys;
                $property = new \ReflectionProperty(ConfigureService::class, 'cp');
                $property->setValue($this, ['url' => 'https://panel.example.test/api/v1', 'token' => 'test-token']);
            }

            public function getVFUserDetails(int $id): ?array
            {
                $this->lookups[] = $id;

                return ['id' => 1010, 'extRelationId' => $id];
            }

            public function initCurl($token)
            {
                $keys = $this->keys;

                return new class($keys) extends Curl
                {
                    private array $keys;

                    public function __construct($keys)
                    {
                        $this->keys = $keys;
                    }

                    public function get($url = null)
                    {
                        return json_encode(['data' => $this->keys]);
                    }

                    public function getRequestInfo($key = null)
                    {
                        return 200;
                    }
                };
            }
        };

        return $cs;
    }

    #[Test]
    public function ssh_lookup_uses_the_explicit_client_relation_and_accepts_documented_legacy_shape(): void
    {
        $cs = $this->configurator([['id' => 1, 'name' => 'Owned key', 'enabled' => true], ['id' => 2, 'enabled' => false]]);
        $keys = $cs->getClientSshKeys(10);
        $this->assertSame([10], $cs->lookups);
        $this->assertSame([1], array_column($keys['data'], 'id'));
        $this->expectException(\RuntimeException::class);
        ServiceTools::select($keys['data'], 20, ['id']);
    }

    #[Test]
    public function returned_foreign_key_owner_fails_closed(): void
    {
        $cs = $this->configurator([['id' => 20, 'ownerId' => 1020]]);
        $this->assertNull($cs->getClientSshKeys(10));
    }
}
