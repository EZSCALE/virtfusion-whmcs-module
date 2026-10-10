<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\AdminTools;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

final class MigrationToolsFixture extends ServiceTools
{
    public $state = ['active' => false, 'migration' => null];

    public array $inventory = ['migratable' => true, 'migrating' => false, 'activeMigrations' => false,
        'destinations' => [['id' => 2, 'available' => true, 'disabled' => false, 'maintenance' => false]]];

    public array $writes = [];

    public function __construct() {}

    public function read(array $ctx, string $path)
    {
        if (str_ends_with($path, '/destinations')) {
            return $this->inventory;
        }
        if (str_ends_with($path, '/destinations/2')) {
            return ['disks' => [['drive' => 'a', 'storageOptions' => [['id' => 3]]],
                ['drive' => 'b', 'storageOptions' => [['id' => 4]]]]];
        }

        return $this->state;
    }

    public function mutate(array $ctx, string $action, string $method, string $path, ?array $body, array $input, bool $admin): array
    {
        $this->writes[] = compact('action', 'method', 'path', 'body');

        return ['state' => 'queued'];
    }
}

final class MigrationToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CurrentUser::$admin = new class
        {
            public int $id = 1;

            public int $roleid = 1;

            public function hasPermission(string $permission): bool
            {
                return true;
            }
        };
    }

    private function dispatch(MigrationToolsFixture $tools, string $action, array $input = []): array
    {
        return (new AdminTools($tools))->execute(['serverId' => 100], $action,
            array_replace(['destinationId' => 2, 'disks' => [['drive' => 'a', 'storageId' => 3], ['drive' => 'b', 'storageId' => 4]], 'migrationConfirmed' => true], $input),
            ProviderSettings::defaults());
    }

    #[Test]
    public function native_inactive_envelope_and_nested_destinations_allow_validated_start(): void
    {
        foreach (['migrationHybridStart', 'migrationLiveStart'] as $action) {
            $tools = new MigrationToolsFixture;
            $this->dispatch($tools, $action);
            $this->assertCount(1, $tools->writes);
            $this->assertSame(2, $tools->writes[0]['body']['destinationId']);
            $this->assertSame('post', $tools->writes[0]['method']);
        }
    }

    #[Test]
    public function destination_details_resolve_only_from_nested_owned_inventory(): void
    {
        $tools = new MigrationToolsFixture;
        $this->assertCount(2, $this->dispatch($tools, 'migrationDestinationDetails')['data']['disks']);
        $this->expectException(\RuntimeException::class);
        $this->dispatch($tools, 'migrationDestinationDetails', ['destinationId' => 99]);
    }

    public static function invalidStates(): array
    {
        return [[null], [[]], [false], [['active' => 'false', 'migration' => null]],
            [['active' => false]], [['active' => false, 'migration' => ['id' => 1]]],
            [['active' => true, 'migration' => null]], [['active' => true, 'migration' => []]]];
    }

    #[Test]
    #[DataProvider('invalidStates')]
    public function malformed_state_cannot_authorize_any_migration_action($state): void
    {
        foreach (['migrationLiveStart', 'migrationLiveCancel'] as $action) {
            $tools = new MigrationToolsFixture;
            $tools->state = $state;

            try {
                $this->dispatch($tools, $action);
                $this->fail('Malformed state authorized a mutation.');
            } catch (\RuntimeException $e) {
                $this->assertSame(502, $e->getCode());
            }
            $this->assertSame([], $tools->writes);
        }
    }

    #[Test]
    public function idle_migration_cannot_cancel_and_active_migration_cannot_start(): void
    {
        foreach ([['active' => false, 'migration' => null], ['active' => true, 'migration' => ['id' => 1]]] as $state) {
            $tools = new MigrationToolsFixture;
            $tools->state = $state;

            try {
                $this->dispatch($tools, $state['active'] ? 'migrationHybridStart' : 'migrationHybridCancel');
                $this->fail('Conflicting state authorized mutation.');
            } catch (\RuntimeException $e) {
                $this->assertSame(409, $e->getCode());
            }
            $this->assertSame([], $tools->writes);
        }
    }

    #[Test]
    public function unavailable_or_foreign_destination_never_reaches_mutation(): void
    {
        foreach (['migratable', 'migrating', 'activeMigrations', 'available', 'disabled', 'maintenance', 'foreign', 'missing'] as $flag) {
            $tools = new MigrationToolsFixture;
            if (in_array($flag, ['migratable', 'migrating', 'activeMigrations'], true)) {
                $tools->inventory[$flag] = ! $tools->inventory[$flag];
            } elseif ($flag === 'foreign') {
                $tools->inventory['destinations'][0]['id'] = 99;
            } elseif ($flag === 'missing') {
                unset($tools->inventory['destinations']);
            } else {
                $tools->inventory['destinations'][0][$flag] = ! $tools->inventory['destinations'][0][$flag];
            }

            try {
                $this->dispatch($tools, 'migrationLiveStart');
                $this->fail('Invalid destination authorized mutation.');
            } catch (\RuntimeException $e) {
                $this->assertContains($e->getCode(), [403, 409, 502]);
            }
            $this->assertSame([], $tools->writes);
        }
    }

    #[Test]
    public function hybrid_requires_every_owned_drive_once_and_a_valid_storage_option(): void
    {
        foreach ([[], [['drive' => 'a', 'storageId' => 3]], [['drive' => 'a', 'storageId' => 3], ['drive' => 'a', 'storageId' => 3]],
            [['drive' => 'a', 'storageId' => 99], ['drive' => 'b', 'storageId' => 4]], ['invalid']] as $disks) {
            $tools = new MigrationToolsFixture;

            try {
                $this->dispatch($tools, 'migrationHybridStart', ['disks' => $disks]);
                $this->fail('Invalid mapping authorized mutation.');
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $this->assertSame([], $tools->writes);
            }
        }
    }

    #[Test]
    public function recovery_flags_and_forced_actions_require_the_separate_role(): void
    {
        foreach (['migrationLiveStart', 'migrationLiveForceCancel', 'migrationLiveForceDataSuccess'] as $action) {
            $tools = new MigrationToolsFixture;
            if ($action !== 'migrationLiveStart') {
                $tools->state = ['active' => true, 'migration' => ['id' => 1]];
            }

            try {
                $this->dispatch($tools, $action, ['allowUnsafe' => true, 'targetConfirmed' => '100']);
                $this->fail('Empty recovery allowlist authorized unsafe action.');
            } catch (\RuntimeException $e) {
                $this->assertSame(403, $e->getCode());
            }
            $this->assertSame([], $tools->writes);
        }
    }
}
