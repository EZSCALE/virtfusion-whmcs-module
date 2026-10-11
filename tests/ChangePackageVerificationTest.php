<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use VirtFusionDirect\Tests\Support\LifecycleGateway;
use WHMCS\Module\Server\VirtFusionDirect\Operation;

final class ChangePackageVerificationTest extends TestCase
{
    private const SERVICE = 7;

    private const VM = 100;

    private const PKG = 5;

    private const PARAMS = ['serviceid' => self::SERVICE, 'userid' => 10, 'serverid' => 1, 'configoption2' => self::PKG];

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        DB::schema()->create('mod_virtfusion_direct', function ($t) {
            $t->increments('id');
            $t->integer('service_id');
            $t->integer('server_id')->nullable();
            $t->text('server_object')->nullable();
        });
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
        Operation::schema();
        DB::table('tblproducts')->insert(['id' => 3, 'servertype' => 'VirtFusionDirect']);
        DB::table('tblhosting')->insert(['id' => self::SERVICE, 'userid' => 10, 'server' => 1, 'packageid' => 3, 'domainstatus' => 'Active']);
        DB::table('mod_virtfusion_direct')->insert(['service_id' => self::SERVICE, 'server_id' => self::VM, 'server_object' => null]);
    }

    private static function server(int $memory, int $cores, int $traffic, int $storage): array
    {
        return [200, ['data' => ['id' => self::VM, 'settings' => ['resources' => [
            'memory' => $memory, 'cpuCores' => $cores, 'traffic' => $traffic, 'storage' => $storage]]]]];
    }

    private static function package(int $memory, int $cores, int $traffic, int $storage): array
    {
        return [200, ['data' => ['id' => self::PKG, 'memory' => $memory, 'cpuCores' => $cores, 'traffic' => $traffic, 'primaryStorage' => $storage]]];
    }

    /** Gateway whose GET /servers/100 answers $before first and $after on the next read. */
    private function gateway(array $before, array $after, ?array $package = null): LifecycleGateway
    {
        $gw = new class extends LifecycleGateway
        {
            public function legacyCapability(array $cp, string $minimum): bool
            {
                return true;
            }
        };
        $gw->sequences['GET /servers/' . self::VM] = [$before, $after];
        $gw->routes['GET /packages/' . self::PKG] = $package ?? self::package(4096, 2, 1000, 50);
        $gw->routes['PUT /servers/' . self::VM . '/package/' . self::PKG] = [200, ['info' => []]];

        return $gw;
    }

    private function paths(LifecycleGateway $gw, string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($gw->calls, fn ($c) => $c[0] === $method)));
    }

    #[Test]
    public function storage_downgrade_returns_error(): void
    {
        $gw = $this->gateway(self::server(4096, 2, 1000, 50), self::server(4096, 2, 1000, 50), self::package(4096, 2, 1000, 25));

        $result = $gw->changePackage(self::PARAMS);

        $this->assertStringStartsWith('Package change incomplete: ', $result);
        $this->assertStringContainsString('reduction not applied for storage', $result);
        $this->assertStringContainsString('cannot shrink', $result);
        $this->assertSame(['/servers/100/package/5'], $this->paths($gw, 'PUT'));
        $logs = $this->logsFor('changePackage:reductionNotApplied');
        $this->assertCount(1, $logs);
        $this->assertSame(['serviceid' => self::SERVICE, 'resource' => 'storage', 'requested' => 25, 'before' => 50, 'after' => 50], $logs[0]['request']);
    }

    #[Test]
    public function memory_modify_failure_returns_error(): void
    {
        $gw = $this->gateway(self::server(4096, 2, 1000, 50), self::server(4096, 2, 1000, 50));
        $gw->routes['PUT /servers/100/modify/memory'] = [500, ['msg' => 'boom']];

        $result = $gw->changePackage(self::PARAMS + ['configoptions' => ['Memory' => 2048]]);

        $this->assertStringContainsString('failed to apply memory', $result);
        $this->assertStringContainsString('reduction not applied for memory', $result);
        $this->assertNotSame('success', $result);
        $this->assertSame(['/servers/100/package/5', '/servers/100/modify/memory'], $this->paths($gw, 'PUT'));
        $this->assertCount(1, $this->logsFor('changePackage:modifyFailed'));
    }

    #[Test]
    public function successful_downgrade_returns_success(): void
    {
        $gw = $this->gateway(self::server(4096, 2, 1000, 50), self::server(2048, 2, 1000, 50));
        $gw->routes['PUT /servers/100/modify/memory'] = [201, ['data' => []]];

        $this->assertSame('success', $gw->changePackage(self::PARAMS + ['configoptions' => ['Memory' => 2]]));
        $modify = array_values(array_filter($gw->calls, fn ($c) => $c[1] === '/servers/100/modify/memory'));
        $this->assertSame(['memory' => 2048], $modify[0][2]);
        $this->assertSame([], $this->logsFor('changePackage:reductionNotApplied'));
    }

    #[Test]
    public function upgrade_without_reduction_returns_success(): void
    {
        $gw = $this->gateway(self::server(2048, 1, 500, 25), self::server(4096, 2, 1000, 25), self::package(4096, 2, 1000, 50));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(['/servers/100/package/5'], $this->paths($gw, 'PUT'));
        $this->assertSame([], $this->logsFor('changePackage:reductionNotApplied'));
    }

    #[Test]
    public function unreadable_server_refuses_before_put(): void
    {
        $gw = $this->gateway([502, ['msg' => 'bad gateway']], self::server(4096, 2, 1000, 50));

        $this->assertSame('Unable to read current server resources; package change was not attempted.', $gw->changePackage(self::PARAMS));
        $this->assertSame([], $this->paths($gw, 'PUT'));
    }

    #[Test]
    public function unavailable_package_without_options_refuses_before_put(): void
    {
        $gw = $this->gateway(self::server(4096, 2, 1000, 50), self::server(4096, 2, 1000, 50), [500, ['msg' => 'down']]);

        $this->assertSame('Package change could not be verified: VirtFusion package #5 is unavailable.', $gw->changePackage(self::PARAMS));
        $this->assertSame([], $this->paths($gw, 'PUT'));
    }

    #[Test]
    public function unreadable_server_after_change_reports_reduction_unverified(): void
    {
        $gw = $this->gateway(self::server(4096, 2, 1000, 50), [502, null], self::package(2048, 2, 1000, 50));

        $result = $gw->changePackage(self::PARAMS);

        $this->assertSame('Package change incomplete: reduction not applied for memory', $result);
        $this->assertNull($this->logsFor('changePackage:reductionNotApplied')[0]['request']['after']);
    }

    #[Test]
    public function pack_products_keep_plain_error(): void
    {
        $gw = $this->gateway(self::server(4096, 2, 1000, 50), self::server(4096, 2, 1000, 50));

        $this->assertSame(
            'Resource pack plans must be changed in VirtFusion; automatic package changes are unsupported.',
            $gw->changePackage(self::PARAMS + ['configoption9' => 'Resource Pack']),
        );
        $this->assertSame([], $gw->calls);
    }
}
