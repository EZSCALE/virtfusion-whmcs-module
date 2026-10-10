<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\IsoLibrary;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

/** Read-only native observations must establish completion without mutation replay. */
final class IsoOperationGateway extends ServiceTools
{
    public array $media = ['mounted' => ['type' => 'library', 'id' => 20]];

    public array $iso = ['id' => 20, 'name' => 'Renamed ISO', 'global' => false, 'enabled' => true,
        'users' => [['id' => 1010, 'enabled' => 1]]];

    public int $reads = 0;

    public function __construct() {}

    public function observe(array $ctx, string $id): array
    {
        return $this->poll($ctx, $id);
    }

    public function request(array $ctx, string $method, string $path, ?array $body = null): array
    {
        if ($method !== 'get') {
            throw new \LogicException('Polling must never replay a mutation.');
        }
        $this->reads++;
        if ($path === '/media/iso?results=200&page=1') {
            return [200, ['data' => [$this->iso], 'last_page' => 1]];
        }
        if ($path !== '/servers/100/media/iso') {
            throw new \LogicException('Unexpected target.');
        }

        return [200, ['data' => $this->media]];
    }
}

final class IsoOperationTest extends TestCase
{
    private array $ctx = ['serviceId' => 1, 'serverId' => 100, 'panelId' => 1, 'user' => ['id' => 1010]];

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        CurrentUser::$user = (object) ['id' => 100];
        Operation::schema();
        IsoLibrary::schema();
    }

    public static function mountObservations(): array
    {
        return [[['mounted' => ['type' => 'library', 'id' => 20]], true],
            [['mounted' => ['type' => 'library', 'id' => '20']], true],
            [['mounted' => ['type' => 'library', 'id' => 21]], false],
            [['mounted' => ['type' => 'library', 'id' => '20junk']], false],
            [['mounted' => ['type' => 'custom', 'id' => 20]], false], [['mounted' => null], false], [[], false]];
    }

    #[Test]
    #[DataProvider('mountObservations')]
    public function library_mount_completes_only_for_the_exact_owned_native_target(array $media, bool $complete): void
    {
        $tools = new IsoOperationGateway;
        $tools->media = $media;
        $op = Operation::begin($this->ctx, 'isoMount', ['fields' => ['isoId' => 20]], ['type' => 'user', 'id' => 100], str_repeat('a', 32));
        Operation::finish($op->id, 'queued');
        $this->assertSame($complete ? 'completed' : 'queued', $tools->observe($this->ctx, $op->id)['state']);
        $this->assertSame($complete ? 0 : 1, DB::table(Operation::LOCKS)->count());
    }

    #[Test]
    public function foreign_assignment_cannot_complete_a_mount_or_release_its_reservation(): void
    {
        $tools = new IsoOperationGateway;
        $tools->iso['users'][0]['id'] = 1020;
        $op = Operation::begin($this->ctx, 'isoMount', ['fields' => ['isoId' => 20]], ['type' => 'user', 'id' => 100], str_repeat('a', 32));

        try {
            $tools->observe($this->ctx, $op->id);
            $this->fail('Foreign assignment completed the mount.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame(1, DB::table(Operation::LOCKS)->count());
    }

    public static function ejectObservations(): array
    {
        return [[['mounted' => null], true], [[], false], [['mounted' => false], false],
            [['mounted' => ['type' => 'library', 'id' => 20]], false]];
    }

    #[Test]
    #[DataProvider('ejectObservations')]
    public function eject_requires_an_explicit_empty_native_mount(array $media, bool $complete): void
    {
        $tools = new IsoOperationGateway;
        $tools->media = $media;
        $op = Operation::begin($this->ctx, 'isoEject', [], ['type' => 'user', 'id' => 100], str_repeat('a', 32));
        $this->assertSame($complete ? 'completed' : 'saved', $tools->observe($this->ctx, $op->id)['state']);
        $this->assertSame($complete ? 0 : 1, DB::table(Operation::LOCKS)->count());
    }

    public static function renameObservations(): array
    {
        return [['matching', true], ['wrong-name', false], ['shared', false], ['global', false], ['foreign-binding', false]];
    }

    #[Test]
    #[DataProvider('renameObservations')]
    public function rename_requires_the_exact_name_and_exclusive_private_ownership(string $change, bool $complete): void
    {
        $tools = new IsoOperationGateway;
        DB::table(IsoLibrary::TABLE)->insert(['id' => str_repeat('b', 32), 'panel_id' => 1,
            'user_id' => $change === 'foreign-binding' ? 1020 : 1010, 'iso_id' => 20,
            'state' => 'active', 'name' => 'Original ISO', 'operation_id' => str_repeat('c', 32)]);
        if ($change === 'wrong-name') {
            $tools->iso['name'] = 'Original ISO';
        } elseif ($change === 'shared') {
            $tools->iso['users'][] = ['id' => 1020, 'enabled' => 1];
        } elseif ($change === 'global') {
            $tools->iso['global'] = true;
        }
        $op = Operation::begin($this->ctx, 'isoLibraryRename', ['resource' => '/media/iso/20',
            'fields' => ['name' => 'Renamed ISO']], ['type' => 'user', 'id' => 100], str_repeat('a', 32));
        $this->assertSame($complete ? 'completed' : 'saved', $tools->observe($this->ctx, $op->id)['state']);
        $this->assertSame($complete ? 0 : 1, DB::table(Operation::LOCKS)->count());
    }

    #[Test]
    public function custom_url_mount_without_exact_native_identity_remains_unresolved(): void
    {
        $tools = new IsoOperationGateway;
        $op = Operation::begin($this->ctx, 'isoMount', ['fields' => ['urlHash' => str_repeat('a', 64)]], ['type' => 'user', 'id' => 100], str_repeat('a', 32));
        $this->assertSame('saved', $tools->observe($this->ctx, $op->id)['state']);
        $this->assertSame(1, DB::table(Operation::LOCKS)->count());
        $this->assertSame(0, $tools->reads);
    }
}
