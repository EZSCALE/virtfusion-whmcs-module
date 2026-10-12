<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use VirtFusionDirect\Tests\Support\LifecycleGateway;
use WHMCS\Module\Server\VirtFusionDirect\Cache;
use WHMCS\Module\Server\VirtFusionDirect\ModuleFunctions;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\PackageChangeNotice;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;

final class KeepDiskRestartTest extends TestCase
{
    private const SERVICE = 7;

    private const VM = 100;

    private const PKG = 5;

    private const PARAMS = ['serviceid' => self::SERVICE, 'userid' => 10, 'serverid' => 1, 'configoption2' => self::PKG, 'status' => 'Active'];

    private const KEEP = ['configoptions' => ['Disk on upgrade' => 'Keep my current disk size']];

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
        });
        DB::schema()->create('tblproducts', function ($t) {
            $t->integer('id');
            $t->string('servertype');
            $t->string('configoption4')->nullable();
            $t->string('configoption9')->nullable();
        });
        Operation::schema();
        DB::table('tblproducts')->insert(['id' => 3, 'servertype' => 'VirtFusionDirect']);
        DB::table('tblhosting')->insert(['id' => self::SERVICE, 'userid' => 10, 'server' => 1, 'packageid' => 3, 'domainstatus' => 'Active']);
        DB::table('mod_virtfusion_direct')->insert(['service_id' => self::SERVICE, 'server_id' => self::VM, 'server_object' => null]);
        Cache::forget('restart:1:' . self::VM);
        Cache::forget('restart:1:' . self::VM . ':' . self::SERVICE);
        $GLOBALS['vfd_activity_log'] = [];
    }

    private static function server(int $memory, int $storage, array $pending = [], ?string $state = null): array
    {
        $data = ['id' => self::VM, 'settings' => ['resources' => ['memory' => $memory, 'cpuCores' => 1, 'traffic' => 1000, 'storage' => $storage]],
            'tasks' => ['active' => false, 'actions' => ['pending' => $pending]]];
        if ($state !== null) {
            $data['remoteState'] = ['state' => $state];
        }

        return [200, ['data' => $data]];
    }

    private static function memoryPending(int $from, int $to): array
    {
        return ['id' => 11, 'action' => 'Modify memory', 'requires' => ['boot', 'restart', 'shutdown', 'poweroff'], 'payload' => ['memory' => ['current' => $from, 'new' => $to]]];
    }

    private static function diskPending(int $from, int $to): array
    {
        return ['id' => 12, 'action' => 'Resize primary disk', 'requires' => ['boot', 'restart', 'shutdown', 'poweroff'], 'payload' => ['storage' => ['current' => $from, 'new' => $to]]];
    }

    private static function package(int $memory, int $storage): array
    {
        return [200, ['data' => ['id' => self::PKG, 'memory' => $memory, 'cpuCores' => 1, 'traffic' => 1000, 'primaryStorage' => $storage]]];
    }

    private static function disks(int $size): array
    {
        return [200, ['data' => [
            ['id' => 502, 'primary' => false, 'sizeGb' => 100, 'pending' => null],
            ['id' => 947, 'primary' => true, 'sizeGb' => $size, 'pending' => null],
        ]]];
    }

    /** @param array<int,array> $serverReads GET /servers/100 responses in order; the last one repeats. */
    private function gateway(array $serverReads, array $package, ?array $live = null): LifecycleGateway
    {
        $gw = new class extends LifecycleGateway
        {
            public function legacyCapability(array $cp, string $minimum): bool
            {
                return true;
            }
        };
        $last = array_pop($serverReads);
        $gw->sequences['GET /servers/' . self::VM] = $serverReads;
        $gw->routes['GET /servers/' . self::VM] = $last;
        $gw->routes['GET /packages/' . self::PKG] = $package;
        $gw->routes['PUT /servers/' . self::VM . '/package/' . self::PKG] = [200, ['info' => []]];
        $gw->routes['GET /servers/' . self::VM . '/disks'] = self::disks(25);
        $gw->routes['DELETE /servers/' . self::VM . '/disks/947/pending'] = [204, null];
        $gw->routes['POST /servers/' . self::VM . '/power/restart'] = [200, ['data' => []]];
        if ($live !== null) {
            $gw->routes['GET /servers/' . self::VM . '?remoteState=true'] = $live;
        }

        return $gw;
    }

    private function writes(LifecycleGateway $gw): array
    {
        return array_values(array_map(fn ($c) => $c[0] . ' ' . $c[1], array_filter($gw->calls, fn ($c) => $c[0] !== 'GET')));
    }

    private function disableRestart(): void
    {
        ProviderSettings::schema();
        DB::table(ProviderSettings::TABLE)->insert(['panel_id' => 1, 'settings' => json_encode(['restartOnPackageChange' => false]), 'actor_id' => 1]);
    }

    // ----- keep-disk detection -------------------------------------------------

    public static function keepValues(): array
    {
        return [
            'display name' => ['Keep my current disk size', true],
            'raw friendly name' => ['1|Keep my current disk size', true],
            'lower case' => ['keep disk', true],
            'grow' => ['Grow disk to the plan size', false],
            'raw grow' => ['0|Grow disk to the plan size', false],
            'empty' => ['', false],
            'numeric' => [1, false],
            'null' => [null, false],
        ];
    }

    #[Test]
    #[DataProvider('keepValues')]
    public function keep_disk_value_detection($value, bool $keep): void
    {
        $this->assertSame($keep, ModuleFunctions::isKeepDiskValue($value));
    }

    #[Test]
    public function keep_disk_selection_falls_back_to_the_raw_sub_option_name(): void
    {
        DB::schema()->create('tblproductconfigoptions', function ($t) {
            $t->integer('id');
            $t->string('optionname');
        });
        DB::schema()->create('tblproductconfigoptionssub', function ($t) {
            $t->integer('id');
            $t->string('optionname');
        });
        DB::schema()->create('tblhostingconfigoptions', function ($t) {
            $t->integer('relid');
            $t->integer('configid');
            $t->integer('optionid');
        });
        DB::table('tblproductconfigoptions')->insert(['id' => 40, 'optionname' => 'Disk on upgrade']);
        DB::table('tblproductconfigoptionssub')->insert([['id' => 400, 'optionname' => '0|Grow disk to the plan size'], ['id' => 401, 'optionname' => '1|Keep my current disk size']]);
        DB::table('tblhostingconfigoptions')->insert(['relid' => self::SERVICE, 'configid' => 40, 'optionid' => 401]);

        $gw = $this->gateway([self::server(1024, 25)], self::package(2048, 50));
        // WHMCS passes only the value before the pipe for friendly option names.
        $this->assertTrue($this->call($gw, 'keepDiskSelected', [self::PARAMS + ['configoptions' => ['Disk on upgrade' => '1']]]));
        DB::table('tblhostingconfigoptions')->update(['optionid' => 400]);
        $this->assertFalse($this->call($gw, 'keepDiskSelected', [self::PARAMS + ['configoptions' => ['Disk on upgrade' => '0']]]));
        $this->assertFalse($this->call($gw, 'keepDiskSelected', [self::PARAMS]));
    }

    // ----- keep-disk package changes ------------------------------------------

    #[Test]
    public function keep_disk_upgrade_puts_then_cancels_the_pending_disk_growth(): void
    {
        $gw = $this->gateway([
            self::server(1024, 25),
            self::server(1024, 25, [self::memoryPending(1024, 2048), self::diskPending(25, 50)]),
            self::server(1024, 25, [self::memoryPending(1024, 2048)]),
            self::server(2048, 25),
        ], self::package(2048, 50), self::server(1024, 25, [self::memoryPending(1024, 2048)], 'running'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertSame([
            'PUT /servers/100/package/5',
            'DELETE /servers/100/disks/947/pending',
            'POST /servers/100/power/restart',
        ], $this->writes($gw));
        $this->assertSame([], $this->logsFor('changePackage:reductionNotApplied'));
        $this->assertCount(1, $this->logsFor('changePackage:diskGrowthCancelled'));
    }

    #[Test]
    public function keep_disk_cancel_failure_reports_incomplete_and_does_not_restart(): void
    {
        $gw = $this->gateway([
            self::server(1024, 25),
            self::server(1024, 25, [self::diskPending(25, 50)]),
        ], self::package(2048, 50), self::server(1024, 25, [self::diskPending(25, 50)], 'running'));
        $gw->routes['DELETE /servers/100/disks/947/pending'] = [500, ['msg' => 'boom']];

        $result = $gw->changePackage(self::PARAMS + self::KEEP);

        $this->assertStringStartsWith('Package change incomplete: the disk resize could not be cancelled', $result);
        $this->assertNotContains('POST /servers/100/power/restart', $this->writes($gw));
        $this->assertCount(1, $this->logsFor('changePackage:diskCancelFailed'));
    }

    #[Test]
    public function keep_disk_cancel_that_leaves_the_pending_resize_is_incomplete(): void
    {
        $gw = $this->gateway([
            self::server(1024, 25),
            self::server(1024, 25, [self::diskPending(25, 50)]),
        ], self::package(2048, 50), self::server(1024, 25, [self::diskPending(25, 50)], 'running'));

        $this->assertStringStartsWith('Package change incomplete: the disk resize could not be cancelled', $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertContains('DELETE /servers/100/disks/947/pending', $this->writes($gw));
    }

    #[Test]
    public function keep_disk_downgrade_is_allowed_when_the_disk_fits_and_restarts_a_running_server(): void
    {
        // Kept a 25 GB disk on a 50 GB plan (settings and the disk stay at 25 after the
        // cancel, as on the live panel); now moving back to the 25 GB plan with less RAM.
        $gw = $this->gateway([
            self::server(2048, 25),
            self::server(2048, 25, [self::memoryPending(2048, 1024)]),
            self::server(1024, 25),
        ], self::package(1024, 25), self::server(2048, 25, [self::memoryPending(2048, 1024)], 'running'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertSame(['PUT /servers/100/package/5', 'POST /servers/100/power/restart'], $this->writes($gw));
        $this->assertSame([], $this->logsFor('changePackage:diskDowngradeRefused'));
    }

    #[Test]
    public function keep_disk_downgrade_is_refused_when_the_disk_is_larger_than_the_plan(): void
    {
        $gw = $this->gateway([self::server(2048, 50)], self::package(1024, 25));
        $gw->routes['GET /servers/100/disks'] = self::disks(50);

        $this->assertSame(ModuleFunctions::DISK_DOWNGRADE_REFUSED, $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertSame([], $this->writes($gw));
        $this->assertSame(['serviceid' => self::SERVICE, 'requested' => 25, 'current' => 50, 'keepDisk' => true], $this->logsFor('changePackage:diskDowngradeRefused')[0]['request']);
    }

    #[Test]
    public function keep_disk_with_unreadable_disks_is_refused_before_any_write(): void
    {
        $gw = $this->gateway([self::server(1024, 25)], self::package(2048, 50));
        $gw->routes['GET /servers/100/disks'] = [500, null];

        $this->assertStringContainsString('was not attempted', $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertSame([], $this->writes($gw));
    }

    // ----- automatic restart -----------------------------------------------------

    #[Test]
    public function restart_is_sent_when_running_with_pending_actions_and_polls_until_clear(): void
    {
        $gw = $this->gateway([
            self::server(1024, 25),
            self::server(1024, 50, [self::memoryPending(1024, 2048), self::diskPending(25, 50)]),
            self::server(2048, 50),
        ], self::package(2048, 50), self::server(1024, 50, [self::memoryPending(1024, 2048)], 'running'));
        // After the verification read, two polls still show the change, then it clears.
        $gw->sequences['GET /servers/100'][] = self::server(1024, 50, [self::memoryPending(1024, 2048)]);
        $gw->sequences['GET /servers/100'][] = self::server(1024, 50, [self::memoryPending(1024, 2048)]);

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(['PUT /servers/100/package/5', 'POST /servers/100/power/restart'], $this->writes($gw));
        $this->assertNotEmpty($gw->pauses);
        $this->assertLessThanOrEqual(15, array_sum($gw->pauses));
        $this->assertCount(1, $this->logsFor('changePackage:restart'));
        $this->assertCount(1, $this->logsFor('changePackage:restartApplied'));
    }

    #[Test]
    public function pending_actions_that_never_clear_are_a_logged_warning_not_a_failure(): void
    {
        $stuck = self::server(1024, 50, [self::memoryPending(1024, 2048)]);
        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50), $stuck], self::package(2048, 50), self::server(1024, 50, [self::memoryPending(1024, 2048)], 'running'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(15, array_sum($gw->pauses), 'Never blocks the WHMCS request for more than 15 s.');
        $this->assertCount(3, $gw->pauses);
        $this->assertCount(1, $this->logsFor('changePackage:restartPending'));
    }

    #[Test]
    public function no_restart_when_the_server_is_off(): void
    {
        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50)], self::package(2048, 50), self::server(1024, 50, [self::memoryPending(1024, 2048)], 'shutoff'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(['PUT /servers/100/package/5'], $this->writes($gw));
        $this->assertSame([], $gw->pauses);
        $this->assertCount(1, $this->logsFor('changePackage:restartSkipped'));
    }

    #[Test]
    public function no_restart_without_pending_actions(): void
    {
        $gw = $this->gateway([self::server(1024, 25), self::server(1024, 25)], self::package(1024, 25), self::server(1024, 25, [], 'running'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(['PUT /servers/100/package/5'], $this->writes($gw));
    }

    #[Test]
    public function no_restart_when_the_provider_setting_is_off(): void
    {
        $this->disableRestart();
        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50)], self::package(2048, 50), self::server(1024, 50, [self::memoryPending(1024, 2048)], 'running'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(['PUT /servers/100/package/5'], $this->writes($gw));
        $this->assertNotContains('GET /servers/100?remoteState=true', array_map(fn ($c) => $c[0] . ' ' . $c[1], $gw->calls));
    }

    #[Test]
    public function restart_failure_does_not_fail_the_package_change(): void
    {
        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50)], self::package(2048, 50), self::server(1024, 50, [self::memoryPending(1024, 2048)], 'running'));
        $gw->routes['POST /servers/100/power/restart'] = [423, ['msg' => 'locked']];

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertCount(1, $this->logsFor('changePackage:restartFailed'));
        $this->assertSame([], $gw->pauses);
    }

    #[Test]
    public function restart_setting_defaults_on_and_validates_as_a_boolean(): void
    {
        $this->assertTrue(ProviderSettings::defaults()['restartOnPackageChange']);
        $this->assertFalse(ProviderSettings::validate(['restartOnPackageChange' => false])['restartOnPackageChange']);
        $this->expectException(\InvalidArgumentException::class);
        ProviderSettings::validate(['restartOnPackageChange' => 'yes']);
    }

    // ----- customer notice ---------------------------------------------------------

    #[Test]
    public function notice_markup_carries_the_restart_and_keep_disk_text_safely(): void
    {
        $html = PackageChangeNotice::html(7, true, true);
        $this->assertStringContainsString('Your server will restart automatically to apply this plan change once it is processed (usually under a minute of downtime). Please save your work.', json_decode($this->jsonArg($html, 'text'), true));
        $this->assertSame('Want to be able to downgrade later? Before upgrading, set "Disk on upgrade" to "Keep my current disk size" under Upgrade/Downgrade Options for this service.', json_decode($this->jsonArg($html, 'keepDisk'), true));
        $this->assertStringNotContainsString('confirm(', $html);
        $this->assertStringNotContainsString('innerHTML', $html);
        $this->assertStringContainsString('textContent', $html);
        $this->assertStringNotContainsString('"Keep', $html, 'Quotes inside strings are JSON_HEX encoded.');

        $plain = PackageChangeNotice::html(7, false, true);
        $this->assertNull(json_decode($this->jsonArg($plain, 'keepDisk'), true));

        $noRestart = PackageChangeNotice::html(7, false, false);
        $this->assertStringContainsString('next time the server restarts', json_decode($this->jsonArg($noRestart, 'text'), true));
    }

    #[Test]
    public function notice_covers_upgrade_and_downgrade_templates(): void
    {
        foreach (['upgrade', 'upgradesummary', 'upgrade-configure', 'viewcart', 'checkout'] as $template) {
            $this->assertTrue(PackageChangeNotice::isUpgradeTemplate($template), $template);
        }
        foreach (['clientareaproductdetails', 'cart', 'upgrade-summary', ''] as $template) {
            $this->assertFalse(PackageChangeNotice::isUpgradeTemplate($template), $template);
        }
        $hooks = (string) file_get_contents(__DIR__ . '/../modules/servers/VirtFusionDirect/hooks.php');
        $this->assertMatchesRegularExpression("/add_hook\\('ClientAreaFooterOutput'[\\s\\S]{0,800}PackageChangeNotice::isUpgradeTemplate/", $hooks);
        $this->assertStringContainsString('PackageChangeNotice::html(', $hooks);
    }

    // ----- verification against pending actions (live VM 914 shapes) --------------

    private static function liveMemory(int $from, int $to): array
    {
        return ['id' => 21, 'action' => "Memory Update ({$from}MB => {$to}MB)", 'requires' => ['boot', 'restart', 'shutdown', 'poweroff'], 'payload' => ['memory' => ['units' => 'GB', 'current' => $from, 'new' => $to]]];
    }

    private static function liveStorage(int $from, int $to): array
    {
        return ['id' => 22, 'action' => "Increase Storage ({$from}GB => {$to}GB)", 'requires' => ['boot', 'restart', 'shutdown', 'poweroff'],
            'payload' => ['storage' => ['id' => 947, 'units' => 'GB', 'current' => $from, 'new' => $to], 'disk' => ['id' => 947]]];
    }

    #[Test]
    public function live_upgrade_with_pending_memory_and_storage_succeeds_and_restarts(): void
    {
        // Right after PUT /package the settings still show 1024/25; the change is pending.
        $pending = self::server(1024, 25, [self::liveMemory(1024, 2048), self::liveStorage(25, 50)]);
        $gw = $this->gateway([self::server(1024, 25), $pending, self::server(2048, 50)], self::package(2048, 50), self::server(1024, 25, [self::liveMemory(1024, 2048), self::liveStorage(25, 50)], 'running'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(['PUT /servers/100/package/5', 'POST /servers/100/power/restart'], $this->writes($gw));
        $this->assertSame([], $this->logsFor('changePackage:reductionNotApplied'));
    }

    #[Test]
    public function live_memory_downgrade_pending_counts_as_applied_and_restarts(): void
    {
        $pending = self::server(2048, 50, [self::liveMemory(2048, 1024)]);
        $gw = $this->gateway([self::server(2048, 50), $pending, self::server(1024, 50)], self::package(1024, 50), self::server(2048, 50, [self::liveMemory(2048, 1024)], 'running'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(['PUT /servers/100/package/5', 'POST /servers/100/power/restart'], $this->writes($gw));
        $this->assertSame([], $this->logsFor('changePackage:reductionNotApplied'));
    }

    #[Test]
    public function pending_cpu_reduction_counts_as_applied(): void
    {
        $cpu = ['id' => 23, 'action' => 'CPU Update (2 => 1)', 'requires' => ['restart'], 'payload' => ['cpuCores' => ['current' => 2, 'new' => 1]]];
        $before = self::server(2048, 50);
        $before[1]['data']['settings']['resources']['cpuCores'] = 2;
        $after = self::server(2048, 50, [$cpu]);
        $after[1]['data']['settings']['resources']['cpuCores'] = 2;
        $gw = $this->gateway([$before, $after, self::server(2048, 50)], self::package(2048, 50), self::server(2048, 50, [$cpu], 'shutoff'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame([], $this->logsFor('changePackage:reductionNotApplied'));
    }

    #[Test]
    public function a_reduction_neither_applied_nor_pending_is_still_reported(): void
    {
        $gw = $this->gateway([self::server(2048, 50), self::server(2048, 50)], self::package(1024, 50), self::server(2048, 50, [], 'running'));

        $this->assertSame('Package change incomplete: reduction not applied for memory', $gw->changePackage(self::PARAMS));
        $this->assertNotContains('POST /servers/100/power/restart', $this->writes($gw));
    }

    #[Test]
    public function keep_disk_storage_is_verified_unchanged_after_the_cancel(): void
    {
        // The cancel verification passes, but the final read shows the resize back in the queue.
        $gw = $this->gateway([
            self::server(1024, 25),
            self::server(1024, 25, [self::liveMemory(1024, 2048), self::liveStorage(25, 50)]),
            self::server(1024, 25, [self::liveMemory(1024, 2048)]),
            self::server(1024, 25, [self::liveMemory(1024, 2048), self::liveStorage(25, 50)]),
        ], self::package(2048, 50), self::server(1024, 25, [self::liveMemory(1024, 2048)], 'running'));

        $this->assertSame(ModuleFunctions::DISK_CANCEL_FAILED, $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertNotContains('POST /servers/100/power/restart', $this->writes($gw));
    }

    // ----- review follow-ups: disk rule on every path -------------------------------

    #[Test]
    public function unknown_target_disk_is_refused_before_the_put(): void
    {
        $gw = $this->gateway([self::server(1024, 25)], [200, ['data' => ['id' => self::PKG, 'memory' => 2048, 'cpuCores' => 1, 'traffic' => 1000]]]);

        $this->assertSame('Package change could not be verified: the target disk size is unknown. Package change was not attempted.', $gw->changePackage(self::PARAMS));
        $this->assertSame([], $this->writes($gw));
    }

    #[Test]
    public function actual_disk_larger_than_settings_counts_as_current(): void
    {
        $gw = $this->gateway([self::server(1024, 25)], self::package(1024, 25));
        $gw->routes['GET /servers/100/disks'] = self::disks(50);

        $this->assertSame(ModuleFunctions::DISK_DOWNGRADE_REFUSED, $gw->changePackage(self::PARAMS));
        $this->assertSame([], $this->writes($gw));
        $this->assertSame(50, $this->logsFor('changePackage:diskDowngradeRefused')[0]['request']['current']);
    }

    #[Test]
    public function downgrade_refusal_alerts_the_operator(): void
    {
        $gw = $this->gateway([self::server(2048, 50)], self::package(1024, 25));
        $gw->routes['GET /servers/100/disks'] = self::disks(50);

        $gw->changePackage(self::PARAMS);

        $this->assertCount(1, $GLOBALS['vfd_activity_log']);
        $this->assertStringContainsString('#' . self::SERVICE, $GLOBALS['vfd_activity_log'][0]);
        $this->assertStringContainsString('plan change refused: disk cannot shrink — revert the product in WHMCS', $GLOBALS['vfd_activity_log'][0]);
    }

    #[Test]
    public function keep_disk_fails_when_the_primary_disk_grew_anyway(): void
    {
        $gw = $this->gateway([
            self::server(1024, 25),
            self::server(1024, 25, [self::liveStorage(25, 50)]),
            self::server(1024, 25),
        ], self::package(2048, 50), self::server(1024, 25, [], 'running'));
        $gw->sequences['GET /servers/100/disks'] = [self::disks(25), self::disks(50)];
        $gw->routes['GET /servers/100/disks'] = self::disks(50);

        $this->assertSame(ModuleFunctions::DISK_CANCEL_FAILED, $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertNotContains('POST /servers/100/power/restart', $this->writes($gw));
    }

    #[Test]
    public function keep_disk_fails_when_the_primary_disk_is_unreadable_after_the_cancel(): void
    {
        $gw = $this->gateway([
            self::server(1024, 25),
            self::server(1024, 25, [self::diskPending(25, 50)]),
            self::server(1024, 25),
        ], self::package(2048, 50), self::server(1024, 25, [], 'running'));
        $gw->sequences['GET /servers/100/disks'] = [self::disks(25)];
        $gw->routes['GET /servers/100/disks'] = [500, null];

        $this->assertSame(ModuleFunctions::DISK_CANCEL_FAILED, $gw->changePackage(self::PARAMS + self::KEEP));
    }

    #[Test]
    public function secondary_disk_pending_actions_are_ignored(): void
    {
        $secondary = ['id' => 30, 'action' => 'Increase Storage (100GB => 500GB)', 'requires' => ['restart'], 'payload' => ['storage' => ['id' => 502, 'current' => 100, 'new' => 500], 'disk' => ['id' => 502]]];
        // Normal mode: the secondary disk growth is not the server's "storage".
        $gw = $this->gateway([self::server(1024, 25, [$secondary]), self::server(1024, 25, [$secondary])], self::package(1024, 25));
        $this->assertSame('success', $gw->changePackage(self::PARAMS));

        // Keep-disk: nothing to cancel on the primary disk.
        $gw = $this->gateway([self::server(1024, 25, [$secondary]), self::server(1024, 25, [$secondary])], self::package(2048, 50));
        $this->assertSame('success', $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertNotContains('DELETE /servers/100/disks/947/pending', $this->writes($gw));
        $this->assertNotContains('DELETE /servers/100/disks/502/pending', $this->writes($gw));
    }

    #[Test]
    public function primary_disk_pending_is_matched_by_disk_id(): void
    {
        $gw = $this->gateway([
            self::server(1024, 25),
            self::server(1024, 25, [['id' => 31, 'action' => 'Increase Storage', 'payload' => ['storage' => ['id' => 947, 'current' => 25, 'new' => 50]]]]),
            self::server(1024, 25),
        ], self::package(2048, 50));

        $this->assertSame('success', $gw->changePackage(self::PARAMS + self::KEEP));
        $this->assertContains('DELETE /servers/100/disks/947/pending', $this->writes($gw));
    }

    #[Test]
    public function no_restart_for_a_suspended_server_or_inactive_service(): void
    {
        $live = self::server(1024, 50, [self::memoryPending(1024, 2048)], 'running');
        $live[1]['data']['suspended'] = true;
        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50)], self::package(2048, 50), $live);
        $gw->routes['GET /servers/100/disks'] = self::disks(25);
        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertNotContains('POST /servers/100/power/restart', $this->writes($gw));

        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50)], self::package(2048, 50), self::server(1024, 50, [self::memoryPending(1024, 2048)], 'running'));
        $this->assertSame('success', $gw->changePackage(['status' => 'Suspended'] + self::PARAMS));
        $this->assertNotContains('POST /servers/100/power/restart', $this->writes($gw));
    }

    #[Test]
    public function a_second_change_within_two_minutes_does_not_restart_again(): void
    {
        $live = self::server(1024, 50, [self::memoryPending(1024, 2048)], 'running');
        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50)], self::package(2048, 50), $live);
        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertContains('POST /servers/100/power/restart', $this->writes($gw));

        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50)], self::package(2048, 50), $live);
        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertNotContains('POST /servers/100/power/restart', $this->writes($gw));
    }

    #[Test]
    public function keep_disk_fallback_escapes_like_wildcards(): void
    {
        $this->configOptionTables();
        DB::table('tblproductconfigoptions')->insert(['id' => 41, 'optionname' => 'DiskXon upgrade']);
        DB::table('tblproductconfigoptionssub')->insert(['id' => 411, 'optionname' => '1|Keep my current disk size']);
        DB::table('tblhostingconfigoptions')->insert(['relid' => self::SERVICE, 'configid' => 41, 'optionid' => 411]);
        $gw = new class extends LifecycleGateway
        {
            protected function configOptionNaming(): array
            {
                return ['keepDisk' => 'Disk_on upgrade'];
            }
        };

        $this->assertFalse($this->call($gw, 'keepDiskSelected', [self::PARAMS + ['configoptions' => ['Disk_on upgrade' => '1']]]));
        DB::table('tblproductconfigoptions')->where('id', 41)->update(['optionname' => 'Disk_on upgrade']);
        $this->assertTrue($this->call($gw, 'keepDiskSelected', [self::PARAMS + ['configoptions' => ['Disk_on upgrade' => '1']]]));
        // Another service's rows never count.
        DB::table('tblhostingconfigoptions')->update(['relid' => 99]);
        $this->assertFalse($this->call($gw, 'keepDiskSelected', [self::PARAMS + ['configoptions' => ['Disk_on upgrade' => '1']]]));
    }

    private function configOptionTables(): void
    {
        foreach (['tblproductconfigoptions', 'tblproductconfigoptionssub'] as $table) {
            if (! DB::schema()->hasTable($table)) {
                DB::schema()->create($table, function ($t) {
                    $t->integer('id');
                    $t->string('optionname');
                });
            }
        }
        if (! DB::schema()->hasTable('tblhostingconfigoptions')) {
            DB::schema()->create('tblhostingconfigoptions', function ($t) {
                $t->integer('relid');
                $t->integer('configid');
                $t->integer('optionid');
            });
        }
    }

    // ----- cart (pre-order) guard ---------------------------------------------------

    private function cartGateway(int $disk, int $planDisk): LifecycleGateway
    {
        DB::schema()->table('tblproducts', function ($t) {
            $t->string('configoption2')->nullable();
        });
        DB::table('tblproducts')->insert([['id' => 4, 'servertype' => 'VirtFusionDirect', 'configoption2' => '6'], ['id' => 8, 'servertype' => 'other', 'configoption2' => '6']]);
        $gw = $this->gateway([self::server(1024, 25)], self::package(1024, 25));
        $gw->routes['GET /servers/100/disks'] = self::disks($disk);
        $gw->routes['GET /packages/6'] = [200, ['data' => ['id' => 6, 'memory' => 512, 'primaryStorage' => $planDisk]]];
        Cache::forget($gw->panelCacheKey($gw->getCP(1), 'pkg:6'));

        return $gw;
    }

    #[Test]
    public function cart_upgrade_to_a_smaller_disk_is_refused(): void
    {
        $gw = $this->cartGateway(25, 20);
        $this->assertSame(ModuleFunctions::CART_DISK_REFUSED, $gw->upgradeDiskConflict(10, self::SERVICE, 4));
        $this->assertSame('This plan has a smaller disk than your server. Disks can grow but never shrink, so this change is not available.', ModuleFunctions::CART_DISK_REFUSED);
    }

    #[Test]
    public function cart_upgrade_with_enough_disk_or_foreign_service_passes(): void
    {
        $gw = $this->cartGateway(25, 25);
        $this->assertNull($gw->upgradeDiskConflict(10, self::SERVICE, 4));
        $this->assertNull($gw->upgradeDiskConflict(20, self::SERVICE, 4), 'Not the client\'s service.');
        $this->assertNull($gw->upgradeDiskConflict(10, self::SERVICE, 8), 'Not a VirtFusionDirect product.');
        $this->assertSame([], $this->writes($gw));
    }

    #[Test]
    public function cart_guard_fails_open_when_the_panel_is_unreadable(): void
    {
        $gw = $this->cartGateway(25, 20);
        $gw->routes['GET /servers/100/disks'] = [500, null];
        $gw->sequences['GET /servers/100'] = [];
        $gw->routes['GET /servers/100'] = [500, null];
        $this->assertNull($gw->upgradeDiskConflict(10, self::SERVICE, 4));
    }

    public static function cartShapes(): array
    {
        $service = new \stdClass;
        $service->id = 7;
        $product = new \stdClass;
        $product->id = 4;
        $item = new \stdClass;
        $item->type = 'service';
        $item->service = $service;
        $item->newProduct = $product;
        $addon = clone $item;
        $addon->type = 'addon';

        return [
            'hook vars objects' => [['upgrades' => [$item, $addon]], null, [[7, 4]]],
            'hook vars arrays' => [['upgrades' => [['type' => 'service', 'service' => ['id' => 7], 'newProduct' => ['id' => 4]]]], null, [[7, 4]]],
            'session entity shape' => [[], ['upgrades' => [['upgrade_type' => 'service', 'entity_id' => '7', 'target_entity_id' => '4']]], [[7, 4]]],
            'session service shape' => [[], ['upgrades' => [['type' => 'service', 'serviceid' => 7, 'productid' => 4], ['type' => 'addon', 'serviceid' => 9, 'productid' => 4]]], [[7, 4]]],
            'nothing' => [[], ['products' => []], []],
            'garbage' => [['upgrades' => 'x'], ['upgrades' => [null, 5, ['type' => 'service']]], []],
        ];
    }

    #[Test]
    #[DataProvider('cartShapes')]
    public function cart_upgrade_items_are_read_defensively(array $vars, ?array $cart, array $expected): void
    {
        $this->assertSame($expected, array_map(fn ($u) => [$u['serviceId'], $u['productId']], PackageChangeNotice::cartUpgrades($vars, $cart)));
    }

    #[Test]
    public function checkout_hook_runs_the_upgrade_disk_guard(): void
    {
        $hooks = (string) file_get_contents(__DIR__ . '/../modules/servers/VirtFusionDirect/hooks.php');
        $start = strpos($hooks, "add_hook('ShoppingCartValidateCheckout'");
        $block = substr($hooks, $start, 3000);
        $this->assertStringContainsString('PackageChangeNotice::cartUpgrades(', $block);
        $this->assertStringContainsString('upgradeDiskConflict(', $block);
        $this->assertLessThan(strpos($block, "isset(\$_SESSION['cart']['products'])"), strpos($block, 'upgradeDiskConflict('), 'Upgrade-only carts are checked too.');
    }

    // ----- notice service resolution -----------------------------------------------

    #[Test]
    public function notice_resolves_the_service_on_every_upgrade_step(): void
    {
        $upgrade = new \stdClass;
        $upgrade->service = (object) ['id' => self::SERVICE];
        $this->assertSame(self::SERVICE, (int) PackageChangeNotice::service(['templatefile' => 'upgrade-configure', 'serviceToBeUpgraded' => $upgrade], 10)->id);
        $this->assertSame(self::SERVICE, (int) PackageChangeNotice::service(['templatefile' => 'upgrade-configure', 'serviceToBeUpgraded' => (object) ['id' => self::SERVICE]], 10)->id);
        $this->assertSame(self::SERVICE, (int) PackageChangeNotice::service(['templatefile' => 'viewcart', 'upgrades' => [['type' => 'service', 'service' => ['id' => self::SERVICE], 'newProduct' => ['id' => 3]]]], 10)->id);
        $this->assertNull(PackageChangeNotice::service(['templatefile' => 'viewcart', 'upgrades' => []], 10));
        $this->assertNull(PackageChangeNotice::service(['templatefile' => 'upgrade-configure', 'serviceToBeUpgraded' => $upgrade], 20), 'Another client.');
    }

    /** Extract the JSON literal assigned to `name:` in the notice's config object. */
    private function jsonArg(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/' . $name . ': (null|"(?:[^"\\\\]|\\\\.)*")/', $html, $m), $name);

        return $m[1];
    }
}
