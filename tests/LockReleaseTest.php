<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

require_once __DIR__ . '/ServiceAuthorizationTest.php';
require_once __DIR__ . '/PrivateIsoPolicyTest.php';

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\IsoLibrary;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;
use WHMCS\Module\Server\VirtFusionDirect\ResourcePack;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

/**
 * Unified-tool reservations must not outlive outcomes nothing can resolve.
 *
 * Fire-and-forget writes and pre-submission rejections release the VM; resolvable
 * actions, jobless backups and ambiguous sent requests keep it reserved.
 */
final class LockReleaseTest extends TestCase
{
    private const CTX = ['serviceId' => 1, 'serverId' => 100, 'panelId' => 1];

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
        DB::table('tblhosting')->insert(['id' => 1, 'userid' => 10, 'server' => 1, 'packageid' => 1, 'domainstatus' => 'Active']);
        DB::table('tblproducts')->insert(['id' => 1, 'servertype' => 'VirtFusionDirect', 'configoption9' => '']);
        DB::table('tblservers')->insert(['id' => 1, 'type' => 'VirtFusionDirect', 'hostname' => 'panel.example.test', 'password' => 'test-token']);
        DB::table('mod_virtfusion_direct')->insert(['service_id' => 1, 'server_id' => 100]);
        DB::table('tblusers_clients')->insert(['auth_user_id' => 100, 'client_id' => 10, 'owner' => 1]);
        ResourcePack::schema();
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
        CurrentUser::$admin = null;
        parent::tearDown();
    }

    /** Service tools whose native writes all answer with one scripted response. */
    private function fixture(int $code, $body, array $settings = []): ServiceTools
    {
        return new class($code, $body, $settings) extends ServiceTools
        {
            public array $writes = [];

            public array $reads = [];

            public array $owners = [100 => 1010];

            public bool $dnd = false;

            public function __construct(private int $code, private $body, private array $overrides) {}

            public function initCurl($token)
            {
                return new ScriptedCurl($this);
            }

            protected function version(array $cp): ?string
            {
                return '7.1.0';
            }

            protected function settings(int $panel): array
            {
                return array_replace(ProviderSettings::defaults(), ['power' => true, 'rescue' => true, 'backups' => true, 'rdns' => true, 'rdnsBackend' => 'virtfusion'], $this->overrides);
            }

            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                if ($method === 'get') {
                    if (str_contains($path, '/backupManager/')) {
                        return [200, ['data' => []]];
                    }

                    return [200, ['data' => ['remoteState' => ['state' => 'running'], 'images' => [['id' => 1]]]]];
                }
                $this->writes[] = [$path, $body];

                return [$this->code, $this->body];
            }
        };
    }

    /** Service tools whose VM carries 192.0.2.10 for PTR tests. */
    private function ptrFixture(string $backend, bool $rejectBeforeSubmit = false): ServiceTools
    {
        return new class($backend, $rejectBeforeSubmit) extends ServiceTools
        {
            public array $writes = [];

            public array $reads = [];

            public array $owners = [100 => 1010];

            public bool $dnd = false;

            public function __construct(private string $backend, private bool $reject) {}

            public function initCurl($token)
            {
                return new ScriptedCurl($this);
            }

            protected function version(array $cp): ?string
            {
                return '7.1.0';
            }

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
                return true;
            }

            public function revalidate(array $ctx, bool $admin): array
            {
                if ($this->reject) {
                    throw new \RuntimeException('Service changed before submission.', 409);
                }

                return parent::revalidate($ctx, $admin);
            }

            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                $this->writes[] = [$path, $body];

                return [200, ['data' => ['address' => '192.0.2.10', 'ptr' => 'a.example.test']]];
            }
        };
    }

    private function locks(): int
    {
        return DB::table(Operation::LOCKS)->count();
    }

    private function recoveryAdmin(): void
    {
        CurrentUser::$admin = new class
        {
            public int $id = 1;

            public int $roleid = 5;

            public function hasPermission($permission)
            {
                return in_array($permission, ['View Clients Products/Services', 'Perform Module Command Operations'], true);
            }
        };
    }

    private function reconcile(ServiceTools $tools, string $operationId): array
    {
        return $tools->execute(1, 'operationReconcile', [
            'operationId' => $operationId, 'reconciliationConfirmed' => true, 'targetConfirmed' => '100', 'resolution' => 'failed',
        ], true);
    }

    #[Test]
    public function power_204_completes_and_releases_the_vm(): void
    {
        $tools = $this->fixture(204, null);
        $first = $tools->execute(1, 'powerBoot', ['requestId' => str_repeat('a', 32)]);
        $this->assertSame('completed', $first['state']);
        $this->assertSame('Request accepted; verify applied state.', $first['message']);
        $this->assertSame(0, $this->locks());

        $second = $tools->execute(1, 'powerShutdown', ['powerConfirmed' => true, 'requestId' => str_repeat('b', 32)]);
        $this->assertSame('completed', $second['state']);
        $this->assertCount(2, $tools->writes);
        $this->assertSame(0, $this->locks());
    }

    #[Test]
    public function queued_202_without_job_id_completes_and_releases(): void
    {
        $tools = $this->fixture(202, ['data' => ['queueId' => 7]]);
        $result = $tools->execute(1, 'powerRestart', ['powerConfirmed' => true, 'requestId' => str_repeat('c', 32)]);
        $this->assertSame('completed', $result['state']);
        $this->assertSame('completed', Operation::get($result['operationId'], self::CTX)->state);
        $this->assertSame(0, $this->locks());
    }

    #[Test]
    public function native_ptr_backend_200_completes_and_releases(): void
    {
        $tools = $this->ptrFixture('virtfusion');
        $result = $tools->execute(1, 'ptrSet', ['address' => '192.0.2.10', 'hostname' => 'a.example.test', 'requestId' => str_repeat('d', 32)]);
        $this->assertSame('completed', $result['state']);
        $this->assertCount(1, $tools->writes);
        $this->assertSame(0, $this->locks());
    }

    #[Test]
    public function pre_request_rejection_finishes_failed_and_releases(): void
    {
        $tools = new class extends ServiceTools
        {
            public array $writes = [];

            public array $reads = [];

            public array $owners = [100 => 1010];

            public bool $dnd = false;

            public function __construct() {}

            public function initCurl($token)
            {
                return new ScriptedCurl($this);
            }

            protected function version(array $cp): ?string
            {
                return '7.1.0';
            }

            protected function settings(int $panel): array
            {
                return array_replace(ProviderSettings::defaults(), ['power' => true]);
            }

            public function revalidate(array $ctx, bool $admin): array
            {
                DB::table('tblhosting')->where('id', 1)->update(['domainstatus' => 'Suspended']);

                return parent::revalidate($ctx, $admin);
            }

            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                $this->writes[] = [$path, $body];

                return [204, null];
            }
        };

        try {
            $tools->execute(1, 'powerBoot', ['requestId' => str_repeat('e', 32)]);
            $this->fail('expected rejection');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('Service is no longer active.', $e->getMessage());
        }
        $this->assertSame([], $tools->writes, 'No native request was made');
        $this->assertSame('failed', DB::table(Operation::TABLE)->first()->state);
        $this->assertSame(0, $this->locks());
    }

    #[Test]
    public function resolvable_actions_still_hold_the_reservation(): void
    {
        $tools = $this->fixture(202, ['data' => ['queueId' => 7]]);
        $result = $tools->execute(1, 'rescueStart', ['imageId' => 1, 'restartConfirmed' => true, 'requestId' => str_repeat('f', 32)]);
        $this->assertSame('queued', $result['state']);
        $this->assertSame(1, $this->locks());
    }

    #[Test]
    public function iso_mount_by_url_is_not_resolvable_but_by_id_is(): void
    {
        $this->assertFalse(ServiceTools::resolvable('isoMount', ['url' => 'x']));
        $this->assertTrue(ServiceTools::resolvable('isoMount', ['isoId' => 3]));
        $this->assertTrue(ServiceTools::resolvable('rescueEnd', null));
        $this->assertFalse(ServiceTools::resolvable('powerBoot', null));
    }

    #[Test]
    public function backup_create_without_job_id_stays_unknown(): void
    {
        $tools = $this->fixture(202, ['data' => ['queueId' => 7]]);
        $result = $tools->execute(1, 'backupCreate', ['requestId' => str_repeat('1', 32)]);
        $this->assertSame('unknown', $result['state']);
        $this->assertCount(1, $tools->writes);
        $this->assertSame(1, $this->locks());
    }

    #[Test]
    public function module_command_admin_can_reconcile_jobless_operation_without_recovery_role(): void
    {
        $this->recoveryAdmin();
        $tools = $this->fixture(204, null, ['adminTools' => true, 'recoveryRoles' => []]);
        $op = Operation::begin(self::CTX, 'powerBoot', [], ['type' => 'user', 'id' => 100], str_repeat('2', 32));
        Operation::finish($op->id, 'unknown');

        $result = $this->reconcile($tools, $op->id);
        $this->assertSame('failed', $result['state']);
        $this->assertSame('failed', Operation::get($op->id, self::CTX)->state);
        $this->assertSame(0, $this->locks());
        $this->assertSame([], $tools->writes);
    }

    #[Test]
    public function job_bearing_operation_still_requires_recovery_role(): void
    {
        $this->recoveryAdmin();
        $tools = $this->fixture(204, null, ['adminTools' => true, 'recoveryRoles' => []]);
        $op = Operation::begin(self::CTX, 'backupCreate', [], ['type' => 'user', 'id' => 100], str_repeat('3', 32));
        Operation::finish($op->id, 'queued', [], 5);

        try {
            $this->reconcile($tools, $op->id);
            $this->fail('Job-bearing reconciliation bypassed the recovery role.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Recovery role required.', $e->getMessage());
        }
        $this->assertSame('queued', Operation::get($op->id, self::CTX)->state);
        $this->assertSame(1, $this->locks());
    }

    #[Test]
    public function pre_submission_failure_in_powerdns_ptr_path_releases(): void
    {
        DB::table('tbladdonmodules')->insert([
            ['module' => 'virtfusiondns', 'setting' => 'enabled', 'value' => 'on'],
            ['module' => 'virtfusiondns', 'setting' => 'endpoint', 'value' => 'https://pdns.example.test'],
            ['module' => 'virtfusiondns', 'setting' => 'apiKey', 'value' => 'test-key'],
        ]);
        Config::reset();
        $this->assertTrue(Config::isEnabled());
        $tools = $this->ptrFixture('powerdns', true);

        try {
            $tools->execute(1, 'ptrSet', ['address' => '192.0.2.10', 'hostname' => 'a.example.test', 'requestId' => str_repeat('4', 32)]);
            $this->fail('expected rejection');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('Service changed before submission.', $e->getMessage());
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame('failed', DB::table(Operation::TABLE)->first()->state);
        $this->assertSame(0, $this->locks());
    }

    #[Test]
    public function pre_submission_failure_in_private_iso_import_releases_entry_and_quota(): void
    {
        IsoLibrary::schema();
        $ctx = array_replace(self::CTX, ['user' => ['id' => 1010], 'cp' => ['url' => 'https://synthetic.invalid', 'token' => 'fixture']]);
        $settings = array_replace(ProviderSettings::defaults(), ['isoLimit' => 1, 'urlIsolationVerified' => true]);
        $input = ['name' => 'New private ISO', 'url' => 'https://public.example/test.iso', 'requestId' => str_repeat('5', 32)];
        $tools = new PrivateIsoGateway;
        $tools->rows = [];
        $tools->onRevalidate = function () {
            throw new \RuntimeException('Service is no longer active.', 409);
        };

        try {
            (new IsoLibrary($tools))->execute($ctx, 'isoLibraryCreate', $input, $settings, false);
            $this->fail('expected rejection');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('Service is no longer active.', $e->getMessage());
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame('removed', DB::table(IsoLibrary::TABLE)->first()->state);
        $this->assertSame('failed', DB::table(Operation::TABLE)->first()->state);
        $this->assertSame(0, $this->locks());

        // The unsent import no longer counts against the private ISO quota.
        $tools->onRevalidate = null;
        $tools->mutationCode = 422;
        $result = (new IsoLibrary($tools))->execute($ctx, 'isoLibraryCreate', array_replace($input, ['requestId' => str_repeat('6', 32)]), $settings, false);
        $this->assertSame('failed', $result['state']);
        $this->assertCount(1, $tools->writes);
    }
}
