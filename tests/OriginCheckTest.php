<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\Module;

/** Thrown in place of Module::output()'s exit so a test can observe the response. */
final class OutputCaptured extends \RuntimeException
{
    public array $data;

    public int $status;

    public function __construct(array $data, int $status)
    {
        parent::__construct((string) ($data['errors'] ?? ''), $status);
        $this->data = $data;
        $this->status = $status;
    }
}

final class OriginCheckTest extends TestCase
{
    private const SERVER_KEYS = ['REQUEST_METHOD', 'HTTP_HOST', 'HTTP_ORIGIN', 'HTTP_REFERER'];

    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        DB::schema()->create('tblconfiguration', function ($t) {
            $t->string('setting');
            $t->string('value');
        });
        DB::table('tblconfiguration')->insert(['setting' => 'SystemURL', 'value' => 'https://billing.example.test/']);
        foreach (self::SERVER_KEYS as $key) {
            $this->savedServer[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST'] = 'billing.example.test';
        $GLOBALS['vfd_valid_csrf'] = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        unset($GLOBALS['vfd_valid_csrf']);
        parent::tearDown();
    }

    private function module(): Module
    {
        return new class extends Module
        {
            public function __construct() {}

            public function output($data, $json = true, $exit = true, $rspCode = 200)
            {
                throw new OutputCaptured((array) $data, (int) $rspCode);
            }
        };
    }

    private function refusal(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('Request was accepted.');
    }

    #[Test]
    public function null_origin_with_valid_session_token_is_accepted(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'null';

        $this->assertTrue($this->module()->requireSameOrigin());
        $this->assertCount(1, $this->logsFor('csrf:null-origin'));
        $this->assertStringNotContainsString('token', json_encode($this->logsFor('csrf:null-origin')[0]['request']));
    }

    #[Test]
    public function absent_origin_and_referer_with_valid_session_token_is_accepted(): void
    {
        $this->assertTrue($this->module()->requireSameOrigin());
        $this->assertCount(1, $this->logsFor('csrf:null-origin'));
    }

    #[Test]
    public function null_origin_with_invalid_token_is_refused_by_csrf(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'null';
        $GLOBALS['vfd_valid_csrf'] = false;

        $e = $this->refusal(fn () => $this->module()->requireSameOrigin());
        $this->assertNotInstanceOf(OutputCaptured::class, $e);
        $this->assertSame(403, $e->getCode());
        $this->assertSame('Invalid CSRF token.', $e->getMessage());
        $this->assertSame([], $this->logsFor('csrf:null-origin'));
    }

    #[Test]
    public function foreign_origin_with_valid_token_is_refused(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $e = $this->refusal(fn () => $this->module()->requireSameOrigin());
        $this->assertInstanceOf(OutputCaptured::class, $e);
        $this->assertSame(403, $e->status);
        $this->assertSame('cross-origin check failed', $e->data['errors']);
    }

    #[Test]
    public function null_origin_with_foreign_referer_is_refused(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'null';
        $_SERVER['HTTP_REFERER'] = 'https://evil.example/page';

        $e = $this->refusal(fn () => $this->module()->requireSameOrigin());
        $this->assertInstanceOf(OutputCaptured::class, $e);
        $this->assertSame(403, $e->status);
        $this->assertSame('cross-origin check failed', $e->data['errors']);
    }

    #[Test]
    public function foreign_referer_without_origin_is_refused(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.example/page';

        $e = $this->refusal(fn () => $this->module()->requireSameOrigin());
        $this->assertInstanceOf(OutputCaptured::class, $e);
        $this->assertSame('cross-origin check failed', $e->data['errors']);
    }

    #[Test]
    public function matching_origin_is_accepted_without_null_origin_log(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://billing.example.test';

        $this->assertTrue($this->module()->requireSameOrigin());
        $this->assertSame([], $this->logsFor('csrf:null-origin'));
    }

    #[Test]
    public function matching_referer_is_accepted(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://billing.example.test/clientarea.php?action=productdetails&id=1';

        $this->assertTrue($this->module()->requireSameOrigin());
    }
}
