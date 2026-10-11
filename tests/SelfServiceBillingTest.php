<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use VirtFusionDirect\Tests\Support\LifecycleGateway;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\Operation;
use WHMCS\Module\Server\VirtFusionDirect\UsageUpdater;

/** Self-service balance below threshold; records every credit grant instead of calling the panel. */
final class TopOffGateway extends Module
{
    public array $grants = [];

    public mixed $grantResult = ['data' => ['id' => 1]];

    public function __construct() {}

    public function getSelfServiceUsage($serviceID)
    {
        return ['data' => ['credit' => 1]];
    }

    public function addSelfServiceCredit($serviceID, $tokens, $reference = '')
    {
        $this->grants[] = [(int) $serviceID, (float) $tokens, $reference];
        $GLOBALS['vfd_localapi_calls'][] = ['command' => 'grant', 'values' => []];

        return $this->grantResult;
    }
}

final class SelfServiceBillingTest extends TestCase
{
    private const SERVICE = 1;

    private const CLIENT = 10;

    private const TERMINATE = ['serviceid' => self::SERVICE, 'userid' => self::CLIENT, 'serverid' => 1];

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
            $t->string('domain')->nullable();
            $t->string('dedicatedip')->nullable();
            $t->string('username')->nullable();
            $t->string('password')->nullable();
        });
        DB::schema()->create('tblproducts', function ($t) {
            $t->integer('id');
            $t->string('servertype');
            $t->string('configoption9')->nullable();
        });
        DB::schema()->create('tblinvoices', function ($t) {
            $t->increments('id');
            $t->integer('userid');
            $t->string('status');
        });
        Operation::schema();
        DB::table('tblproducts')->insert(['id' => 3, 'servertype' => 'VirtFusionDirect']);
        DB::table('tblproducts')->insert(['id' => 4, 'servertype' => 'cpanel']);
        DB::table('tblhosting')->insert(['id' => self::SERVICE, 'userid' => self::CLIENT, 'server' => 1, 'packageid' => 3, 'domainstatus' => 'Active']);
        DB::table('mod_virtfusion_direct')->insert(['service_id' => self::SERVICE, 'server_id' => 100, 'server_object' => '{}']);
        CurrentUser::$client = CurrentUser::$user = CurrentUser::$admin = null;
    }

    private function topOff(TopOffGateway $gateway): void
    {
        $product = (object) ['configoption5' => 10, 'configoption6' => 25];
        $this->call(new UsageUpdater, 'maybeTopOff', [$gateway, self::SERVICE, $product]);
    }

    private function billables(): array
    {
        return array_values(array_filter($GLOBALS['vfd_localapi_calls'], static fn (array $c): bool => $c['command'] === 'AddBillableItem'));
    }

    private function ledger(): array
    {
        return DB::table(UsageUpdater::TOPOFF_TABLE)->get()->all();
    }

    private function terminator(): LifecycleGateway
    {
        $gw = new LifecycleGateway;
        $gw->routes['GET /servers/100'] = [200, ['data' => ['id' => 100, 'owner' => ['id' => 1010], 'network' => ['interfaces' => []]]]];
        $gw->routes['GET /users/10/byExtRelation'] = [200, ['data' => ['id' => 1010, 'extRelationId' => self::CLIENT]]];
        $gw->routes['DELETE /servers/100'] = [204, null];
        $gw->routes['PUT /users/10/byExtRelation'] = [200, ['data' => ['id' => 1010]]];

        return $gw;
    }

    private function puts(LifecycleGateway $gw): array
    {
        return array_values(array_filter($gw->calls, static fn (array $c): bool => $c[0] === 'PUT'));
    }

    #[Test]
    public function top_off_creates_billable_item_before_granting(): void
    {
        $GLOBALS['vfd_localapi_handler'] = static fn () => ['result' => 'success', 'billableid' => 9];
        $gateway = new TopOffGateway;
        $this->topOff($gateway);

        $commands = array_column($GLOBALS['vfd_localapi_calls'], 'command');
        $this->assertSame(['AddBillableItem', 'grant'], $commands);
        $bill = $this->billables()[0]['values'];
        $this->assertSame(self::CLIENT, $bill['clientid']);
        $this->assertSame('25.00', $bill['amount']);
        $this->assertSame('nextcron', $bill['invoiceaction']);
        $this->assertSame([[self::SERVICE, 25.0, 'Auto top-off']], $gateway->grants);
        $ledger = $this->ledger();
        $this->assertCount(1, $ledger);
        $this->assertSame('granted', $ledger[0]->state);
        $this->assertSame(9, (int) $ledger[0]->billable_id);
        $this->assertSame(date('Y-m-d'), $ledger[0]->grant_date);
        $this->assertCount(1, $this->logsFor('UsageUpdate:autoTopOff'));
    }

    #[Test]
    public function second_run_same_day_does_not_grant_again(): void
    {
        $gateway = new TopOffGateway;
        $this->topOff($gateway);
        $this->topOff($gateway);

        $this->assertCount(1, $this->billables());
        $this->assertCount(1, $gateway->grants);
        $this->assertCount(1, $this->ledger());
        $this->assertSame('already-granted-today', $this->logsFor('UsageUpdate:topOffSkipped')[0]['request']['reason']);
    }

    #[Test]
    public function unpaid_invoice_skips_grant(): void
    {
        DB::table('tblinvoices')->insert(['userid' => self::CLIENT, 'status' => 'Unpaid']);
        $gateway = new TopOffGateway;
        $this->topOff($gateway);

        $this->assertSame([], $GLOBALS['vfd_localapi_calls']);
        $this->assertSame([], $gateway->grants);
        $this->assertFalse(DB::schema()->hasTable(UsageUpdater::TOPOFF_TABLE) && $this->ledger() !== []);
        $this->assertSame('unpaid-invoices', $this->logsFor('UsageUpdate:topOffSkipped')[0]['request']['reason']);
    }

    #[Test]
    public function billing_failure_prevents_grant(): void
    {
        $GLOBALS['vfd_localapi_handler'] = static fn () => ['result' => 'error', 'message' => 'x'];
        $gateway = new TopOffGateway;
        $this->topOff($gateway);

        $this->assertSame([], $gateway->grants);
        $this->assertSame('billing_failed', $this->ledger()[0]->state);
        $this->assertCount(1, $this->logsFor('UsageUpdate:topOffBillingFailed'));
        $this->assertSame([], $this->logsFor('UsageUpdate:autoTopOff'));
    }

    #[Test]
    public function credit_failure_is_recorded_for_operator(): void
    {
        $GLOBALS['vfd_localapi_handler'] = static fn () => ['result' => 'success', 'billableid' => 9];
        $gateway = new TopOffGateway;
        $gateway->grantResult = false;
        $this->topOff($gateway);

        $this->assertSame('credit_failed', $this->ledger()[0]->state);
        $failed = $this->logsFor('UsageUpdate:topOffCreditFailed');
        $this->assertCount(1, $failed);
        $this->assertSame(9, $failed[0]['response']['billableId']);
        $this->assertSame([], $this->logsFor('UsageUpdate:autoTopOff'));
    }

    #[Test]
    public function empty_grant_body_counts_as_granted(): void
    {
        $GLOBALS['vfd_localapi_handler'] = static fn () => ['result' => 'success', 'billableid' => 9];
        $gateway = new TopOffGateway;
        $gateway->grantResult = [];
        $this->topOff($gateway);

        $this->assertSame('granted', $this->ledger()[0]->state);
        $this->assertSame([], $this->logsFor('UsageUpdate:topOffCreditFailed'));
        $this->assertCount(1, $this->logsFor('UsageUpdate:autoTopOff'));
    }

    #[Test]
    public function terminate_keeps_self_service_when_a_suspended_service_exists(): void
    {
        DB::table('tblhosting')->insert(['id' => 2, 'userid' => self::CLIENT, 'server' => 1, 'packageid' => 3, 'domainstatus' => 'Suspended']);
        $gw = $this->terminator();

        $this->assertSame('success', $gw->terminateAccount(self::TERMINATE));
        $this->assertSame([], $this->puts($gw));
    }

    #[Test]
    public function terminate_revokes_self_service_when_last_service_on_panel(): void
    {
        // Same client on another panel and a non-VFD product on this one do not count.
        DB::table('tblhosting')->insert(['id' => 2, 'userid' => self::CLIENT, 'server' => 2, 'packageid' => 3, 'domainstatus' => 'Active']);
        DB::table('tblhosting')->insert(['id' => 3, 'userid' => self::CLIENT, 'server' => 1, 'packageid' => 4, 'domainstatus' => 'Active']);
        DB::table('tblhosting')->insert(['id' => 4, 'userid' => self::CLIENT, 'server' => 1, 'packageid' => 3, 'domainstatus' => 'Terminated']);
        $gw = $this->terminator();

        $this->assertSame('success', $gw->terminateAccount(self::TERMINATE));
        $puts = $this->puts($gw);
        $this->assertCount(1, $puts);
        $this->assertSame('/users/10/byExtRelation', $puts[0][1]);
        $this->assertSame(['selfService' => 0, 'selfServiceHourlyCredit' => false], $puts[0][2]);
        $this->assertCount(1, $this->logsFor('SelfServiceRevoke'));
    }

    #[Test]
    public function terminate_revokes_when_vm_already_gone(): void
    {
        $gw = $this->terminator();
        $gw->routes['GET /servers/100'] = [404, ['msg' => 'server not found']];
        $gw->routes['DELETE /servers/100'] = [404, ['msg' => 'server not found']];

        $this->assertSame('success', $gw->terminateAccount(self::TERMINATE));
        $this->assertCount(1, $this->puts($gw));
    }

    #[Test]
    public function terminate_keeps_self_service_when_another_active_service_exists(): void
    {
        DB::table('tblhosting')->insert(['id' => 2, 'userid' => self::CLIENT, 'server' => 1, 'packageid' => 3, 'domainstatus' => 'Active']);
        $gw = $this->terminator();

        $this->assertSame('success', $gw->terminateAccount(self::TERMINATE));
        $this->assertSame([], $this->puts($gw));
    }

    #[Test]
    public function revoke_failure_does_not_fail_termination(): void
    {
        $gw = $this->terminator();
        $gw->routes['PUT /users/10/byExtRelation'] = [500, ['msg' => 'boom']];

        $this->assertSame('success', $gw->terminateAccount(self::TERMINATE));
        $this->assertCount(1, $this->puts($gw));
        $failed = $this->logsFor('SelfServiceRevoke:failed');
        $this->assertCount(1, $failed);
        $this->assertSame([], $this->logsFor('SelfServiceRevoke'));
    }
}
