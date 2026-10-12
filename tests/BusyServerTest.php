<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\Module;

/** Answers ownership reads and returns a scripted status for every mutation. */
class BusyCurl extends Curl
{
    private object $owner;

    private int $code = 0;

    public function __construct(object $owner)
    {
        $this->owner = $owner;
    }

    public function addOption($name, $value) {}

    public function get($url = null)
    {
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $this->owner->calls[] = ['get', $path];
        $this->code = 200;
        if (preg_match('~/users/(\d+)/byExtRelation$~', $path, $m)) {
            return json_encode(['data' => ['id' => 1010, 'extRelationId' => (int) $m[1], 'admin' => false]]);
        }
        if (preg_match('~/servers/(\d+)$~', $path, $m)) {
            return json_encode(['data' => ['id' => (int) $m[1], 'owner' => ['id' => 1010]]]);
        }
        $this->code = 404;

        return '{}';
    }

    public function post($url = null)
    {
        $this->owner->calls[] = ['post', (string) parse_url((string) $url, PHP_URL_PATH)];
        $this->code = $this->owner->mutationCode;

        return $this->code === 423 ? json_encode(['errors' => ['server is locked']]) : json_encode(['data' => ['password' => 'x']]);
    }

    public function getRequestInfo($param = false)
    {
        return $param === 'http_code' ? $this->code : ['http_code' => $this->code];
    }
}

class BusyGateway extends Module
{
    public array $calls = [];

    public int $mutationCode = 423;

    /** Key ID createUserSshKey() returns (null = creation failed). */
    public ?int $createdKey = 55;

    public ?array $ownedKeys = [['id' => 9, 'name' => 'Mine']];

    public array $keyCalls = [];

    public function __construct() {}

    public function initCurl($token)
    {
        return new BusyCurl($this);
    }

    protected function configurator(int $panelId): ConfigureService
    {
        return new class($this) extends ConfigureService
        {
            private BusyGateway $gateway;

            public function __construct(BusyGateway $gateway)
            {
                $this->gateway = $gateway;
            }

            public function createUserSshKey(int $userId, string $publicKey): ?int
            {
                $this->gateway->keyCalls[] = ['create', $userId];

                return $this->gateway->createdKey;
            }

            public function getClientSshKeys(int $clientId): ?array
            {
                $this->gateway->keyCalls[] = ['list', $clientId];

                return $this->gateway->ownedKeys === null ? null : ['data' => $this->gateway->ownedKeys];
            }

            public function fetchTemplates(?int $serverPackageId): ?array
            {
                return ['data' => [['name' => 'Debian', 'templates' => [['id' => 7, 'name' => 'Debian 12']]]]];
            }
        };
    }
}

final class BusyServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        DB::schema()->create('tblhosting', function ($t) {
            $t->integer('id');
            $t->integer('userid');
            $t->integer('server');
            $t->integer('packageid');
            $t->string('domainstatus');
        });
        DB::schema()->create('tblproducts', function ($t) {
            $t->integer('id');
            $t->string('servertype');
            $t->string('configoption2')->nullable();
        });
        DB::schema()->create('tblservers', function ($t) {
            $t->integer('id');
            $t->string('type');
            $t->string('hostname');
            $t->string('password');
            $t->integer('disabled')->default(0);
        });
        DB::schema()->create('mod_virtfusion_direct', function ($t) {
            $t->integer('service_id');
            $t->integer('server_id')->nullable();
        });
        DB::table('tblhosting')->insert(['id' => 1, 'userid' => 10, 'server' => 1, 'packageid' => 1, 'domainstatus' => 'Active']);
        DB::table('tblproducts')->insert(['id' => 1, 'servertype' => 'VirtFusionDirect', 'configoption2' => '3']);
        DB::table('tblservers')->insert(['id' => 1, 'type' => 'VirtFusionDirect', 'hostname' => 'panel.example.test', 'password' => 'test-token']);
        DB::table('mod_virtfusion_direct')->insert(['service_id' => 1, 'server_id' => 100]);
    }

    public static function mutations(): array
    {
        return [
            'power' => [fn (Module $m) => $m->serverPowerAction(1, 'restart'), '/api/v1/servers/100/power/restart'],
            'server password' => [fn (Module $m) => $m->resetServerPassword(1, 'root'), '/api/v1/servers/100/resetPassword'],
            'rebuild' => [fn (Module $m) => $m->rebuildServer(1, 7, 'web-1'), '/api/v1/servers/100/build'],
        ];
    }

    #[Test]
    #[DataProvider('mutations')]
    public function a_locked_server_is_reported_as_busy(callable $call, string $path): void
    {
        $gateway = new BusyGateway;
        $this->assertFalse($call($gateway));
        $this->assertContains(['post', $path], $gateway->calls);
        $this->assertTrue($gateway->serverWasBusy());
    }

    #[Test]
    #[DataProvider('mutations')]
    public function other_failures_are_not_reported_as_busy(callable $call, string $path): void
    {
        $gateway = new BusyGateway;
        $gateway->mutationCode = 500;
        $this->assertFalse($call($gateway));
        $this->assertContains(['post', $path], $gateway->calls);
        $this->assertFalse($gateway->serverWasBusy());
    }

    #[Test]
    #[DataProvider('mutations')]
    public function success_clears_the_busy_flag(callable $call, string $path): void
    {
        $gateway = new BusyGateway;
        $this->assertFalse($call($gateway));
        $gateway->mutationCode = 200;
        $this->assertNotFalse($call($gateway));
        $this->assertFalse($gateway->serverWasBusy());
    }

    #[Test]
    public function busy_message_is_customer_readable(): void
    {
        $this->assertSame('The server is busy with another task. Try again in a minute.', Module::SERVER_BUSY_MESSAGE);
    }

    public static function errors(): array
    {
        return [
            'csrf' => [new \RuntimeException('Invalid CSRF token.', 403), 403, 'Invalid CSRF token.'],
            'auth' => [new \RuntimeException('Authentication required.', 401), 401, 'Authentication required.'],
            'missing' => [new \RuntimeException('SSH key not found.', 404), 404, 'SSH key not found.'],
            'method' => [new \RuntimeException('POST required.', 405), 405, 'POST required.'],
            'conflict' => [new \RuntimeException('Busy.', 409), 409, 'Busy.'],
            'throttle' => [new \RuntimeException('Slow down.', 429), 429, 'Slow down.'],
            'server error code' => [new \RuntimeException('internal detail', 500), 500, 'An unexpected error occurred'],
            'bad gateway code' => [new \RuntimeException('internal detail', 502), 500, 'An unexpected error occurred'],
            'no code' => [new \RuntimeException('internal detail'), 500, 'An unexpected error occurred'],
            'non-runtime with code' => [new \LogicException('internal detail', 403), 500, 'An unexpected error occurred'],
            'type error' => [new \TypeError('internal detail'), 500, 'An unexpected error occurred'],
        ];
    }

    #[Test]
    #[DataProvider('errors')]
    public function client_errors_honour_runtime_exception_codes(\Throwable $e, int $status, string $message): void
    {
        $this->assertSame(['status' => $status, 'message' => $message], Module::clientError($e));
    }

    private function builds(BusyGateway $gateway): array
    {
        return array_values(array_filter($gateway->calls, fn ($c) => $c === ['post', '/api/v1/servers/100/build']));
    }

    #[Test]
    public function rebuild_with_a_pasted_key_creates_it_under_the_verified_owner(): void
    {
        $gateway = new BusyGateway;
        $gateway->mutationCode = 200;
        $this->assertNotFalse($gateway->rebuildServer(1, 7, null, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA'));
        $this->assertSame([['create', 1010]], $gateway->keyCalls);
        $this->assertCount(1, $this->builds($gateway));
        $this->assertNull($gateway->lastRefusal());
    }

    #[Test]
    public function rebuild_refuses_when_the_pasted_key_cannot_be_created(): void
    {
        $gateway = new BusyGateway;
        $gateway->mutationCode = 200;
        $gateway->createdKey = null;
        $this->assertFalse($gateway->rebuildServer(1, 7, null, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA'));
        $this->assertSame([], $this->builds($gateway), 'No build without the requested key.');
        $this->assertSame(502, $gateway->lastRefusal()['status']);
        $this->assertStringContainsString('rebuild was not started', $gateway->lastRefusal()['message']);
    }

    #[Test]
    public function rebuild_refuses_a_key_id_the_owner_does_not_have(): void
    {
        $gateway = new BusyGateway;
        $gateway->mutationCode = 200;
        $this->assertFalse($gateway->rebuildServer(1, 7, null, '12'));
        $this->assertSame([], $this->builds($gateway));
        $this->assertSame(['status' => 404, 'message' => 'SSH key not found.'], $gateway->lastRefusal());

        $this->assertNotFalse($gateway->rebuildServer(1, 7, null, '9'));
        $this->assertNull($gateway->lastRefusal());
    }

    #[Test]
    public function rebuild_with_an_unreadable_key_list_is_a_502(): void
    {
        $gateway = new BusyGateway;
        $gateway->mutationCode = 200;
        $gateway->ownedKeys = null;
        $this->assertFalse($gateway->rebuildServer(1, 7, null, '9'));
        $this->assertSame([], $this->builds($gateway));
        $this->assertSame(502, $gateway->lastRefusal()['status']);
    }
}
