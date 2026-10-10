<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\BackupTools;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

final class BackupWorkflowFixture extends ServiceTools
{
    public array $points = [['serverId' => 100, 'disk' => 2, 'chainId' => 1, 'timestamp' => 123]];

    public array $job = ['serverId' => 100, 'jobId' => 10, 'status' => 'completed', 'exitCode' => 0];

    public array $writes = [];

    public bool $ambiguousRestore = false;

    public function __construct() {}

    public function revalidate(array $ctx, bool $admin): array
    {
        return $ctx;
    }

    public function request(array $ctx, string $method, string $path, ?array $body = null): array
    {
        if ($method === 'get') {
            $data = str_contains($path, '/backups?') ? $this->points : (str_contains($path, '/jobs?') ? [$this->job] : $this->job);

            return [200, ['data' => $data, 'last_page' => 1]];
        }
        $this->writes[] = compact('path', 'body');
        if (str_ends_with($path, '/restore') && $this->ambiguousRestore) {
            return [0, null];
        }

        return [202, ['data' => ['serverId' => 100, 'jobId' => str_ends_with($path, '/backup') ? 10 : 20]]];
    }
}

final class BackupWorkflowTest extends TestCase
{
    private array $ctx = ['serviceId' => 1, 'serverId' => 100, 'panelId' => 1, 'cp' => ['url' => 'https://synthetic.invalid', 'token' => 'fixture']];

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        Operation::schema();
        CurrentUser::$user = (object) ['id' => 100];
    }

    private function safeguard(BackupWorkflowFixture $tools): array
    {
        return (new BackupTools($tools))->execute($this->ctx, 'backupRestore', ['safeguard' => true, 'overwriteConfirmed' => true,
            'selections' => [['disk' => 2, 'chainId' => 1, 'timestamp' => 123]], 'requestId' => str_repeat('a', 32)], false);
    }

    private function proceed(BackupWorkflowFixture $tools, string $id): array
    {
        return (new BackupTools($tools))->execute($this->ctx, 'backupRestoreContinue', ['operationId' => $id, 'overwriteConfirmed' => true], false);
    }

    #[Test]
    public function safeguard_waits_for_confirmation_then_restores_the_original_pinned_target_once(): void
    {
        $tools = new BackupWorkflowFixture;
        $operation = $this->safeguard($tools);
        $this->assertSame(['/servers/100/backupManager/backup'], array_column($tools->writes, 'path'));
        $tools->points[] = ['serverId' => 100, 'disk' => 2, 'chainId' => 2, 'timestamp' => 456];
        $this->proceed($tools, $operation['operationId']);
        $this->assertSame(['disks' => ['2'], 'diskChains' => ['2' => 1], 'diskUntils' => ['2' => 123]], $tools->writes[1]['body']);

        try {
            $this->proceed($tools, $operation['operationId']);
            $this->fail('Repeated Continue submitted another restore.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertCount(2, $tools->writes);
    }

    public static function failures(): array
    {
        return [['failed'], ['missing']];
    }

    #[Test]
    #[DataProvider('failures')]
    public function failed_safeguard_or_missing_original_point_cannot_start_restore(string $failure): void
    {
        $tools = new BackupWorkflowFixture;
        $operation = $this->safeguard($tools);
        if ($failure === 'failed') {
            $tools->job['exitCode'] = 1;
        } else {
            $tools->points = [['serverId' => 100, 'disk' => 2, 'chainId' => 2, 'timestamp' => 456]];
        }

        try {
            $this->proceed($tools, $operation['operationId']);
            $this->fail('Unavailable safeguard target reached restore.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertCount(1, $tools->writes);
    }

    #[Test]
    public function ambiguous_restore_keeps_unknown_state_and_never_replays(): void
    {
        $tools = new BackupWorkflowFixture;
        $operation = $this->safeguard($tools);
        $tools->ambiguousRestore = true;
        $result = $this->proceed($tools, $operation['operationId']);
        $this->assertSame('unknown', $result['state']);

        try {
            $this->proceed($tools, $operation['operationId']);
            $this->fail('Ambiguous restore was replayed.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertCount(2, $tools->writes);
        $row = DB::table(Operation::TABLE)->where('id', $operation['operationId'])->first();
        $this->assertSame('backupRestore', $row->action);
        $this->assertSame('unknown', $row->state);
    }
}
