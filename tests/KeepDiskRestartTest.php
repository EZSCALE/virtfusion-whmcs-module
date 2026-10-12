<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use VirtFusionDirect\Tests\Support\LifecycleGateway;
use WHMCS\Module\Server\VirtFusionDirect\ModuleFunctions;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\PackageChangeNotice;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;

final class KeepDiskRestartTest extends TestCase
{
    private const SERVICE = 7;

    private const VM = 100;

    private const PKG = 5;

    private const PARAMS = ['serviceid' => self::SERVICE, 'userid' => 10, 'serverid' => 1, 'configoption2' => self::PKG];

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
            ['id' => 501, 'primary' => true, 'sizeGb' => $size, 'pending' => null],
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
        $gw->routes['DELETE /servers/' . self::VM . '/disks/501/pending'] = [204, null];
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
            'DELETE /servers/100/disks/501/pending',
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
        $gw->routes['DELETE /servers/100/disks/501/pending'] = [500, ['msg' => 'boom']];

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
        $this->assertContains('DELETE /servers/100/disks/501/pending', $this->writes($gw));
    }

    #[Test]
    public function keep_disk_downgrade_is_allowed_when_the_disk_fits_and_restarts_a_running_server(): void
    {
        // Kept a 25 GB disk on a 50 GB plan; now moving back to the 25 GB plan with less RAM.
        $gw = $this->gateway([
            self::server(2048, 50),
            self::server(2048, 50, [self::memoryPending(2048, 1024)]),
            self::server(1024, 50),
        ], self::package(1024, 25), self::server(2048, 50, [self::memoryPending(2048, 1024)], 'running'));

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
        $this->assertLessThanOrEqual(120, array_sum($gw->pauses));
        $this->assertCount(1, $this->logsFor('changePackage:restart'));
        $this->assertCount(1, $this->logsFor('changePackage:restartApplied'));
    }

    #[Test]
    public function pending_actions_that_never_clear_are_a_logged_warning_not_a_failure(): void
    {
        $stuck = self::server(1024, 50, [self::memoryPending(1024, 2048)]);
        $gw = $this->gateway([self::server(1024, 25), self::server(2048, 50), $stuck], self::package(2048, 50), self::server(1024, 50, [self::memoryPending(1024, 2048)], 'running'));

        $this->assertSame('success', $gw->changePackage(self::PARAMS));
        $this->assertSame(120, array_sum($gw->pauses));
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
        $this->assertStringContainsString('Keep my current disk size', json_decode($this->jsonArg($html, 'keepDisk'), true));
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
        foreach (['upgrade', 'upgradesummary', 'upgrade-configure', 'upgrade-summary'] as $template) {
            $this->assertTrue(PackageChangeNotice::isUpgradeTemplate($template), $template);
        }
        foreach (['clientareaproductdetails', 'cart', 'viewcart', ''] as $template) {
            $this->assertFalse(PackageChangeNotice::isUpgradeTemplate($template), $template);
        }
        $hooks = (string) file_get_contents(__DIR__ . '/../modules/servers/VirtFusionDirect/hooks.php');
        $this->assertMatchesRegularExpression("/add_hook\\('ClientAreaFooterOutput'[\\s\\S]{0,800}PackageChangeNotice::isUpgradeTemplate/", $hooks);
        $this->assertStringContainsString('PackageChangeNotice::html(', $hooks);
    }

    /** Extract the JSON literal assigned to `name:` in the notice's config object. */
    private function jsonArg(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/' . $name . ': (null|"(?:[^"\\\\]|\\\\.)*")/', $html, $m), $name);

        return $m[1];
    }
}
