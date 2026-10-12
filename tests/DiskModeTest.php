<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use VirtFusionDirect\Tests\Support\LifecycleGateway;
use WHMCS\Module\Server\VirtFusionDirect\DiskMode;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\PackageChangeNotice;

final class DiskModeTest extends TestCase
{
    private const SERVICE = 7;

    private const CLIENT = __DIR__ . '/../modules/servers/VirtFusionDirect/client.php';

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        DB::schema()->create('tblhosting', function ($t) {
            $t->integer('id');
            $t->integer('userid');
            $t->integer('packageid');
            $t->integer('server')->default(1);
            $t->string('domainstatus');
        });
        DB::schema()->create('tblproductconfiglinks', function ($t) {
            $t->integer('gid');
            $t->integer('pid');
        });
        DB::schema()->create('tblproductconfigoptions', function ($t) {
            $t->integer('id');
            $t->integer('gid');
            $t->string('optionname');
            $t->integer('hidden')->default(0);
        });
        DB::schema()->create('tblproductconfigoptionssub', function ($t) {
            $t->integer('id');
            $t->integer('configid');
            $t->string('optionname');
            $t->integer('hidden')->default(0);
        });
        DB::schema()->create('tblhostingconfigoptions', function ($t) {
            $t->integer('relid');
            $t->integer('configid');
            $t->integer('optionid');
        });
        DB::schema()->create('tblupgrades', function ($t) {
            $t->integer('id');
            $t->integer('relid');
            $t->string('type');
            $t->string('status');
        });
        DB::table('tblhosting')->insert(['id' => self::SERVICE, 'userid' => 10, 'packageid' => 3, 'domainstatus' => 'Active']);
        DB::table('tblproductconfiglinks')->insert(['gid' => 125, 'pid' => 3]);
        // Production shape: option 480 in group 125, hidden, with grow|… / keep|… sub-options.
        DB::table('tblproductconfigoptions')->insert([
            ['id' => 479, 'gid' => 125, 'optionname' => 'IPv4', 'hidden' => 0],
            ['id' => 480, 'gid' => 125, 'optionname' => 'Disk on upgrade', 'hidden' => 1],
        ]);
        DB::table('tblproductconfigoptionssub')->insert([
            ['id' => 2462, 'configid' => 480, 'optionname' => 'grow|Grow disk to the plan size'],
            ['id' => 2463, 'configid' => 480, 'optionname' => 'keep|Keep my current disk size (allows downgrading later)'],
            ['id' => 2400, 'configid' => 479, 'optionname' => '1|1 IPv4'],
        ]);
        $GLOBALS['vfd_localapi_handler'] = function ($command, $values) {
            if ($command === 'UpdateClientProduct') {
                $sel = unserialize(base64_decode($values['configoptions']));
                DB::table('tblhostingconfigoptions')->updateOrInsert(['relid' => $values['serviceid'], 'configid' => key($sel)], ['optionid' => current($sel)]);
            }

            return ['result' => 'success'];
        };
    }

    private function module(?string $lock = null): Module
    {
        return new class($lock) extends Module
        {
            public function __construct(private ?string $lock) {}

            public function unresolvedOperation(int $serviceID): ?string
            {
                return $this->lock;
            }
        };
    }

    #[Test]
    public function resolves_the_hidden_option_and_both_sub_options(): void
    {
        $this->assertSame(['optionId' => 480, 'growId' => 2462, 'keepId' => 2463], DiskMode::option(self::SERVICE, 'Disk on upgrade'));
        $this->assertNull(DiskMode::option(self::SERVICE, 'Something else'));
        DB::table('tblproductconfiglinks')->delete();
        $this->assertNull(DiskMode::option(self::SERVICE, 'Disk on upgrade'), 'Option group not linked to this product.');
    }

    #[Test]
    public function sub_options_fall_back_to_keep_in_the_name(): void
    {
        DB::table('tblproductconfigoptionssub')->where('id', 2462)->update(['optionname' => '0|Grow disk to the plan size']);
        DB::table('tblproductconfigoptionssub')->where('id', 2463)->update(['optionname' => '1|Keep my current disk size']);
        $this->assertSame(['optionId' => 480, 'growId' => 2462, 'keepId' => 2463], DiskMode::option(self::SERVICE, 'Disk on upgrade'));
    }

    #[Test]
    public function no_stored_value_reads_as_grow(): void
    {
        $this->assertSame(['status' => 200, 'data' => ['mode' => 'grow']], DiskMode::get(self::SERVICE, 'Disk on upgrade'));
        DB::table('tblhostingconfigoptions')->insert(['relid' => self::SERVICE, 'configid' => 480, 'optionid' => 2463]);
        $this->assertSame(['status' => 200, 'data' => ['mode' => 'keep']], DiskMode::get(self::SERVICE, 'Disk on upgrade'));
    }

    #[Test]
    public function set_saves_through_local_api_and_returns_the_stored_mode(): void
    {
        $result = DiskMode::set($this->module(), self::SERVICE, 'Disk on upgrade', 'keep');

        $this->assertSame(['status' => 200, 'data' => ['mode' => 'keep']], $result);
        $call = $GLOBALS['vfd_localapi_calls'][0];
        $this->assertSame('UpdateClientProduct', $call['command']);
        $this->assertSame(self::SERVICE, $call['values']['serviceid']);
        $this->assertSame([480 => 2463], unserialize(base64_decode($call['values']['configoptions'])));

        $this->assertSame(['status' => 200, 'data' => ['mode' => 'grow']], DiskMode::set($this->module(), self::SERVICE, 'Disk on upgrade', 'grow'));
        $this->assertSame([480 => 2462], unserialize(base64_decode($GLOBALS['vfd_localapi_calls'][1]['values']['configoptions'])));
        $this->assertCount(2, $this->logsFor('diskModeSet'));
        $this->assertStringNotContainsString('@', json_encode($GLOBALS['vfd_test_log']));
    }

    public static function invalidModes(): array
    {
        return [['Keep'], ['keep '], [''], ['1'], [null], [['keep']], [true]];
    }

    #[Test]
    #[DataProvider('invalidModes')]
    public function only_exact_grow_or_keep_is_accepted($mode): void
    {
        $this->assertSame(400, DiskMode::set($this->module(), self::SERVICE, 'Disk on upgrade', $mode)['status']);
        $this->assertSame([], $GLOBALS['vfd_localapi_calls']);
    }

    #[Test]
    public function product_without_the_option_is_404(): void
    {
        $this->assertSame(404, DiskMode::get(self::SERVICE, 'Missing option')['status']);
        $this->assertSame(404, DiskMode::set($this->module(), self::SERVICE, 'Missing option', 'keep')['status']);
        $this->assertSame([], $GLOBALS['vfd_localapi_calls']);
    }

    #[Test]
    public function a_pending_plan_change_or_unresolved_operation_is_409(): void
    {
        DB::table('tblupgrades')->insert(['id' => 1, 'relid' => self::SERVICE, 'type' => 'package', 'status' => 'Pending']);
        $result = DiskMode::set($this->module(), self::SERVICE, 'Disk on upgrade', 'keep');
        $this->assertSame(['status' => 409, 'errors' => 'Finish or cancel the pending plan change first.'], $result);

        DB::table('tblupgrades')->update(['status' => 'Completed']);
        $this->assertSame(409, DiskMode::set($this->module('op-1'), self::SERVICE, 'Disk on upgrade', 'keep')['status']);
        $this->assertSame([], $GLOBALS['vfd_localapi_calls']);
    }

    #[Test]
    public function local_api_failure_is_502(): void
    {
        $GLOBALS['vfd_localapi_handler'] = fn () => ['result' => 'error', 'message' => 'Service ID Not Found'];
        $this->assertSame(['status' => 502, 'errors' => 'Could not save the setting. Please try again.'], DiskMode::set($this->module(), self::SERVICE, 'Disk on upgrade', 'keep'));
    }

    #[Test]
    public function keep_disk_selected_reads_a_hidden_option_absent_from_params(): void
    {
        DB::table('tblhostingconfigoptions')->insert(['relid' => self::SERVICE, 'configid' => 480, 'optionid' => 2463]);
        $gw = new LifecycleGateway;
        // WHMCS may or may not pass hidden options in $params['configoptions'].
        $this->assertTrue($this->call($gw, 'keepDiskSelected', [['serviceid' => self::SERVICE]]));
        $this->assertTrue($this->call($gw, 'keepDiskSelected', [['serviceid' => self::SERVICE, 'configoptions' => ['IPv4' => '1']]]));
        $this->assertTrue($this->call($gw, 'keepDiskSelected', [['serviceid' => self::SERVICE, 'configoptions' => ['Disk on upgrade' => 'keep']]]));
        DB::table('tblhostingconfigoptions')->update(['optionid' => 2462]);
        $this->assertFalse($this->call($gw, 'keepDiskSelected', [['serviceid' => self::SERVICE]]));
        $this->assertFalse($this->call($gw, 'keepDiskSelected', [['serviceid' => self::SERVICE, 'configoptions' => ['Disk on upgrade' => 'grow']]]));
    }

    #[Test]
    public function upgrade_notice_states_the_current_mode_with_a_service_page_link(): void
    {
        $grow = PackageChangeNotice::html(self::SERVICE, 'grow', true);
        $this->assertSame("Disk on plan changes: Grow (your disk grows to the new plan's size and can't shrink later). Change this on your service page before choosing a plan.", $this->jsonArg($grow, 'diskMode'));
        $this->assertSame('clientarea.php?action=productdetails&id=7#vf-sec-diskmode', $this->jsonArg($grow, 'diskModeUrl'));
        $keep = PackageChangeNotice::html(self::SERVICE, 'keep', true);
        $this->assertSame('Disk on plan changes: Keep current size (you can downgrade later). Change this on your service page before choosing a plan.', $this->jsonArg($keep, 'diskMode'));
        $this->assertNull($this->jsonArg(PackageChangeNotice::html(self::SERVICE, null, true), 'diskMode'));
        $this->assertStringNotContainsString('innerHTML', $keep);
    }

    private function jsonArg(string $html, string $name)
    {
        $this->assertSame(1, preg_match('/[{ ]' . $name . ': (null|"(?:[^"\\\\]|\\\\.)*")/', $html, $m), $name);

        return json_decode($m[1], true);
    }

    // ----- client.php contracts --------------------------------------------------------

    private function caseBlock(string $action): string
    {
        $source = (string) file_get_contents(self::CLIENT);
        $start = strpos($source, "case '" . $action . "':");
        $this->assertNotFalse($start, $action);
        $ends = array_filter([strpos($source, "\n        case '", $start + 1), strpos($source, "\n        default:", $start + 1)], fn ($p) => $p !== false);

        return substr($source, $start, min($ends) - $start);
    }

    #[Test]
    public function disk_mode_set_guards_run_before_any_write(): void
    {
        $block = $this->caseBlock('diskModeSet');
        $write = strpos($block, 'DiskMode::set(');
        $this->assertNotFalse($write);
        $previous = -1;
        foreach (['$vf->requirePost()', '$vf->requireSameOrigin()', '$vf->validateUserOwnsService($serviceID)', "\$vf->requireServiceStatus(\$serviceID, ['Active'])", "\$vf->requireRateLimit('diskModeSet:' . \$serviceID, 5)"] as $guard) {
            $at = strpos($block, $guard);
            $this->assertNotFalse($at, $guard);
            $this->assertGreaterThan($previous, $at, $guard);
            $this->assertLessThan($write, $at, $guard);
            $previous = $at;
        }
        $this->assertStringContainsString("'service <> owner mismatch'], true, true, 403);", $block);
    }

    #[Test]
    public function disk_mode_get_is_owned_and_read_only(): void
    {
        $block = $this->caseBlock('diskModeGet');
        $this->assertLessThan(strpos($block, 'DiskMode::get('), strpos($block, '$vf->validateUserOwnsService($serviceID)'));
        $this->assertStringNotContainsString('DiskMode::set(', $block);
        $this->assertStringNotContainsString('requirePost', $block);
    }

    #[Test]
    public function service_page_has_the_panel_and_sidebar_entry(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../modules/servers/VirtFusionDirect/templates/overview.tpl');
        $this->assertStringContainsString('id="vf-sec-diskmode"', $tpl);
        $this->assertStringContainsString("Upgrades grow your disk to the new plan's size. Disks can never shrink, so you can't downgrade below that size later.", $tpl);
        $this->assertStringContainsString("Upgrades keep your current disk size. You get the new plan's CPU and RAM and can downgrade later.", $tpl);
        $hooks = (string) file_get_contents(__DIR__ . '/../modules/servers/VirtFusionDirect/hooks.php');
        $this->assertStringContainsString("'vf-sec-diskmode'", $hooks);
        $js = (string) file_get_contents(__DIR__ . '/../modules/servers/VirtFusionDirect/templates/js/module.js');
        $this->assertStringContainsString('"diskModeSet"', $js);
        $this->assertStringNotContainsString('confirm(', substr($js, (int) strpos($js, 'function vfLoadDiskMode')));
    }
}
