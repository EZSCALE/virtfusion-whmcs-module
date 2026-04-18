<?php

namespace WHMCS\Module\Server\VirtFusionDirect\PowerDns;

use WHMCS\Database\Capsule as DB;
use WHMCS\Module\Server\VirtFusionDirect\Log;

/**
 * Loads PowerDNS addon settings from tbladdonmodules (module="virtfusiondns") and
 * decrypts the API key using WHMCS's native decrypt() helper.
 *
 * WHY "LOOSE COUPLING" VIA TBLADDONMODULES
 * ----------------------------------------
 * WHMCS lets an operator activate/deactivate addon modules independently of server
 * modules. If the server module required addon PHP code at load time (e.g. via
 * require_once on the addon's files), deactivating the addon would fatal-error every
 * checkout and service page.
 *
 * Instead, the server module reads raw rows from tbladdonmodules. If the addon is
 * missing OR deactivated OR "enabled" is set to No, isEnabled() returns false and
 * every PtrManager call site short-circuits. The server module never dereferences
 * addon code directly; it just asks the DB "what are the PowerDNS settings?" and
 * does nothing with them if they're absent.
 *
 * REQUEST-SCOPED CACHE
 * --------------------
 * get() caches the resolved config in a static property for the remainder of the
 * PHP request. Without that, every PtrManager call would re-query tbladdonmodules
 * and re-decrypt the API key — wasteful on the provisioning path where we touch
 * PowerDNS 1-5 times per server. reset() is exposed for scenarios where settings
 * change mid-request (the addon's _output() page after a vfdns_test click).
 *
 * API KEY HANDLING
 * ----------------
 * WHMCS stores password-type addon config fields encrypted in tbladdonmodules.value.
 * We call decrypt() — the same helper the server-module uses for the VirtFusion
 * bearer token — to get plaintext. If decryption fails (e.g. the WHMCS encryption
 * key changed or the value was inserted manually as plaintext), we fall back to
 * using the raw value. This is defensive; logs note the failure so an operator
 * can diagnose.
 *
 * The decrypted key exists only in memory inside this process's request lifetime.
 * It's passed to PowerDns\Client via the get() array and used for the X-API-Key
 * header; it's never written to disk, logged, or sent anywhere except to the
 * configured PowerDNS endpoint.
 */
class Config
{
    /**
     * Name used for this addon in modules/addons/ AND stored in tbladdonmodules.module.
     * These two MUST match — WHMCS auto-lowercases the module directory name when
     * writing to the DB, so "VirtFusionDns" (directory) becomes "virtfusiondns" here.
     */
    public const MODULE_NAME = 'virtfusiondns';

    /** @var array<string,mixed>|null Null = not loaded yet; an array = resolved settings */
    private static $cached = null;

    /**
     * Force a reload on next get().
     *
     * Primary use case: the addon's _output() page calls this before re-fetching
     * config so a test-connection click after saving settings sees the saved values.
     * Most other code should NOT call this — the request-scoped cache is there for
     * good performance reasons.
     */
    public static function reset(): void
    {
        self::$cached = null;
    }

    /**
     * Return the fully-resolved configuration array with decrypted apiKey.
     *
     * Keys: enabled(bool), endpoint(string), apiKey(string), serverId(string),
     *       defaultTtl(int), cacheTtl(int).
     */
    public static function get(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $config = [
            'enabled' => false,
            'endpoint' => '',
            'apiKey' => '',
            'serverId' => 'localhost',
            'defaultTtl' => 3600,
            'cacheTtl' => 60,
        ];

        try {
            // pluck('value', 'setting') returns a Collection keyed by 'setting' with
            // 'value' as the values — so $rows['enabled'] reads the row where
            // setting='enabled'. Efficient: one query regardless of how many
            // settings exist.
            $rows = DB::table('tbladdonmodules')
                ->where('module', self::MODULE_NAME)
                ->pluck('value', 'setting')
                ->toArray();

            // WHMCS yesno fields store either "on"/"" or "1"/"0" depending on version
            // and form handling. Accept all common truthy representations rather than
            // relying on a single literal.
            $enabledRaw = $rows['enabled'] ?? '';
            $config['enabled'] = in_array(strtolower((string) $enabledRaw), ['on', 'yes', '1', 'true'], true);

            // Trim trailing slash from endpoint so Client::base() can safely concatenate
            // "/api/v1/..." without producing doubled slashes.
            $config['endpoint'] = rtrim((string) ($rows['endpoint'] ?? ''), '/');
            $config['serverId'] = (string) ($rows['serverId'] ?? 'localhost');

            // Floor at 60s for defaultTtl and 10s for cacheTtl. Prevents a foot-gun
            // where an operator accidentally saves "0" and causes PowerDNS to treat
            // PTRs as non-cacheable (which some resolvers refuse) or this module to
            // hammer PowerDNS on every call.
            $config['defaultTtl'] = max(60, (int) ($rows['defaultTtl'] ?? 3600));
            $config['cacheTtl'] = max(10, (int) ($rows['cacheTtl'] ?? 60));

            if (! empty($rows['apiKey'])) {
                try {
                    // decrypt() is WHMCS's global helper — matches how the VirtFusion
                    // bearer token is handled in Module::getCP().
                    $decrypted = decrypt($rows['apiKey']);

                    // Fallback to raw value if decrypt returned empty or non-string —
                    // defends against the rare case where decrypt silently fails
                    // (wrong encryption key at rest) or the value was inserted
                    // manually as plaintext during development.
                    $config['apiKey'] = is_string($decrypted) && $decrypted !== '' ? $decrypted : (string) $rows['apiKey'];
                } catch (\Throwable $e) {
                    // Even when decrypt throws, we try the raw value so a diagnostic
                    // path exists. Operator sees the decrypt error in the module log
                    // but isn't locked out of using the addon while they investigate.
                    $config['apiKey'] = (string) $rows['apiKey'];
                    Log::insert('PowerDns:Config', 'decrypt skipped', $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Any DB-level failure (table doesn't exist, connection dropped, etc.)
            // leaves $config at its safe defaults — isEnabled() returns false,
            // nothing gets written to PowerDNS, and the server module continues
            // to provision as if the addon weren't installed.
            Log::insert('PowerDns:Config', 'load failed', $e->getMessage());
        }

        self::$cached = $config;

        return $config;
    }

    /** True only when the addon is activated, configured, and has both endpoint and key. */
    public static function isEnabled(): bool
    {
        $c = self::get();

        return $c['enabled'] && $c['endpoint'] !== '' && $c['apiKey'] !== '';
    }

    public static function endpoint(): string
    {
        return self::get()['endpoint'];
    }

    public static function apiKey(): string
    {
        return self::get()['apiKey'];
    }

    public static function serverId(): string
    {
        return self::get()['serverId'];
    }

    public static function defaultTtl(): int
    {
        return self::get()['defaultTtl'];
    }

    public static function cacheTtl(): int
    {
        return self::get()['cacheTtl'];
    }
}
