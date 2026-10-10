<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\IsoLibrary;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;

final class IsoQuotaTest extends TestCase
{
    #[Test]
    public function a_pending_import_reserves_the_account_quota_across_different_vms(): void
    {
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        CurrentUser::$user = (object) ['id' => 100];
        $ctx = ['serviceId' => 1, 'serverId' => 100, 'panelId' => 1, 'user' => ['id' => 1010], 'cp' => ['url' => 'https://test.invalid', 'token' => 'test']];
        $settings = array_replace(ProviderSettings::defaults(), ['isoLimit' => 1, 'urlIsolationVerified' => true, 'urlIsolationEvidence' => 'synthetic native boundary']);
        $tools = new class extends ServiceTools
        {
            public int $writes = 0;

            public $onImport;

            public function __construct() {}

            public function request(array $ctx, string $method, string $path, ?array $body = null): array
            {
                $this->writes++;
                if ($this->onImport) {
                    ($this->onImport)();
                }

                return [0, null]; // Native outcome ambiguous; entry must stay reserved.
            }
        };
        $tools->onImport = function () use ($ctx, $settings, $tools) {
            $secondCtx = array_replace($ctx, ['serviceId' => 2, 'serverId' => 101]);

            try {
                (new IsoLibrary($tools))->execute($secondCtx, 'isoLibraryCreate', ['name' => 'Second ISO', 'url' => 'https://public.example/test.iso', 'requestId' => str_repeat('b', 32)], $settings, false);
                $this->fail('Parallel service bypassed the customer quota.');
            } catch (\RuntimeException $e) {
                $this->assertSame(409, $e->getCode());
            }
        };
        $result = (new IsoLibrary($tools))->execute($ctx, 'isoLibraryCreate', ['name' => 'First ISO', 'url' => 'https://public.example/test.iso', 'requestId' => str_repeat('a', 32)], $settings, false);
        $this->assertSame('unknown', $result['state']);
        $this->assertSame(1, $tools->writes, 'Second quota request made no native mutation.');
        $this->assertSame(1, DB::table(IsoLibrary::TABLE)->where('state', 'pending')->count());
    }
}
