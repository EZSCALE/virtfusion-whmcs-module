<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Two-tier cache: Redis when ext-redis is available, atomic filesystem fallback otherwise.
 *
 * WHY TWO TIERS
 * -------------
 * The module is deployed to every kind of WHMCS install — shared hosting, dedicated
 * VPS, bare-metal. Requiring Redis would exclude the long tail of smaller operators
 * who never installed the extension. But operators who DO have Redis get a huge
 * performance win for cross-request caching (PowerDNS zone lists, OS template
 * listings, traffic stats), so we opportunistically use it when present.
 *
 * The fallback is filesystem-based, using a module-private directory under the OS
 * temp directory: vfd_cache_<md5 of the WHMCS ROOTDIR>, so each WHMCS install gets
 * its own directory outside the web root. Writes are atomic via the classic
 * tmp-file + rename pattern so a process crash mid-write can never corrupt an
 * existing cache entry for another concurrent reader.
 *
 * The directory is created 0700. Cached API responses must not be readable or
 * replaceable by other local users, so the file tier refuses (and disables itself
 * for the process, logging Cache:fileTierDisabled once) when the directory is a
 * symlink, is not a directory, is not owned by the PHP process's effective user
 * (checked with a probe file when ext-posix is missing), or has any group/other
 * permission bits. A disabled tier is also written once to the WHMCS activity log,
 * since every rate limit then depends on Redis. A pre-existing directory is never chmodded: an unsafe
 * directory is someone else's to fix, not ours to adopt.
 *
 * EXPIRY SEMANTICS
 * ----------------
 * Redis: native SETEX — the key auto-expires on the server side.
 * Filesystem: we store a JSON envelope {expires, data} and check expiry on read,
 * deleting stale entries lazily. This means a cache with lots of expired entries
 * will slowly accumulate files until accessed — acceptable for the module's scale
 * (tens-to-hundreds of keys per install) but worth noting if someone ports this
 * to a higher-volume context.
 *
 * NAMESPACE
 * ---------
 * Every key is prefixed with "vfd:" to avoid collisions with anything else that
 * shares the Redis instance. Define VFD_REDIS_AUTH (a password string, or a
 * [user, pass] array for Redis 6+ ACLs) to authenticate after connecting; a
 * failed AUTH disables the Redis tier for the process, like a failed connect.
 * Nested keys add their own sub-prefix (e.g.
 * "pdns:zones:<hash>" for PowerDNS zone lists) for semantic clarity in the logs.
 *
 * FAILURE MODES
 * -------------
 * Redis unreachable: we set $redisAvailable = false on first failure, which
 * permanently disables Redis for the rest of this PHP process (subsequent calls
 * skip straight to the file cache). Prevents paying reconnect overhead on every
 * miss when Redis is down.
 * File cache write fails: silently skipped. Cache is best-effort; a failed SET
 * just means the next GET will re-fetch from the authoritative source.
 */
class Cache
{
    /** Module-global key prefix — keeps us out of Redis key collisions on shared installs. */
    const PREFIX = 'vfd:';

    /** @var \Redis|null */
    private static $redis = null;

    /** @var bool|null */
    private static $redisAvailable = null;

    /** @var string|null Candidate directory before the check; null once refused. */
    private static $fileDir = null;

    /** @var bool Whether the candidate directory has been checked by secureDirectory(). */
    private static $fileChecked = false;

    /**
     * Try to connect to Redis. Returns the connection or null.
     */
    private static function redis(): ?\Redis
    {
        if (self::$redisAvailable === false) {
            return null;
        }

        if (self::$redis !== null) {
            return self::$redis;
        }

        if (! extension_loaded('redis')) {
            self::$redisAvailable = false;

            return null;
        }

        try {
            $redis = new \Redis;
            $redis->connect(
                defined('VFD_REDIS_HOST') ? VFD_REDIS_HOST : '127.0.0.1',
                defined('VFD_REDIS_PORT') ? (int) VFD_REDIS_PORT : 6379,
                1.0,
            );
            if (! self::authenticate($redis, defined('VFD_REDIS_AUTH') ? VFD_REDIS_AUTH : null)) {
                self::$redisAvailable = false;

                return null;
            }
            self::$redis = $redis;
            self::$redisAvailable = true;

            return $redis;
        } catch (\Exception $e) {
            self::$redisAvailable = false;

            return null;
        }
    }

    /**
     * Authenticate a Redis connection when a credential is configured.
     *
     * Not type-hinted to \Redis so tests can pass a stub.
     *
     * @param  object  $redis  Connected client exposing auth().
     * @param  string|array|null  $credential  Password, or [user, pass]; null/'' skips AUTH.
     */
    private static function authenticate($redis, $credential): bool
    {
        if ($credential === null || $credential === '') {
            return true;
        }

        try {
            return (bool) $redis->auth($credential);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Per-install cache directory under the OS temp directory, outside the web root.
     */
    public static function defaultDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), '/') . '/vfd_cache_' . md5(defined('ROOTDIR') ? (string) ROOTDIR : dirname(__DIR__, 3));
    }

    /**
     * Create the directory privately if missing, then verify it is safe to use.
     *
     * Refuses symlinks, non-directories, directories not owned by this process's
     * effective user (checked with a probe file when ext-posix is unavailable) and directories
     * with any group/other permission bits. A pre-existing directory is never
     * chmodded.
     */
    public static function secureDirectory(string $dir): bool
    {
        if (! file_exists($dir) && ! is_link($dir)) {
            @mkdir($dir, 0700, true);
            @chmod($dir, 0700);
        }
        clearstatcache(true, $dir);

        if (is_link($dir) || ! is_dir($dir)) {
            return false;
        }
        if (! self::ownedByProcess($dir, function_exists('posix_geteuid'))) {
            return false;
        }

        return (fileperms($dir) & 0077) === 0;
    }

    /**
     * Whether $dir is owned by this process's effective user.
     *
     * With ext-posix the euid is compared directly. Without it, a 0600 probe file is
     * created inside the directory (exclusive create, so an existing path is never
     * reused) and its owner, which is necessarily this process, must match the
     * directory owner. The probe is always removed.
     */
    private static function ownedByProcess(string $dir, bool $posix): bool
    {
        if ($posix) {
            return fileowner($dir) === posix_geteuid();
        }

        $probe = $dir . '/.vfd_probe_' . bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'x');
        if ($handle === false) {
            return false;
        }
        fclose($handle);

        try {
            @chmod($probe, 0600);
            clearstatcache(true, $probe);
            $owner = @fileowner($probe);
            $dirOwner = @fileowner($dir);

            return $owner !== false && $owner === $dirOwner;
        } finally {
            @unlink($probe);
        }
    }

    /**
     * The verified filesystem cache directory, or null when the file tier is disabled.
     */
    private static function fileDir(): ?string
    {
        if (self::$fileChecked) {
            return self::$fileDir;
        }

        $dir = self::$fileDir ?? self::defaultDirectory();
        self::$fileChecked = true;
        if (! self::secureDirectory($dir)) {
            self::$fileDir = null;
            Log::insert('Cache:fileTierDisabled', ['dir' => $dir], 'Cache directory is not private; file cache disabled');
            if (function_exists('logActivity')) {
                logActivity('VirtFusionDirect: file cache disabled (directory not private); rate limits depend on Redis');
            }

            return null;
        }

        self::$fileDir = $dir;

        return $dir;
    }

    /**
     * Convert a cache key to a safe filename, or null when the file tier is disabled.
     */
    private static function filePath(string $key): ?string
    {
        $dir = self::fileDir();

        return $dir === null ? null : $dir . '/' . md5($key) . '.cache';
    }

    /**
     * Get a cached value.
     *
     * @param  string  $key
     * @return mixed|null Returns null on miss
     */
    public static function get($key)
    {
        // Try Redis first
        $redis = self::redis();
        if ($redis) {
            try {
                $data = $redis->get(self::PREFIX . $key);
                if ($data !== false) {
                    return json_decode($data, true);
                }

                return null;
            } catch (\Exception $e) {
                // Fall through to file cache
            }
        }

        // File cache fallback
        $path = self::filePath($key);
        if ($path === null || ! file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $entry = json_decode($raw, true);
        if (! $entry || ! isset($entry['expires']) || ! isset($entry['data'])) {
            @unlink($path);

            return null;
        }

        if ($entry['expires'] < time()) {
            @unlink($path);

            return null;
        }

        return $entry['data'];
    }

    /**
     * Store a value in cache.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $ttl  Time-to-live in seconds
     */
    public static function set($key, $value, $ttl = 300)
    {
        // Try Redis first
        $redis = self::redis();
        if ($redis) {
            try {
                $redis->setex(self::PREFIX . $key, $ttl, json_encode($value));

                return;
            } catch (\Exception $e) {
                // Fall through to file cache
            }
        }

        // File cache fallback with atomic write.
        // Writing to a temp file + rename ensures that readers either see the
        // complete previous entry or the complete new entry — never a truncated
        // or partially-written file. getmypid() suffix lets concurrent PHP
        // processes write to the same key without stomping each other's temp files.
        $path = self::filePath($key);
        if ($path === null) {
            return;
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        $entry = json_encode(['expires' => time() + $ttl, 'data' => $value]);

        if (@file_put_contents($tmp, $entry, LOCK_EX) !== false) {
            // rename() is atomic on POSIX when source and target are on the same
            // filesystem (which they always are here — both in sys_get_temp_dir).
            @rename($tmp, $path);
        }
    }

    /**
     * Delete a cached value.
     *
     * @param  string  $key
     */
    public static function forget($key)
    {
        $redis = self::redis();
        if ($redis) {
            try {
                $redis->del(self::PREFIX . $key);
            } catch (\Exception $e) {
                // Continue to file cleanup
            }
        }

        $path = self::filePath($key);
        if ($path !== null && file_exists($path)) {
            @unlink($path);
        }
    }
}
