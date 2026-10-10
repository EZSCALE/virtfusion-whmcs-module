<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\IsoLibrary;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

/** Native media fixtures isolate ownership and payload boundaries, without downloading URLs. */
final class PrivateIsoGateway extends ServiceTools
{
    public array $rows = [['id' => 20, 'name' => 'Private ISO', 'enabled' => true, 'global' => false, 'users' => [['id' => 1010, 'enabled' => true]]]];

    public array $writes = [];

    public int $mutationCode = 204;

    public function __construct() {}

    public function revalidate(array $ctx, bool $admin): array
    {
        return $ctx;
    }

    public function request(array $ctx, string $method, string $path, ?array $body = null): array
    {
        if ($method === 'get') {
            return [200, ['data' => $this->rows, 'last_page' => 1]];
        }
        $this->writes[] = compact('method', 'path', 'body');

        return [$this->mutationCode, null];
    }
}

final class PrivateIsoPolicyTest extends TestCase
{
    private array $ctx = ['serviceId' => 1, 'serverId' => 100, 'panelId' => 1, 'user' => ['id' => 1010], 'cp' => ['url' => 'https://synthetic.invalid', 'token' => 'fixture']];

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        Operation::schema();
        IsoLibrary::schema();
        CurrentUser::$user = (object) ['id' => 100];
    }

    private function bind(int $panel = 1, int $user = 1010, string $state = 'active'): void
    {
        DB::table(IsoLibrary::TABLE)->insert(['id' => str_repeat('a', 32), 'panel_id' => $panel, 'user_id' => $user,
            'iso_id' => 20, 'state' => $state, 'name' => 'Private ISO', 'operation_id' => str_repeat('b', 32)]);
    }

    #[Test]
    public function unverified_native_isolation_blocks_even_a_syntactically_valid_public_url(): void
    {
        $tools = new PrivateIsoGateway;

        try {
            (new IsoLibrary($tools))->execute($this->ctx, 'isoLibraryCreate', ['name' => 'Private ISO', 'url' => 'https://public.example/test.iso'], ProviderSettings::defaults(), false);
            $this->fail('Unverified downloader received a URL.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame([], $tools->writes);
        $this->assertSame(0, DB::table(IsoLibrary::TABLE)->count());
        $this->assertSame(0, DB::table(Operation::TABLE)->count());
    }

    public static function changedOwnership(): array
    {
        return [['panel'], ['user'], ['pending'], ['global'], ['shared'], ['foreign'], ['disabled']];
    }

    #[Test]
    #[DataProvider('changedOwnership')]
    public function private_management_rejects_foreign_bindings_and_changed_native_assignments(string $change): void
    {
        $this->bind($change === 'panel' ? 2 : 1, $change === 'user' ? 1020 : 1010, $change === 'pending' ? 'pending' : 'active');
        $tools = new PrivateIsoGateway;
        if ($change === 'global') {
            $tools->rows[0]['global'] = true;
        }
        if ($change === 'shared') {
            $tools->rows[0]['users'][] = ['id' => 1020, 'enabled' => true];
        }
        if ($change === 'foreign') {
            $tools->rows[0]['users'] = [['id' => 1020, 'enabled' => true]];
        }
        if ($change === 'disabled') {
            $tools->rows[0]['users'][0]['enabled'] = false;
        }
        foreach (['isoLibraryRename', 'isoLibraryDelete'] as $action) {
            try {
                (new IsoLibrary($tools))->execute($this->ctx, $action, ['isoId' => 20, 'name' => 'Renamed ISO', 'deleteConfirmed' => true], ProviderSettings::defaults(), false);
                $this->fail('Changed private ownership authorized a mutation.');
            } catch (\RuntimeException $e) {
                $this->assertContains($e->getCode(), [403, 409]);
            }
        }
        $this->assertSame([], $tools->writes);
    }

    #[Test]
    public function rename_sends_only_the_name_despite_privileged_input_fields(): void
    {
        $this->bind();
        $tools = new PrivateIsoGateway;
        (new IsoLibrary($tools))->execute($this->ctx, 'isoLibraryRename', ['isoId' => 20, 'name' => 'Renamed ISO', 'global' => true,
            'users' => [['id' => 1020]], 'download' => true, 'requestId' => str_repeat('c', 32)], ProviderSettings::defaults(), false);
        $this->assertSame([['method' => 'put', 'path' => '/media/iso/20', 'body' => ['name' => 'Renamed ISO']]], $tools->writes);
    }

    #[Test]
    public function rejected_delete_never_forces_removal_or_releases_the_entry(): void
    {
        $this->bind();
        $tools = new PrivateIsoGateway;
        $tools->mutationCode = 409; // Native mounted-media conflict.
        $result = (new IsoLibrary($tools))->execute($this->ctx, 'isoLibraryDelete', ['isoId' => 20, 'deleteConfirmed' => true,
            'force' => true, 'requestId' => str_repeat('d', 32)], ProviderSettings::defaults(), false);
        $this->assertSame(['confirm' => true, 'force' => false], $tools->writes[0]['body']);
        $this->assertSame('failed', $result['state']);
        $this->assertSame('active', DB::table(IsoLibrary::TABLE)->first()->state);
    }
}
