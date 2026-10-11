<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use WHMCS\Module\Server\VirtFusionDirect\Cache;
use WHMCS\Module\Server\VirtFusionDirect\Log;

/**
 * S10: lifecycle logs carry a projection of $params, redaction covers access hashes
 * and other credential-shaped keys, and the file cache only runs in a private,
 * per-install directory. Redis AUTH failures disable the Redis tier.
 */
final class LogAndCacheHardeningTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (['fileDir' => null, 'fileChecked' => false] as $name => $value) {
            $p = new ReflectionProperty(Cache::class, $name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @chmod($path, 0700);
                foreach (glob($path . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($path);
            }
        }
        parent::tearDown();
    }

    private function tempPath(string $suffix = ''): string
    {
        $path = rtrim(sys_get_temp_dir(), '/') . '/vfd_test_' . bin2hex(random_bytes(6)) . $suffix;
        $this->cleanup[] = $path;

        return $path;
    }

    #[Test]
    public function params_projection_drops_secrets_and_pii(): void
    {
        $params = [
            'serviceid' => 42,
            'userid' => 7,
            'serverid' => 3,
            'pid' => 11,
            'status' => 'Active',
            'serverpassword' => 'srv-pass-SECRET',
            'serveraccesshash' => 'ACCESSHASH-SECRET',
            'password' => 'client-pass-SECRET',
            'clientsdetails' => [
                'email' => 'victim@example.test',
                'notes' => 'private-note-SECRET',
                'phonenumber' => '+15550001234',
            ],
            'customfields' => ['Initial Server Password' => 'cf-SECRET'],
            'model' => (object) ['notes' => 'model-note-SECRET'],
            'configoption1' => '5',
            'configoption12' => '2',
            'configoptions' => ['Memory' => '4096'],
        ];

        $out = Log::params($params);

        $keys = array_keys($out);
        sort($keys);
        $this->assertSame(
            ['configoption1', 'configoption12', 'configoptions', 'pid', 'serverid', 'serviceid', 'status', 'userid'],
            $keys,
        );
        $json = json_encode($out);
        foreach (['srv-pass-SECRET', 'ACCESSHASH-SECRET', 'client-pass-SECRET', 'victim@example.test',
            'private-note-SECRET', '+15550001234', 'cf-SECRET', 'model-note-SECRET'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertSame(42, $out['serviceid']);
        $this->assertSame(['Memory' => '4096'], $out['configoptions']);
    }

    #[Test]
    public function params_projection_ignores_non_array_configoptions(): void
    {
        $this->assertSame(['serviceid' => 1], Log::params(['serviceid' => 1, 'configoptions' => 'x']));
    }

    #[Test]
    public function redact_masks_accesshash_notes_credential_private_and_hash_keys(): void
    {
        $out = Log::redact([
            'serveraccesshash' => 'a',
            'notes' => 'b',
            'adminCredential' => 'c',
            'privateKey' => 'd',
            'hash' => 'e',
            'password_hash' => 'f',
            'urlHash' => 'keep-url-hash',
            'name' => 'keep-name',
        ]);

        foreach (['serveraccesshash', 'notes', 'adminCredential', 'privateKey', 'hash', 'password_hash'] as $key) {
            $this->assertSame('[redacted]', $out[$key], $key);
        }
        $this->assertSame('keep-url-hash', $out['urlHash']);
        $this->assertSame('keep-name', $out['name']);
    }

    #[Test]
    public function redact_masks_accesshash_in_free_text(): void
    {
        $this->assertSame('accesshash=[redacted]', Log::redact('accesshash=abc123'));
    }

    #[Test]
    public function module_functions_never_log_raw_params(): void
    {
        $source = file_get_contents(VFD_LIB . '/ModuleFunctions.php');

        $this->assertDoesNotMatchRegularExpression('/Log::insert\([^;]*,\s*\$params\s*,/', $source);
        $this->assertGreaterThanOrEqual(10, substr_count($source, 'Log::params($params)'));
    }

    #[Test]
    public function secure_directory_accepts_private_owned_dir(): void
    {
        $dir = $this->tempPath();

        $this->assertTrue(Cache::secureDirectory($dir));
        $this->assertDirectoryExists($dir);
        $this->assertSame(0700, fileperms($dir) & 0777);
    }

    #[Test]
    public function secure_directory_rejects_group_or_world_accessible_dir(): void
    {
        $dir = $this->tempPath();
        mkdir($dir);
        chmod($dir, 0770);

        $this->assertFalse(Cache::secureDirectory($dir));
        clearstatcache();
        $this->assertSame(0770, fileperms($dir) & 0777, 'a pre-existing directory must never be chmodded');
    }

    #[Test]
    public function secure_directory_rejects_symlink(): void
    {
        $target = $this->tempPath();
        mkdir($target, 0700);
        chmod($target, 0700);
        $link = $this->tempPath('_link');
        symlink($target, $link);

        $this->assertTrue(Cache::secureDirectory($target));
        $this->assertFalse(Cache::secureDirectory($link));
    }

    #[Test]
    public function default_directory_is_namespaced_per_install(): void
    {
        $dir = Cache::defaultDirectory();

        $this->assertMatchesRegularExpression('~/vfd_cache_[a-f0-9]{32}$~', $dir);
        $this->assertNotSame(sys_get_temp_dir() . '/vfd_cache', $dir);
        $this->assertNotSame(rtrim(sys_get_temp_dir(), '/') . '/vfd_cache', $dir);
    }

    #[Test]
    public function file_tier_disabled_when_directory_unsafe(): void
    {
        $dir = $this->tempPath();
        mkdir($dir);
        chmod($dir, 0777);
        foreach (['fileDir' => $dir, 'fileChecked' => false] as $name => $value) {
            $p = new ReflectionProperty(Cache::class, $name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }

        Cache::set('k', 1, 60);

        $this->assertSame([], glob($dir . '/*') ?: []);
        $this->assertNull(Cache::get('k'));
        Cache::forget('k');
        $logs = $this->logsFor('Cache:fileTierDisabled');
        $this->assertCount(1, $logs, 'the refusal is logged once per process');
        $this->assertSame(0777, fileperms($dir) & 0777);
    }

    #[Test]
    public function owner_check_without_posix_uses_a_probe_file(): void
    {
        $dir = $this->tempPath();
        mkdir($dir, 0700);
        chmod($dir, 0700);

        // Forces the no-ext-posix branch: ownership is proven by a probe file this process creates.
        $this->assertTrue($this->call(Cache::class, 'ownedByProcess', [$dir, false]));
        $this->assertSame([], array_diff(scandir($dir), ['.', '..']), 'the probe file is removed');
        $this->assertSame(function_exists('posix_geteuid'), $this->call(Cache::class, 'ownedByProcess', [$dir, true]));

        // A directory the probe cannot be written to is refused.
        $this->assertFalse($this->call(Cache::class, 'ownedByProcess', [$dir . '/missing', false]));
    }

    #[Test]
    public function disabled_file_tier_is_signalled_in_activity_log(): void
    {
        $GLOBALS['vfd_activity_log'] = [];
        $dir = $this->tempPath();
        mkdir($dir);
        chmod($dir, 0777);
        foreach (['fileDir' => $dir, 'fileChecked' => false] as $name => $value) {
            $p = new ReflectionProperty(Cache::class, $name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }

        Cache::get('k');
        Cache::get('k');

        $this->assertSame(['VirtFusionDirect: file cache disabled (directory not private); rate limits depend on Redis'], $GLOBALS['vfd_activity_log']);
        $this->assertCount(1, $this->logsFor('Cache:fileTierDisabled'));
    }

    #[Test]
    public function file_tier_works_in_private_directory(): void
    {
        $dir = $this->tempPath();
        foreach (['fileDir' => $dir, 'fileChecked' => false] as $name => $value) {
            $p = new ReflectionProperty(Cache::class, $name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }

        Cache::set('k', ['v' => 1], 60);
        $this->assertSame(['v' => 1], Cache::get('k'));
        Cache::forget('k');
        $this->assertNull(Cache::get('k'));
        $this->assertSame([], $this->logsFor('Cache:fileTierDisabled'));
    }

    #[Test]
    public function redis_auth_failure_disables_redis(): void
    {
        $stub = new class
        {
            public int $calls = 0;

            public function auth($credential): bool
            {
                $this->calls++;

                return false;
            }
        };

        $this->assertFalse($this->call(Cache::class, 'authenticate', [$stub, 'pw']));
        $this->assertSame(1, $stub->calls);

        $this->assertTrue($this->call(Cache::class, 'authenticate', [$stub, null]));
        $this->assertTrue($this->call(Cache::class, 'authenticate', [$stub, '']));
        $this->assertSame(1, $stub->calls, 'no credential means auth() is never called');
    }

    #[Test]
    public function redis_auth_passes_credential_through_and_survives_exceptions(): void
    {
        $ok = new class
        {
            public $seen = null;

            public function auth($credential): bool
            {
                $this->seen = $credential;

                return true;
            }
        };
        $this->assertTrue($this->call(Cache::class, 'authenticate', [$ok, ['user', 'pass']]));
        $this->assertSame(['user', 'pass'], $ok->seen);

        $throws = new class
        {
            public function auth($credential): bool
            {
                throw new \RuntimeException('WRONGPASS');
            }
        };
        $this->assertFalse($this->call(Cache::class, 'authenticate', [$throws, 'pw']));
    }
}
