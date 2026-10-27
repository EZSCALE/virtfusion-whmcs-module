<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\Module;

/** Exercise the actual credit helper without a panel, invoice or live balance. */
final class CreditIssuanceGateway extends Module
{
    public array $writes = [];

    public function getCP($serverId = false, $any = false)
    {
        return ['url' => 'https://synthetic.invalid/api/v1', 'token' => 'fixture'];
    }

    public function initCurl($token)
    {
        return new class($this) extends Curl
        {
            private array $options = [];

            public function __construct(private CreditIssuanceGateway $gateway) {}

            public function addOption($key, $value)
            {
                $this->options[$key] = $value;
            }

            public function post($url = null)
            {
                $this->gateway->writes[] = ['url' => $url, 'body' => json_decode($this->options[CURLOPT_POSTFIELDS], true)];

                return '{"data":{"id":1}}';
            }

            public function getRequestInfo($key = false)
            {
                return $key === 'http_code' ? 201 : ['http_code' => 201];
            }
        };
    }
}

final class CreditIssuanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        DB::schema()->create('tblhosting', function ($t) {
            $t->integer('id');
            $t->integer('userid');
            $t->integer('server');
        });
        DB::table('tblhosting')->insert(['id' => 1, 'userid' => 10, 'server' => 1]);
        CurrentUser::$client = CurrentUser::$user = CurrentUser::$admin = null;
    }

    #[Test]
    public function authenticated_customer_cannot_mint_credit_for_an_owned_service(): void
    {
        CurrentUser::$client = (object) ['id' => 10];
        CurrentUser::$user = (object) ['id' => 100];
        $gateway = new CreditIssuanceGateway;
        $this->assertFalse($gateway->addSelfServiceCredit(1, 1000000));
        $this->assertSame([], $gateway->writes);
    }

    #[Test]
    public function unattended_cli_keeps_configured_credit_grants_available(): void
    {
        $gateway = new CreditIssuanceGateway;
        $this->assertSame(['data' => ['id' => 1]], $gateway->addSelfServiceCredit(1, 100, 'Auto top-off'));
        $this->assertCount(1, $gateway->writes);
        $this->assertSame(100.0, $gateway->writes[0]['body']['tokens'] * 1.0);
        $this->assertSame('Auto top-off', $gateway->writes[0]['body']['reference_1']);
    }

    public static function amounts(): array
    {
        return [[INF], [NAN], [-INF], [0], [-1]];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function non_finite_and_non_positive_grants_never_reach_native_credit($amount): void
    {
        $gateway = new CreditIssuanceGateway;
        $this->assertFalse($gateway->addSelfServiceCredit(1, $amount));
        $this->assertSame([], $gateway->writes);
    }

    #[Test]
    public function credit_grants_require_admin_module_command_permission(): void
    {
        CurrentUser::$admin = new class
        {
            public int $id = 1;

            public bool $allowed = false;

            public function hasPermission($permission)
            {
                return $this->allowed && $permission === 'Perform Module Command Operations';
            }
        };
        $gateway = new CreditIssuanceGateway;
        $this->assertFalse($gateway->addSelfServiceCredit(1, 100));
        $this->assertSame([], $gateway->writes);
        CurrentUser::$admin->allowed = true;
        $this->assertNotFalse($gateway->addSelfServiceCredit(1, 100));
        $this->assertCount(1, $gateway->writes);
    }
}
