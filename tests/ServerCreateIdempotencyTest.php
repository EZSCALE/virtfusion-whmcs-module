<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use VirtFusionDirect\Tests\Support\LifecycleGateway;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Operation;

final class ServerCreateIdempotencyTest extends TestCase
{
    private const SERVICE = 7;

    private const CLIENT = 10;

    private const PARAMS = ['serviceid' => self::SERVICE, 'userid' => self::CLIENT, 'serverid' => 1,
        'configoption1' => 1, 'configoption2' => 2, 'configoption3' => 1];

    /** @var array<int,int> */
    private array $builds = [];

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
        DB::schema()->create('tblclients', function ($t) {
            $t->integer('id');
            $t->string('firstname')->nullable();
            $t->string('lastname')->nullable();
            $t->string('email')->nullable();
        });
        Operation::schema();
        DB::table('tblproducts')->insert(['id' => 3, 'servertype' => 'VirtFusionDirect']);
        DB::table('tblclients')->insert(['id' => self::CLIENT, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@example.test']);
        DB::table('tblhosting')->insert(['id' => self::SERVICE, 'userid' => self::CLIENT, 'server' => 1, 'packageid' => 3,
            'domainstatus' => 'Pending']);
    }

    private static function server(int $id, int $owner = 1010): array
    {
        return ['data' => ['id' => $id, 'name' => 'vm' . $id, 'hostname' => 'vm' . $id . '.example', 'owner' => ['id' => $owner],
            'network' => ['interfaces' => [['ipv4' => [['address' => '192.0.2.' . $id]], 'ipv6' => []]]]]];
    }

    private static function list(int ...$ids): array
    {
        return [200, ['data' => array_map(static fn (int $id): array => ['id' => $id], $ids)]];
    }

    private function gateway(): LifecycleGateway
    {
        $gw = new LifecycleGateway;
        $gw->routes['GET /users/10/byExtRelation'] = [200, ['data' => ['id' => 1010, 'extRelationId' => self::CLIENT]]];
        $builds = &$this->builds;
        $gw->cs = new class($builds) extends ConfigureService
        {
            public function __construct(private array &$builds) {}

            public function initServerBuild(int $id, array $vars, ?int $vfUserId = null): bool
            {
                $this->builds[] = $id;

                return true;
            }
        };

        return $gw;
    }

    private function posts(LifecycleGateway $gw, string $path = '/servers'): int
    {
        return count(array_filter($gw->calls, static fn (array $c): bool => $c[0] === 'POST' && $c[1] === $path));
    }

    private function row(): ?object
    {
        return DB::table('mod_virtfusion_direct')->where('service_id', self::SERVICE)->first();
    }

    private function ops(): array
    {
        return DB::table(Operation::TABLE)->where('service_id', self::SERVICE)->where('action', 'server:create')
            ->orderBy('created_at')->orderBy('rowid')->get()->all();
    }

    private function locks(): int
    {
        return DB::table(Operation::LOCKS)->count();
    }

    private function pendingWithOp(string $state, string $createdAt, array $known = [5]): void
    {
        DB::table('mod_virtfusion_direct')->insert(['service_id' => self::SERVICE, 'server_id' => null, 'server_object' => null]);
        DB::table(Operation::TABLE)->insert(['id' => str_repeat('a', 32), 'request_key' => str_repeat('b', 64), 'panel_id' => 1,
            'service_id' => self::SERVICE, 'vm_id' => 0, 'actor_type' => 'system', 'actor_id' => 0, 'action' => 'server:create',
            'targets' => json_encode(['packageId' => 2, 'vfUserId' => 1010, 'knownServerIds' => $known]), 'state' => $state,
            'created_at' => $createdAt, 'updated_at' => $createdAt]);
        DB::table(Operation::LOCKS)->insert(['scope' => '1:service:' . self::SERVICE, 'operation_id' => str_repeat('a', 32)]);
    }

    #[Test]
    public function timeout_leaves_pending_row_and_retry_does_not_post_again_when_vm_exists(): void
    {
        $gw = $this->gateway();
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5);
        $gw->routes['POST /servers'] = [0, null];
        $result = $gw->createAccount(self::PARAMS);
        $this->assertStringContainsString('outcome unknown', $result);
        $this->assertStringContainsString('HTTP 0', $result);
        $this->assertNotNull($this->row());
        $this->assertNull($this->row()->server_id);
        $this->assertSame(['unknown'], array_column($this->ops(), 'state'));
        $this->assertSame([5], json_decode($this->ops()[0]->targets, true)['knownServerIds']);
        $this->assertSame(1, $this->locks());

        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5, 77);
        $gw->routes['GET /servers/77'] = [200, self::server(77)];
        $this->assertSame('success', $gw->createAccount(self::PARAMS));
        $this->assertSame(1, $this->posts($gw));
        $this->assertSame(77, (int) $this->row()->server_id);
        $this->assertStringContainsString('vm77.example', (string) $this->row()->server_object);
        $this->assertSame(['completed'], array_column($this->ops(), 'state'));
        $this->assertSame(0, $this->locks());
        $this->assertSame([77], $this->builds);
        $this->assertSame('vm77.example', DB::table('tblhosting')->where('id', self::SERVICE)->value('domain'));
    }

    #[Test]
    public function retry_after_ambiguous_failure_creates_when_no_server_appeared(): void
    {
        $gw = $this->gateway();
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5);
        $gw->routes['POST /servers'] = [502, null];
        $this->assertStringContainsString('outcome unknown (HTTP 502)', $gw->createAccount(self::PARAMS));

        $gw->routes['POST /servers'] = [201, self::server(80)];
        $this->assertSame('success', $gw->createAccount(self::PARAMS));
        $this->assertSame(2, $this->posts($gw));
        $this->assertSame(['failed', 'completed'], array_column($this->ops(), 'state'));
        $this->assertSame(80, (int) $this->row()->server_id);
        $this->assertSame(1, DB::table('mod_virtfusion_direct')->count());
        $this->assertSame(0, $this->locks());
        $this->assertSame([80], $this->builds);
    }

    #[Test]
    public function multiple_unbound_candidates_refuse(): void
    {
        $gw = $this->gateway();
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5);
        $gw->routes['POST /servers'] = [504, null];
        $gw->createAccount(self::PARAMS);

        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5, 77, 78);
        $result = $gw->createAccount(self::PARAMS);
        $this->assertStringContainsString('2 unbound servers exist', $result);
        $this->assertSame(1, $this->posts($gw));
        $this->assertSame(['unknown'], array_column($this->ops(), 'state'));
        $this->assertNull($this->row()->server_id);
        $this->assertSame([], $this->builds);
    }

    #[Test]
    public function servers_bound_elsewhere_are_not_candidates(): void
    {
        $gw = $this->gateway();
        $this->pendingWithOp('unknown', date('Y-m-d H:i:s', time() - 3600));
        DB::table('mod_virtfusion_direct')->insert(['service_id' => 99, 'server_id' => 78, 'server_object' => null]);
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5, 77, 78);
        $gw->routes['GET /servers/77'] = [200, self::server(77)];
        $this->assertSame('success', $gw->createAccount(self::PARAMS));
        $this->assertSame(0, $this->posts($gw));
        $this->assertSame(77, (int) $this->row()->server_id);
    }

    #[Test]
    public function adoption_refuses_when_candidate_owner_differs(): void
    {
        $gw = $this->gateway();
        $this->pendingWithOp('unknown', date('Y-m-d H:i:s', time() - 3600));
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5, 77);
        $gw->routes['GET /servers/77'] = [200, self::server(77, 2000)];
        $this->assertNotSame('success', $gw->createAccount(self::PARAMS));
        $this->assertSame(0, $this->posts($gw));
        $this->assertNull($this->row()->server_id);
        $this->assertSame(['unknown'], array_column($this->ops(), 'state'));
    }

    #[Test]
    public function unresolved_create_with_unavailable_list_refuses(): void
    {
        $gw = $this->gateway();
        $this->pendingWithOp('unknown', date('Y-m-d H:i:s', time() - 3600));
        $gw->routes['GET /servers/user/1010?results=200'] = [500, null];
        $this->assertStringContainsString('server list is unavailable', $gw->createAccount(self::PARAMS));
        $this->assertSame(0, $this->posts($gw));
    }

    #[Test]
    public function fresh_create_refuses_when_server_list_unavailable(): void
    {
        $gw = $this->gateway();
        $gw->routes['GET /servers/user/1010?results=200'] = [503, null];
        $gw->routes['POST /servers'] = [201, self::server(80)];
        $this->assertSame('Unable to list existing VirtFusion servers; creation was not attempted.', $gw->createAccount(self::PARAMS));
        $this->assertSame(0, $this->posts($gw));
        $this->assertNull($this->row());
        $this->assertSame([], $this->ops());
    }

    #[Test]
    public function another_unresolved_create_for_same_client_refuses_adoption(): void
    {
        $gw = $this->gateway();
        $this->pendingWithOp('unknown', date('Y-m-d H:i:s', time() - 3600));
        DB::table('tblhosting')->insert(['id' => 8, 'userid' => self::CLIENT, 'server' => 1, 'packageid' => 3, 'domainstatus' => 'Pending']);
        DB::table(Operation::TABLE)->insert(['id' => str_repeat('c', 32), 'request_key' => str_repeat('d', 64), 'panel_id' => 1,
            'service_id' => 8, 'vm_id' => 0, 'actor_type' => 'system', 'actor_id' => 0, 'action' => 'server:create',
            'targets' => json_encode(['knownServerIds' => [5]]), 'state' => 'unknown',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5, 77);
        $gw->routes['GET /servers/77'] = [200, self::server(77)];
        $this->assertStringContainsString('another service', $gw->createAccount(self::PARAMS));
        $this->assertSame(0, $this->posts($gw));
        $this->assertNull($this->row()->server_id);
    }

    #[Test]
    public function concurrent_create_in_flight_is_refused(): void
    {
        $gw = $this->gateway();
        $this->pendingWithOp('saved', date('Y-m-d H:i:s'));
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5);
        $gw->routes['POST /servers'] = [201, self::server(80)];
        $this->assertStringContainsString('already in progress', $gw->createAccount(self::PARAMS));
        $this->assertSame(0, $this->posts($gw));
    }

    #[Test]
    public function client_error_cleans_up_pending_state(): void
    {
        $gw = $this->gateway();
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5);
        $gw->routes['POST /servers'] = [422, ['errors' => ['bad package']]];
        $this->assertSame('bad package', $gw->createAccount(self::PARAMS));
        $this->assertNull($this->row());
        $this->assertSame(['failed'], array_column($this->ops(), 'state'));
        $this->assertSame(0, $this->locks());
    }

    #[Test]
    public function legacy_null_row_without_operation_is_reused(): void
    {
        $gw = $this->gateway();
        DB::table('mod_virtfusion_direct')->insert(['service_id' => self::SERVICE, 'server_id' => null, 'server_object' => null]);
        $gw->routes['GET /servers/user/1010?results=200'] = self::list(5);
        $gw->routes['POST /servers'] = [201, self::server(80)];
        $this->assertSame('success', $gw->createAccount(self::PARAMS));
        $this->assertSame(1, DB::table('mod_virtfusion_direct')->count());
        $this->assertSame(80, (int) $this->row()->server_id);
    }

    #[Test]
    public function existing_bound_row_still_refuses(): void
    {
        $gw = $this->gateway();
        DB::table('mod_virtfusion_direct')->insert(['service_id' => self::SERVICE, 'server_id' => 9, 'server_object' => null]);
        $this->assertSame('Service already exists. You must run a termination first.', $gw->createAccount(self::PARAMS));
        $this->assertSame([], $gw->calls);
    }

    #[Test]
    public function lifecycle_actions_refuse_on_pending_row(): void
    {
        $gw = $this->gateway();
        $this->pendingWithOp('unknown', date('Y-m-d H:i:s'));
        $message = 'Server provisioning is unresolved for this service; run Create to reconcile before this action.';
        $this->assertSame($message, $gw->suspendAccount(self::PARAMS));
        $this->assertSame($message, $gw->unsuspendAccount(self::PARAMS));
        $this->assertSame($message, $gw->changePackage(self::PARAMS));
        $this->assertSame($message, $gw->terminateAccount(self::PARAMS));
        $this->assertSame([], $gw->calls);
        $this->assertNotNull($this->row());
    }

    #[Test]
    public function terminate_clears_stale_pending_row_without_unresolved_create(): void
    {
        $gw = $this->gateway();
        DB::table('mod_virtfusion_direct')->insert(['service_id' => self::SERVICE, 'server_id' => null, 'server_object' => null]);
        $this->assertSame('Server provisioning is unresolved for this service; run Create to reconcile before this action.', $gw->suspendAccount(self::PARAMS));
        $this->assertSame('success', $gw->terminateAccount(self::PARAMS));
        $this->assertSame([], $gw->calls);
        $this->assertNull($this->row());
    }

    #[Test]
    public function operation_actor_comes_from_lifecycle_actor(): void
    {
        $gw = $this->gateway();
        $gw->routes['GET /servers/user/1010?results=200'] = self::list();
        $gw->routes['POST /servers'] = [201, self::server(80)];
        CurrentUser::$admin = (object) ['id' => 4];

        try {
            $this->assertSame('success', $gw->createAccount(self::PARAMS));
        } finally {
            CurrentUser::$admin = null;
        }
        $this->assertSame(['admin', 4], [$this->ops()[0]->actor_type, (int) $this->ops()[0]->actor_id]);
    }
}
