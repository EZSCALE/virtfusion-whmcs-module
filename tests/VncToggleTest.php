<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\Module;

/** Module with a fixed service context and a curl that records the POST body. */
class VncPanel extends Module
{
    public array $bodies = [];

    public array $paths = [];

    public int $code = 200;

    public ?array $response = null;

    public function __construct() {}

    public function legacyCapability(array $cp, string $minimum): bool
    {
        return true;
    }

    protected function resolveServiceContext($serviceID)
    {
        $panel = $this;
        $curl = new class($panel) extends Curl
        {
            private ?string $body = null;

            public function __construct(private VncPanel $panel) {}

            public function addOption($name, $value)
            {
                if ($name === CURLOPT_POSTFIELDS) {
                    $this->body = $value;
                }
            }

            public function post($url = null)
            {
                $this->panel->paths[] = (string) parse_url((string) $url, PHP_URL_PATH);
                $this->panel->bodies[] = json_decode((string) $this->body, true);

                return $this->panel->response === null ? '' : json_encode($this->panel->response);
            }

            public function getRequestInfo($param = false)
            {
                return $param === 'http_code' ? $this->panel->code : ['http_code' => $this->panel->code];
            }
        };

        return ['service' => (object) [], 'whmcsService' => (object) ['userid' => 10], 'cp' => ['url' => 'https://cp.example.test/api/v1', 'token' => 't', 'panel_id' => 1], 'request' => $curl, 'serverId' => 914];
    }

    private static function vnc(bool $enabled): array
    {
        return ['data' => ['vnc' => ['ip' => '10.0.0.1', 'port' => 5903, 'password' => 'pw', 'wss' => ['token' => 'tok', 'url' => '/vnc/?token=tok'], 'enabled' => $enabled], 'queueId' => $enabled ? 6184 : null]];
    }

    public function answer(bool $enabled): self
    {
        $this->response = self::vnc($enabled);

        return $this;
    }
}

final class VncToggleTest extends TestCase
{
    #[Test]
    public function enable_posts_the_documented_action_body(): void
    {
        $panel = (new VncPanel)->answer(true);
        $result = $panel->toggleVnc(1, true);

        $this->assertSame([['action' => 'enable']], $panel->bodies);
        $this->assertSame(['/api/v1/servers/914/vnc'], $panel->paths);
        $this->assertTrue($result['data']['vnc']['enabled']);
        $this->assertSame('https://cp.example.test', $result['baseUrl']);
    }

    #[Test]
    public function disable_posts_the_documented_action_body(): void
    {
        $panel = (new VncPanel)->answer(false);

        $this->assertNotFalse($panel->toggleVnc(1, false));
        $this->assertSame([['action' => 'disable']], $panel->bodies);
    }

    #[Test]
    public function enable_answered_with_enabled_false_is_a_failure(): void
    {
        // Live: the old {"vnc":true} body got 200 + enabled:false + queueId:null, and the token never streamed.
        $panel = (new VncPanel)->answer(false);

        $this->assertFalse($panel->toggleVnc(1, true));
        $this->assertCount(1, $this->logsFor('toggleVnc:notEnabled'));
    }

    #[Test]
    public function the_legacy_vnc_key_is_never_sent(): void
    {
        $source = (string) file_get_contents(VFD_LIB . '/Module.php');
        $start = strpos($source, 'public function toggleVnc(');
        $body = substr($source, $start, (int) strpos($source, "\n    }\n", $start) - $start);
        $this->assertStringNotContainsString("'vnc' =>", $body);
        $this->assertStringContainsString("'action' => \$enabled ? 'enable' : 'disable'", $body);
    }
}
