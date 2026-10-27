<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

require_once __DIR__ . '/ServiceAuthorizationTest.php';

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\Cache;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;
use WHMCS\Module\Server\VirtFusionDirect\PtrTools;
use WHMCS\Module\Server\VirtFusionDirect\ResourcePack;

/** PTR fixture on 192.0.2.10 whose forward-DNS answer is scripted. */
class PtrForwardFixture extends AuthorizedToolsFixture
{
    public string $backend = 'virtfusion';

    /** @var bool|\Closure */
    public $forward = true;

    public int $forwardChecks = 0;

    protected function settings(int $panel): array
    {
        return array_replace(ProviderSettings::defaults(), ['rdns' => true, 'rdnsBackend' => $this->backend]);
    }

    public function verifyVmOwner(array $cp, int $vm, int $client): array
    {
        $owned = parent::verifyVmOwner($cp, $vm, $client);
        $owned['server']['network'] = ['interfaces' => [['ipv4' => [['address' => '192.0.2.10']]]]];

        return $owned;
    }

    public function forwardDnsMatches(string $hostname, string $ip): bool
    {
        $this->forwardChecks++;

        return $this->forward instanceof \Closure ? ($this->forward)($hostname, $ip) : $this->forward;
    }

    public function request(array $ctx, string $method, string $path, ?array $body = null): array
    {
        $this->writes[] = [$path, $body];

        return [200, ['data' => ['address' => '192.0.2.10', 'ptr' => $body['ptr'] ?? null]]];
    }
}

/**
 * S11 input hardening: FCrDNS on both PTR backends, strict hostnames,
 * a cached/throttled serverData view and a tblhosting sync that never
 * copies free-text names or blanks the stored password.
 */
final class InputHardeningTest extends TestCase
{
    private ?string $cacheDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        DB::schema()->create('tblhosting', function ($t) {
            $t->integer('id');
            $t->integer('userid');
            $t->integer('server');
            $t->integer('packageid');
            $t->string('domainstatus');
            $t->string('domain')->nullable();
            $t->string('dedicatedip')->nullable();
            $t->string('username')->nullable();
            $t->string('password')->nullable();
        });
        DB::schema()->create('tblproducts', function ($t) {
            $t->integer('id');
            $t->string('servertype');
            $t->string('configoption9')->nullable();
        });
        DB::schema()->create('tblservers', function ($t) {
            $t->integer('id');
            $t->string('type');
            $t->string('hostname');
            $t->string('password');
            $t->integer('disabled')->default(0);
        });
        DB::schema()->create('tblusers_clients', function ($t) {
            $t->integer('auth_user_id');
            $t->integer('client_id');
            $t->integer('owner');
            $t->string('permissions')->nullable();
        });
        DB::schema()->create('mod_virtfusion_direct', function ($t) {
            $t->integer('service_id');
            $t->integer('server_id');
        });
        DB::schema()->create('tbladdonmodules', function ($t) {
            $t->string('module');
            $t->string('setting');
            $t->text('value')->nullable();
        });
        DB::table('tblhosting')->insert(['id' => 1, 'userid' => 10, 'server' => 1, 'packageid' => 1, 'domainstatus' => 'Active', 'domain' => 'keep.example', 'password' => 'x']);
        DB::table('tblproducts')->insert(['id' => 1, 'servertype' => 'VirtFusionDirect', 'configoption9' => '']);
        DB::table('tblservers')->insert(['id' => 1, 'type' => 'VirtFusionDirect', 'hostname' => 'panel.example.test', 'password' => 'test-token']);
        DB::table('mod_virtfusion_direct')->insert(['service_id' => 1, 'server_id' => 100]);
        DB::table('tblusers_clients')->insert(['auth_user_id' => 100, 'client_id' => 10, 'owner' => 1]);
        ResourcePack::schema();
        Operation::schema();
        Config::reset();
        CurrentUser::$client = (object) ['id' => 10];
        CurrentUser::$user = (object) ['id' => 100];
        CurrentUser::$admin = null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $GLOBALS['vfd_valid_csrf'] = true;
    }

    protected function tearDown(): void
    {
        Config::reset();
        foreach (['fileDir' => null, 'fileChecked' => false] as $name => $value) {
            $p = new ReflectionProperty(Cache::class, $name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
        if ($this->cacheDir !== null) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    private function ptrSet(PtrForwardFixture $tools, string $hostname, string $requestId): array
    {
        return $tools->execute(1, 'ptrSet', ['address' => '192.0.2.10', 'hostname' => $hostname, 'requestId' => str_repeat($requestId, 32)]);
    }

    private function enablePowerDns(): void
    {
        DB::table('tbladdonmodules')->insert([
            ['module' => 'virtfusiondns', 'setting' => 'enabled', 'value' => 'on'],
            ['module' => 'virtfusiondns', 'setting' => 'endpoint', 'value' => 'https://pdns.example.test'],
            ['module' => 'virtfusiondns', 'setting' => 'apiKey', 'value' => 'test-key'],
        ]);
        Config::reset();
        $this->assertTrue(Config::isEnabled());
    }

    /** Point the file cache tier at a private, empty directory for this test. */
    private function isolatedCache(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/vfd-s11-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0700);
        foreach (['fileDir' => $this->cacheDir, 'fileChecked' => false] as $name => $value) {
            $p = new ReflectionProperty(Cache::class, $name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }

    private function serverObject(array $fields): object
    {
        return json_decode(json_encode(['data' => array_replace(['id' => 100, 'name' => '-', 'hostname' => null, 'owner' => ['email' => 'owner@example.test']], $fields)]));
    }

    #[Test]
    public function virtfusion_backend_ptr_requires_forward_dns(): void
    {
        $tools = new PtrForwardFixture;
        $tools->forward = false;

        try {
            $this->ptrSet($tools, 'a.example.test', 'e');
            $this->fail('expected FCrDNS rejection');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('Forward DNS for this hostname does not resolve to 192.0.2.10. Create the A/AAAA record first, then try again.', $e->getMessage());
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame(0, DB::table(Operation::TABLE)->count());

        $tools->forward = fn (string $host, string $ip) => $host === 'a.example.test' && $ip === '192.0.2.10';
        $result = $this->ptrSet($tools, 'a.example.test.', 'f');
        $this->assertSame('completed', $result['state']);
        $this->assertCount(1, $tools->writes);
        $this->assertSame('completed', DB::table(Operation::TABLE)->first()->state);
    }

    #[Test]
    public function ptr_rejects_localhost_ip_shaped_and_numeric_tld(): void
    {
        $tools = new PtrForwardFixture;
        foreach (['localhost', '1.2.3.4', 'host.123'] as $i => $hostname) {
            try {
                $this->ptrSet($tools, $hostname, (string) $i);
                $this->fail('accepted ' . $hostname);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Invalid PTR hostname.', $e->getMessage(), $hostname);
            }
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame(0, $tools->forwardChecks);
    }

    #[Test]
    public function ptr_rejects_trailing_newline_hostname(): void
    {
        $tools = new PtrForwardFixture;
        $tools->forward = true;

        try {
            $this->ptrSet($tools, "host.example.com\n", 'd');
            $this->fail('accepted a hostname with a trailing newline');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid PTR hostname.', $e->getMessage());
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame(0, $tools->forwardChecks);
        // Legacy client rdnsUpdate validates with the same constant: the normal forms still pass.
        $this->assertSame(1, preg_match(PtrTools::HOSTNAME_PATTERN, 'host.example.com'));
        $this->assertSame(1, preg_match(PtrTools::HOSTNAME_PATTERN, 'host.example.com.'));
        $this->assertSame(0, preg_match(PtrTools::HOSTNAME_PATTERN, "host.example.com\n"));
    }

    #[Test]
    public function powerdns_backend_checks_forward_dns_before_reserving(): void
    {
        $this->enablePowerDns();
        $tools = new PtrForwardFixture;
        $tools->backend = 'powerdns';
        $tools->forward = false;

        try {
            $this->ptrSet($tools, 'a.example.test', 'a');
            $this->fail('expected FCrDNS rejection');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->assertSame(1, $tools->forwardChecks);
        $this->assertSame(0, DB::table(Operation::TABLE)->count());
        $this->assertSame(0, DB::table(Operation::LOCKS)->count());
    }

    #[Test]
    public function empty_ptr_delete_skips_forward_check(): void
    {
        $tools = new PtrForwardFixture;
        $tools->forward = function () {
            throw new \LogicException('forward DNS must not be checked for a delete');
        };
        $result = $this->ptrSet($tools, '', 'b');
        $this->assertSame('completed', $result['state']);
        $this->assertSame(0, $tools->forwardChecks);
        $this->assertCount(1, $tools->writes);
        $this->assertNull($tools->writes[0][1]['ptr']);
    }

    #[Test]
    public function tblhosting_sync_copies_only_valid_hostname_and_never_password(): void
    {
        $module = $this->bare(Module::class);
        $row = fn () => DB::table('tblhosting')->where('id', 1)->first();

        $module->updateWhmcsServiceParamsOnServerObject(1, $this->serverObject(['hostname' => null, 'name' => '<b>x</b>']));
        $this->assertSame('keep.example', $row()->domain);
        $this->assertSame('x', $row()->password);
        $this->assertSame('owner@example.test', $row()->username);

        $module->updateWhmcsServiceParamsOnServerObject(1, $this->serverObject(['hostname' => 'vps1.example.com']));
        $this->assertSame('vps1.example.com', $row()->domain);
        $this->assertSame('x', $row()->password);

        $module->updateWhmcsServiceParamsOnServerObject(1, $this->serverObject(['hostname' => 'bad host']));
        $this->assertSame('vps1.example.com', $row()->domain);
        $this->assertSame('x', $row()->password);
    }

    #[Test]
    public function server_data_view_caches_and_skips_rate_limit_on_hit(): void
    {
        $this->isolatedCache();
        $module = new class extends Module
        {
            public int $fetches = 0;

            public $object;

            public function __construct() {}

            protected function resolveServiceContext($serviceID)
            {
                return ['cp' => ['url' => 'https://panel.example.test/api/v1', 'token' => 'test-token'], 'serverId' => 100];
            }

            public function fetchServerData($serviceID)
            {
                $this->fetches++;

                return $this->object;
            }
        };
        $module->object = $this->serverObject(['name' => 'web', 'hostname' => 'vps1.example.com']);
        $throttled = 0;
        $before = function () use (&$throttled) {
            $throttled++;
        };

        $first = $module->serverDataView(1, $before);
        $second = $module->serverDataView(1, $before);

        $this->assertSame(1, $module->fetches);
        $this->assertSame(1, $throttled);
        $this->assertSame('vps1.example.com', $first['hostname']);
        $this->assertSame($first, $second);
        $this->assertSame('', $second['password']);
        $this->assertArrayNotHasKey('data', $second, 'only the sanitised view is cached');
    }

    #[Test]
    public function hostname_validator_rules(): void
    {
        $this->assertTrue(Module::isValidHostname('web1'));
        $this->assertTrue(Module::isValidHostname('web1.example.com', 63));
        $this->assertFalse(Module::isValidHostname(''));
        $this->assertFalse(Module::isValidHostname(str_repeat('a', 64), 63));
        $this->assertFalse(Module::isValidHostname('a..b'));
        $this->assertFalse(Module::isValidHostname('-a'));
        $this->assertFalse(Module::isValidHostname('a_b'));
        $this->assertFalse(Module::isValidHostname('web.example.com.'));
        $this->assertFalse(Module::isValidHostname(str_repeat('a', 30) . '.' . str_repeat('b', 29) . '.com', 63));
        $this->assertTrue(Module::isValidHostname(str_repeat('a', 63)));
        $this->assertFalse(Module::isValidHostname(str_repeat('a', 64)));
    }
}
