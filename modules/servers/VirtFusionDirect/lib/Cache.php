<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class Cache
{
    const PREFIX = 'vfd:';

    /** @var \Redis|null */
    private static $redis = null;

    /** @var bool */
    private static $available = true;

    /**
     * Get a Redis connection, or null if unavailable.
     *
     * @return \Redis|null
     */
    private static function redis()
    {
        if (!self::$available) {
            return null;
        }

        if (self::$redis !== null) {
            return self::$redis;
        }

        if (!class_exists('Redis')) {
            self::$available = false;
            return null;
        }

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            self::$redis = $redis;
            return $redis;
        } catch (\Exception $e) {
            self::$available = false;
            return null;
        }
    }

    /**
     * Get a cached value.
     *
     * @param string $key
     * @return mixed|null Returns null on miss
     */
    public static function get($key)
    {
        $redis = self::redis();
        if (!$redis) {
            return null;
        }

        try {
            $data = $redis->get(self::PREFIX . $key);
            if ($data === false) {
                return null;
            }
            return json_decode($data, true);
        } catch (\Exception $e) {
            return null;
        }
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
        $redis = self::redis();
        if (!$redis) {
            return;
        }

        try {
            $redis->setex(self::PREFIX . $key, $ttl, json_encode($value));
        } catch (\Exception $e) {
            // Silently fail — caching is optional
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
        if (!$redis) {
            return;
        }

        try {
            $redis->del(self::PREFIX . $key);
        } catch (\Exception $e) {
            // Silently fail
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
        if (!$redis) {
            return;
        }

        try {
            $keys = $redis->keys(self::PREFIX . $pattern);
            if (!empty($keys)) {
                $redis->del($keys);
            }
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
