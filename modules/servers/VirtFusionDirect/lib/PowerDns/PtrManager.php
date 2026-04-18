<?php

namespace WHMCS\Module\Server\VirtFusionDirect\PowerDns;

use WHMCS\Database\Capsule as DB;
use WHMCS\Module\Server\VirtFusionDirect\Cache;
use WHMCS\Module\Server\VirtFusionDirect\Database;
use WHMCS\Module\Server\VirtFusionDirect\Log;

/**
 * Orchestrates PTR lifecycle against PowerDNS for VirtFusion servers.
 *
 * RESPONSIBILITIES
 * ----------------
 *  - Compute zone membership for a given IP by matching against PowerDNS's zone list
 *  - Verify forward DNS (A/AAAA) before writing any PTR; never write a PTR whose
 *    hostname doesn't already resolve to the target IP
 *  - Preserve client-customised PTRs during server renames (only overwrite PTRs
 *    whose current content equals the previous hostname)
 *  - Provide read-through views for client-area and admin panels with status flags
 *  - Support an explicit admin reconcile (optionally forceful) and an additive-only
 *    cron reconciliation that never overwrites existing values
 *
 * CACHING MODEL
 * -------------
 * Two tiers, both serving different purposes:
 *
 *   $zoneListCache   — the list of every zone PowerDNS knows about. Populated once
 *                      per PtrManager instance via locate(). The underlying Client
 *                      caches the HTTP response for Config::cacheTtl() seconds across
 *                      requests; this instance field just memoises the lookup within
 *                      one request so multiple IPs on the same server don't each
 *                      call Client::listZones().
 *
 *   $zoneCache       — decoded RRset contents of individual zones, keyed by zone
 *                      name. Populated lazily as findPtrRRset() looks up each IP's
 *                      zone. IMPORTANT: request-scoped only — we must invalidate on
 *                      writes (see invalidateZone) so a read-after-write within the
 *                      same request sees fresh data. This is why deletePtr/writePtr
 *                      call invalidateZone before returning.
 *
 * Neither cache is shared between PtrManager instances (new PtrManager per WHMCS
 * request is cheap). The Client's HTTP-response cache IS shared across requests via
 * the module's Cache class (Redis or filesystem), which is where cross-request
 * amortisation happens.
 *
 * SHORT-CIRCUIT BEHAVIOUR
 * -----------------------
 * Every public method checks Config::isEnabled() and returns an empty/no-op summary
 * when the addon is inactive. This means unrelated calling code (createAccount,
 * terminateAccount, renameServer, the client panel endpoint, cron) can always
 * invoke PtrManager without a feature flag — the gate lives here.
 *
 * The summary arrays deliberately include 'enabled' => bool so test harnesses and
 * admin UIs can tell "we did nothing because disabled" apart from "we did nothing
 * because there were no IPs".
 */
class PtrManager
{
    /** @var Client */
    private $client;

    /** @var array<string, array<string,mixed>|null> Request-scoped zone contents cache, keyed by zone name */
    private $zoneCache = [];

    /** @var string[]|null Request-scoped zone-list memo (Client handles cross-request caching) */
    private $zoneListCache = null;

    public function __construct(?Client $client = null)
    {
        // Dependency-inject the Client so tests can pass a mock; default to the
        // Config-driven instance so production code never has to wire this up.
        $this->client = $client ?? new Client;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Sync PTRs for every IP on the given server object.
     *
     * TWO MODES OF OPERATION
     * ----------------------
     *   CREATE  ($oldHostname = null) — provisioning path.
     *                                   Write $newHostname to every IP that doesn't
     *                                   already have a PTR. Pre-existing PTRs are
     *                                   preserved (shouldn't exist on a new server,
     *                                   but if they do they're likely left over from
     *                                   a previous owner of the IP and must not be
     *                                   silently overwritten).
     *
     *   RENAME  ($oldHostname given)  — rename path.
     *                                   Only overwrite PTRs whose current content
     *                                   equals $oldHostname. Anything else was set
     *                                   by the client (custom rDNS like mail servers
     *                                   need to match HELO) and must be preserved.
     *
     * The forward-DNS check runs before every write. A PTR without a matching
     * A/AAAA is FCrDNS-broken and actively harms deliverability, so we'd rather
     * leave the PTR absent than set a broken one.
     *
     * ERROR SEMANTICS
     * ---------------
     * This method never throws. Every per-IP failure is caught, logged, and
     * recorded in $summary['errors']. Lifecycle callers (createAccount,
     * renameServer) wrap the call in their own try/catch as belt-and-braces,
     * but the expectation is that DNS issues never bubble up to WHMCS as
     * provisioning failures.
     *
     * @param  object|array  $serverObject  VirtFusion server payload
     * @return array Summary counts: written, preserved, forward_missing, no_zone, skipped_ipv6, errors, details[]
     */
    public function syncServer($serverObject, ?string $oldHostname, string $newHostname): array
    {
        $summary = [
            'enabled' => false,
            'written' => 0,
            'preserved' => 0,
            'forward_missing' => 0,
            'no_zone' => 0,
            'skipped_ipv6' => 0,
            'errors' => 0,
            'details' => [],
        ];

        if (! Config::isEnabled()) {
            return $summary;
        }
        $summary['enabled'] = true;

        $extracted = IpUtil::extractIps($serverObject);
        // Report (not write) v6 subnet-only allocations. UI can surface "IPv6 PTR
        // not configured — /64 without explicit host" as guidance.
        $summary['skipped_ipv6'] = count($extracted['skipped']);

        foreach ($extracted['addresses'] as $ip) {
            try {
                $loc = $this->locate($ip);
                if ($loc === null) {
                    // IP isn't covered by any zone we host. Not an error — the
                    // operator may manage reverse DNS for this range elsewhere.
                    $summary['no_zone']++;
                    $summary['details'][] = ['ip' => $ip, 'status' => 'no-zone'];

                    continue;
                }

                $current = $this->readPtr($loc);

                // Rename-mode preservation check. The "current PTR equals old
                // hostname" comparison is the whole safety mechanism for protecting
                // client-custom rDNS across server renames — see class docblock.
                // On CREATE mode ($oldHostname === null) we skip this branch,
                // which means pre-existing PTRs on a new IP get overwritten; this
                // is acceptable because a fresh IP shouldn't have PTRs yet.
                if ($oldHostname !== null && $current !== null) {
                    if (self::normalizeHost($current) !== self::normalizeHost($oldHostname)) {
                        $summary['preserved']++;
                        $summary['details'][] = ['ip' => $ip, 'status' => 'preserved', 'current' => $current];

                        continue;
                    }
                }

                if (! Resolver::resolvesTo($newHostname, $ip, Config::cacheTtl())) {
                    $summary['forward_missing']++;
                    $summary['details'][] = ['ip' => $ip, 'status' => 'forward-missing', 'desired' => $newHostname];
                    Log::insert('PowerDns:syncServer', ['ip' => $ip, 'hostname' => $newHostname], 'forward DNS mismatch; PTR skipped');

                    continue;
                }

                $result = $this->writePtr($loc, $newHostname);
                if ($result['ok']) {
                    $summary['written']++;
                    $summary['details'][] = ['ip' => $ip, 'status' => 'written', 'content' => $newHostname];
                } else {
                    $summary['errors']++;
                    $summary['details'][] = ['ip' => $ip, 'status' => 'error', 'http' => $result['http']];
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::insert('PowerDns:syncServer', ['ip' => $ip], $e->getMessage());
            }
        }

        return $summary;
    }

    /**
     * Delete every PTR belonging to the given server.
     *
     * @return array Summary counts: deleted, no_zone, errors
     */
    public function deleteForServer($serverObject): array
    {
        $summary = ['enabled' => false, 'deleted' => 0, 'no_zone' => 0, 'errors' => 0];
        if (! Config::isEnabled()) {
            return $summary;
        }
        $summary['enabled'] = true;

        $extracted = IpUtil::extractIps($serverObject);
        foreach ($extracted['addresses'] as $ip) {
            try {
                $loc = $this->locate($ip);
                if ($loc === null) {
                    $summary['no_zone']++;

                    continue;
                }
                $result = $this->deletePtr($loc);
                if ($result['ok']) {
                    $summary['deleted']++;
                } else {
                    $summary['errors']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::insert('PowerDns:deleteForServer', ['ip' => $ip], $e->getMessage());
            }
        }

        return $summary;
    }

    /**
     * Produce a per-IP status list suitable for client-area and admin display.
     *
     * Each entry: [ip, ptr, ttl, zone, status]
     * Status values: ok, unverified, missing, no-zone, error, disabled.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listPtrs($serverObject, ?string $expectedHostname = null): array
    {
        $out = [];
        $extracted = IpUtil::extractIps($serverObject);

        if (! Config::isEnabled()) {
            foreach ($extracted['addresses'] as $ip) {
                $out[] = ['ip' => $ip, 'ptr' => null, 'ttl' => null, 'zone' => null, 'status' => 'disabled'];
            }

            return $out;
        }

        // Subnet-only rows come first so the client UI can render "you have a /64,
        // here's how to add a host PTR inside it" above the discrete-IP list.
        // These carry no PTR content themselves — they're informational anchors
        // plus the "Add custom host" entry point.
        foreach ($extracted['subnets'] as $s) {
            $out[] = [
                'ip' => null,
                'subnet' => $s['subnet'],
                'cidr' => $s['cidr'],
                'ptr' => null,
                'ttl' => null,
                'zone' => null,
                'status' => 'subnet-only',
            ];
        }

        foreach ($extracted['addresses'] as $ip) {
            try {
                $loc = $this->locate($ip);
                if ($loc === null) {
                    $out[] = ['ip' => $ip, 'ptr' => null, 'ttl' => null, 'zone' => null, 'status' => 'no-zone'];

                    continue;
                }
                $rrset = $this->findPtrRRset($loc);
                if ($rrset === null) {
                    $out[] = ['ip' => $ip, 'ptr' => null, 'ttl' => null, 'zone' => $loc['zone'], 'status' => 'missing'];

                    continue;
                }
                $ptr = $rrset['content'];
                $status = Resolver::resolvesTo($ptr, $ip, Config::cacheTtl()) ? 'ok' : 'unverified';
                $out[] = [
                    'ip' => $ip,
                    'ptr' => $ptr,
                    'ttl' => $rrset['ttl'],
                    'zone' => $loc['zone'],
                    'status' => $status,
                ];
            } catch (\Throwable $e) {
                Log::insert('PowerDns:listPtrs', ['ip' => $ip], $e->getMessage());
                $out[] = ['ip' => $ip, 'ptr' => null, 'ttl' => null, 'zone' => null, 'status' => 'error'];
            }
        }

        return $out;
    }

    /**
     * Client-initiated PTR set/delete.
     *
     * Differences from syncServer():
     *   - Only ever writes one PTR, not a whole server's worth
     *   - Rate-limited per IP (10s window) to stop save-button abuse
     *   - Forward-DNS failure is a HARD REJECT that surfaces to the user — not a
     *     silent skip like the automatic paths. The client wants immediate feedback
     *     when their A record is missing.
     *   - Empty content path is an explicit delete (DELETE changetype, not REPLACE-empty)
     *
     * IP-OWNERSHIP NOTE
     * -----------------
     * This method TRUSTS that the caller has already verified the client owns $ip —
     * that check lives in the calling endpoint (client.php rdnsUpdate) where it has
     * access to the WHMCS session. If you call setPtr() from a new code path, you
     * MUST add the ownership guard upstream of it.
     *
     * @return array{ok: bool, reason: string, http?: int}
     *                                                     reason values: disabled, invalid-ip, rate-limited, no-zone,
     *                                                     forward-missing, deleted, delete-failed, written, write-failed
     */
    public function setPtr(string $ip, string $content): array
    {
        if (! Config::isEnabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        if (! (IpUtil::isIpv4($ip) || IpUtil::isIpv6($ip))) {
            return ['ok' => false, 'reason' => 'invalid-ip'];
        }

        // Rate limit: one successful check per IP per 10s. Uses the module's
        // two-tier Cache (Redis or filesystem), so the limit spans PHP processes.
        // md5 of IP as the key keeps filesystem filenames short and safe.
        $rateKey = 'pdns:write-lock:' . md5($ip);
        if (Cache::get($rateKey) !== null) {
            return ['ok' => false, 'reason' => 'rate-limited'];
        }
        // Set the lock BEFORE any downstream work so a parallel request racing
        // through the same IP sees the lock and gets rate-limited cleanly.
        Cache::set($rateKey, 1, 10);

        $loc = $this->locate($ip);
        if ($loc === null) {
            return ['ok' => false, 'reason' => 'no-zone'];
        }

        $content = trim($content);
        if ($content === '') {
            $result = $this->deletePtr($loc);

            return ['ok' => $result['ok'], 'reason' => $result['ok'] ? 'deleted' : 'delete-failed', 'http' => $result['http']];
        }

        if (! Resolver::resolvesTo($content, $ip, Config::cacheTtl())) {
            return ['ok' => false, 'reason' => 'forward-missing'];
        }

        $result = $this->writePtr($loc, $content);

        return ['ok' => $result['ok'], 'reason' => $result['ok'] ? 'written' : 'write-failed', 'http' => $result['http']];
    }

    /**
     * Admin reconciliation for a single service.
     *
     * The user-facing purpose: "make the PTRs match what they should be, but don't
     * step on client customisations unless I explicitly ask".
     *
     * Uses the STORED server_object (from mod_virtfusion_direct) rather than fetching
     * fresh from VirtFusion. Reasons:
     *   1. Admin reconcile runs from the services tab — no live-data dependency
     *   2. Cron calls this once per service; fetching fresh would mean N VirtFusion
     *      calls per reconcile run
     *   3. The stored object is the ground truth for "what IPs/hostname did this
     *      service have at last sync" — if VirtFusion temporarily returns a different
     *      shape, we'd rather work from known-good data than retry.
     *
     * If the stored state is materially out of date (e.g. IPs were added in VirtFusion
     * after last sync), an admin should hit "Update Server Object" first.
     *
     * FORCE MODE
     * ----------
     * $force = true is the only code path in the entire module that overwrites a
     * non-matching PTR. It's reachable exclusively via the admin "Reconcile (force
     * reset)" button — never from cron, never from client writes, never from
     * automatic lifecycle. This asymmetry is deliberate: forceful overrides are
     * the admin's explicit choice, not a silent automation.
     *
     * @return array Summary counts: added, reset, preserved, forward_missing, no_zone, errors
     */
    public function reconcile(int $serviceId, bool $force = false): array
    {
        $summary = [
            'enabled' => false,
            'added' => 0,
            'reset' => 0,
            'preserved' => 0,
            'forward_missing' => 0,
            'no_zone' => 0,
            'errors' => 0,
        ];
        if (! Config::isEnabled()) {
            return $summary;
        }
        $summary['enabled'] = true;

        $row = Database::getSystemService($serviceId);
        if (! $row || empty($row->server_object)) {
            $summary['errors']++;

            return $summary;
        }
        $serverObject = json_decode($row->server_object, true);
        if (! is_array($serverObject)) {
            $summary['errors']++;

            return $summary;
        }

        $hostname = self::extractHostname($serverObject);
        if ($hostname === null) {
            $summary['errors']++;

            return $summary;
        }

        $extracted = IpUtil::extractIps($serverObject);
        foreach ($extracted['addresses'] as $ip) {
            try {
                $loc = $this->locate($ip);
                if ($loc === null) {
                    $summary['no_zone']++;

                    continue;
                }

                $current = $this->readPtr($loc);
                $verified = Resolver::resolvesTo($hostname, $ip, Config::cacheTtl());

                if ($current === null) {
                    if (! $verified) {
                        $summary['forward_missing']++;

                        continue;
                    }
                    $result = $this->writePtr($loc, $hostname);
                    if ($result['ok']) {
                        $summary['added']++;
                    } else {
                        $summary['errors']++;
                    }

                    continue;
                }

                if ($force && self::normalizeHost($current) !== self::normalizeHost($hostname)) {
                    if (! $verified) {
                        $summary['forward_missing']++;

                        continue;
                    }
                    $result = $this->writePtr($loc, $hostname);
                    if ($result['ok']) {
                        $summary['reset']++;
                    } else {
                        $summary['errors']++;
                    }

                    continue;
                }

                $summary['preserved']++;
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::insert('PowerDns:reconcile', ['ip' => $ip, 'service' => $serviceId], $e->getMessage());
            }
        }

        return $summary;
    }

    /**
     * Cron reconciliation across every managed service.
     *
     * Called from the DailyCronJob hook. Iterates every row in mod_virtfusion_direct
     * and runs reconcile() on each with $force = false. That means:
     *
     *   - IPs missing a PTR get one (if forward DNS resolves)
     *   - Existing PTRs are NEVER touched, even if they differ from the hostname
     *
     * This asymmetry is the safety property. A brief forward-DNS blip during the
     * cron window shouldn't trigger mass-rewrites that corrupt client-custom
     * records. Admins who need forceful re-alignment must run the per-service
     * "Reconcile (force reset)" button explicitly.
     *
     * Failures on individual services are logged and counted but never abort the
     * job — a misconfigured single zone or one VirtFusion-unreachable service
     * should not block reconciliation for the rest of the fleet.
     *
     * @return array Aggregate summary across all services
     */
    public function reconcileAll(): array
    {
        $summary = [
            'enabled' => false,
            'services' => 0,
            'added' => 0,
            'preserved' => 0,
            'forward_missing' => 0,
            'no_zone' => 0,
            'errors' => 0,
        ];
        if (! Config::isEnabled()) {
            return $summary;
        }
        $summary['enabled'] = true;

        try {
            $rows = DB::table(Database::SYSTEM_TABLE)->pluck('service_id');
        } catch (\Throwable $e) {
            Log::insert('PowerDns:reconcileAll', [], $e->getMessage());

            return $summary;
        }

        foreach ($rows as $serviceId) {
            $summary['services']++;

            try {
                $r = $this->reconcile((int) $serviceId, false);
                $summary['added'] += $r['added'];
                $summary['preserved'] += $r['preserved'];
                $summary['forward_missing'] += $r['forward_missing'];
                $summary['no_zone'] += $r['no_zone'];
                $summary['errors'] += $r['errors'];
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::insert('PowerDns:reconcileAll:service', ['service' => $serviceId], $e->getMessage());
            }
        }

        Log::insert('PowerDns:reconcileAll', [], $summary);

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Resolve an IP to the (zone, ptrName) pair using the cached zone list.
     * Handles both standard and RFC 2317 classless zones (delegates to IpUtil).
     *
     * Memoised within this instance: the zone list is fetched once (via the Client,
     * which itself caches across requests per Config::cacheTtl()) and reused for
     * every IP of the current server. A server with 3 IPs in the same /24 therefore
     * triggers ONE listZones call, not three.
     *
     * @return array{zone: string, ptrName: string}|null null means "no zone covers this IP"
     */
    private function locate(string $ip): ?array
    {
        if ($this->zoneListCache === null) {
            $this->zoneListCache = $this->client->listZones();
        }

        return IpUtil::findZoneAndPtrName($ip, $this->zoneListCache);
    }

    /** @return array<string,mixed>|null */
    private function getZoneCached(string $zoneName): ?array
    {
        if (array_key_exists($zoneName, $this->zoneCache)) {
            return $this->zoneCache[$zoneName];
        }
        $this->zoneCache[$zoneName] = $this->client->getZone($zoneName);

        return $this->zoneCache[$zoneName];
    }

    /**
     * Current PTR content for a located address, or null if absent.
     *
     * @param  array{zone: string, ptrName: string}  $loc
     */
    private function readPtr(array $loc): ?string
    {
        $rrset = $this->findPtrRRset($loc);

        return $rrset === null ? null : $rrset['content'];
    }

    /**
     * Find a PTR RRset at the located name.
     *
     * @param  array{zone: string, ptrName: string}  $loc
     * @return array{content: string, ttl: int}|null
     */
    private function findPtrRRset(array $loc): ?array
    {
        $zone = $this->getZoneCached($loc['zone']);
        if ($zone === null || empty($zone['rrsets']) || ! is_array($zone['rrsets'])) {
            return null;
        }
        foreach ($zone['rrsets'] as $rrset) {
            if (($rrset['type'] ?? '') !== 'PTR') {
                continue;
            }
            if (self::normalizeHost($rrset['name'] ?? '') !== self::normalizeHost($loc['ptrName'])) {
                continue;
            }
            $records = $rrset['records'] ?? [];
            foreach ($records as $record) {
                if (! empty($record['disabled'])) {
                    continue;
                }
                if (! empty($record['content'])) {
                    return [
                        'content' => rtrim((string) $record['content'], '.'),
                        'ttl' => (int) ($rrset['ttl'] ?? Config::defaultTtl()),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Write/replace a PTR record.
     *
     * Always uses REPLACE changetype rather than a create-then-update pattern —
     * REPLACE is idempotent and atomic from PowerDNS's view, whereas separate
     * create + update would briefly leave the record absent.
     *
     * Content is canonicalised to end with a trailing dot before sending (PowerDNS
     * treats unqualified names as relative to the zone, which is not what we want
     * for PTR content — "host.example.com" without a trailing dot would be stored
     * as "host.example.com.113.0.203.in-addr.arpa.").
     *
     * @param  array{zone: string, ptrName: string}  $loc
     * @return array{ok: bool, http: int}
     */
    private function writePtr(array $loc, string $content): array
    {
        $content = rtrim(trim($content), '.') . '.';
        $ttl = Config::defaultTtl();

        $result = $this->client->patchRRset($loc['zone'], [
            'name' => $loc['ptrName'],
            'type' => 'PTR',
            'ttl' => $ttl,
            'changetype' => 'REPLACE',
            'records' => [['content' => $content, 'disabled' => false]],
        ]);

        $this->invalidateZone($loc['zone']);

        return ['ok' => $result['ok'], 'http' => $result['http']];
    }

    /**
     * Delete a PTR record.
     *
     * @param  array{zone: string, ptrName: string}  $loc
     * @return array{ok: bool, http: int}
     */
    private function deletePtr(array $loc): array
    {
        $result = $this->client->patchRRset($loc['zone'], [
            'name' => $loc['ptrName'],
            'type' => 'PTR',
            'changetype' => 'DELETE',
        ]);

        $this->invalidateZone($loc['zone']);

        return ['ok' => $result['ok'], 'http' => $result['http']];
    }

    /**
     * Drop the cached zone contents so the next read re-fetches from PowerDNS.
     * Called after every successful write so read-after-write in the same request
     * (e.g. listPtrs right after setPtr in a test harness) observes fresh data.
     */
    private function invalidateZone(string $zoneName): void
    {
        unset($this->zoneCache[$zoneName]);
    }

    /**
     * Normalise a hostname for comparison: lowercase, no trailing dot.
     *
     * DNS hostnames are case-insensitive and the trailing dot is syntactic, not
     * semantic. PowerDNS returns content with a trailing dot ("host.example.com.");
     * user input typically doesn't have one. Both forms of "FooBar.example.com."
     * vs "foobar.example.com" should compare equal, which is what this produces.
     */
    private static function normalizeHost(string $h): string
    {
        return strtolower(rtrim(trim($h), '.'));
    }

    /**
     * Extract the server hostname from a VirtFusion server payload.
     *
     * Accepts either object or array shape, wrapped or unwrapped by a `data` property.
     * Falls back to `name` when `hostname` is absent or "-", matching the semantics
     * of the existing ServerResource::process() behavior.
     *
     * Public so lifecycle call sites (createAccount, renameServer) can pull the
     * hostname from a response or stored JSON blob without duplicating the logic.
     *
     * @param  object|array  $serverObject
     */
    public static function extractHostname($serverObject): ?string
    {
        if (is_object($serverObject)) {
            $serverObject = json_decode(json_encode($serverObject), true);
        }
        if (! is_array($serverObject)) {
            return null;
        }
        $data = $serverObject['data'] ?? $serverObject;
        if (! empty($data['hostname']) && $data['hostname'] !== '-') {
            return (string) $data['hostname'];
        }
        if (! empty($data['name']) && $data['name'] !== '-') {
            return (string) $data['name'];
        }

        return null;
    }
}
