<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\ResourcePack;

class PackFixture extends ResourcePack
{
    public array $requests = [];

    public bool $timeout = false;

    public bool $dependencyFailure = false;

    public int $reportedOwner = 1010;

    public int $reportedPack = 50;

    public $onCreate;

    public function __construct() {}

    protected function connection($params)
    {
        return ['panel_id' => 1, 'url' => 'https://panel.example.test/api/v1', 'token' => 'token'];
    }

    protected function hasServer($serviceId)
    {
        return false;
    }

    protected function request(array $cp, $method, $path, $body = null)
    {
        $this->requests[] = [$method, $path, $body];
        if ($method === 'get' && str_starts_with($path, '/users/')) {
            return [200, ['data' => ['id' => $this->reportedOwner, 'extRelationId' => 10]]];
        }
        if ($method === 'get' && str_starts_with($path, '/selfService/report/')) {
            return [200, ['data' => ['limits' => ['packs' => [['type' => 'pack', 'id' => $this->reportedPack]]]]]];
        }
        if ($method === 'get' && str_starts_with($path, '/selfService/resourcePack/')) {
            return [200, ['data' => ['id' => 50, 'servers' => []]]];
        }
        if ($method === 'post' && str_starts_with($path, '/selfService/resourcePack/byUser')) {
            if ($this->onCreate) {
                ($this->onCreate)();
            }
            if ($this->timeout) {
                return [0, null];
            }

            return [201, ['data' => ['id' => 50]]];
        }
        if ($this->dependencyFailure && $method === 'post' && str_contains($path, '/resourcePackServers/')) {
            return [424, ['errors' => ['Dependency unavailable']]];
        }

        return [204, null];
    }
}

final class ResourcePackLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        ResourcePack::schema();
        DB::schema()->create('tblhosting', function ($t) {
            $t->integer('id');
            $t->integer('userid');
        });
        DB::table('tblhosting')->insert(['id' => 1, 'userid' => 10]);
    }

    private function params(): array
    {
        return ['serviceid' => 1, 'userid' => 10, 'serverid' => 1, 'configoption9' => 'Resource Pack', 'configoption10' => '7'];
    }

    #[Test]
    public function profile_and_assigned_pack_are_distinct_and_retry_cannot_duplicate(): void
    {
        $pack = new PackFixture;
        $pack->onCreate = function () {
            $second = new PackFixture;
            $this->assertNotSame('success', $second->createAccount($this->params()));
            $this->assertSame([], $second->requests);
        };
        $this->assertSame('success', $pack->createAccount($this->params()));
        $binding = ResourcePack::binding(1);
        $this->assertSame(7, $binding->profile_id);
        $this->assertSame(50, $binding->pack_id);
        $this->assertSame('active', $binding->state);
        $posts = array_values(array_filter($pack->requests, fn ($r) => $r[0] === 'post'));
        $this->assertSame(['packId' => 7, 'enabled' => true], $posts[0][2]);
        $this->assertNotSame('success', $pack->createAccount($this->params()));
        $this->assertCount(3, $pack->requests);
    }

    #[Test]
    public function ambiguous_creation_keeps_pending_binding_and_never_replays(): void
    {
        $pack = new PackFixture;
        $pack->timeout = true;
        $this->assertNotSame('success', $pack->createAccount($this->params()));
        $this->assertSame('pending', ResourcePack::binding(1)->state);
        $before = count($pack->requests);
        $pack->createAccount($this->params());
        $this->assertCount($before, $pack->requests);
    }

    private function bind(): void
    {
        DB::table(ResourcePack::TABLE)->insert(['service_id' => 1, 'pack_id' => 50, 'state' => 'active', 'panel_id' => 1, 'user_id' => 1010, 'profile_id' => 7]);
    }

    #[Test]
    public function default_termination_retains_pack_and_servers_with_allocation_disabled(): void
    {
        $this->bind();
        $pack = new PackFixture;
        $this->assertSame('success', $pack->lifecycle('terminate', $this->params()));
        $this->assertSame('terminated', ResourcePack::binding(1)->state);
        $mutations = array_values(array_filter($pack->requests, fn ($r) => $r[0] !== 'get'));
        $this->assertSame([['put', '/selfService/resourcePack/50', ['enabled' => false]], ['post', '/selfService/resourcePackServers/50/suspend', null]], $mutations);
        $this->assertNotContains('delete', array_column($mutations, 0));
    }

    #[Test]
    public function dependency_failure_remains_unresolved_instead_of_claiming_removal(): void
    {
        $this->bind();
        $pack = new PackFixture;
        $pack->dependencyFailure = true;
        $this->assertNotSame('success', $pack->lifecycle('terminate', $this->params()));
        $this->assertSame('terminate_pending', ResourcePack::binding(1)->state);
        $before = count($pack->requests);
        $pack->lifecycle('terminate', $this->params());
        $this->assertCount($before, $pack->requests);
    }

    #[Test]
    public function changed_user_or_pack_membership_prevents_lifecycle_mutations(): void
    {
        $this->bind();
        foreach ([['reportedOwner', 1020], ['reportedPack', 51]] as [$field, $value]) {
            $pack = new PackFixture;
            $pack->{$field} = $value;
            $this->assertNotSame('success', $pack->lifecycle('suspend', $this->params()));
            $this->assertSame([], array_filter($pack->requests, fn ($r) => $r[0] !== 'get'));
        }
    }

    #[Test]
    public function official_source_bindings_are_not_implicitly_adopted(): void
    {
        DB::schema()->create('mod_virtfusion_res_pks', function ($t) {
            $t->integer('service_id');
            $t->integer('pack_id');
        });
        DB::table('mod_virtfusion_res_pks')->insert(['service_id' => 99, 'pack_id' => 50]);
        $this->assertNull(ResourcePack::binding(99));
        $this->assertSame(1, DB::table('mod_virtfusion_res_pks')->count());
    }
}
