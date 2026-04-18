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
 * The fallback is filesystem-based, using the OS temp directory. Writes are atomic
 * via the classic tmp-file + rename pattern so a process crash mid-write can never
 * corrupt an existing cache entry for another concurrent reader.
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
 * shares the Redis instance. Nested keys add their own sub-prefix (e.g.
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

    /** @var string */
    private static $fileDir = '';

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
            $redis->connect('127.0.0.1', 6379, 1.0);
            self::$redis = $redis;
            self::$redisAvailable = true;

            return $redis;
        } catch (\Exception $e) {
            self::$redisAvailable = false;

            return null;
        }
    }

    /**
     * Get the filesystem cache directory, creating it if needed.
     */
    private static function fileDir(): string
    {
        if (self::$fileDir !== '') {
            return self::$fileDir;
        }

        $dir = sys_get_temp_dir() . '/vfd_cache';
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        self::$fileDir = $dir;

        return $dir;
    }

    /**
     * Convert a cache key to a safe filename.
     */
    private static function filePath(string $key): string
    {
        return self::fileDir() . '/' . md5($key) . '.cache';
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
        if (! file_exists($path)) {
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
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
