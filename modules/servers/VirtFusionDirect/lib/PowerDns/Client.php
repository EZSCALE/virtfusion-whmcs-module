<?php

namespace WHMCS\Module\Server\VirtFusionDirect\PowerDns;

use WHMCS\Module\Server\VirtFusionDirect\Cache;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\Log;

/**
 * Thin HTTP wrapper around the PowerDNS Authoritative HTTP API.
 *
 * WHY A SEPARATE CLIENT INSTEAD OF REUSING MODULE::INITCURL()
 * -----------------------------------------------------------
 * Module::initCurl() is hardcoded to Bearer auth for VirtFusion. PowerDNS uses
 * X-API-Key, and mixing the two authorization styles inside one factory method
 * would either require a new flag (leaky abstraction) or accidental leakage of
 * the VirtFusion token into a PowerDNS request. A dedicated wrapper keeps the
 * two credential flows completely isolated — a bug in PowerDNS handling can
 * never leak a VirtFusion token, and vice versa.
 *
 * LOGGING RULES
 * -------------
 * We NEVER pass the API key or any header containing it to Log::insert().
 * PATCH/NOTIFY calls log the zone+operation+HTTP code, successes log minimally,
 * errors include up to 500 bytes of response body (PowerDNS error responses are
 * small JSON fragments, not customer data). The Curl class doesn't capture
 * request headers by default (CURLOPT_HEADER is off), so even the internal
 * request_header field doesn't contain the API key.
 *
 * CACHING
 * -------
 * listZones() caches the zone list via the module's Cache class (Redis/filesystem)
 * for Config::cacheTtl() seconds. Zone lists rarely change — the TTL balances
 * "pick up a newly-created zone soon" against "don't hammer PowerDNS for every
 * listZones call across unrelated lifecycle events".
 *
 * getZone() and patchRRset() are NOT cached here; per-request memoisation of
 * getZone results lives in PtrManager::getZoneCached so it can invalidate on
 * write from within the same request.
 *
 * SINGLE-USE CURL INSTANCES
 * -------------------------
 * newCurl() returns a fresh Curl for every HTTP call. That's how the existing
 * module's Curl class is designed — reusing a handle across requests produces
 * undefined behaviour because options from the first call bleed into the second.
 * It's cheap (curl_init is microseconds).
 */
class Client
{
    /** @var string */
    private $endpoint;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $serverId;

    /**
     * @param  array<string,mixed>|null  $config  Optional pre-resolved config; defaults to PowerDns\Config::get()
     */
    public function __construct(?array $config = null)
    {
        $config = $config ?? Config::get();
        $this->endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->apiKey = (string) ($config['apiKey'] ?? '');
        $this->serverId = (string) ($config['serverId'] ?? 'localhost');
    }

    /** Base URL for the configured PowerDNS server (no trailing slash). */
    private function base(): string
    {
        return $this->endpoint . '/api/v1/servers/' . rawurlencode($this->serverId);
    }

    /**
     * Encode a zone name to its PowerDNS URL-safe id form.
     *
     * PowerDNS's API uses a custom URL encoding for zone names that have characters
     * like "/" which would collide with path semantics. Instead of using %-encoding
     * (which many HTTP frameworks would parse back out at routing time), PowerDNS
     * uses "=HH" where HH is the hex code — so "/" becomes "=2F".
     *
     * This only matters for RFC 2317 classless-delegation zone names like
     * "64/64.113.0.203.in-addr.arpa." whose zone id in the API is
     * "64=2F64.113.0.203.in-addr.arpa.". Standard zones pass through unchanged
     * because they contain no "/" characters.
     *
     * Using rawurlencode() here would produce "%2F" which PowerDNS does NOT accept.
     * That's why this is a plain str_replace.
     */
    private function zoneIdEncode(string $zoneName): string
    {
        return str_replace('/', '=2F', rtrim($zoneName, '.') . '.');
    }

    /** Fresh Curl instance with PowerDNS auth + JSON headers. */
    private function newCurl(): Curl
    {
        $curl = new Curl;
        $curl->addOption(CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
            'X-API-Key: ' . $this->apiKey,
        ]);

        return $curl;
    }

    /**
     * Healthcheck. Returns [ok: bool, http: int, error: ?string].
     * Used by the addon's Test Connection button and by VirtFusionDirect_TestConnection().
     *
     * @return array{ok: bool, http: int, error: ?string}
     */
    public function ping(): array
    {
        try {
            $curl = $this->newCurl();
            $body = $curl->get($this->base());
            $http = (int) $curl->getRequestInfo('http_code');
            if ($http === 200) {
                return ['ok' => true, 'http' => 200, 'error' => null];
            }
            if ($http === 0) {
                $err = (string) ($curl->getRequestInfo('curl_error') ?: 'connection failed');

                return ['ok' => false, 'http' => 0, 'error' => $err];
            }
            if ($http === 401 || $http === 403) {
                return ['ok' => false, 'http' => $http, 'error' => 'authentication failed (check API key)'];
            }

            return ['ok' => false, 'http' => $http, 'error' => 'unexpected HTTP ' . $http . ': ' . substr((string) $body, 0, 200)];
        } catch (\Throwable $e) {
            Log::insert('PowerDns:ping', [], $e->getMessage());

            return ['ok' => false, 'http' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * List every zone on the configured PowerDNS server.
     *
     * Result is cached for the configured cacheTtl. Used as the primary zone-discovery
     * strategy: PtrManager finds the containing zone for a PTR name by longest-suffix
     * matching against this list rather than probing individual zones.
     *
     * @return string[] Zone names with trailing dot
     */
    public function listZones(): array
    {
        $ttl = Config::cacheTtl();
        $cacheKey = 'pdns:zones:' . md5($this->endpoint . '|' . $this->serverId);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $zones = [];

        try {
            $curl = $this->newCurl();
            $body = $curl->get($this->base() . '/zones');
            $http = (int) $curl->getRequestInfo('http_code');

            if ($http === 200) {
                $decoded = json_decode((string) $body, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $z) {
                        if (! empty($z['name'])) {
                            $zones[] = rtrim((string) $z['name'], '.') . '.';
                        }
                    }
                }
            } else {
                Log::insert('PowerDns:listZones', ['http' => $http], substr((string) $body, 0, 500));
            }
        } catch (\Throwable $e) {
            Log::insert('PowerDns:listZones', [], $e->getMessage());
        }

        Cache::set($cacheKey, $zones, $ttl);

        return $zones;
    }

    /** Drop any cached zone list (call after PATCHes or settings changes). */
    public function forgetZoneCache(): void
    {
        $cacheKey = 'pdns:zones:' . md5($this->endpoint . '|' . $this->serverId);
        Cache::forget($cacheKey);
    }

    /**
     * Fetch a single zone by name. Returns decoded JSON array, or null on 404/error.
     *
     * @return array<string,mixed>|null
     */
    public function getZone(string $zoneName): ?array
    {
        try {
            $zoneName = rtrim($zoneName, '.') . '.';
            $curl = $this->newCurl();
            $body = $curl->get($this->base() . '/zones/' . $this->zoneIdEncode($zoneName));
            $http = (int) $curl->getRequestInfo('http_code');
            if ($http === 200) {
                $decoded = json_decode((string) $body, true);

                return is_array($decoded) ? $decoded : null;
            }
            if ($http !== 404) {
                Log::insert('PowerDns:getZone', ['zone' => $zoneName, 'http' => $http], substr((string) $body, 0, 500));
            }
        } catch (\Throwable $e) {
            Log::insert('PowerDns:getZone', ['zone' => $zoneName], $e->getMessage());
        }

        return null;
    }

    /**
     * Apply an RRset change to a zone via PATCH.
     *
     * $rrset keys (per PowerDNS API): name, type, ttl?, changetype (REPLACE|DELETE|EXTEND), records[].
     * On success PowerDNS returns 204 No Content.
     *
     * @return array{ok: bool, http: int, body: string}
     */
    public function patchRRset(string $zoneName, array $rrset): array
    {
        try {
            $zoneName = rtrim($zoneName, '.') . '.';
            if (isset($rrset['name'])) {
                $rrset['name'] = rtrim((string) $rrset['name'], '.') . '.';
            }

            $payload = ['rrsets' => [$rrset]];
            $curl = $this->newCurl();
            $curl->addOption(CURLOPT_POSTFIELDS, json_encode($payload));
            $body = $curl->patch($this->base() . '/zones/' . $this->zoneIdEncode($zoneName));
            $http = (int) $curl->getRequestInfo('http_code');

            Log::insert(
                'PowerDns:patchRRset',
                [
                    'zone' => $zoneName,
                    'name' => $rrset['name'] ?? null,
                    'type' => $rrset['type'] ?? null,
                    'changetype' => $rrset['changetype'] ?? null,
                ],
                ['http' => $http, 'body' => substr((string) $body, 0, 500)],
            );

            if ($http === 204) {
                // Fire-and-forget NOTIFY so slaves pick up the bumped SOA serial immediately.
                //
                // Background: PowerDNS auto-increments SOA on every API write when the zone
                // has soa_edit_api=INCREASE (recommended; see README). Slaves normally learn
                // about the new serial via polling at the refresh interval (often 15+ min)
                // OR via a NOTIFY push from the master. Without our NOTIFY, rDNS changes
                // made via this module would take effect on the authoritative master
                // immediately but wouldn't propagate until the next scheduled poll.
                //
                // Only meaningful for Master-kind zones. For Native zones (no slaves) or
                // Slave zones (reverse direction), PowerDNS returns a 422 or similar —
                // notifyZone() logs that and returns ok=false, but we don't care here:
                // the PATCH itself succeeded, which is what we report upward.
                $this->notifyZone($zoneName);
            }

            return ['ok' => $http === 204, 'http' => $http, 'body' => (string) $body];
        } catch (\Throwable $e) {
            Log::insert('PowerDns:patchRRset', ['zone' => $zoneName], $e->getMessage());

            return ['ok' => false, 'http' => 0, 'body' => $e->getMessage()];
        }
    }

    /**
     * Send a DNS NOTIFY to all slaves for this zone. Only applicable to Master-kind zones;
     * PowerDNS returns 400/422 for Native/Slave kinds and that's fine — we log and continue.
     *
     * SOA serial bumping itself is handled by PowerDNS (soa_edit_api=INCREASE or similar
     * on the zone); this call just ensures slaves learn about the new serial right away
     * rather than waiting for the next scheduled refresh.
     *
     * @return array{ok: bool, http: int}
     */
    public function notifyZone(string $zoneName): array
    {
        try {
            $zoneName = rtrim($zoneName, '.') . '.';
            $curl = $this->newCurl();
            $body = $curl->put($this->base() . '/zones/' . $this->zoneIdEncode($zoneName) . '/notify');
            $http = (int) $curl->getRequestInfo('http_code');

            if ($http !== 200) {
                Log::insert('PowerDns:notifyZone', ['zone' => $zoneName, 'http' => $http], substr((string) $body, 0, 300));
            }

            return ['ok' => $http === 200, 'http' => $http];
        } catch (\Throwable $e) {
            Log::insert('PowerDns:notifyZone', ['zone' => $zoneName], $e->getMessage());

            return ['ok' => false, 'http' => 0];
        }
    }
}
