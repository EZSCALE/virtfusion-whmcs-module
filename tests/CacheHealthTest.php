<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\AdminHTML;
use WHMCS\Module\Server\VirtFusionDirect\Cache;
use WHMCS\Module\Server\VirtFusionDirect\ModuleFunctions;

/**
 * Cache health is surfaced to admins (services tab, failed Test Connection) instead of
 * a System Activity Log line on every PHP request when no cache tier is usable.
 */
final class CacheHealthTest extends TestCase
{
    private const WARNING = 'Warning: no usable cache (Redis unavailable and cache directory not private); rate limits are disabled.';

    /** @var list<string> */
    private array $dirs = [];

    private mixed $redisBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redisBefore = $this->prop('redisAvailable')->getValue();
    }

    protected function tearDown(): void
    {
        $this->prop('redisAvailable')->setValue(null, $this->redisBefore);
        $this->prop('fileDir')->setValue(null, null);
        $this->prop('fileChecked')->setValue(null, false);
        foreach ($this->dirs as $dir) {
            @chmod($dir, 0700);
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        CurrentUser::$admin = null;
        parent::tearDown();
    }

    private function prop(string $name): ReflectionProperty
    {
        $p = new ReflectionProperty(Cache::class, $name);
        $p->setAccessible(true);

        return $p;
    }

    /** Point the file tier at a fresh private (or world-writable) directory. */
    private function state(bool $redis, bool $privateDir): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/vfd_health_' . bin2hex(random_bytes(6));
        $this->dirs[] = $dir;
        mkdir($dir, 0700);
        chmod($dir, $privateDir ? 0700 : 0777);
        $this->prop('redisAvailable')->setValue(null, $redis);
        $this->prop('fileDir')->setValue(null, $dir);
        $this->prop('fileChecked')->setValue(null, false);

        return $dir;
    }

    #[Test]
    public function health_reports_each_combination(): void
    {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$redis, $private]) {
            $dir = $this->state($redis, $private);

            $this->assertSame(['redis' => $redis, 'fileTier' => $private, 'directory' => $dir], Cache::health(), json_encode([$redis, $private]));
        }
    }

    #[Test]
    public function health_does_not_write_the_activity_log(): void
    {
        $GLOBALS['vfd_activity_log'] = [];
        $this->state(false, false);

        Cache::health();
        Cache::health();

        $this->assertSame([], $GLOBALS['vfd_activity_log']);
        $this->assertCount(1, $this->logsFor('Cache:fileTierDisabled'));
    }

    #[Test]
    public function warning_only_when_both_tiers_unavailable(): void
    {
        $this->state(false, false);
        $this->assertSame(self::WARNING, Cache::healthWarning());

        foreach ([[true, false], [false, true], [true, true]] as [$redis, $private]) {
            $this->state($redis, $private);
            $this->assertNull(Cache::healthWarning());
        }
    }

    #[Test]
    public function admin_html_renders_escaped_red_warning(): void
    {
        $this->state(false, false);
        $html = AdminHTML::cacheHealth();

        $this->assertStringContainsString(htmlspecialchars(self::WARNING, ENT_QUOTES, 'UTF-8'), $html);
        $this->assertStringContainsString('text-danger', $html);

        $this->state(true, true);
        $this->assertSame('', AdminHTML::cacheHealth());
    }

    private function adminTab(): array
    {
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
        DB::schema()->create('tblconfiguration', function ($t) {
            $t->string('setting');
            $t->text('value');
        });
        DB::table('tblconfiguration')->insert(['setting' => 'SystemURL', 'value' => 'https://whmcs.example/']);
        CurrentUser::$admin = new class
        {
            public int $id = 4;

            public function hasPermission($permission)
            {
                return $permission === 'View Clients Products/Services';
            }
        };

        $gw = $this->bare(ModuleFunctions::class);

        return $gw->adminServicesTabFields(['serviceid' => 7, 'serverid' => 1, 'status' => 'Terminated']);
    }

    #[Test]
    public function admin_services_tab_shows_warning_when_unhealthy(): void
    {
        $this->state(false, false);

        $fields = $this->adminTab();

        $this->assertArrayHasKey('Cache', $fields);
        $this->assertStringContainsString('rate limits are disabled', $fields['Cache']);
    }

    #[Test]
    public function admin_services_tab_has_no_warning_when_healthy(): void
    {
        $this->state(false, true);

        $fields = $this->adminTab();

        $this->assertArrayHasKey('Server ID', $fields);
        $this->assertArrayNotHasKey('Cache', $fields);
    }

    #[Test]
    public function failed_test_connection_carries_the_warning(): void
    {
        if (! defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once VFD_LIB . '/../VirtFusionDirect.php';

        $this->state(false, false);
        $result = \VirtFusionDirect_TestConnection(['serverhostname' => '', 'serverpassword' => '']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('hostname and password are required', $result['error']);
        $this->assertStringEndsWith(self::WARNING, $result['error']);

        $this->state(true, true);
        $result = \VirtFusionDirect_TestConnection(['serverhostname' => '', 'serverpassword' => '']);
        $this->assertStringNotContainsString('Warning:', $result['error']);
    }
}
