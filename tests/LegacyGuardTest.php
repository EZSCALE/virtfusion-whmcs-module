<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\ResourcePack;

require_once __DIR__ . '/ServiceAuthorizationTest.php';

class RecordingCurl extends Curl
{
    private object $owner;

    public function __construct(object $owner)
    {
        $this->owner = $owner;
    }

    public function addOption($name, $value) {}

    public function post($url = null)
    {
        $this->owner->calls[] = ['post', parse_url((string) $url, PHP_URL_PATH)];

        return json_encode(['data' => ['password' => 'new']]);
    }

    public function get($url = null)
    {
        $this->owner->calls[] = ['get', parse_url((string) $url, PHP_URL_PATH)];

        return '{}';
    }

    public function getRequestInfo($param = false)
    {
        return $param === 'http_code' ? 201 : ['http_code' => 201];
    }
}

class RecordingGateway extends Module
{
    public array $calls = [];

    public function __construct() {}

    public function initCurl($token)
    {
        return new RecordingCurl($this);
    }
}

final class LegacyGuardTest extends TestCase
{
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
            $t->integer('server_id')->nullable();
        });
        Operation::schema();
        ResourcePack::schema();
        DB::table('tblhosting')->insert([
            ['id' => 1, 'userid' => 10, 'server' => 1, 'packageid' => 1, 'domainstatus' => 'Active'],
            ['id' => 4, 'userid' => 10, 'server' => 1, 'packageid' => 1, 'domainstatus' => 'Active'],
        ]);
        DB::table('tblproducts')->insert(['id' => 1, 'servertype' => 'VirtFusionDirect', 'configoption9' => '']);
        DB::table('tblservers')->insert(['id' => 1, 'type' => 'VirtFusionDirect', 'hostname' => 'panel.example.test', 'password' => 'test-token']);
        DB::table('mod_virtfusion_direct')->insert([['service_id' => 1, 'server_id' => 100], ['service_id' => 4, 'server_id' => null]]);
        DB::table('tblusers_clients')->insert(['auth_user_id' => 100, 'client_id' => 10, 'owner' => 1]);
        CurrentUser::$client = (object) ['id' => 10];
        CurrentUser::$user = (object) ['id' => 100];
        CurrentUser::$admin = null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $GLOBALS['vfd_valid_csrf'] = true;
    }

    private function admin(array $permissions): object
    {
        return new class($permissions)
        {
            public int $id = 1;

            public int $roleid = 2;

            public array $permissions;

            public function __construct(array $permissions)
            {
                $this->permissions = $permissions;
            }

            public function hasPermission($permission)
            {
                return in_array($permission, $this->permissions, true);
            }
        };
    }

    #[Test]
    public function unresolved_operation_is_reported_for_the_bound_vm(): void
    {
        $module = new class extends Module
        {
            public function __construct() {}
        };

        $this->assertNull($module->unresolvedOperation(1));
        DB::table(Operation::LOCKS)->insert(['scope' => '1:vm:200', 'operation_id' => 'other']);
        $this->assertNull($module->unresolvedOperation(1), 'A lock on another VM is not ours.');
        DB::table(Operation::LOCKS)->insert(['scope' => '1:vm:100', 'operation_id' => 'op-100']);
        $this->assertSame('op-100', $module->unresolvedOperation(1));
        $this->assertNull($module->unresolvedOperation(4), 'Unprovisioned service has no VM lock.');
        $this->assertNull($module->unresolvedOperation(999), 'Unknown service has no VM lock.');
        $this->assertSame('op-100', Operation::lockedBy(1, 100));
        $this->assertNull(Operation::lockedBy(1, 300));
    }

    #[Test]
    public function reset_user_password_denies_sub_user_without_productsso(): void
    {
        CurrentUser::$user = (object) ['id' => 101];
        DB::table('tblusers_clients')->insert(['auth_user_id' => 101, 'client_id' => 10, 'owner' => 0, 'permissions' => 'manageproducts']);

        $gateway = new RecordingGateway;
        $this->assertFalse($gateway->resetUserPassword(1, 10));
        $this->assertSame([], $gateway->calls, 'No request without productsso.');

        DB::table('tblusers_clients')->where('auth_user_id', 101)->update(['permissions' => 'manageproducts,productsso']);
        $gateway = new RecordingGateway;
        $this->assertNotFalse($gateway->resetUserPassword(1, 10));
        $this->assertSame([['post', '/api/v1/users/10/byExtRelation/resetPassword']], $gateway->calls);
    }

    #[Test]
    public function rescue_credentials_hidden_from_read_only_admin(): void
    {
        $state = ['active' => true, 'ssh' => ['password' => 'x'], 'images' => []];

        CurrentUser::$admin = $this->admin(['View Clients Products/Services']);
        $tools = new AuthorizedToolsFixture;
        $tools->state = $state;
        $data = $tools->execute(1, 'rescueStatus', [], true)['data'];
        $this->assertTrue($data['active']);
        $this->assertArrayNotHasKey('ssh', $data);

        CurrentUser::$admin = $this->admin(['View Clients Products/Services', 'Perform Module Command Operations']);
        $tools = new AuthorizedToolsFixture;
        $tools->state = $state;
        $this->assertSame(['password' => 'x'], $tools->execute(1, 'rescueStatus', [], true)['data']['ssh']);

        CurrentUser::$admin = null;
        $tools = new AuthorizedToolsFixture;
        $tools->state = $state;
        $this->assertSame(['password' => 'x'], $tools->execute(1, 'rescueStatus', [], false)['data']['ssh']);
    }
}
