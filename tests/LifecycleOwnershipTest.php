<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use VirtFusionDirect\Tests\Support\LifecycleGateway;
use WHMCS\Module\Server\VirtFusionDirect\Operation;

final class LifecycleOwnershipTest extends TestCase
{
    private const SERVICE = 7;

    private const CLIENT = 10;

    private const VM = 100;

    private const PARAMS = ['serviceid' => self::SERVICE, 'userid' => self::CLIENT, 'serverid' => 1];

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
            'domainstatus' => 'Active', 'domain' => 'old.example', 'dedicatedip' => '192.0.2.1']);
        DB::table('mod_virtfusion_direct')->insert(['service_id' => self::SERVICE, 'server_id' => self::VM,
            'server_object' => json_encode(self::server(1010, '192.0.2.1', 'old.example'))]);
    }

    private static function server(int $owner, string $ip, string $hostname): array
    {
        return ['data' => ['id' => self::VM, 'name' => $hostname, 'hostname' => $hostname, 'owner' => ['id' => $owner],
            'network' => ['interfaces' => [['ipv4' => [['address' => $ip]], 'ipv6' => []]]]]];
    }

    private function gateway(int $owner = 1010, string $ip = '198.51.100.7', string $hostname = 'current.example'): LifecycleGateway
    {
        $gw = new LifecycleGateway;
        $gw->routes['GET /servers/100'] = [200, self::server($owner, $ip, $hostname)];
        $gw->routes['GET /users/10/byExtRelation'] = [200, ['data' => ['id' => 1010, 'extRelationId' => self::CLIENT]]];

        return $gw;
    }

    private function methods(LifecycleGateway $gw, string $method): array
    {
        return array_values(array_filter($gw->calls, static fn (array $c): bool => $c[0] === $method));
    }

    private function ptrCalls(LifecycleGateway $gw, string $name): array
    {
        return array_values(array_filter($gw->ptr, static fn (array $c): bool => $c[0] === $name));
    }

    #[Test]
    public function suspend_refuses_when_vm_owned_by_another_user(): void
    {
        $gw = $this->gateway(2000);
        $result = $gw->suspendAccount(self::PARAMS);
        $this->assertStringContainsString('ownership mismatch', $result);
        $this->assertStringContainsString('Refusing to suspend', $result);
        $this->assertSame([], $this->methods($gw, 'POST'));
        $this->assertNotEmpty($this->logsFor('suspendAccount:ownership'));
    }

    #[Test]
    public function unsuspend_refuses_on_mismatch(): void
    {
        $gw = $this->gateway(2000);
        $result = $gw->unsuspendAccount(self::PARAMS);
        $this->assertStringContainsString('ownership mismatch', $result);
        $this->assertStringContainsString('Refusing to unsuspend', $result);
        $this->assertSame([], $this->methods($gw, 'POST'));
    }

    #[Test]
    public function terminate_refuses_on_mismatch_and_keeps_binding(): void
    {
        $gw = $this->gateway(2000);
        $result = $gw->terminateAccount(self::PARAMS);
        $this->assertStringContainsString('ownership mismatch', $result);
        $this->assertStringContainsString('Refusing to terminate', $result);
        $this->assertSame([], $this->methods($gw, 'DELETE'));
        $this->assertSame(1, DB::table('mod_virtfusion_direct')->where('service_id', self::SERVICE)->count());
    }

    #[Test]
    public function terminate_refuses_when_ownership_unavailable(): void
    {
        $gw = $this->gateway();
        $gw->routes['GET /servers/100'] = [502, null];
        $result = $gw->terminateAccount(self::PARAMS);
        $this->assertStringContainsString('ownership unavailable', $result);
        $this->assertStringContainsString('Refusing to terminate', $result);
        $this->assertSame([], $this->methods($gw, 'DELETE'));
        $this->assertSame(1, DB::table('mod_virtfusion_direct')->where('service_id', self::SERVICE)->count());
    }

    #[Test]
    public function suspend_refuses_when_server_body_is_malformed(): void
    {
        $gw = $this->gateway();
        $gw->routes['GET /servers/100'] = [200, ['data' => 'nope']];
        $this->assertStringContainsString('ownership unavailable', $gw->suspendAccount(self::PARAMS));
        $this->assertSame([], $this->methods($gw, 'POST'));
    }

    #[Test]
    public function suspend_proceeds_when_owned(): void
    {
        $gw = $this->gateway();
        $gw->routes['POST /servers/100/suspend'] = [204, null];
        $this->assertSame('success', $gw->suspendAccount(self::PARAMS));
        $this->assertSame([['POST', '/servers/100/suspend', null]], $this->methods($gw, 'POST'));
    }

    #[Test]
    public function terminate_deletes_ptrs_from_fresh_ips(): void
    {
        $gw = $this->gateway();
        $gw->dns = true;
        $gw->routes['DELETE /servers/100'] = [204, null];
        $this->assertSame('success', $gw->terminateAccount(self::PARAMS));
        $deletes = $this->ptrCalls($gw, 'deleteForServer');
        $this->assertCount(1, $deletes);
        $sent = json_encode($deletes[0][1][0]);
        $this->assertStringContainsString('198.51.100.7', $sent);
        $this->assertStringNotContainsString('192.0.2.1', $sent);
        $this->assertNull($deletes[0][1][1], 'fresh IPs are this VM\'s: delete every PTR');
        $this->assertSame(0, DB::table('mod_virtfusion_direct')->where('service_id', self::SERVICE)->count());
    }

    #[Test]
    public function terminate_falls_back_to_stored_object_only_when_vm_missing(): void
    {
        $gw = $this->gateway();
        $gw->dns = true;
        $gw->routes['GET /servers/100'] = [404, ['msg' => 'server not found']];
        $gw->routes['DELETE /servers/100'] = [404, ['msg' => 'server not found']];
        $this->assertSame('success', $gw->terminateAccount(self::PARAMS));
        $deletes = $this->ptrCalls($gw, 'deleteForServer');
        $this->assertCount(1, $deletes);
        $this->assertSame(self::server(1010, '192.0.2.1', 'old.example'), $deletes[0][1][0]);
        // The stored IPs may have been reassigned: only PTRs still naming this server are deleted.
        $this->assertSame('old.example', $deletes[0][1][1]);
        $this->assertNotEmpty(array_filter(
            $this->logsFor('PowerDns:terminate'),
            static fn (array $e): bool => str_contains((string) json_encode($e['response']), 'fresh server data unavailable'),
        ));
    }

    #[Test]
    public function terminate_skips_ptr_fallback_when_stored_object_has_no_hostname(): void
    {
        $stored = self::server(1010, '192.0.2.1', 'old.example');
        $stored['data']['hostname'] = '-';
        $stored['data']['name'] = '-';
        DB::table('mod_virtfusion_direct')->where('service_id', self::SERVICE)->update(['server_object' => json_encode($stored)]);
        $gw = $this->gateway();
        $gw->dns = true;
        $gw->routes['GET /servers/100'] = [404, ['msg' => 'server not found']];
        $gw->routes['DELETE /servers/100'] = [404, ['msg' => 'server not found']];
        $this->assertSame('success', $gw->terminateAccount(self::PARAMS));
        $this->assertSame([], $this->ptrCalls($gw, 'deleteForServer'));
        $this->assertNotEmpty(array_filter(
            $this->logsFor('PowerDns:terminate'),
            static fn (array $e): bool => str_contains((string) json_encode($e['response']), 'no stored hostname'),
        ));
    }

    #[Test]
    public function rename_syncs_ptrs_from_fresh_server_and_old_hostname(): void
    {
        $gw = $this->gateway();
        $gw->dns = true;
        $gw->routes['PUT /servers/100/modify/name'] = [201, null];
        $this->assertTrue($gw->renameServer(self::SERVICE, 'new.example'));
        $syncs = $this->ptrCalls($gw, 'syncServer');
        $this->assertCount(1, $syncs);
        [$object, $old, $new] = $syncs[0][1];
        $this->assertSame(self::server(1010, '198.51.100.7', 'current.example'), $object);
        $this->assertSame('current.example', $old);
        $this->assertSame('new.example', $new);
        $stored = (string) DB::table('mod_virtfusion_direct')->where('service_id', self::SERVICE)->value('server_object');
        $this->assertStringContainsString('198.51.100.7', $stored);
        $this->assertStringContainsString('current.example', $stored);
        $this->assertSame(['GET', '/servers/100', null], $gw->calls[0]);
    }

    #[Test]
    public function rename_skips_ptr_sync_when_fresh_fetch_fails(): void
    {
        $gw = $this->gateway();
        $gw->dns = true;
        $gw->routes['GET /servers/100'] = [500, null];
        $gw->routes['PUT /servers/100/modify/name'] = [201, null];
        $this->assertTrue($gw->renameServer(self::SERVICE, 'new.example'));
        $this->assertSame([], $this->ptrCalls($gw, 'syncServer'));
        $this->assertStringContainsString('old.example', (string) DB::table('mod_virtfusion_direct')->value('server_object'));
        $this->assertNotEmpty($this->logsFor('PowerDns:renameServer'));
    }

    #[Test]
    public function rename_does_not_fetch_when_dns_disabled(): void
    {
        $gw = $this->gateway();
        $gw->routes['PUT /servers/100/modify/name'] = [201, null];
        $this->assertTrue($gw->renameServer(self::SERVICE, 'new.example'));
        $this->assertSame([], $this->methods($gw, 'GET'));
        $this->assertSame([], $gw->ptr);
    }
}
