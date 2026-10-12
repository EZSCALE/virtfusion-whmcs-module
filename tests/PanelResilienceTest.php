<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\Access;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\Module;

/** Curl whose transfer() is scripted: each attempt pops one result. */
class ScriptedTransferCurl extends Curl
{
    public array $attempts = [];

    /** @var array<int,array{response: mixed, info: array, errno: int, error: string}> */
    public array $script = [];

    protected function transfer(array $options): array
    {
        $this->attempts[] = $options;

        return array_shift($this->script) ?? ['response' => false, 'info' => ['http_code' => 0], 'errno' => 7, 'error' => 'no script'];
    }
}

/** Module whose panel answers per path: [httpCode, body|null]; code 0 + null = transport failure. */
class OwnershipPanel extends Module
{
    public array $routes = [];

    public function __construct() {}

    public function initCurl($token)
    {
        $panel = $this;

        return new class($panel) extends Curl
        {
            private int $code = 0;

            public function __construct(private OwnershipPanel $panel) {}

            public function addOption($name, $value) {}

            public function get($url = null)
            {
                $path = (string) parse_url((string) $url, PHP_URL_PATH);
                [$this->code, $body] = $this->panel->routes[$path] ?? [404, ['msg' => 'not routed']];

                return $body === null ? false : (is_string($body) ? $body : json_encode($body));
            }

            public function getRequestInfo($param = false)
            {
                return $param === 'http_code' ? $this->code : ['http_code' => $this->code];
            }
        };
    }
}

final class PanelResilienceTest extends TestCase
{
    private const USER = '/api/v1/users/10/byExtRelation';

    private const SERVER = '/api/v1/servers/100';

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
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
        });
        DB::schema()->create('tblservers', function ($t) {
            $t->integer('id');
            $t->string('type');
            $t->string('hostname');
            $t->string('password');
            $t->integer('disabled')->default(0);
        });
        DB::schema()->create('tblusers_clients', function ($t) {
            $t->integer('auth_user_id');
            $t->integer('client_id');
            $t->integer('owner');
            $t->string('permissions')->nullable();
        });
        DB::schema()->create('mod_virtfusion_direct', function ($t) {
            $t->integer('service_id');
            $t->integer('server_id')->nullable();
        });
        DB::table('tblhosting')->insert(['id' => 1, 'userid' => 10, 'server' => 1, 'packageid' => 1, 'domainstatus' => 'Active']);
        DB::table('tblproducts')->insert(['id' => 1, 'servertype' => 'VirtFusionDirect']);
        DB::table('tblservers')->insert(['id' => 1, 'type' => 'VirtFusionDirect', 'hostname' => 'panel.example.test', 'password' => 'test-token']);
        DB::table('tblusers_clients')->insert(['auth_user_id' => 100, 'client_id' => 10, 'owner' => 1]);
        DB::table('mod_virtfusion_direct')->insert(['service_id' => 1, 'server_id' => 100]);
        CurrentUser::$client = (object) ['id' => 10];
        CurrentUser::$user = (object) ['id' => 100];
        CurrentUser::$admin = null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['vfd_csrf_failure'], $GLOBALS['vfd_valid_csrf']);
        parent::tearDown();
    }

    // ----- 1. CSRF -------------------------------------------------------------------

    public static function csrfFailures(): array
    {
        return ['WHMCS 9 throws its own exception' => ['whmcs'], 'returns false' => ['false'], 'runtime' => ['runtime']];
    }

    #[Test]
    #[DataProvider('csrfFailures')]
    public function invalid_or_missing_token_is_always_a_403(string $mode): void
    {
        $GLOBALS['vfd_valid_csrf'] = false;
        $GLOBALS['vfd_csrf_failure'] = $mode;

        try {
            Access::csrf();
            $this->fail('Accepted an invalid token.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Invalid CSRF token.', $e->getMessage());
            $this->assertSame(['status' => 403, 'message' => 'Invalid CSRF token.'], Module::clientError($e));
        }
    }

    #[Test]
    public function valid_token_passes(): void
    {
        $GLOBALS['vfd_valid_csrf'] = true;
        Access::csrf();
        $this->addToAssertionCount(1);
    }

    // ----- 2. ownership: mismatch vs unreachable panel -------------------------------

    private function panel(array $user, array $server): OwnershipPanel
    {
        $panel = new OwnershipPanel;
        $panel->routes = [self::USER => $user, self::SERVER => $server];

        return $panel;
    }

    private static function owned(): array
    {
        return [[200, ['data' => ['id' => 1010, 'extRelationId' => 10, 'admin' => false]]], [200, ['data' => ['id' => 100, 'owner' => ['id' => 1010]]]]];
    }

    #[Test]
    public function owned_service_returns_the_client(): void
    {
        [$user, $server] = self::owned();
        $this->assertSame(10, $this->panel($user, $server)->validateUserOwnsService(1));
    }

    public static function mismatches(): array
    {
        [$user, $server] = self::owned();

        return [
            'foreign VM owner' => [$user, [200, ['data' => ['id' => 100, 'owner' => ['id' => 2020]]]]],
            'relation of another client' => [[200, ['data' => ['id' => 1010, 'extRelationId' => 20, 'admin' => false]]], $server],
            'panel admin user' => [[200, ['data' => ['id' => 1010, 'extRelationId' => 10, 'admin' => true]]], $server],
            'no panel user' => [[404, ['msg' => 'not found']], $server],
            'VM gone' => [$user, [404, ['msg' => 'not found']]],
        ];
    }

    #[Test]
    #[DataProvider('mismatches')]
    public function genuine_mismatch_is_still_a_403(array $user, array $server): void
    {
        $this->assertFalse($this->panel($user, $server)->validateUserOwnsService(1));
    }

    public static function unreachable(): array
    {
        [$user, $server] = self::owned();

        return [
            'user lookup timeout' => [[0, null], $server],
            'user lookup 502' => [[502, ['msg' => 'bad gateway']], $server],
            'user lookup 401' => [[401, ['msg' => 'Unauthenticated.']], $server],
            'user lookup undecodable' => [[200, '<html>'], $server],
            'server read timeout' => [$user, [0, null]],
            'server read 500' => [$user, [500, ['msg' => 'boom']]],
            'server read undecodable' => [$user, [200, 'not json']],
        ];
    }

    #[Test]
    #[DataProvider('unreachable')]
    public function unreachable_panel_is_a_503_not_a_mismatch(array $user, array $server): void
    {
        try {
            $this->panel($user, $server)->validateUserOwnsService(1);
            $this->fail('Unreachable panel treated as a decision.');
        } catch (\RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertSame(Module::PANEL_UNREACHABLE, $e->getMessage());
            $this->assertSame(['status' => 503, 'message' => 'The control panel is temporarily unreachable. Please try again in a moment.'], Module::clientError($e));
        }
    }

    #[Test]
    #[DataProvider('unreachable')]
    public function verify_vm_owner_reports_unreachable_as_503(array $user, array $server): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(503);
        $this->panel($user, $server)->verifyVmOwner(['url' => 'https://panel.example.test/api/v1', 'token' => 't'], 100, 10);
    }

    #[Test]
    public function any_503_reaches_the_customer_with_the_generic_text(): void
    {
        $this->assertSame(['status' => 503, 'message' => Module::PANEL_UNREACHABLE], Module::clientError(new \RuntimeException('VirtFusion ownership unavailable for server #100.', 503)));
    }

    #[Test]
    public function client_entry_point_lets_the_503_reach_the_outer_catch(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../modules/servers/VirtFusionDirect/client.php');
        $this->assertStringContainsString('Module::clientError($e)', substr($source, (int) strrpos($source, '} catch (Throwable $e) {')));
        $this->assertStringContainsString('503', (string) file_get_contents(VFD_LIB . '/Module.php'));
    }

    // ----- 3. Curl resilience --------------------------------------------------------

    private static function ok(): array
    {
        return ['response' => '{"data":[]}', 'info' => ['http_code' => 200, 'connect_time' => 0.01], 'errno' => 0, 'error' => ''];
    }

    private static function failure(int $errno, float $connectTime): array
    {
        return ['response' => false, 'info' => ['http_code' => 0, 'connect_time' => $connectTime], 'errno' => $errno, 'error' => 'boom'];
    }

    public static function retryable(): array
    {
        return [
            'connection refused / unreachable' => [7, 0.0],
            'connect timeout, no connection' => [28, 0.0],
            'connect timeout at the limit' => [28, 5.0],
        ];
    }

    #[Test]
    #[DataProvider('retryable')]
    public function idempotent_get_retries_once_on_a_connect_failure(int $errno, float $connectTime): void
    {
        $curl = new ScriptedTransferCurl;
        $curl->script = [self::failure($errno, $connectTime), self::ok()];

        $this->assertSame('{"data":[]}', $curl->get('https://panel.example.test/api/v1/servers/1'));
        $this->assertCount(2, $curl->attempts);
        $this->assertSame(200, $curl->getRequestInfo('http_code'));
        $this->assertCount(1, $this->logsFor('Curl:retry'));
    }

    #[Test]
    public function get_retries_only_once(): void
    {
        $curl = new ScriptedTransferCurl;
        $curl->script = [self::failure(7, 0.0), self::failure(7, 0.0), self::ok()];

        $this->assertFalse($curl->get('https://panel.example.test/api/v1/servers/1'));
        $this->assertCount(2, $curl->attempts);
        $this->assertSame(7, $curl->getRequestInfo('curl_errno'));
    }

    #[Test]
    public function a_timeout_after_connecting_is_not_retried(): void
    {
        $curl = new ScriptedTransferCurl;
        $curl->script = [self::failure(28, 0.4), self::ok()];

        $this->assertFalse($curl->get('https://panel.example.test/api/v1/servers/1'));
        $this->assertCount(1, $curl->attempts);
    }

    #[Test]
    public function writes_are_never_retried(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $curl = new ScriptedTransferCurl;
            $curl->script = [self::failure(7, 0.0), self::ok()];
            $this->assertFalse($curl->{$method}('https://panel.example.test/api/v1/servers/1/power/restart'), $method);
            $this->assertCount(1, $curl->attempts, $method);
        }
    }

    #[Test]
    public function connect_timeout_is_five_seconds(): void
    {
        $curl = new ScriptedTransferCurl;
        $curl->script = [self::ok()];
        $curl->get('https://panel.example.test/api/v1/servers/1');
        $this->assertSame(5, $curl->attempts[0][CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(30, $curl->attempts[0][CURLOPT_TIMEOUT]);
    }

    #[Test]
    public function ip_resolve_constant_maps_to_curl_options(): void
    {
        $this->assertSame(CURL_IPRESOLVE_V4, Curl::ipResolveOption('v4'));
        $this->assertSame(CURL_IPRESOLVE_V6, Curl::ipResolveOption('V6'));
        $this->assertNull(Curl::ipResolveOption(null));
        $this->assertNull(Curl::ipResolveOption('auto'));
        $this->assertNull(Curl::ipResolveOption(4));
    }
}
