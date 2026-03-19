<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class Cache
{
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

        if (!extension_loaded('redis')) {
            self::$redisAvailable = false;
            return null;
        }

        try {
            $redis = new \Redis();
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
        if (!is_dir($dir)) {
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
     * @param string $key
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
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $entry = json_decode($raw, true);
        if (!$entry || !isset($entry['expires']) || !isset($entry['data'])) {
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
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time-to-live in seconds
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

        // File cache fallback with atomic write (race condition safe)
        $path = self::filePath($key);
        $tmp = $path . '.' . getmypid() . '.tmp';
        $entry = json_encode(['expires' => time() + $ttl, 'data' => $value]);

        if (@file_put_contents($tmp, $entry, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
    }

    /**
     * Delete a cached value.
     *
     * @param string $key
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

    /**
     * Delete all cache keys matching a pattern.
     *
     * @param string $pattern Glob pattern (e.g., "os:*")
     */
    public static function forgetPattern($pattern)
    {
        $redis = self::redis();
        if ($redis) {
            try {
                $keys = $redis->keys(self::PREFIX . $pattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            } catch (\Exception $e) {
                // Continue to file cleanup
            }
        }

        // File cache: can only clear all files for pattern matches
        // Since file names are md5 hashed, we can't match patterns.
        // For non-Redis, TTL expiry handles cleanup naturally.
    }
}
