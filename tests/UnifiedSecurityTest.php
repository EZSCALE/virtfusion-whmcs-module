<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\Access;
use WHMCS\Module\Server\VirtFusionDirect\BackupTools;
use WHMCS\Module\Server\VirtFusionDirect\FirewallTools;
use WHMCS\Module\Server\VirtFusionDirect\IsoLibrary;
use WHMCS\Module\Server\VirtFusionDirect\Log;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\PanelVersion;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;
use WHMCS\Module\Server\VirtFusionDirect\ResourcePack;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

final class UnifiedSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        Operation::schema();
        ProviderSettings::schema();
        CurrentUser::$client = (object) ['id' => 10];
        CurrentUser::$user = (object) ['id' => 100];
        CurrentUser::$admin = null;
        $GLOBALS['vfd_valid_csrf'] = true;
    }

    public static function versions(): array
    {
        return [
            [200, ['name' => '6.2.0 Build 1'], '6.2.0', false],
            [200, ['name' => '7.0.0 Build 9'], '7.0.0', false],
            [503, ['name' => '7.1.0 Build 1'], '7.1.0', true],
            [200, ['number' => 7001000001], '7.1.0', true],
            [200, ['number' => 6002000000], '6.2.0', false],
        ];
    }

    #[Test]
    #[DataProvider('versions')]
    public function installed_version_controls_availability(int $code, array $v, string $expected, bool $available): void
    {
        $version = PanelVersion::parse($code, json_encode(['data' => ['version' => $v]]));
        $this->assertSame($expected, $version);
        $this->assertSame($available, PanelVersion::supports($version, '7.1.0'));
    }

    #[Test]
    public function auth_failure_never_becomes_legacy(): void
    {
        $this->expectException(\RuntimeException::class);
        PanelVersion::parse(401, '{}');
    }

    #[Test]
    public function absent_status_disables_new_features(): void
    {
        $this->assertNull(PanelVersion::parse(404, '{}'));
        $this->assertFalse(PanelVersion::supports(null, '7.1.0'));
    }

    #[Test]
    public function malformed_status_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        PanelVersion::parse(503, '{"data":{}}');
    }

    #[Test]
    public function all_customer_features_default_off_and_recovery_roles_empty(): void
    {
        $settings = ProviderSettings::get(1);
        foreach (['network', 'disks', 'backups', 'rescue', 'elevation', 'iso', 'isoLibrary', 'firewall'] as $feature) {
            $this->assertFalse($settings[$feature]);
        }
        $this->assertSame([], $settings['recoveryRoles']);
        $this->assertSame(5, $settings['isoLimit']);
    }

    #[Test]
    public function viewing_client_account_does_not_grant_management_permission(): void
    {
        $this->assertFalse(Access::clientAllowed(null, 'manageproducts'));
        $this->assertFalse(Access::clientAllowed((object) ['owner' => 0, 'permissions' => 'products,tickets'], 'manageproducts'));
        $this->assertTrue(Access::clientAllowed((object) ['owner' => 0, 'permissions' => 'products,manageproducts'], 'manageproducts'));
        $this->assertTrue(Access::clientAllowed((object) ['owner' => 1], 'manageproducts'));
    }

    #[Test]
    public function csrf_denies_missing_validation(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $GLOBALS['vfd_valid_csrf'] = false;
        $this->expectException(\RuntimeException::class);
        Access::csrf();
    }

    #[Test]
    public function mutation_requires_post(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->expectException(\RuntimeException::class);
        Access::csrf();
    }

    private function ctx(int $service = 1, int $vm = 100, int $panel = 1): array
    {
        return ['serviceId' => $service, 'serverId' => $vm, 'panelId' => $panel];
    }

    #[Test]
    public function duplicate_requests_and_concurrent_service_aliases_cannot_replay_vm_mutations(): void
    {
        $ctx = $this->ctx();
        Operation::begin($ctx, 'rescueStart', [], ['type' => 'user', 'id' => 100], str_repeat('a', 32));
        foreach ([[$ctx, str_repeat('a', 32)], [$this->ctx(2), str_repeat('b', 32)]] as [$other, $nonce]) {
            try {
                Operation::begin($other, 'rescueStart', [], ['type' => 'user', 'id' => 100], $nonce);
                $this->fail('Duplicate/conflicting mutation was reserved.');
            } catch (\RuntimeException $e) {
                $this->assertSame(409, $e->getCode());
            }
        }
        $this->assertSame(1, DB::table(Operation::TABLE)->count());
    }

    #[Test]
    public function unknown_outcomes_keep_the_reservation(): void
    {
        $op = Operation::begin($this->ctx(), 'isoMount', [], ['type' => 'user', 'id' => 100], str_repeat('c', 32));
        Operation::finish($op->id, Operation::resultState(0, null));
        $this->assertSame('unknown', Operation::get($op->id, $this->ctx())->state);
        $this->assertSame(1, DB::table(Operation::LOCKS)->count());
        Operation::finish($op->id, 'completed');
        $this->assertSame(0, DB::table(Operation::LOCKS)->count());
        $this->assertSame(1, DB::table(Operation::TABLE)->count(), 'Audit history retained.');
    }

    #[Test]
    public function operation_records_cannot_be_read_through_another_panel_or_service(): void
    {
        $op = Operation::begin($this->ctx(), 'isoMount', [], ['type' => 'user', 'id' => 100], str_repeat('d', 32));
        foreach ([$this->ctx(2), $this->ctx(1, 200), $this->ctx(1, 100, 2)] as $foreign) {
            try {
                Operation::get($op->id, $foreign);
                $this->fail('Foreign operation disclosed.');
            } catch (\RuntimeException $e) {
                $this->assertSame(404, $e->getCode());
            }
        }
    }

    #[Test]
    public function applied_failure_is_distinct_from_queued_or_completed(): void
    {
        $this->assertSame('saved', Operation::resultState(201, ['applied' => false]));
        $this->assertSame('saved', Operation::resultState(201, ['applied' => null]));
        $this->assertSame('completed', Operation::resultState(201, ['applied' => true]));
        $this->assertSame('queued', Operation::resultState(200, ['data' => ['jobId' => 1]]));
        $this->assertSame('failed', Operation::resultState(409, []));
    }

    #[Test]
    public function provider_deny_blocks_broad_allows_in_both_directions_and_ip_families(): void
    {
        foreach (['in', 'out', 'both'] as $direction) {
            foreach ([4, 6] as $family) {
                try {
                    FirewallTools::protectedPolicy(['action' => 'allow', 'direction' => $direction, 'ipType' => $family], [
                        ['action' => 'deny', 'direction' => 'both', 'ipType' => $family, 'priority' => 1000, 'enabled' => true],
                    ]);
                    $this->fail('Protected deny was bypassed.');
                } catch (\RuntimeException $e) {
                    $this->assertSame(403, $e->getCode());
                }
            }
        }
    }

    #[Test]
    public function customer_firewall_cannot_send_replace_all_or_priority_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FirewallTools::rule(['rulesets' => [1], 'priority' => 1000]);
    }

    #[Test]
    public function iso_requires_enabled_assignment_and_does_not_trust_admin_mount_permissions(): void
    {
        $this->assertFalse(IsoLibrary::eligible(['enabled' => true, 'global' => false, 'users' => [['id' => 20, 'enabled' => true]]], 10));
        $this->assertFalse(IsoLibrary::eligible(['enabled' => true, 'users' => [['id' => 10, 'enabled' => false]]], 10));
        $this->assertTrue(IsoLibrary::eligible(['enabled' => true, 'global' => false, 'users' => [['id' => 10, 'enabled' => true]]], 10));
    }

    #[Test]
    public function backend_filter_is_not_trusted_for_backup_job_ownership(): void
    {
        $this->expectException(\RuntimeException::class);
        ServiceTools::ownedRows([['serverId' => 200, 'jobId' => 1]], 100);
    }

    #[Test]
    public function restore_choices_pin_disk_chain_and_timestamp_and_reject_other_points(): void
    {
        $points = [['disk' => 1, 'chainId' => 2, 'timestamp' => 1000]];
        $body = BackupTools::restoreTargets($points, $points);
        $this->assertSame(['1'], $body['disks']);
        $this->assertSame(2, $body['diskChains'][1]);
        $this->assertSame(1000, $body['diskUntils'][1]);
        $this->expectException(\RuntimeException::class);
        BackupTools::restoreTargets($points, [['disk' => 1, 'chainId' => 3, 'timestamp' => 1000]]);
    }

    #[Test]
    public function foreign_subordinate_ids_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        ServiceTools::select([['id' => 1, 'drive' => 'a']], 200);
    }

    #[Test]
    public function invalid_instance_prefix_never_falls_back(): void
    {
        $this->expectException(\RuntimeException::class);
        Module::validateInstancePrefix(['prefix' => '../10']);
    }

    #[Test]
    public function pack_profiles_are_not_accepted_as_vm_members(): void
    {
        $this->assertSame([100], ResourcePack::memberIds(['pid' => 200, 'servers' => [['id' => 100]]]));
        $this->assertFalse(ResourcePack::containsPack([['type' => 'hourly', 'id' => 200]], 200));
    }

    #[Test]
    public function credentials_and_session_urls_never_enter_logs(): void
    {
        Log::insert('secret-test', ['serverpassword' => 'secret-api'], json_encode(['data' => ['password' => 'rescue-password', 'wss' => ['token' => 'vnc-session'], 'authentication' => ['endpoint_complete' => '/token?1=SSO-secret'], 'url' => 'https://download.test/?token=private'], 'name' => 'safe']));
        $encoded = json_encode($GLOBALS['vfd_test_log']);
        foreach (['secret-api', 'rescue-password', 'vnc-session', 'SSO-secret', 'token=private'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertStringContainsString('safe', $encoded);
    }

    #[Test]
    public function cache_keys_separate_panels_and_tokens(): void
    {
        $module = new class extends Module
        {
            public function __construct() {}
        };
        $a = $module->panelCacheKey(['url' => 'https://a.test/api/v1', 'token' => 'a'], 'backups:100');
        $b = $module->panelCacheKey(['url' => 'https://b.test/api/v1', 'token' => 'a'], 'backups:100');
        $c = $module->panelCacheKey(['url' => 'https://a.test/api/v1', 'token' => 'b'], 'backups:100');
        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    #[Test]
    public function admin_read_permission_and_recovery_permission_are_separate(): void
    {
        CurrentUser::$admin = new class
        {
            public int $id = 1;

            public int $roleid = 2;

            public array $permissions = ['View Clients Products/Services'];

            public function hasPermission($permission)
            {
                return in_array($permission, $this->permissions, true);
            }
        };
        $this->assertSame(1, Access::admin('View Clients Products/Services'));

        try {
            Access::admin('Perform Module Command Operations');
            $this->fail('Read-only administrator could mutate.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        CurrentUser::$admin->permissions[] = 'Perform Module Command Operations';

        try {
            Access::recovery(ProviderSettings::defaults());
            $this->fail('Recovery allowed with an empty role list.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        Access::recovery(array_replace(ProviderSettings::defaults(), ['recoveryRoles' => [2]]));
        $this->assertTrue(true);
    }

    #[Test]
    public function unverified_prerequisites_and_ambiguous_timeouts_fail_closed(): void
    {
        $this->assertSame('unknown', Operation::resultState(408, null));
        $this->assertSame('unknown', Operation::resultState(302, null));
        $this->expectException(\InvalidArgumentException::class);
        ProviderSettings::validate(['urlIsolationVerified' => true]);
    }
}
