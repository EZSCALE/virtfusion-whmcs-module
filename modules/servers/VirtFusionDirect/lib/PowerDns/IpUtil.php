<?php

namespace WHMCS\Module\Server\VirtFusionDirect\PowerDns;

/**
 * Pure static helpers for IP address manipulation and PTR-name construction.
 *
 * DESIGN NOTES
 * ------------
 * Everything here is pure — no I/O, no globals, no state. That matters for two reasons:
 *   1. PtrManager can compose these helpers freely without worrying about test isolation.
 *   2. They are safe to call inside tight loops (e.g. iterating every zone in PowerDNS
 *      and testing it against a PTR name) without triggering hidden network or DB hits.
 *
 * Naming conventions used here:
 *   - "PTR name"  = the fully-qualified record name the PTR lives at,
 *                   e.g. "5.113.0.203.in-addr.arpa."  (trailing dot always).
 *   - "zone name" = the zone the record belongs to,
 *                   e.g. "113.0.203.in-addr.arpa."    (trailing dot always).
 *   - "nibble"    = a single hex digit representing 4 bits, used in IPv6 reverse names.
 *   - "classless" = an RFC 2317 sub-zone like "64/64.113.0.203.in-addr.arpa." —
 *                   a delegation of a sub-range of a /24, covered in parseClasslessZone().
 *
 * All zone/PTR strings are normalised with a trailing dot because PowerDNS's canonical
 * form always carries one, and mixing dotted/undotted forms makes string comparison
 * unreliable (".example.com." ≠ ".example.com").
 */
class IpUtil
{
    /** Strict IPv4 validation (rejects "1", "::1", and other ambiguous forms). */
    public static function isIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /** Strict IPv6 validation (rejects IPv4-mapped, etc. — only pure v6 addresses). */
    public static function isIpv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Fully-expand an IPv6 address to 32 lowercase hex characters (no colons).
     * e.g. 2001:db8::1 -> "20010db8000000000000000000000001"
     *
     * Why: PTR names under ip6.arpa use *all* 32 nibbles (no compression, no :: shorthand),
     * so we need the fully-expanded form before we can reverse the nibbles.
     *
     * Implementation: inet_pton normalises any valid IPv6 notation to 16 raw bytes,
     * and bin2hex turns that into 32 lowercase hex chars. No manual padding/splitting
     * logic means we can't get ":" vs "::" compression wrong.
     *
     * @return string|null 32-char hex string, or null if input isn't valid IPv6
     */
    public static function expandIpv6(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        // inet_pton returns 16 bytes for v6, 4 bytes for v4. Guard on both conditions
        // so a valid IPv4 like "192.0.2.1" doesn't silently pass through this v6 helper.
        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }

        return bin2hex($bin);
    }

    /**
     * Build the fully-qualified PTR name (trailing dot) for an IPv4 or IPv6 address.
     *
     * IPv4 example: 203.0.113.5 -> "5.113.0.203.in-addr.arpa."
     * IPv6 example: 2001:db8::1 -> "1.0.0.0.[...].8.b.d.0.1.0.0.2.ip6.arpa."
     *
     * @return string|null PTR name with trailing dot, or null if input isn't a valid IP
     */
    public static function ptrNameForIp(string $ip): ?string
    {
        // IPv4: reverse the four octets and suffix with in-addr.arpa.
        //   203.0.113.5  ->  5.113.0.203.in-addr.arpa.
        if (self::isIpv4($ip)) {
            $octets = array_reverse(explode('.', $ip));

            return implode('.', $octets) . '.in-addr.arpa.';
        }

        // IPv6: expand to 32 nibbles, reverse each nibble, suffix with ip6.arpa.
        //   2001:db8::1  ->  1.0.0.0.[...].8.b.d.0.1.0.0.2.ip6.arpa.
        // The nibble-level reversal (not byte-level) is important: each hex digit
        // becomes its own DNS label. inet_pton/bin2hex give us the 32-char form;
        // str_split with no length arg defaults to 1 so each char becomes one label.
        if (self::isIpv6($ip)) {
            $hex = self::expandIpv6($ip);
            if ($hex === null) {
                return null;
            }
            $nibbles = array_reverse(str_split($hex));

            return implode('.', $nibbles) . '.ip6.arpa.';
        }

        return null;
    }

    /**
     * Extract every IP address and IPv6 subnet from a VirtFusion server object.
     *
     * Walks every interface, not just interfaces[0] (ServerResource only reads the primary).
     * Returns three buckets:
     *
     *   addresses  — discrete host IPs (v4 always, v6 when the API exposes per-host records
     *                or a /128 subnet entry). Each entry is a plain IP string.
     *
     *   subnets    — IPv6 subnet allocations (e.g. 2001:db8:0:5d::/64) where the module
     *                cannot auto-discover individual host addresses. These are surfaced
     *                so the client UI can show "here's your /64" and offer an "Add host PTR"
     *                path where the customer types a specific address inside the subnet.
     *                Each entry: ['subnet' => '2001:db8:0:5d::', 'cidr' => 64].
     *
     *   skipped    — malformed / unusable entries (non-IP, missing cidr, etc.) kept for
     *                logging so we can diagnose schema drift in the VirtFusion API.
     *
     * @param  object|array  $serverObject  Raw VirtFusion server payload (may be wrapped in `data`)
     * @return array{addresses: string[], subnets: array<int, array{subnet: string, cidr: int}>, skipped: array}
     */
    public static function extractIps($serverObject): array
    {
        $addresses = [];
        $subnets = [];
        $skipped = [];

        // Normalise object-or-array input. json_decode(json_encode($x), true) is the
        // cheapest defensive way to turn a stdClass tree (VirtFusion's response) or
        // an already-decoded array (stored server_object blob) into a uniform array.
        if (is_object($serverObject)) {
            $serverObject = json_decode(json_encode($serverObject), true);
        }
        if (! is_array($serverObject)) {
            return ['addresses' => [], 'subnets' => [], 'skipped' => []];
        }

        // VirtFusion wraps the payload in a "data" key on GET responses but the stored
        // server_object blob is sometimes already unwrapped. Accept both shapes.
        $data = $serverObject['data'] ?? $serverObject;
        $interfaces = $data['network']['interfaces'] ?? [];
        if (! is_array($interfaces)) {
            return ['addresses' => [], 'subnets' => [], 'skipped' => []];
        }

        // Walk every interface (not just interfaces[0]). ServerResource only reads [0]
        // because it's building display data for the "primary" IP; rDNS needs PTRs
        // for every IP no matter which interface it lives on.
        foreach ($interfaces as $iface) {
            foreach (($iface['ipv4'] ?? []) as $v4) {
                // Accept both "address" and "ip" field names — VirtFusion's schema
                // has evolved and we want the module to survive minor shape changes.
                $candidate = $v4['address'] ?? ($v4['ip'] ?? null);
                if ($candidate && self::isIpv4($candidate)) {
                    // Use the IP as an array key for free de-duplication. If the same
                    // IP appears on two interfaces (unusual but possible), we write
                    // one PTR not two.
                    $addresses[$candidate] = true;
                }
            }

            foreach (($iface['ipv6'] ?? []) as $v6) {
                // Preferred shape: a discrete host address (the normal v6 pattern).
                $candidate = $v6['address'] ?? ($v6['ip'] ?? null);
                if ($candidate && self::isIpv6($candidate)) {
                    $addresses[$candidate] = true;

                    continue;
                }

                // Subnet-with-cidr shape. VirtFusion's common v6 allocation model is
                // to route a whole /64 to the VPS and let the OS auto-assign specific
                // host addresses. The module can't know which host the customer
                // actually uses, so we surface the subnet as a first-class entry and
                // let the client UI offer an "Add host PTR" path with containment
                // ownership verification.
                $subnet = $v6['subnet'] ?? null;
                $cidr = isset($v6['cidr']) ? (int) $v6['cidr'] : null;
                if ($subnet && self::isIpv6($subnet) && $cidr !== null) {
                    if ($cidr === 128) {
                        // Single-host "subnet" — treat as a discrete address.
                        $addresses[$subnet] = true;
                    } elseif ($cidr > 0 && $cidr < 128) {
                        // Genuine subnet allocation. Dedupe by (subnet, cidr) pair.
                        $key = $subnet . '/' . $cidr;
                        if (! isset($subnets[$key])) {
                            $subnets[$key] = ['subnet' => $subnet, 'cidr' => $cidr];
                        }
                    } else {
                        $skipped[] = ['subnet' => $subnet, 'cidr' => $cidr, 'reason' => 'invalid-cidr'];
                    }
                }
            }
        }

        return [
            'addresses' => array_keys($addresses),
            'subnets' => array_values($subnets),
            'skipped' => $skipped,
        ];
    }

    /**
     * True if $ip falls inside the subnet $prefix/$cidrBits.
     *
     * Used for subnet-containment ownership checks when the customer wants to set
     * a PTR for a specific host address inside an IPv6 subnet allocated to their
     * VPS — we can't enumerate their assigned hosts, but we CAN prove the address
     * they're claiming lies within one of their subnets.
     *
     * Works on the binary (inet_pton) representation so v6 notation differences
     * (compression, case) don't affect the comparison.
     *
     * ALGORITHM
     * ---------
     *   1. Convert both IPs to 16 raw bytes via inet_pton (or 4 for v4).
     *   2. Compare the first floor(cidr/8) bytes byte-wise (full-byte prefix).
     *   3. If cidr isn't a multiple of 8, mask the next byte and compare bits.
     *
     * Example: 2001:db8::5 vs 2001:db8::/32
     *   fullBytes = 32/8 = 4; first 4 bytes of both are 20:01:0d:b8 → match
     *   remBits = 0 → no partial byte to compare
     *   → true
     */
    public static function ipv6InSubnet(string $ip, string $subnetPrefix, int $cidrBits): bool
    {
        if (! self::isIpv6($ip) || ! self::isIpv6($subnetPrefix)) {
            return false;
        }
        if ($cidrBits < 0 || $cidrBits > 128) {
            return false;
        }
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnetPrefix);
        if ($ipBin === false || $subBin === false) {
            return false;
        }

        $fullBytes = intdiv($cidrBits, 8);
        $remBits = $cidrBits % 8;

        // Compare whole-byte prefix with a single substr compare.
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subBin, 0, $fullBytes)) {
            return false;
        }

        // Compare the partial byte at the cidr boundary, if any.
        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find the longest-suffix zone from a list of zone names that contains a given PTR name.
     * Both inputs are normalised to a trailing dot before matching.
     *
     * @param  string  $ptrName  Fully-qualified PTR name (with or without trailing dot)
     * @param  string[]  $zones  List of zone names from PowerDNS (with or without trailing dots)
     * @return string|null Matching zone name with trailing dot, or null if no zone covers the PTR
     */
    public static function findContainingZone(string $ptrName, array $zones): ?string
    {
        $ptrName = rtrim($ptrName, '.') . '.';
        $best = null;
        $bestLen = 0;

        foreach ($zones as $zone) {
            if (! is_string($zone) || $zone === '') {
                continue;
            }
            if (strpos($zone, '/') !== false) {
                // RFC 2317 classless zones can't be identified by plain suffix match:
                // a PTR like "5.113.0.203.in-addr.arpa." does NOT end with
                // ".64/64.113.0.203.in-addr.arpa." even when 5 is in range. Range
                // matching lives in findZoneAndPtrName; this helper is kept for any
                // caller that only deals with standard zones.
                continue;
            }
            $z = rtrim($zone, '.') . '.';
            // Prefix with "." so a zone "example.com." doesn't accidentally match
            // "foo.anotherexample.com." via naive substring compare.
            $suffix = '.' . $z;
            if ($ptrName === $z || substr($ptrName, -strlen($suffix)) === $suffix) {
                // Longest match wins. For nested delegations (e.g. both
                // "0.203.in-addr.arpa." and "113.0.203.in-addr.arpa." exist),
                // the more specific one is the correct authoritative zone.
                $len = strlen($z);
                if ($len > $bestLen) {
                    $best = $z;
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }

    /**
     * Parse an RFC 2317 classless-delegation IPv4 reverse zone name.
     *
     * RFC 2317 lets a /24 owner delegate sub-ranges of that /24 to separate
     * authoritative servers by creating CNAMEs in the parent zone that point
     * into a named sub-zone. The sub-zone's label conventionally uses "X/Y"
     * where the slash carries structural meaning, not path semantics.
     *
     * Two "Y" conventions exist in the wild. We accept both:
     *
     *   (a) Y is a CIDR prefix length, Y ∈ [24, 32]. Standard per the RFC.
     *       "64/26.113.0.203.in-addr.arpa." — /26 → 64 addresses → covers 64..127
     *       "0/25.1.168.192.in-addr.arpa."  — /25 → 128 addresses → covers 0..127
     *
     *   (b) Y is a block size (count of addresses), Y > 32. Non-standard but
     *       used by some operators because the label reads naturally:
     *       "64/64.113.0.203.in-addr.arpa." — size 64 → covers 64..127
     *
     * We disambiguate by Y's magnitude: ≤32 is a prefix length, >32 is a count.
     * (Y=32 would be "a single-host delegation", valid under convention (a).)
     *
     * ALIGNMENT CHECK
     * ---------------
     * We also verify X is a multiple of the block size. Misaligned entries
     * like "3/26.x.y.z" don't correspond to any real DNS delegation — a /26
     * must start at a multiple of 64 (0, 64, 128, or 192). Rejecting these
     * prevents silent write-into-wrong-zone if an operator mis-names a zone.
     *
     * @return array{parent: string, start: int, end: int}|null
     *                                                          parent: parent /24 reverse zone name with trailing dot (e.g. "113.0.203.in-addr.arpa.")
     *                                                          start/end: inclusive last-octet range covered by this classless zone
     */
    public static function parseClasslessZone(string $zone): ?array
    {
        $zone = rtrim($zone, '.') . '.';

        // Structural gate 1: must end in .in-addr.arpa. — classless only applies to IPv4.
        if (substr($zone, -strlen('.in-addr.arpa.')) !== '.in-addr.arpa.') {
            return null;
        }

        // Structural gate 2: must have at least 5 labels to contain both the
        // classless label and a full /24 parent: "X/Y . o . o . o . in-addr . arpa . ''"
        // The trailing empty label from the terminal dot bumps this to ≥ 7 in practice,
        // but 5 is the minimum we need to safely slice below.
        $labels = explode('.', $zone);
        if (count($labels) < 5) {
            return null;
        }

        // Structural gate 3: the first label must contain a "/". If not, this is a
        // standard zone (e.g. "113.0.203.in-addr.arpa.") — let the caller handle it.
        $first = $labels[0];
        if (strpos($first, '/') === false) {
            return null;
        }

        // Parse "X/Y" — reject if either side isn't a non-negative integer.
        $parts = explode('/', $first, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }
        $x = (int) $parts[0];
        $y = (int) $parts[1];

        // X must fit in an octet; Y must be positive (0 and negative make no sense).
        if ($x < 0 || $x > 255 || $y <= 0) {
            return null;
        }

        // Map Y → block size using the dual-convention rule described in the doc-block.
        if ($y <= 32) {
            // CIDR prefix convention. Values <24 would cross /24 boundaries (outside
            // the scope of a single-/24 delegation), >32 is impossible for IPv4.
            if ($y < 24 || $y > 32) {
                return null;
            }
            // 1 << (32 - Y) gives the block size. Y=24→256 (whole /24), Y=32→1 (host).
            $size = 1 << (32 - $y);
        } else {
            // Block-size convention. Accept any positive Y that fits the /24 range check below.
            $size = $y;
        }

        // Alignment: X must sit on a block boundary. For size=64, legal starts are
        // 0, 64, 128, 192. Mis-alignments indicate a misconfigured zone label.
        if ($x % $size !== 0) {
            return null;
        }

        $end = $x + $size - 1;
        // The range must stay within the parent /24 (last octet 0..255).
        if ($end > 255) {
            return null;
        }

        // The parent zone is everything after the first label, i.e. the /24 reverse zone.
        // array_slice(labels, 1) drops "X/Y" and the implode reconstructs the trailing-dot form.
        $parent = implode('.', array_slice($labels, 1));

        return ['parent' => $parent, 'start' => $x, 'end' => $end];
    }

    /**
     * Resolve an IP to its (zone, ptrName) pair in one shot, handling both standard
     * reverse zones and RFC 2317 classless delegations.
     *
     * For a classless match, the returned ptrName includes the classless zone
     * label (e.g. "100.64/64.113.0.203.in-addr.arpa.") — this is the actual DNS
     * name the PTR record lives at in PowerDNS. Classless zones take precedence
     * over any matching parent zone, because in a properly-delegated setup the
     * parent only holds CNAMEs pointing into the classless sub-zone.
     *
     * @param  string[]  $zones  Zone names from PowerDNS (trailing dots optional)
     * @return array{zone: string, ptrName: string}|null
     */
    public static function findZoneAndPtrName(string $ip, array $zones): ?array
    {
        $ptrName = self::ptrNameForIp($ip);
        if ($ptrName === null) {
            return null;
        }

        $ipv4 = self::isIpv4($ip);
        // Extract the last octet up front for classless range comparison.
        // Only meaningful for IPv4 since RFC 2317 is IPv4-only (IPv6 delegations
        // naturally align on nibble boundaries and don't need classless tricks).
        $lastOctet = null;
        if ($ipv4) {
            $octets = explode('.', $ip);
            $lastOctet = (int) $octets[3];
        }

        $bestDirect = null;
        $bestDirectLen = 0;
        $classlessMatch = null;

        // Single pass over the zone list, bucketing each candidate into the
        // classless path or the direct-suffix-match path.
        foreach ($zones as $zone) {
            if (! is_string($zone) || $zone === '') {
                continue;
            }
            $z = rtrim($zone, '.') . '.';

            if (strpos($z, '/') !== false) {
                // Classless path. Skip for IPv6 entirely.
                if (! $ipv4) {
                    continue;
                }
                $parsed = self::parseClasslessZone($z);
                if ($parsed === null) {
                    // Malformed classless zone name (misaligned, wrong TLD, etc.) — skip.
                    continue;
                }
                // The PTR still needs to suffix-match the PARENT zone; otherwise the
                // classless zone lives under a different /24 and isn't relevant.
                $parentSuffix = '.' . $parsed['parent'];
                if (substr($ptrName, -strlen($parentSuffix)) !== $parentSuffix) {
                    continue;
                }
                // Range gate: the host octet must fall inside this classless zone's window.
                if ($lastOctet < $parsed['start'] || $lastOctet > $parsed['end']) {
                    continue;
                }
                // The record name inside a classless zone prepends the full host octet
                // to the classless label, e.g. PTR "100" lives at:
                //   "100.64/64.113.0.203.in-addr.arpa."
                // (NOT "100.113.0.203.in-addr.arpa." — the classless sub-zone holds the RRset).
                $classlessMatch = [
                    'zone' => $z,
                    'ptrName' => $lastOctet . '.' . $z,
                ];

                continue;
            }

            // Direct suffix-match path (standard reverse zones).
            $suffix = '.' . $z;
            if ($ptrName === $z || substr($ptrName, -strlen($suffix)) === $suffix) {
                // Longest-match wins (see findContainingZone() for rationale).
                if (strlen($z) > $bestDirectLen) {
                    $bestDirect = ['zone' => $z, 'ptrName' => $ptrName];
                    $bestDirectLen = strlen($z);
                }
            }
        }

        // PRECEDENCE: classless beats direct. In a correctly-delegated RFC 2317 setup
        // the parent /24 zone only contains CNAMEs pointing into the classless sub-zone —
        // it does NOT hold the PTR RRset directly. Writing to the parent would create a
        // record that's shadowed by the CNAME and never consulted during resolution.
        return $classlessMatch ?? $bestDirect;
    }
}
