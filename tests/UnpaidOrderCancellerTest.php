<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\ProviderSettings;
use WHMCS\Module\Server\VirtFusionDirect\UnpaidOrderCanceller;

/**
 * Abandoned unpaid VirtFusion-only orders are cancelled after a per-panel number of
 * days so a stale Pending service no longer fixes the client's billing type.
 * Mixed, partly provisioned and (partly) paid orders are always left for a human.
 */
final class UnpaidOrderCancellerTest extends TestCase
{
    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $s = DB::schema();
        $s->create('tblservers', function ($t) {
            $t->integer('id');
            $t->string('type');
            $t->integer('disabled')->default(0);
        });
        $s->create('tblproducts', function ($t) {
            $t->integer('id');
            $t->string('servertype');
        });
        $s->create('tblorders', function ($t) {
            $t->integer('id');
            $t->integer('userid');
            $t->string('status');
            $t->dateTime('date');
            $t->integer('invoiceid')->default(0);
        });
        $s->create('tblinvoices', function ($t) {
            $t->integer('id');
            $t->string('status');
            $t->decimal('credit', 10, 2)->default(0);
            $t->decimal('total', 10, 2)->default(0);
        });
        $s->create('tblhosting', function ($t) {
            $t->integer('id');
            $t->integer('orderid');
            $t->integer('userid');
            $t->integer('server');
            $t->integer('packageid');
            $t->string('domainstatus');
        });
        foreach (['tblhostingaddons', 'tbldomains', 'tblupgrades'] as $table) {
            $s->create($table, function ($t) {
                $t->integer('id');
                $t->integer('orderid');
            });
        }
        $s->create('tblaccounts', function ($t) {
            $t->integer('id');
            $t->integer('invoiceid');
            $t->decimal('amountin', 10, 2)->default(0);
        });
        ProviderSettings::schema();
        DB::table('tblservers')->insert([['id' => 1, 'type' => 'VirtFusionDirect'], ['id' => 2, 'type' => 'VirtFusionDirect'], ['id' => 9, 'type' => 'cpanel']]);
        DB::table('tblproducts')->insert([['id' => 10, 'servertype' => 'VirtFusionDirect'], ['id' => 20, 'servertype' => 'cpanel']]);
    }

    /** Insert a Pending order with one item per entry of $items ([product, status, panel]). */
    private function order(int $ageDays, array $items = [[10, 'Pending', 1]], string $invoiceStatus = 'Unpaid', bool $withInvoice = true): int
    {
        $id = $this->nextId++;
        $invoice = $withInvoice ? 500 + $id : 0;
        if ($withInvoice) {
            DB::table('tblinvoices')->insert(['id' => $invoice, 'status' => $invoiceStatus, 'total' => 10]);
        }
        DB::table('tblorders')->insert(['id' => $id, 'userid' => 7, 'status' => 'Pending',
            'date' => date('Y-m-d H:i:s', time() - $ageDays * 86400 - 60), 'invoiceid' => $invoice]);
        foreach ($items as $n => [$product, $status, $panel]) {
            DB::table('tblhosting')->insert(['id' => $id * 100 + $n, 'orderid' => $id, 'userid' => 7, 'server' => $panel,
                'packageid' => $product, 'domainstatus' => $status]);
        }

        return $id;
    }

    private function setDays(int $panel, int $days): void
    {
        DB::table(ProviderSettings::TABLE)->insert(['panel_id' => $panel,
            'settings' => json_encode(['unpaidOrderCancelDays' => $days]), 'actor_id' => 1]);
    }

    /** @return list<int> */
    private function cancelled(): array
    {
        $ids = [];
        foreach ($GLOBALS['vfd_localapi_calls'] as $call) {
            if ($call['command'] === 'CancelOrder') {
                $ids[] = $call['values']['orderid'];
            }
        }

        return $ids;
    }

    #[Test]
    public function default_is_three_days(): void
    {
        $this->assertSame(3, ProviderSettings::defaults()['unpaidOrderCancelDays']);
    }

    #[Test]
    public function old_unpaid_vfd_only_order_is_cancelled(): void
    {
        $id = $this->order(4, [[10, 'Pending', 1], [10, 'Pending', 1]]);

        $result = (new UnpaidOrderCanceller)->run();

        $this->assertSame([$id], $this->cancelled());
        $call = $GLOBALS['vfd_localapi_calls'][0];
        $this->assertSame(['orderid' => $id, 'cancelsub' => false, 'noemail' => true], $call['values']);
        $this->assertSame(1, $result['cancelled']);
        $summary = $this->logsFor('UnpaidOrderCanceller:run');
        $this->assertCount(1, $summary);
        $this->assertSame([$id], $summary[0]['response']['orderIds']);
    }

    #[Test]
    public function order_without_invoice_is_cancelled(): void
    {
        $id = $this->order(5, withInvoice: false);

        (new UnpaidOrderCanceller)->run();

        $this->assertSame([$id], $this->cancelled());
        $this->assertSame([], array_values(array_filter($GLOBALS['vfd_localapi_calls'], static fn ($c) => $c['command'] === 'UpdateInvoice')));
    }

    #[Test]
    public function invoice_left_unpaid_by_cancel_order_is_cancelled_explicitly(): void
    {
        $id = $this->order(4);

        (new UnpaidOrderCanceller)->run();

        $this->assertSame(['CancelOrder', 'UpdateInvoice'], array_column($GLOBALS['vfd_localapi_calls'], 'command'));
        $this->assertSame(['invoiceid' => 500 + $id, 'status' => 'Cancelled'], $GLOBALS['vfd_localapi_calls'][1]['values']);
    }

    #[Test]
    public function invoice_already_cancelled_by_cancel_order_is_not_updated_again(): void
    {
        $id = $this->order(4);
        $GLOBALS['vfd_localapi_handler'] = static function ($command, $values) {
            if ($command === 'CancelOrder') {
                DB::table('tblinvoices')->where('id', 500 + $values['orderid'])->update(['status' => 'Cancelled']);
            }

            return ['result' => 'success'];
        };

        (new UnpaidOrderCanceller)->run();

        $this->assertSame(['CancelOrder'], array_column($GLOBALS['vfd_localapi_calls'], 'command'));
        $this->assertSame([$id], $this->cancelled());
    }

    #[Test]
    public function younger_order_is_skipped(): void
    {
        $this->order(2);

        $result = (new UnpaidOrderCanceller)->run();

        $this->assertSame([], $this->cancelled());
        $this->assertSame(0, $result['cancelled']);
    }

    #[Test]
    public function zero_days_disables_the_job(): void
    {
        $this->setDays(1, 0);
        $this->order(30);

        (new UnpaidOrderCanceller)->run();

        $this->assertSame([], $GLOBALS['vfd_localapi_calls']);
    }

    #[Test]
    public function mixed_orders_are_skipped(): void
    {
        $this->order(10, [[10, 'Pending', 1], [20, 'Pending', 9]]);
        $domain = $this->order(10);
        DB::table('tbldomains')->insert(['id' => 1, 'orderid' => $domain]);
        $addon = $this->order(10);
        DB::table('tblhostingaddons')->insert(['id' => 1, 'orderid' => $addon]);
        $upgrade = $this->order(10);
        DB::table('tblupgrades')->insert(['id' => 1, 'orderid' => $upgrade]);
        $this->order(10, [[10, 'Pending', 1], [10, 'Pending', 2]]);

        $result = (new UnpaidOrderCanceller)->run();

        $this->assertSame([], $this->cancelled());
        $this->assertSame(0, $result['cancelled']);
        $this->assertGreaterThanOrEqual(4, $result['skipped']);
    }

    #[Test]
    public function partly_provisioned_order_is_skipped(): void
    {
        $this->order(10, [[10, 'Pending', 1], [10, 'Active', 1]]);
        $this->order(10, [[10, 'Active', 1]]);

        (new UnpaidOrderCanceller)->run();

        $this->assertSame([], $this->cancelled());
    }

    #[Test]
    public function paid_or_partly_paid_invoice_is_skipped(): void
    {
        $this->order(10, invoiceStatus: 'Paid');
        $partial = $this->order(10);
        DB::table('tblaccounts')->insert(['id' => 1, 'invoiceid' => 500 + $partial, 'amountin' => 2]);
        $credit = $this->order(10);
        DB::table('tblinvoices')->where('id', 500 + $credit)->update(['credit' => 1]);
        $this->order(10, invoiceStatus: 'Cancelled');

        (new UnpaidOrderCanceller)->run();

        $this->assertSame([], $this->cancelled());
    }

    #[Test]
    public function each_panel_uses_its_own_setting(): void
    {
        $this->setDays(1, 0);
        $this->setDays(2, 7);
        $this->order(10, [[10, 'Pending', 1]]);
        $young = $this->order(5, [[10, 'Pending', 2]]);
        $old = $this->order(8, [[10, 'Pending', 2]]);

        (new UnpaidOrderCanceller)->run();

        $this->assertSame([$old], $this->cancelled());
        $this->assertNotContains($young, $this->cancelled());
    }

    #[Test]
    public function cancel_order_error_is_logged_and_others_continue(): void
    {
        $bad = $this->order(10);
        $good = $this->order(10);
        $GLOBALS['vfd_localapi_handler'] = static fn ($command, $values) => $command === 'CancelOrder' && $values['orderid'] === $bad
            ? ['result' => 'error', 'message' => 'Order is not pending']
            : ['result' => 'success'];

        $result = (new UnpaidOrderCanceller)->run();

        $this->assertSame([$bad, $good], $this->cancelled());
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['cancelled']);
        $failed = $this->logsFor('UnpaidOrderCanceller:failed');
        $this->assertCount(1, $failed);
        $this->assertSame($bad, $failed[0]['request']['orderid']);
        $this->assertStringContainsString('Order is not pending', (string) $failed[0]['response']);
    }

    #[Test]
    public function cancellations_are_capped_per_run(): void
    {
        for ($i = 0; $i < UnpaidOrderCanceller::MAX_PER_RUN + 5; $i++) {
            $this->order(10, withInvoice: false);
        }

        $result = (new UnpaidOrderCanceller)->run();

        $this->assertCount(UnpaidOrderCanceller::MAX_PER_RUN, $this->cancelled());
        $this->assertSame(100, UnpaidOrderCanceller::MAX_PER_RUN);
        $this->assertSame(100, $result['cancelled']);
    }

    #[Test]
    public function summary_log_contains_no_client_identifiers(): void
    {
        $this->order(10);

        (new UnpaidOrderCanceller)->run();

        $summary = json_encode($this->logsFor('UnpaidOrderCanceller:run'));
        $this->assertStringNotContainsString('userid', $summary);
    }

    #[Test]
    public function setting_accepts_zero_to_365(): void
    {
        foreach ([0, 3, 365] as $days) {
            $this->assertSame($days, ProviderSettings::validate(['unpaidOrderCancelDays' => $days])['unpaidOrderCancelDays']);
        }
    }

    #[Test]
    #[DataProvider('invalidDays')]
    public function setting_rejects_out_of_range_or_non_integer(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderSettings::validate(['unpaidOrderCancelDays' => $value]);
    }

    public static function invalidDays(): array
    {
        return ['negative' => [-1], 'too large' => [366], 'text' => ['abc'], 'float' => [2.5], 'bool' => [true], 'null' => [null]];
    }

    #[Test]
    public function daily_cron_runs_the_canceller_in_its_own_try_catch(): void
    {
        $hooks = file_get_contents(VFD_LIB . '/../hooks.php');

        $this->assertMatchesRegularExpression(
            "/add_hook\\('DailyCronJob', 1, function \\(\\\$vars\\) \\{(?:(?!\\n\\}\\);).)*?\\} catch \\(Throwable \\\$e\\) \\{(?:(?!\\n\\}\\);).)*?\\}\\s*try \\{\\s*\\(new UnpaidOrderCanceller\\)->run\\(\\);\\s*\\} catch \\(Throwable \\\$e\\) \\{\\s*Log::insert\\('UnpaidOrderCanceller:DailyCronJob'/s",
            $hooks,
        );
    }

    #[Test]
    public function provider_settings_ui_labels_the_setting(): void
    {
        $js = file_get_contents(VFD_LIB . '/../templates/js/tools.js');

        $this->assertStringContainsString('"Cancel unpaid VirtFusion orders after (days, 0 = off)"', $js);
        $this->assertStringContainsString('unpaidOrderCancelDays', $js);
    }
}
