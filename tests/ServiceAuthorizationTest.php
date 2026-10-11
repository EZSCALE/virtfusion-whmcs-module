<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\IsoLibrary;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;
use WHMCS\Module\Server\VirtFusionDirect\ResourcePack;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

class ScriptedCurl extends Curl
{
    private $owner;

    private int $code = 0;

    public function __construct($owner)
    {
        $this->owner = $owner;
    }

    public function addOption($name, $value) {}

    public function get($url = null)
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->owner->reads[] = $path;
        if (preg_match('~/users/(\d+)/byExtRelation$~', $path, $m)) {
            $this->code = 200;

            return json_encode(['data' => ['id' => 1000 + (int) $m[1], 'extRelationId' => (int) $m[1], 'admin' => false]]);
        }
        if (preg_match('~/servers/(\d+)$~', $path, $m)) {
            $this->code = 200;

            return json_encode(['data' => ['id' => (int) $m[1], 'owner' => ['id' => $this->owner->owners[(int) $m[1]] ?? 9999], 'settings' => ['doNotDestroy' => $this->owner->dnd]]]);
        }
        $this->code = 404;

        return '{}';
    }

    public function getRequestInfo($key = null)
    {
        return $key === 'http_code' ? $this->code : ['http_code' => $this->code];
    }
}

class AuthorizedToolsFixture extends ServiceTools
{
    public array $reads = [];

    public array $writes = [];

    public array $owners = [100 => 1010, 200 => 1020];

    public array $pack = ['id' => 50, 'servers' => [['id' => 100]]];

    public array $state = ['eligible' => [['id' => 1, 'drive' => 'a']], 'active' => false];

    public string $installedVersion = '7.1.0';

    public bool $enabled = true;

    public bool $dnd = false;

    public function __construct() {}

    public function initCurl($token)
    {
        return new ScriptedCurl($this);
    }

    protected function version(array $cp): ?string
    {
        return $this->installedVersion;
    }

    protected function settings(int $panel): array
    {
        return array_replace(ProviderSettings::defaults(), ['rescue' => $this->enabled, 'elevation' => $this->enabled, 'iso' => $this->enabled, 'disks' => $this->enabled, 'adminTools' => true]);
    }

    protected function resourcePacks(): ResourcePack
    {
        $pack = $this->pack;

        return new class($pack) extends ResourcePack
        {
            private array $pack;

            public function __construct($pack)
            {
                $this->pack = $pack;
            }

            public function fetchPack($serviceId, array $cp)
            {
                return $this->pack;
            }
        };
    }

    public function request(array $ctx, string $method, string $path, ?array $body = null): array
    {
        if ($method === 'get') {
            $this->reads[] = $path;

            return [200, ['data' => $this->state]];
        }
        $this->writes[] = [$path, $body];

        return [202, ['data' => ['queueId' => 1]]];
    }
}

final class ServiceAuthorizationTest extends TestCase
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
            $t->integer('server_id');
        });
        DB::table('tblhosting')->insert([
            ['id' => 1, 'userid' => 10, 'server' => 1, 'packageid' => 1, 'domainstatus' => 'Active'],
            ['id' => 2, 'userid' => 20, 'server' => 1, 'packageid' => 1, 'domainstatus' => 'Active'],
            ['id' => 3, 'userid' => 10, 'server' => 1, 'packageid' => 2, 'domainstatus' => 'Active'],
        ]);
        DB::table('tblproducts')->insert([['id' => 1, 'servertype' => 'VirtFusionDirect', 'configoption9' => ''], ['id' => 2, 'servertype' => 'VirtFusionDirect', 'configoption9' => 'Resource Pack']]);
        DB::table('tblservers')->insert(['id' => 1, 'type' => 'VirtFusionDirect', 'hostname' => 'panel.example.test', 'password' => 'test-token']);
        DB::table('mod_virtfusion_direct')->insert([['service_id' => 1, 'server_id' => 100], ['service_id' => 2, 'server_id' => 200]]);
        DB::table('tblusers_clients')->insert(['auth_user_id' => 100, 'client_id' => 10, 'owner' => 1]);
        ResourcePack::schema();
        DB::table(ResourcePack::TABLE)->insert(['service_id' => 3, 'pack_id' => 50, 'state' => 'active', 'panel_id' => 1, 'user_id' => 1010, 'profile_id' => 5]);
        CurrentUser::$client = (object) ['id' => 10];
        CurrentUser::$user = (object) ['id' => 100];
        CurrentUser::$admin = null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $GLOBALS['vfd_valid_csrf'] = true;
    }

    private function denied(AuthorizedToolsFixture $tools, int $service, string $action, array $fields): void
    {
        try {
            $tools->execute($service, $action, $fields);
            $this->fail('Unauthorized action accepted.');
        } catch (\RuntimeException $e) {
            $this->assertContains($e->getCode(), [403, 409]);
        }
        $this->assertSame([], $tools->writes, 'No remote mutation on rejection.');
    }

    public static function customerActions(): array
    {
        $cases = [];
        foreach (ServiceTools::ACTIONS as $action => [$feature]) {
            if ($feature !== 'adminTools') {
                $cases[$action] = [$action];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('customerActions')]
    public function every_customer_action_denies_another_account_before_native_reads(string $action): void
    {
        $tools = new AuthorizedToolsFixture;
        $this->denied($tools, 2, $action, ['requestId' => str_repeat('a', 32)]);
        $this->assertSame([], $tools->reads);
    }

    #[Test]
    #[DataProvider('customerActions')]
    public function every_customer_action_denies_revoked_account_permission(string $action): void
    {
        DB::table('tblusers_clients')->update(['owner' => 0, 'permissions' => 'products,tickets']);
        $tools = new AuthorizedToolsFixture;
        $this->denied($tools, 1, $action, ['requestId' => str_repeat('a', 32)]);
        $this->assertSame([], $tools->reads);
    }

    #[Test]
    #[DataProvider('customerActions')]
    public function every_customer_action_denies_a_logged_out_user_even_with_selected_client(string $action): void
    {
        CurrentUser::$user = null;
        $tools = new AuthorizedToolsFixture;

        try {
            $tools->execute(1, $action, ['requestId' => str_repeat('a', 32)]);
            $this->fail('Expired session authorized a service action.');
        } catch (\RuntimeException $e) {
            $this->assertSame(401, $e->getCode());
        }
        $this->assertSame([], $tools->reads);
        $this->assertSame([], $tools->writes);
    }

    #[Test]
    public function another_clients_service_cannot_be_read_or_changed(): void
    {
        $tools = new AuthorizedToolsFixture;
        $this->denied($tools, 2, 'rescueStatus', []);
        $this->denied($tools, 2, 'rescueStart', ['imageId' => 1, 'restartConfirmed' => true]);
        $this->assertSame([], $tools->reads, 'Reject before remote access.');
    }

    #[Test]
    public function forged_local_binding_cannot_cross_virtfusion_ownership(): void
    {
        $tools = new AuthorizedToolsFixture;
        DB::table('mod_virtfusion_direct')->where('service_id', 1)->update(['server_id' => 200]);
        $this->denied($tools, 1, 'rescueStatus', []);
    }

    #[Test]
    public function selected_vm_is_not_accepted_for_a_server_service(): void
    {
        $this->denied(new AuthorizedToolsFixture, 1, 'rescueStatus', ['memberVm' => 200]);
    }

    #[Test]
    public function removed_pack_member_and_foreign_owner_are_both_rejected(): void
    {
        $tools = new AuthorizedToolsFixture;
        $this->denied($tools, 3, 'rescueStatus', ['memberVm' => 200]);
        $tools->pack['servers'] = [['id' => 200]];
        $this->denied($tools, 3, 'rescueStatus', ['memberVm' => 200]);
    }

    #[Test]
    public function current_owned_pack_member_can_use_enabled_controls(): void
    {
        $tools = new AuthorizedToolsFixture;
        $tools->state = ['active' => false, 'images' => [['id' => 1, 'name' => 'Rescue']]];
        $result = $tools->execute(3, 'rescueStart', ['memberVm' => 100, 'imageId' => 1, 'restartConfirmed' => true, 'requestId' => str_repeat('a', 32)]);
        $this->assertSame('queued', $result['state']);
        $this->assertSame('/servers/100/rescue', $tools->writes[0][0]);
        $this->assertSame(['imageId' => 1, 'sendMail' => true], $tools->writes[0][1]);
    }

    #[Test]
    public function vf6_and_vf70_do_not_enable_vf71_actions_even_when_setting_is_on(): void
    {
        foreach (['6.2.0', '7.0.0'] as $v) {
            $tools = new AuthorizedToolsFixture;
            $tools->installedVersion = $v;
            $this->denied($tools, 1, 'elevationStart', ['disks' => ['a']]);
        }
    }

    #[Test]
    public function disabled_settings_and_restricted_users_deny_every_service_action(): void
    {
        $tools = new AuthorizedToolsFixture;
        $tools->enabled = false;
        $this->denied($tools, 1, 'rescueStatus', []);
        $tools->enabled = true;
        DB::table('tblusers_clients')->update(['owner' => 0, 'permissions' => 'products,tickets']);
        $this->denied($tools, 1, 'rescueStatus', []);
    }

    #[Test]
    public function customer_cannot_select_admin_tool_or_another_eligible_disk(): void
    {
        $this->denied(new AuthorizedToolsFixture, 1, 'diskDelete', ['diskRef' => 1]);
        $this->denied(new AuthorizedToolsFixture, 1, 'elevationStart', ['disks' => ['b']]);
    }

    #[Test]
    public function dnd_and_invalid_rescue_image_block_mutation(): void
    {
        $tools = new AuthorizedToolsFixture;
        $tools->dnd = true;
        $this->denied($tools, 1, 'elevationStart', ['disks' => ['a']]);
        $tools->dnd = false;
        $tools->state = ['images' => [['id' => 1]]];
        $this->denied($tools, 1, 'rescueStart', ['imageId' => 2, 'restartConfirmed' => true]);
    }

    #[Test]
    public function two_submissions_create_only_one_queue_request(): void
    {
        $tools = new AuthorizedToolsFixture;
        $fields = ['disks' => ['a'], 'requestId' => str_repeat('f', 32)];
        $tools->execute(1, 'elevationStart', $fields);

        try {
            $tools->execute(1, 'elevationStart', $fields);
            $this->fail('Duplicate submission accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertCount(1, $tools->writes);
        $this->assertSame(1, DB::table(Operation::TABLE)->count());
    }

    #[Test]
    public function custom_urls_are_disabled_without_native_isolation_evidence(): void
    {
        $this->denied(new AuthorizedToolsFixture, 1, 'isoMount', ['url' => 'https://public.example.test/test.iso']);
    }

    #[Test]
    public function every_admin_action_is_denied_to_customer_entry_points(): void
    {
        foreach (ServiceTools::ACTIONS as $action => $definition) {
            if ($definition[0] === 'adminTools') {
                $this->denied(new AuthorizedToolsFixture, 1, $action, []);
            }
        }
    }

    #[Test]
    public function valid_owner_with_get_or_bad_csrf_cannot_submit_a_mutation(): void
    {
        foreach ([['GET', true], ['POST', false]] as [$method, $valid]) {
            $tools = new AuthorizedToolsFixture;
            $_SERVER['REQUEST_METHOD'] = $method;
            $GLOBALS['vfd_valid_csrf'] = $valid;

            try {
                $tools->execute(1, 'elevationStart', ['disks' => ['a'], 'requestId' => str_repeat('a', 32)]);
                $this->fail('Mutation passed method/token guards.');
            } catch (\RuntimeException $e) {
                $this->assertContains($e->getCode(), [403, 405]);
            }
            $this->assertSame([], $tools->writes);
        }
    }

    #[Test]
    public function administrator_can_validate_native_controls_without_enabling_customer_features(): void
    {
        CurrentUser::$admin = new class
        {
            public int $id = 1;

            public function hasPermission($permission)
            {
                return true;
            }
        };
        $tools = new AuthorizedToolsFixture;
        $tools->enabled = false;
        $result = $tools->execute(1, 'rescueStatus', [], true);
        $this->assertFalse($result['data']['active']);
        $this->assertSame([], $tools->writes);
    }

    #[Test]
    public function suspended_pack_cannot_mutate_after_initial_validation(): void
    {
        $tools = new class extends AuthorizedToolsFixture
        {
            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                if ($method === 'get') {
                    DB::table(ResourcePack::TABLE)->where('service_id', 3)->update(['state' => 'suspended']);

                    return [200, ['data' => ['images' => [['id' => 1]]]]];
                }

                return parent::request($ctx, $method, $path, $body);
            }
        };

        try {
            $tools->execute(3, 'rescueStart', ['memberVm' => 100, 'imageId' => 1, 'restartConfirmed' => true, 'requestId' => str_repeat('d', 32)]);
            $this->fail('Suspended pack accepted a write.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame('failed', DB::table(Operation::TABLE)->first()->state);
    }

    #[Test]
    public function safeguard_backup_rechecks_owner_after_reading_recovery_points(): void
    {
        $tools = new class extends AuthorizedToolsFixture
        {
            protected function settings(int $panel): array
            {
                return array_replace(parent::settings($panel), ['backups' => true]);
            }

            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                if ($method === 'get' && str_contains($path, '/backupManager/')) {
                    if (str_contains($path, '/backups?')) {
                        $this->owners[100] = 1020;

                        return [200, ['data' => [['serverId' => 100, 'disk' => 1, 'chainId' => 2, 'timestamp' => 3]]]];
                    }

                    return [200, ['data' => []]];
                }

                return parent::request($ctx, $method, $path, $body);
            }
        };

        try {
            $tools->execute(1, 'backupRestore', ['overwriteConfirmed' => true, 'safeguard' => true, 'selections' => [['disk' => 1, 'chainId' => 2, 'timestamp' => 3]], 'requestId' => str_repeat('e', 32)]);
            $this->fail('Foreign owner received a safeguard mutation.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame('failed', DB::table(Operation::TABLE)->first()->state);
    }

    #[Test]
    public function continued_restore_rechecks_owner_and_releases_unsent_reservation(): void
    {
        $tools = new class extends AuthorizedToolsFixture
        {
            protected function settings(int $panel): array
            {
                return array_replace(parent::settings($panel), ['backups' => true]);
            }

            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                if ($method === 'get' && str_contains($path, '/backupManager/')) {
                    if (str_contains($path, '/backups?')) {
                        $this->owners[100] = 1020;

                        return [200, ['data' => [['serverId' => 100, 'disk' => 1, 'chainId' => 2, 'timestamp' => 3]]]];
                    }
                    if ($path === '/backupManager/jobs/5') {
                        return [200, ['data' => ['serverId' => 100, 'jobId' => 5, 'status' => 'completed', 'exitCode' => 0]]];
                    }

                    return [200, ['data' => []]];
                }

                return parent::request($ctx, $method, $path, $body);
            }
        };
        $ctx = ['panelId' => 1, 'serviceId' => 1, 'serverId' => 100];
        $op = Operation::begin($ctx, 'backupSafeguard', ['disks' => ['1'], 'diskChains' => ['1' => 2], 'diskUntils' => ['1' => 3]], ['type' => 'user', 'id' => 100], str_repeat('f', 32));
        Operation::finish($op->id, 'saved', [], 5);

        try {
            $tools->execute(1, 'backupRestoreContinue', ['overwriteConfirmed' => true, 'operationId' => $op->id]);
            $this->fail('Foreign owner received a restore mutation.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame('failed', Operation::get($op->id, $ctx)->state);
        $this->assertSame(0, DB::table(Operation::LOCKS)->count());
    }

    #[Test]
    public function private_iso_assignment_stops_if_service_owner_changes_after_creation(): void
    {
        $tools = new class extends AuthorizedToolsFixture
        {
            protected function settings(int $panel): array
            {
                return array_replace(parent::settings($panel), ['isoLibrary' => true, 'urlIsolationVerified' => true]);
            }

            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                if ($method === 'post' && $path === '/media/iso') {
                    $this->writes[] = [$path, $body];
                    $this->owners[100] = 1020;

                    return [201, ['data' => ['id' => 20]]];
                }
                if ($method === 'get' && str_starts_with($path, '/media/iso?')) {
                    return [200, ['data' => $this->writes ? [['id' => 20, 'global' => false, 'users' => []]] : []]];
                }

                return parent::request($ctx, $method, $path, $body);
            }
        };

        try {
            $tools->execute(1, 'isoLibraryCreate', ['name' => 'Private ISO', 'url' => 'https://public.example/test.iso', 'requestId' => str_repeat('a', 32)]);
            $this->fail('ISO was assigned after ownership changed.');
        } catch (\RuntimeException $e) {
            $this->assertSame(502, $e->getCode());
        }
        $this->assertCount(1, $tools->writes, 'Create may have occurred; no assignment or replay is permitted.');
        $this->assertSame(false, $tools->writes[0][1]['global']);
        $this->assertSame('unknown', DB::table(Operation::TABLE)->first()->state);
        $this->assertSame('pending', DB::table(IsoLibrary::TABLE)->first()->state);
    }

    #[Test]
    public function another_completed_job_on_the_same_vm_cannot_release_an_operation(): void
    {
        $tools = new class extends AuthorizedToolsFixture
        {
            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                return [200, ['data' => ['serverId' => 100, 'jobId' => 6, 'status' => 'completed', 'exitCode' => 0]]];
            }
        };
        $ctx = ['panelId' => 1, 'serviceId' => 1, 'serverId' => 100];
        $op = Operation::begin($ctx, 'backupCreate', [], ['type' => 'user', 'id' => 100], str_repeat('a', 32));
        Operation::finish($op->id, 'queued', [], 5);

        try {
            $tools->execute(1, 'operation', ['operationId' => $op->id]);
            $this->fail('An unrelated completed job released the reservation.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame('queued', Operation::get($op->id, $ctx)->state);
        $this->assertSame(1, DB::table(Operation::LOCKS)->count());
    }

    public static function unsafeIsoCreationResponses(): array
    {
        return [
            'existing ID' => [201, [['id' => 20]], ['id' => 20, 'global' => false, 'users' => []]],
            'acceptance without creation' => [200, [], ['id' => 20, 'global' => false, 'users' => []]],
            'global entry' => [201, [], ['id' => 20, 'global' => true, 'users' => []]],
            'foreign assignment' => [201, [], ['id' => 20, 'global' => false, 'users' => [['id' => 1020, 'enabled' => true]]]],
        ];
    }

    #[Test]
    #[DataProvider('unsafeIsoCreationResponses')]
    public function ambiguous_or_existing_iso_ids_never_authorize_assignment_changes(int $code, array $before, array $created): void
    {
        $tools = new class($code, $before, $created) extends AuthorizedToolsFixture
        {
            private int $creationCode;

            private array $before;

            private array $created;

            public function __construct($code, $before, $created)
            {
                $this->creationCode = $code;
                $this->before = $before;
                $this->created = $created;
            }

            protected function settings(int $panel): array
            {
                return array_replace(parent::settings($panel), ['isoLibrary' => true, 'urlIsolationVerified' => true]);
            }

            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                if ($method === 'get') {
                    return [200, ['data' => $this->writes ? [$this->created] : $this->before]];
                }
                $this->writes[] = [$path, $body];

                return [$this->creationCode, ['data' => ['id' => 20]]];
            }
        };

        try {
            $tools->execute(1, 'isoLibraryCreate', ['name' => 'Private ISO', 'url' => 'https://public.example/test.iso', 'requestId' => str_repeat('c', 32)]);
            $this->fail('Unproven private creation authorized assignment changes.');
        } catch (\RuntimeException $e) {
            $this->assertSame(502, $e->getCode());
        }
        $this->assertCount(1, $tools->writes);
        $this->assertSame('/media/iso', $tools->writes[0][0]);
        $this->assertSame('pending', DB::table(IsoLibrary::TABLE)->first()->state);
        $this->assertSame('unknown', DB::table(Operation::TABLE)->first()->state);
    }
}
