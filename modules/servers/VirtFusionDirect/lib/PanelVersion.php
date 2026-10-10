<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Parse and cache the installed panel version from GET /api/v1/status.
 *
 * A 503 response may contain a usable version. Only missing routes mean unknown;
 * authentication, transport and malformed responses never imply an old panel.
 */
class PanelVersion
{
    /**
     * Decode a documented version name or number from a 200/503 status response.
     *
     * @param  mixed  $body  Decoded response array or raw JSON string.
     * @return string|null Semantic version; null only for a missing status route.
     *
     * @throws \RuntimeException Authorization/transport failure or unreadable version.
     */
    public static function parse(int $code, $body): ?string
    {
        if ($code === 404 || $code === 405) {
            return null;
        }
        if (! in_array($code, [200, 503], true)) {
            throw new \RuntimeException('Unable to authenticate or read panel version.');
        }
        $body = is_array($body) ? $body : json_decode((string) $body, true);
        $v = $body['data']['version'] ?? null;
        if (is_array($v)) {
            if (isset($v['name']) && preg_match('/^(\d+\.\d+\.\d+)(?:\s|$)/D', $v['name'], $m)) {
                return $m[1];
            }
            if (isset($v['number']) && preg_match('/^(\d)(\d{3})(\d{3})(\d{3})$/D', (string) $v['number'], $m)) {
                return (int) $m[1] . '.' . (int) $m[2] . '.' . (int) $m[3];
            }
        }

        throw new \RuntimeException('Panel returned an unreadable version.');
    }

    /**
     * Require a known installed version at least as new as the feature minimum.
     */
    public static function supports(?string $version, string $minimum): bool
    {
        return $version !== null && version_compare($version, $minimum, '>=');
    }

    /**
     * Read GET /status and cache the version for five minutes per panel/token.
     *
     * Never cache credentials or mistake authentication failure for legacy support.
     *
     * @param  array  $cp  Server-resolved panel connection including url and token.
     */
    public static function detect(Module $module, array $cp): ?string
    {
        $key = $module->panelCacheKey($cp, 'version');
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('version', $cached)) {
            return $cached['version'];
        }
        $request = $module->initCurl($cp['token']);
        $body = $request->get($cp['url'] . '/status');
        $version = self::parse((int) $request->getRequestInfo('http_code'), $body);
        Cache::set($key, ['version' => $version], 300);

        return $version;
    }
}
