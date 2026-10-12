<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\ModuleFunctions;
use WHMCS\Module\Server\VirtFusionDirect\PackageChangeNotice;

final class ClassicUpgradeGuardTest extends TestCase
{
    private const HOOKS = __DIR__ . '/../modules/servers/VirtFusionDirect/hooks.php';

    private static function allowOnly(array $pids): \Closure
    {
        return fn (int $pid): bool => in_array($pid, $pids, true);
    }

    #[Test]
    public function filters_array_shaped_packages_and_keeps_keys(): void
    {
        $packages = [
            0 => ['pid' => 4, 'name' => 'VPS-1'],
            1 => ['pid' => 5, 'name' => 'VPS-2'],
            2 => ['pid' => 6, 'name' => 'VPS-3'],
        ];
        $out = PackageChangeNotice::filterPackages($packages, self::allowOnly([5, 6]));

        $this->assertSame([1 => ['pid' => 5, 'name' => 'VPS-2'], 2 => ['pid' => 6, 'name' => 'VPS-3']], $out['packages']);
        $this->assertSame(1, $out['hidden']);
    }

    #[Test]
    public function filters_object_shaped_packages_and_alternate_id_keys(): void
    {
        $a = (object) ['pid' => 4];
        $b = (object) ['id' => 5];
        $c = ['productid' => '6'];
        $out = PackageChangeNotice::filterPackages([$a, $b, $c], self::allowOnly([5]));

        $this->assertSame([1 => $b], $out['packages']);
        $this->assertSame(2, $out['hidden']);
    }

    public static function failOpen(): array
    {
        return [
            'no id' => [[['name' => 'mystery']]],
            'non-numeric id' => [[['pid' => 'abc']]],
            'scalar entry' => [['x']],
        ];
    }

    #[Test]
    #[DataProvider('failOpen')]
    public function unidentifiable_entries_are_kept(array $packages): void
    {
        $out = PackageChangeNotice::filterPackages($packages, fn () => false);
        $this->assertSame($packages, $out['packages']);
        $this->assertSame(0, $out['hidden']);
    }

    #[Test]
    public function a_failing_check_keeps_the_entry(): void
    {
        $packages = [['pid' => 4], ['pid' => 5]];
        $out = PackageChangeNotice::filterPackages($packages, function (int $pid) {
            if ($pid === 4) {
                throw new \RuntimeException('panel down');
            }

            return false;
        });
        $this->assertSame([['pid' => 4]], $out['packages']);
        $this->assertSame(1, $out['hidden']);
    }

    #[Test]
    public function non_iterable_lists_pass_through(): void
    {
        $this->assertSame(['packages' => null, 'hidden' => 0], PackageChangeNotice::filterPackages(null, fn () => false));
        $this->assertSame(['packages' => 'x', 'hidden' => 0], PackageChangeNotice::filterPackages('x', fn () => false));
    }

    public static function requests(): array
    {
        return [
            'classic step 2' => [['type' => 'package', 'id' => '7', 'pid' => '4', 'step' => '2'], [7, 4]],
            'classic step 3' => [['type' => 'package', 'id' => '7', 'pid' => '4', 'step' => '3', 'paymentmethod' => 'stripe'], [7, 4]],
            'newproductid key' => [['type' => 'package', 'id' => '7', 'newproductid' => '4'], [7, 4]],
            'plan list (no pid)' => [['type' => 'package', 'id' => '7'], null],
            'config options upgrade' => [['type' => 'configoptions', 'id' => '7', 'pid' => '4'], null],
            'forged non-numeric' => [['type' => 'package', 'id' => '7', 'pid' => '4 OR 1=1'], null],
            'array injection' => [['type' => 'package', 'id' => ['7'], 'pid' => '4'], null],
        ];
    }

    #[Test]
    #[DataProvider('requests')]
    public function requested_upgrade_is_read_strictly(array $request, ?array $expected): void
    {
        $got = PackageChangeNotice::requestedUpgrade($request);
        $this->assertSame($expected, $got === null ? null : [$got['serviceId'], $got['productId']]);
    }

    #[Test]
    public function notice_can_carry_the_hidden_plans_line_and_the_block(): void
    {
        $html = PackageChangeNotice::html(7, false, true, ['hidden' => true, 'blocked' => true]);
        $this->assertStringContainsString(json_encode('Plans with a smaller disk than your server are not shown, because disks can grow but never shrink.'), $html);
        $this->assertStringContainsString(json_encode(ModuleFunctions::CART_DISK_REFUSED, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $html);
        $this->assertStringContainsString('upgrade.php', $html, 'Blocked step disables the upgrade form submit.');
        $this->assertStringNotContainsString('innerHTML', $html);

        $plain = PackageChangeNotice::html(7, false, true);
        $this->assertStringContainsString('hidden: null', $plain);
        $this->assertStringContainsString('blocked: null', $plain);
    }

    #[Test]
    public function upgrade_page_hook_filters_validates_and_checks_ownership_first(): void
    {
        $hooks = (string) file_get_contents(self::HOOKS);
        $start = strpos($hooks, "add_hook('ClientAreaPageUpgrade'");
        $this->assertNotFalse($start);
        $block = substr($hooks, $start, (int) strpos($hooks, 'add_hook(', $start + 10) - $start);
        $owner = strpos($block, 'PackageChangeNotice::service(');
        $this->assertNotFalse($owner);
        foreach (['PackageChangeNotice::filterPackages(', 'PackageChangeNotice::requestedUpgrade(', 'upgradeDiskConflict(', "'errormessage'", "'upgradepackages'"] as $needle) {
            $at = strpos($block, $needle);
            $this->assertNotFalse($at, $needle);
            $this->assertLessThan($at, $owner, "{$needle} after the ownership lookup");
        }
        $this->assertStringContainsString('catch (Throwable $e)', $block);
        $this->assertStringContainsString("add_hook('PreUpgradeCheckout'", $hooks);
    }

    #[Test]
    public function upgrade_disk_conflict_reads_the_server_once_per_service(): void
    {
        $source = (string) file_get_contents(VFD_LIB . '/ModuleFunctions.php');
        $this->assertStringContainsString('$this->currentDiskMemo', $source);
    }

    #[Test]
    public function blocked_step_disables_only_the_upgrade_order_form(): void
    {
        $html = PackageChangeNotice::html(7, false, true, ['blocked' => true]);
        // The order form is the one carrying paymentmethod / step=3 or a Continue submit,
        // never the promo-code or language forms.
        $this->assertStringContainsString('[name=paymentmethod]', $html);
        $this->assertStringContainsString('input[name=step][value=\'3\']', $html);
        $this->assertMatchesRegularExpression('/\/continue\/i/', $html);
        $this->assertStringContainsString('isOrderForm', $html);
        $this->assertStringNotContainsString("querySelectorAll(\"form[action*='upgrade.php']\").forEach(function (form) {\n                form.addEventListener", $html);
        $this->assertStringContainsString('vf-plan-blocked', $html);
    }
}
