<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use VirtFusionDirect\Tests\Support\LifecycleGateway;
use WHMCS\Module\Server\VirtFusionDirect\AccountType;
use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Operation;

/** S5: a client account has one billing type; checkout and provisioning refuse a mismatch. */
final class AccountBillingTypeTest extends TestCase
{
    private const CLIENT = 10;

    private const SERVICE = 7;

    private const PARAMS = ['serviceid' => self::SERVICE, 'userid' => self::CLIENT, 'serverid' => 1,
        'configoption1' => 1, 'configoption2' => 2, 'configoption3' => 1];

    /** Product ids by billing type: 20 normal, 21 hourly, 22 pack (blank mode), 23 both, 24 cPanel. */
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
            $t->string('configoption4')->nullable();
            $t->string('configoption9')->nullable();
        });
        DB::schema()->create('tblclients', function ($t) {
            $t->integer('id');
            $t->string('firstname')->nullable();
            $t->string('lastname')->nullable();
            $t->string('email')->nullable();
        });
        Operation::schema();
        DB::table('tblproducts')->insert([
            ['id' => 20, 'servertype' => 'VirtFusionDirect', 'configoption4' => '0|Disabled', 'configoption9' => 'Server'],
            ['id' => 21, 'servertype' => 'VirtFusionDirect', 'configoption4' => '1|Hourly', 'configoption9' => 'Server'],
            ['id' => 22, 'servertype' => 'VirtFusionDirect', 'configoption4' => '', 'configoption9' => 'Resource Pack'],
            ['id' => 23, 'servertype' => 'VirtFusionDirect', 'configoption4' => '3', 'configoption9' => ''],
            ['id' => 24, 'servertype' => 'cpanel', 'configoption4' => '1', 'configoption9' => ''],
        ]);
        DB::table('tblclients')->insert(['id' => self::CLIENT, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@example.test']);
        // The service being provisioned: always Pending, never counted against itself.
        $this->service(self::SERVICE, 21, 'Pending');
    }

    private function service(int $id, int $product, string $status, int $client = self::CLIENT): void
    {
        DB::table('tblhosting')->insert(['id' => $id, 'userid' => $client, 'server' => 1, 'packageid' => $product, 'domainstatus' => $status]);
    }

    private function gateway(array $user = ['id' => 1010, 'extRelationId' => self::CLIENT]): LifecycleGateway
    {
        $gw = new LifecycleGateway;
        $gw->routes['GET /users/10/byExtRelation'] = [200, ['data' => $user]];
        $gw->routes['GET /servers/user/1010?results=200'] = [200, ['data' => []]];
        $gw->routes['POST /servers'] = [201, ['data' => ['id' => 80, 'name' => 'vm80', 'hostname' => 'vm80.example', 'owner' => ['id' => 1010],
            'network' => ['interfaces' => [['ipv4' => [['address' => '192.0.2.80']], 'ipv6' => []]]]]]];
        $gw->routes['PUT /users/10/byExtRelation'] = [200, ['data' => ['id' => 1010]]];
        $gw->cs = new class extends ConfigureService
        {
            public function __construct() {}

            public function initServerBuild(int $id, array $vars, ?int $vfUserId = null): bool
            {
                return true;
            }
        };

        return $gw;
    }

    private function writes(LifecycleGateway $gw): array
    {
        return array_values(array_filter($gw->calls, static fn (array $c): bool => $c[0] !== 'GET'));
    }

    public static function normalisation(): array
    {
        return [
            'blank server' => [['configoption4' => ''], 0],
            'missing server' => [[], 0],
            'null server' => [['configoption4' => null], 0],
            'non-numeric server' => [['configoption4' => 'Hourly'], 0],
            'dropdown disabled' => [['configoption4' => '0|Disabled'], 0],
            'dropdown hourly' => [['configoption4' => '1|Hourly'], 1],
            'dropdown packs' => [['configoption4' => '2|Resource Packs'], 2],
            'plain three' => [['configoption4' => '3'], 3],
            'integer' => [['configoption4' => 1], 1],
            'pack blank defaults to 2' => [['configoption4' => '', 'configoption9' => 'Resource Pack'], 2],
            'pack missing defaults to 2' => [['configoption9' => 'Resource Pack'], 2],
            'pack explicit both' => [['configoption4' => '3|Both', 'configoption9' => 'Resource Pack'], 3],
            'pack explicit disabled' => [['configoption4' => '0|Disabled', 'configoption9' => 'Resource Pack'], 0],
        ];
    }

    #[Test]
    #[DataProvider('normalisation')]
    public function billing_type_normalises_stored_mode(array $product, int $expected): void
    {
        $this->assertSame($expected, AccountType::billingType($product));
        $this->assertSame($expected, AccountType::billingType((object) $product));
    }

    #[Test]
    public function account_type_is_null_without_vfd_services(): void
    {
        DB::table('tblhosting')->delete();
        $this->service(1, 24, 'Active');
        $this->service(2, 21, 'Active', 99);
        $this->assertSame([], AccountType::existingTypes(self::CLIENT));
        $this->assertNull(AccountType::accountType(AccountType::existingTypes(self::CLIENT)));
    }

    #[Test]
    public function account_type_is_the_shared_type(): void
    {
        DB::table('tblhosting')->delete();
        $this->service(1, 20, 'Active');
        $this->service(2, 20, 'Suspended');
        $this->assertSame(0, AccountType::accountType(AccountType::existingTypes(self::CLIENT)));

        DB::table('tblhosting')->delete();
        $this->service(1, 21, 'Active');
        $this->service(2, 21, 'Pending');
        $this->assertSame(1, AccountType::accountType(AccountType::existingTypes(self::CLIENT)));
    }

    #[Test]
    public function disagreeing_legacy_services_make_the_account_conflicted(): void
    {
        DB::table('tblhosting')->delete();
        $this->service(1, 20, 'Active');
        $this->service(2, 22, 'Suspended');
        $types = AccountType::existingTypes(self::CLIENT);
        $this->assertSame([0, 2], $types);
        $this->assertSame(AccountType::CONFLICTED, AccountType::accountType($types));
        // Self-service orders are refused; a normal order is allowed because one service is normal.
        $this->assertFalse(AccountType::compatible(2, $types));
        $this->assertFalse(AccountType::compatible(1, $types));
        $this->assertTrue(AccountType::compatible(0, $types));
        $this->assertFalse(AccountType::compatible(0, [1, 2]));
    }

    #[Test]
    public function terminated_and_cancelled_services_do_not_count(): void
    {
        DB::table('tblhosting')->delete();
        $this->service(1, 21, 'Terminated');
        $this->service(2, 22, 'Cancelled');
        $this->service(3, 20, 'Fraud');
        $this->assertSame([], AccountType::existingTypes(self::CLIENT));
        $this->service(4, 20, 'Active');
        $this->assertSame([0], AccountType::existingTypes(self::CLIENT));
    }

    #[Test]
    public function excluded_service_does_not_count(): void
    {
        $this->assertSame([1], AccountType::existingTypes(self::CLIENT));
        $this->assertSame([], AccountType::existingTypes(self::CLIENT, self::SERVICE));
    }

    #[Test]
    public function checkout_rejects_cart_items_that_disagree(): void
    {
        $this->assertTrue(AccountType::checkoutConflict([1, 2], []));
        $this->assertTrue(AccountType::checkoutConflict([0, 1], []));
        $this->assertFalse(AccountType::checkoutConflict([1, 1], []));
        $this->assertFalse(AccountType::checkoutConflict([], [1]));
    }

    #[Test]
    public function checkout_rejects_cart_that_disagrees_with_account(): void
    {
        $this->assertTrue(AccountType::checkoutConflict([1], [2]));
        $this->assertTrue(AccountType::checkoutConflict([0], [1]));
        $this->assertTrue(AccountType::checkoutConflict([2], [0]));
        $this->assertTrue(AccountType::checkoutConflict([2], [0, 2]));
    }

    #[Test]
    public function checkout_allows_matching_order(): void
    {
        $this->assertFalse(AccountType::checkoutConflict([2, 2], [2]));
        $this->assertFalse(AccountType::checkoutConflict([0], [0]));
        $this->assertFalse(AccountType::checkoutConflict([1], []));
        $this->assertFalse(AccountType::checkoutConflict([0], [0, 1]));
    }

    #[Test]
    public function checkout_hook_calls_the_shared_decision(): void
    {
        $source = (string) file_get_contents(VFD_LIB . '/../hooks.php');
        $hook = substr($source, (int) strpos($source, "add_hook('ShoppingCartValidateCheckout'"));
        $hook = substr($hook, 0, (int) strpos($hook, 'add_hook(', 10));
        $this->assertStringContainsString('AccountType::billingType(', $hook);
        $this->assertStringContainsString('AccountType::existingTypes(', $hook);
        $this->assertStringContainsString('AccountType::checkoutConflict(', $hook);
        $this->assertStringContainsString('AccountType::CHECKOUT_MESSAGE', $hook);
        $this->assertSame(
            'This product uses a different billing type from the services already on your account. Please use a separate account or contact support.',
            AccountType::CHECKOUT_MESSAGE,
        );
    }

    #[Test]
    public function provisioning_refuses_conflicting_server_product_without_api_writes(): void
    {
        $this->service(1, 22, 'Active');
        $gw = $this->gateway();
        $result = $gw->createAccount(self::PARAMS + ['configoption4' => '1|Hourly']);
        $this->assertSame('Billing type conflict: this client already has VirtFusion services with a different billing type. Provisioning was not attempted.', $result);
        $this->assertSame([], $gw->calls);
        $this->assertCount(1, $this->logsFor('createAccount:billingTypeConflict'));
        $this->assertNull(DB::table('mod_virtfusion_direct')->where('service_id', self::SERVICE)->first());
    }

    #[Test]
    public function provisioning_refuses_conflicting_pack_product_without_api_writes(): void
    {
        $this->service(1, 21, 'Active');
        $gw = $this->gateway();
        $result = $gw->createAccount(self::PARAMS + ['configoption4' => '', 'configoption9' => 'Resource Pack', 'configoption10' => 5]);
        $this->assertSame(AccountType::PROVISION_MESSAGE, $result);
        $this->assertSame([], $gw->calls);
        $this->assertCount(1, $this->logsFor('createAccount:billingTypeConflict'));
    }

    #[Test]
    public function provisioning_proceeds_when_billing_types_match(): void
    {
        $this->service(1, 21, 'Active');
        $gw = $this->gateway(['id' => 1010, 'extRelationId' => self::CLIENT, 'selfService' => 1]);
        $this->assertSame('success', $gw->createAccount(self::PARAMS + ['configoption4' => '1|Hourly']));
        $this->assertSame([], $this->logsFor('createAccount:billingTypeConflict'));
        $writes = array_map(static fn (array $c): string => $c[0] . ' ' . $c[1], $this->writes($gw));
        $this->assertContains('POST /servers', $writes);
        $this->assertContains('PUT /users/10/byExtRelation', $writes);
    }

    #[Test]
    public function re_enable_skips_put_when_existing_user_has_a_different_self_service_mode(): void
    {
        // No other services in WHMCS, so the provisioning guard passes; the panel user still has packs on.
        $gw = $this->gateway(['id' => 1010, 'extRelationId' => self::CLIENT, 'selfService' => 2]);
        $this->assertSame('success', $gw->createAccount(self::PARAMS + ['configoption4' => '1|Hourly']));
        $this->assertSame([], array_values(array_filter($gw->calls, static fn (array $c): bool => $c[0] === 'PUT')));
        $this->assertCount(1, $this->logsFor('createAccount:selfServiceConflict'));
    }

    #[Test]
    public function re_enable_puts_when_existing_user_mode_is_off(): void
    {
        $gw = $this->gateway(['id' => 1010, 'extRelationId' => self::CLIENT, 'selfService' => 0]);
        $this->assertSame('success', $gw->createAccount(self::PARAMS + ['configoption4' => '1|Hourly']));
        $puts = array_values(array_filter($gw->calls, static fn (array $c): bool => $c[0] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame(['selfService' => 1, 'selfServiceHourlyCredit' => true], $puts[0][2]);
        $this->assertSame([], $this->logsFor('createAccount:selfServiceConflict'));
    }

    #[Test]
    public function provisioning_check_failure_is_logged_and_does_not_block(): void
    {
        DB::schema()->drop('tblproducts');
        DB::schema()->create('tblproducts', function ($t) {
            $t->integer('id');
            $t->string('servertype');
        });
        $this->assertNull(AccountType::provisioningConflict(self::PARAMS + ['configoption4' => '1']));
        $this->assertCount(1, $this->logsFor('createAccount:billingTypeCheckFailed'));
    }
}
