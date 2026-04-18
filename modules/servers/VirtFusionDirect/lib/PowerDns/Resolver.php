<?php

namespace WHMCS\Module\Server\VirtFusionDirect\PowerDns;

use WHMCS\Module\Server\VirtFusionDirect\Cache;
use WHMCS\Module\Server\VirtFusionDirect\Log;

/**
 * Public-DNS verification helper used for forward-confirmed reverse DNS (FCrDNS) checks.
 *
 * WHAT FCrDNS IS AND WHY IT MATTERS HERE
 * --------------------------------------
 * A PTR record by itself is easy to lie about — anyone who controls a reverse zone
 * can say "this IP is mail.example.com". Receivers defend against that by looking
 * UP the hostname the PTR claims and checking that its A/AAAA records point back
 * at the IP. That "two-way agreement" is FCrDNS.
 *
 * For mail deliverability in particular, a PTR without matching forward DNS is
 * worse than no PTR at all — some filters treat it as evidence of a compromised
 * host. The module enforces FCrDNS before every PTR write: if the user asks us
 * to set "mail.example.com" as the PTR for 1.2.3.4 but mail.example.com resolves
 * to something other than 1.2.3.4, we refuse.
 *
 * USES PUBLIC DNS, NOT POWERDNS
 * -----------------------------
 * This calls dns_get_record(), which hits the system's configured recursive
 * resolver. That's deliberate: the hostname in a PTR may live in a zone hosted
 * anywhere (client's own domain, another DNS provider, etc.) — not necessarily
 * in the PowerDNS instance we're managing. Using the recursive public view means
 * our verification matches what mail servers and other FCrDNS checkers actually
 * see downstream.
 *
 * CNAME FOLLOWING
 * ---------------
 * If the hostname is itself a CNAME, dns_get_record returns the CNAME record
 * (with DNS_CNAME flag) rather than auto-resolving to the ultimate A/AAAA. We
 * follow up to MAX_CNAME_DEPTH hops before giving up. The depth cap prevents
 * accidental infinite loops from misconfigured zones and bounds work per check.
 *
 * CACHING
 * -------
 * Keyed by md5(hostname|ip). A bad-A-record result lives in the cache just like
 * a good one, which means a client who fixes their forward DNS must wait up to
 * cacheTtl seconds before a retry succeeds. Documented in the admin settings
 * tooltip as the tradeoff for not hammering authoritative resolvers when a
 * user mashes the Save button while debugging.
 */
class Resolver
{
    private const CACHE_PREFIX = 'pdns:resolve:';

    /**
     * Maximum hops through a CNAME chain before we give up.
     * Real-world chains are usually 0-2 hops; 5 is generous headroom without
     * letting a loop run unbounded.
     */
    private const MAX_CNAME_DEPTH = 5;

    /**
     * Does the public DNS A/AAAA of $hostname resolve to $ip?
     * Follows up to 5 CNAME hops. Cached for $ttl seconds on the initial call.
     */
    public static function resolvesTo(string $hostname, string $ip, int $ttl = 60): bool
    {
        $hostname = rtrim(trim($hostname), '.');
        if ($hostname === '' || ! (IpUtil::isIpv4($ip) || IpUtil::isIpv6($ip))) {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX . md5($hostname . '|' . $ip);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $match = self::resolveInternal($hostname, $ip, 0);
        Cache::set($cacheKey, $match ? 1 : 0, $ttl);

        return $match;
    }

    private static function resolveInternal(string $hostname, string $ip, int $depth): bool
    {
        if ($depth > self::MAX_CNAME_DEPTH) {
            return false;
        }

        // Request both the matching forward type AND CNAME in one query so we see
        // the whole picture at each hop. If the hostname is a direct A/AAAA, we
        // see that and match immediately; if it's a CNAME, we see the target and
        // recurse.
        $type = IpUtil::isIpv6($ip) ? DNS_AAAA | DNS_CNAME : DNS_A | DNS_CNAME;
        $records = [];

        try {
            // @-suppress: dns_get_record emits a PHP warning on NXDOMAIN, which we'd
            // rather just treat as "no match". The return value (empty array or false)
            // tells us the same thing without polluting the error log.
            $records = @dns_get_record($hostname, $type);
        } catch (\Throwable $e) {
            // Some PHP configurations throw on resolver failure instead of returning false.
            // We treat those as "no match" and log once per (hostname, ip) since callers
            // cache the result — we won't spam the log even for a permanently-broken name.
            Log::insert('PowerDns:Resolver', ['hostname' => $hostname, 'ip' => $ip], $e->getMessage());

            return false;
        }
        if (! is_array($records)) {
            // dns_get_record returns false on resolver failure. Same semantics as above.
            return false;
        }

        // Convert target to binary once, outside the loop. inet_pton normalises
        // "2001:db8::1" and "2001:0db8:0000:0000:0000:0000:0000:0001" to the same
        // bytes, so we can compare regardless of how the resolver formatted its reply.
        $targetBin = @inet_pton($ip);
        foreach ($records as $r) {
            $t = $r['type'] ?? null;
            if ($t === 'CNAME') {
                // CNAME hop: recurse on the target. We don't use a visited-set to
                // detect cycles — MAX_CNAME_DEPTH is a simpler, sufficient guard.
                $next = $r['target'] ?? null;
                if ($next && self::resolveInternal(rtrim($next, '.'), $ip, $depth + 1)) {
                    return true;
                }

                continue;
            }

            // A records expose the address under 'ip', AAAA records under 'ipv6'.
            // Only one of these will be set per record; the other is null.
            $candidate = $r['ip'] ?? ($r['ipv6'] ?? null);
            if ($candidate && $targetBin !== false && @inet_pton($candidate) === $targetBin) {
                return true;
            }
        }

        return false;
    }
}
