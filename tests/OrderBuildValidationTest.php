<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Curl;

final class OrderBuildValidationTest extends TestCase
{
    private const TEMPLATES = ['data' => [
        ['id' => 1, 'name' => 'Ubuntu', 'templates' => [['id' => 11], ['id' => 12, 'enabled' => false]]],
    ]];

    private function configurator(?array $templates, ?array $keys): ConfigureService
    {
        return new class($templates, $keys) extends ConfigureService
        {
            public array $posts = [];

            public array $keyLookups = [];

            public array $createdKeys = [];

            public function __construct(public ?array $templates, public ?array $keys)
            {
                $property = new \ReflectionProperty(ConfigureService::class, 'cp');
                $property->setValue($this, ['url' => 'https://panel.example.test/api/v1', 'token' => 'test-token']);
            }

            public function fetchTemplates(?int $serverPackageId): ?array
            {
                return $this->templates;
            }

            public function getKeysForVfUser(int $userId): ?array
            {
                $this->keyLookups[] = ['vf', $userId];

                return $this->keys;
            }

            public function getClientSshKeys(int $clientId): ?array
            {
                $this->keyLookups[] = ['client', $clientId];

                return $this->keys;
            }

            public function createUserSshKey(int $userId, string $publicKey): ?int
            {
                $this->createdKeys[] = [$userId, $publicKey];

                return 42;
            }

            public function initCurl($token)
            {
                $owner = $this;

                return new class($owner) extends Curl
                {
                    private $body;

                    public function __construct(private $owner) {}

                    public function addOption($name, $value)
                    {
                        if ($name === CURLOPT_POSTFIELDS) {
                            $this->body = $value;
                        }
                    }

                    public function post($url = null)
                    {
                        $this->owner->posts[] = ['url' => $url, 'body' => json_decode((string) $this->body, true)];

                        return '{"data":{}}';
                    }

                    public function getRequestInfo($param = false)
                    {
                        return $param === 'http_code' ? 200 : [];
                    }
                };
            }
        };
    }

    private function vars(string $os, ?string $key = null): array
    {
        $fields = ['Initial Operating System' => $os];
        if ($key !== null) {
            $fields['Initial SSH Key'] = $key;
        }

        return ['serviceid' => 7, 'userid' => 10, 'configoption2' => '3', 'customfields' => $fields];
    }

    #[Test]
    public function eligible_template_flattens_categories_and_rejects_disabled(): void
    {
        $this->assertSame(11, ConfigureService::eligibleTemplate(self::TEMPLATES, 11)['id'] ?? null);
        $this->assertSame(11, ConfigureService::eligibleTemplate(self::TEMPLATES, '11')['id'] ?? null);
        $this->assertNull(ConfigureService::eligibleTemplate(self::TEMPLATES, 12));
        $this->assertNull(ConfigureService::eligibleTemplate(self::TEMPLATES, 99));
        $this->assertNull(ConfigureService::eligibleTemplate(self::TEMPLATES, 1));
        $this->assertNull(ConfigureService::eligibleTemplate(null, 11));
        $this->assertNull(ConfigureService::eligibleTemplate(['data' => 'bad'], 11));
        $this->assertNull(ConfigureService::eligibleTemplate(self::TEMPLATES, 'abc'));
        $this->assertNull(ConfigureService::eligibleTemplate(self::TEMPLATES, ['11']));
        $this->assertNull(ConfigureService::eligibleTemplate(self::TEMPLATES, 11.0));
        $this->assertSame(5, ConfigureService::eligibleTemplate(['data' => [['id' => 5]]], 5)['id'] ?? null);
    }

    #[Test]
    public function build_refuses_template_not_offered_for_package(): void
    {
        $cs = $this->configurator(self::TEMPLATES, ['data' => [['id' => 5]]]);
        $this->assertFalse($cs->initServerBuild(100, $this->vars('99'), 1010));
        $this->assertSame([], $cs->posts);
        $logs = $this->logsFor('initServerBuild');
        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('not offered', (string) end($logs)['response']);
    }

    #[Test]
    public function build_refuses_foreign_ssh_key_id(): void
    {
        $cs = $this->configurator(self::TEMPLATES, ['data' => [['id' => 5]]]);
        $this->assertFalse($cs->initServerBuild(100, $this->vars('11', '777'), 1010));
        $this->assertSame([], $cs->posts);
        $this->assertSame([['vf', 1010]], $cs->keyLookups);
        $logs = $this->logsFor('initServerBuild');
        $this->assertStringContainsString('does not belong', (string) end($logs)['response']);
    }

    #[Test]
    public function build_refuses_key_when_inventory_unavailable(): void
    {
        $cs = $this->configurator(self::TEMPLATES, null);
        $this->assertFalse($cs->initServerBuild(100, $this->vars('11', '5')));
        $this->assertSame([['client', 10]], $cs->keyLookups);
        $this->assertSame([], $cs->posts);
    }

    #[Test]
    public function build_uses_selected_template_and_owned_key(): void
    {
        $cs = $this->configurator(self::TEMPLATES, ['data' => [['id' => 5]]]);
        $this->assertTrue($cs->initServerBuild(100, $this->vars('11', '5'), 1010));
        $this->assertCount(1, $cs->posts);
        $this->assertStringEndsWith('/servers/100/build', $cs->posts[0]['url']);
        $this->assertSame(11, $cs->posts[0]['body']['operatingSystemId']);
        $this->assertSame([5], $cs->posts[0]['body']['sshKeys']);
    }

    #[Test]
    public function raw_public_key_path_unchanged(): void
    {
        $cs = $this->configurator(self::TEMPLATES, ['data' => []]);
        $this->assertTrue($cs->initServerBuild(100, $this->vars('11', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest'), 1010));
        $this->assertSame(1010, $cs->createdKeys[0][0] ?? null);
        $this->assertSame([], $cs->keyLookups);
        $this->assertCount(1, $cs->posts);
        $this->assertSame([42], $cs->posts[0]['body']['sshKeys']);
    }

    #[Test]
    public function raw_key_is_not_created_when_template_is_refused(): void
    {
        $cs = $this->configurator(self::TEMPLATES, ['data' => []]);
        $this->assertFalse($cs->initServerBuild(100, $this->vars('99', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest'), 1010));
        $this->assertSame([], $cs->createdKeys);
        $this->assertSame([], $cs->posts);
    }

    #[Test]
    public function rebuild_uses_template_from_category_payload(): void
    {
        $this->assertSame(11, ConfigureService::eligibleTemplate(self::TEMPLATES, 11)['id'] ?? null);
        $source = (string) file_get_contents(VFD_LIB . '/Module.php');
        $this->assertStringContainsString('ConfigureService::eligibleTemplate($eligible, $osId)', $source);
        $this->assertStringNotContainsString("ServiceTools::select(\$eligible['data']", $source);
        $this->assertStringContainsString("'operatingSystemId' => (int) \$template['id'], 'email' => true", $source);
    }

    #[Test]
    public function checkout_rejects_unoffered_numeric_templates(): void
    {
        $source = (string) file_get_contents(VFD_LIB . '/../hooks.php');
        $this->assertStringContainsString("is_array(\$templates['data'] ?? null) && ! ConfigureService::eligibleTemplate(\$templates, \$fieldValue)", $source);
        $this->assertStringContainsString('$packageSpecId > 0 ? (new ConfigureService)->fetchTemplates($packageSpecId) : null', $source);
        $this->assertStringNotContainsString('$templates !== null && ! ConfigureService::eligibleTemplate', $source);
        $this->assertStringContainsString('The selected Operating System is not available for this product.', $source);
    }
}
