<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

require_once __DIR__ . '/ResourcePackLifecycleTest.php';

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\ResourcePack;

/**
 * ResourcePack::lifecycle() must reserve the service lock and validate its
 * configuration before moving the binding into "<action>_pending", so a held
 * lock or a bad setting never strands the binding.
 */
final class PackLifecycleOrderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        ResourcePack::schema();
        Operation::schema();
        DB::schema()->create('tblhosting', function ($t) {
            $t->integer('id');
            $t->integer('userid');
        });
        DB::table('tblhosting')->insert(['id' => 1, 'userid' => 10]);
        DB::table(ResourcePack::TABLE)->insert(['service_id' => 1, 'pack_id' => 50, 'state' => 'active', 'panel_id' => 1, 'user_id' => 1010, 'profile_id' => 7]);
    }

    private function params(): array
    {
        return ['serviceid' => 1, 'userid' => 10, 'serverid' => 1, 'configoption9' => 'Resource Pack', 'configoption10' => '7'];
    }

    private static function mutations(PackFixture $pack): array
    {
        return array_values(array_filter($pack->requests, fn ($r) => $r[0] !== 'get'));
    }

    #[Test]
    public function held_service_lock_refuses_without_touching_the_binding(): void
    {
        DB::table(Operation::LOCKS)->insert(['scope' => '1:service:1', 'operation_id' => str_repeat('0', 32)]);

        $pack = new PackFixture;
        $this->assertSame('Another operation is unresolved for this service; reconcile it before retrying.', $pack->lifecycle('suspend', $this->params()));
        $this->assertSame([], self::mutations($pack));
        $this->assertSame('active', ResourcePack::binding(1)->state);

        DB::table(Operation::LOCKS)->delete();
        $retry = new PackFixture;
        $this->assertSame('success', $retry->lifecycle('suspend', $this->params()));
        $this->assertSame('suspended', ResourcePack::binding(1)->state);
        $this->assertSame(0, DB::table(Operation::LOCKS)->count());
    }

    #[Test]
    public function blank_deletion_delay_fails_before_any_native_write(): void
    {
        $pack = new PackFixture;
        $params = $this->params() + ['configoption12' => 'Delete Servers', 'configoption11' => ''];
        $this->assertSame('Deletion Delay must be a non-negative number of minutes.', $pack->lifecycle('terminate', $params));
        $this->assertSame([], self::mutations($pack), 'Pack must not be disabled before configuration is validated');
        $this->assertSame('active', ResourcePack::binding(1)->state);
        $this->assertSame(0, DB::table(Operation::LOCKS)->count());
        $this->assertSame(0, DB::table(Operation::TABLE)->count());
    }

    #[Test]
    public function exception_before_first_write_reverts_pending_state(): void
    {
        $pack = new class extends PackFixture
        {
            protected function request(array $cp, $method, $path, $body = null)
            {
                if ($method !== 'get') {
                    throw new \RuntimeException('Transport could not be prepared.');
                }

                return parent::request($cp, $method, $path, $body);
            }
        };
        $this->assertSame('Transport could not be prepared.', $pack->lifecycle('suspend', $this->params()));
        $this->assertSame([], self::mutations($pack));
        $this->assertSame('active', ResourcePack::binding(1)->state);
        $this->assertSame('failed', DB::table(Operation::TABLE)->first()->state);
        $this->assertSame(0, DB::table(Operation::LOCKS)->count());
    }

    #[Test]
    public function failure_after_a_write_keeps_pending_and_unknown(): void
    {
        $pack = new PackFixture;
        $pack->dependencyFailure = true;
        $this->assertNotSame('success', $pack->lifecycle('suspend', $this->params()));
        $this->assertNotSame([], self::mutations($pack));
        $this->assertSame('suspend_pending', ResourcePack::binding(1)->state);
        $this->assertSame('unknown', DB::table(Operation::TABLE)->first()->state);
        $this->assertSame(1, DB::table(Operation::LOCKS)->count());
    }
}
