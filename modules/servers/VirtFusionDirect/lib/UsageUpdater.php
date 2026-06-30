<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule;

/**
 * Daily-cron usage synchroniser for tblhosting (disk + bandwidth).
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * WHMCS calls VirtFusionDirect_UsageUpdate() once per control server during the
 * daily cron. The original implementation walked every Active service on that
 * server and fired one or two SYNCHRONOUS VirtFusion reads per service. The most
 * expensive of those — GET /servers/{id}?remoteState=true — forces a live libvirt
 * round-trip to the hypervisor for qemu-agent disk stats. On a fleet of several
 * thousand VPSes the loop issued thousands of serial requests, a large fraction
 * stalling near the timeout ceiling, so the job ran for 20+ hours and was cut off
 * by the next cron before completing.
 *
 * This rewrite fixes that on three axes:
 *
 *   1. ADMIN PICKS WHAT WE POLL. A per-product "Usage Polling" config option
 *      (plus an install-wide VFD_USAGE_UPDATE_MODE override) selects exactly
 *      which metrics are fetched: nothing, bandwidth, disk, or both. Bandwidth
 *      and disk have different costs (see below), so the operator decides the
 *      trade-off instead of paying for the expensive path unconditionally.
 *
 *   2. CHEAPEST SUFFICIENT CALL PER METRIC.
 *        - bandwidth  -> GET /servers/{id}/traffic            (1 light call;
 *                        monthly[0] carries BOTH used bytes AND the GB limit, so
 *                        no second "limits" fetch and NO remoteState is needed)
 *        - disk       -> GET /servers/{id}?remoteState=true   (1 heavy call;
 *                        the only source of qemu-agent fsinfo + storage limit)
 *        - both       -> both of the above (2 calls)
 *      The libvirt round-trip is therefore incurred ONLY by disk/both modes.
 *
 *   3. BOUNDED CONCURRENCY. The needed reads run through Curl::multiGet()'s
 *      rolling window instead of strictly one-at-a-time, collapsing wall-clock
 *      from O(count) to ~O(count / concurrency).
 *
 * Self-service auto top-off is DECOUPLED from polling and runs in its own pass
 * (Phase 3b) for every linked Active service. It is governed solely by the
 * product's threshold/amount (configoption5/6) — a per-product "Usage Polling:
 * Disabled" choice stops metric reads but NEVER stops billing top-off. It is kept
 * on the per-service sequential path because it moves money (POST credit) and
 * re-reads each user's balance between decisions, so batching would risk
 * double-crediting; it is also near-zero volume on most installs.
 *
 * TUNABLES (define in WHMCS configuration.php; all optional):
 *   VFD_USAGE_UPDATE_MODE         off|disabled | bandwidth | disk | full|both
 *                                 When defined, governs EVERY service install-wide
 *                                 and overrides the per-product option. 'off' is the
 *                                 emergency kill switch: it makes the WHOLE job a
 *                                 no-op (polling AND top-off). To stop only the
 *                                 expensive polling while keeping top-off, use
 *                                 'bandwidth' (or the per-product option) instead.
 *   VFD_USAGE_UPDATE_CONCURRENCY  Max simultaneous API reads (default 10).
 *   VFD_USAGE_UPDATE_TIMEOUT      Per-request timeout, seconds (default 25).
 *   VFD_USAGE_UPDATE_CHUNK        Services processed per batch (default 300).
 *                                 Bounds peak memory — only one chunk's response
 *                                 bodies are resident at once. Lower it if the cron
 *                                 hits memory_limit in Disk/Both mode at scale.
 *
 * Requires PHP 8.0+ (the module's floor; PHP 8.2+ on WHMCS 9). No PHP 8.1+-only
 * syntax is used here, so this class runs on every WHMCS-supported PHP version.
 */
class UsageUpdater
{
    /** Poll nothing — service (or whole job) is skipped. */
    public const MODE_OFF = 'off';

    /** Poll bandwidth used + limit via /servers/{id}/traffic (1 light call). */
    public const MODE_BANDWIDTH = 'bandwidth';

    /** Poll disk used + limit via /servers/{id}?remoteState=true (1 heavy call). */
    public const MODE_DISK = 'disk';

    /** Poll both bandwidth and disk (2 calls). */
    public const MODE_FULL = 'full';

    /**
     * Default mode applied when neither the global constant nor the product's
     * config option specifies one. Bandwidth-only is the safe default: it fixes
     * the reported cron stall out-of-the-box (no libvirt round-trips) while still
     * keeping the figure WHMCS most commonly bills on — bandwidth — up to date.
     */
    public const MODE_DEFAULT = self::MODE_BANDWIDTH;

    /**
     * Entry point invoked by the thin VirtFusionDirect_UsageUpdate() shim.
     *
     * @param  array  $params  WHMCS server params; only 'serverid' is used here.
     * @return string 'success', or a human-readable error string for WHMCS.
     */
    public function run(array $params)
    {
        try {
            $serverId = (int) ($params['serverid'] ?? 0);

            // Install-wide override resolved once. VFD_USAGE_UPDATE_MODE='off' is
            // the deliberate, operator-set emergency kill switch: it makes the
            // WHOLE cron job a no-op — both metric polling AND self-service
            // top-off — and returns before touching the database or the API. To
            // stop only the expensive polling while keeping billing top-off, use
            // the per-product "Usage Polling: Disabled" option (or global
            // 'bandwidth') instead, which leaves top-off running.
            $globalMode = self::globalModeOverride();
            if ($globalMode === self::MODE_OFF) {
                return 'success';
            }

            $module = new Module;
            $cp = $module->getCP($serverId);
            if (! $cp) {
                return 'No control server found for usage update.';
            }

            // Select only the columns the loop reads (id, packageid, userid) so the
            // resident Collection stays small on a 6000-7000 row control server.
            // userid is the WHMCS client id == VirtFusion user external relation id,
            // used to de-duplicate the per-user self-service top-off in Phase 3b.
            $services = Capsule::table('tblhosting')
                ->where('server', $serverId)
                ->where('domainstatus', 'Active')
                ->select('id', 'packageid', 'userid')
                ->get();

            // ---------------------------------------------------------------
            // Phase 1 — build the work lists (no network yet).
            //
            // jobs[serviceId]   = [vfId, mode]   (services we will poll metrics for)
            // topOff[serviceId] = productRow     (every linked service; gated later
            //                     on configoption5/6 — independent of polling mode)
            //
            // Request URLs are built per-chunk in Phase 2 (not all up front) so the
            // response bodies never all live in memory at once.
            // ---------------------------------------------------------------
            $jobs = [];
            $topOff = [];
            $productCache = [];

            foreach ($services as $service) {
                try {
                    $systemService = Database::getSystemService($service->id);
                    if (! $systemService || empty($systemService->server_id)) {
                        // No VirtFusion server linked yet (provisioning pending or
                        // failed mid-create) — nothing to read usage from.
                        continue;
                    }

                    // Resolve the product row once per product id.
                    $pid = (int) $service->packageid;
                    if (! array_key_exists($pid, $productCache)) {
                        $productCache[$pid] = Capsule::table('tblproducts')->where('id', $pid)->first();
                    }
                    $product = $productCache[$pid];

                    // Auto top-off is a BILLING feature governed solely by the
                    // product's threshold/amount (configoption5/6) — it must NOT be
                    // coupled to what we poll. Record every linked Active service as
                    // a candidate here, BEFORE the polling-mode gate below, so that
                    // choosing "Usage Polling: Disabled" for a product never silently
                    // stops its customers' credit top-off. maybeTopOff() is itself
                    // gated on configoption5/6, so non-self-service products are no-ops.
                    // userid lets Phase 3b credit each user's shared balance once.
                    $topOff[$service->id] = ['product' => $product, 'userid' => (int) $service->userid];

                    // Guard a missing tblproducts row: reading ->configoption8 off
                    // null would emit a PHP 8 warning. normalizeMode(null) -> default.
                    $cfg8 = $product ? ($product->configoption8 ?? null) : null;
                    $mode = self::resolveMode($globalMode, $cfg8);
                    if ($mode === self::MODE_OFF) {
                        continue; // skip metric polling; top-off candidate already recorded
                    }

                    $vfId = (int) $systemService->server_id;
                    $jobs[$service->id] = [
                        'vfId' => $vfId,
                        'mode' => $mode,
                    ];
                } catch (\Exception $e) {
                    Log::insert('UsageUpdate:plan:' . $service->id, [], $e->getMessage());

                    continue;
                }
            }

            // The Collection and product cache are no longer needed; free them
            // before the (potentially large) polling phase.
            unset($services, $productCache);

            // ---------------------------------------------------------------
            // Phase 2 + 3a — poll and write in bounded CHUNKS.
            //
            // Curl::multiGet returns every response body in one array, so issuing
            // all reads in a single call would hold the WHOLE fleet's bodies in
            // memory at once (in Disk/Both mode each remoteState body is ~10-20KB;
            // ~7000 of them is >100MB and can OOM a memory-limited cron). Chunking
            // bounds peak memory to one window of bodies while keeping the
            // concurrency window saturated within each chunk.
            // ---------------------------------------------------------------
            $headers = [
                'Accept: application/json',
                'Content-type: application/json; charset=utf-8',
                'authorization: Bearer ' . $cp['token'],
            ];
            $concurrency = self::concurrency();
            $timeout = self::timeout();

            foreach (array_chunk(array_keys($jobs), self::chunkSize()) as $chunkServiceIds) {
                // Build just this chunk's request URLs.
                $requests = [];
                foreach ($chunkServiceIds as $serviceId) {
                    $job = $jobs[$serviceId];
                    if (self::pollsBandwidth($job['mode'])) {
                        $requests['traffic:' . $serviceId] = $cp['url'] . '/servers/' . $job['vfId'] . '/traffic';
                    }
                    if (self::pollsDisk($job['mode'])) {
                        $requests['server:' . $serviceId] = $cp['url'] . '/servers/' . $job['vfId'] . '?remoteState=true';
                    }
                }
                if ($requests === []) {
                    continue;
                }

                $responses = Curl::multiGet($requests, $headers, $concurrency, $timeout);

                foreach ($chunkServiceIds as $serviceId) {
                    try {
                        $serverData = self::decodeData($responses['server:' . $serviceId] ?? null);
                        $trafficData = self::decodeData($responses['traffic:' . $serviceId] ?? null);

                        $update = self::buildUpdate($jobs[$serviceId]['mode'], $serverData, $trafficData);

                        if ($update !== []) {
                            $update['lastupdate'] = date('Y-m-d H:i:s');
                            Capsule::table('tblhosting')->where('id', $serviceId)->update($update);
                        }
                    } catch (\Exception $e) {
                        Log::insert('UsageUpdate:service:' . $serviceId, [], $e->getMessage());

                        continue;
                    }
                }

                // Release this chunk's bodies before fetching the next window.
                unset($responses);
            }

            // ---------------------------------------------------------------
            // Phase 3b — self-service auto top-off, decoupled from polling mode.
            //
            // Considers EVERY linked Active service regardless of its Usage Polling
            // setting, but tops off ONCE PER USER. Self-service credit is a
            // per-VirtFusion-user (per WHMCS client) SHARED balance, so crediting it
            // once per service would over-credit a client owning several
            // self-service VPSes (e.g. amount 25 applied to balance 10 -> 35 -> 60...
            // across siblings). When a user owns services across DIFFERENT top-off
            // products, the STRICTEST configured rule wins — highest threshold, ties
            // broken by lowest service id — so the choice is deterministic (not
            // DB-row-order dependent) and never silently drops a breached threshold.
            // Kept sequential because it moves money (POST credit).
            // ---------------------------------------------------------------
            foreach (self::pickTopOffServices($topOff) as $pick) {
                try {
                    $this->maybeTopOff($module, $pick['serviceId'], $pick['product']);
                } catch (\Exception $e) {
                    Log::insert('UsageUpdate:topOff:' . $pick['serviceId'], [], $e->getMessage());

                    continue;
                }
            }

            return 'success';
        } catch (\Exception $e) {
            return 'Usage update failed: ' . $e->getMessage();
        }
    }

    // =====================================================================
    // Pure helpers (no WHMCS / network dependencies — unit-testable as-is).
    // =====================================================================

    /**
     * Normalise an arbitrary mode string to one of the MODE_* constants.
     *
     * Accepts the canonical names plus common synonyms so both the config-option
     * values and hand-typed constants behave intuitively:
     *   off|disabled|0|none      -> off
     *   bandwidth|traffic|bw|1   -> bandwidth
     *   disk|storage|2           -> disk
     *   full|both|all|3          -> full
     *
     * @param  mixed  $raw  Raw value from a constant or a product config option.
     * @param  string  $default  Mode to use when $raw is blank/unrecognised.
     * @return string One of the MODE_* constants.
     */
    public static function normalizeMode($raw, $default = self::MODE_DEFAULT)
    {
        if ($raw === null) {
            return $default;
        }

        $v = strtolower(trim((string) $raw));
        if ($v === '') {
            return $default;
        }

        switch ($v) {
            case 'off':
            case 'disabled':
            case 'disable':
            case 'none':
            case '0':
                return self::MODE_OFF;
            case 'bandwidth':
            case 'traffic':
            case 'bw':
            case '1':
                return self::MODE_BANDWIDTH;
            case 'disk':
            case 'storage':
            case '2':
                return self::MODE_DISK;
            case 'full':
            case 'both':
            case 'all':
            case '3':
                return self::MODE_FULL;
            default:
                return $default;
        }
    }

    /**
     * Resolve the effective mode for a single service.
     *
     * Precedence: when the install-wide constant is defined it governs everything
     * (so an operator can flip the whole fleet with one line); otherwise the
     * per-product config option decides, falling back to MODE_DEFAULT when unset.
     *
     * @param  string|null  $globalMode  Result of globalModeOverride() (a MODE_*
     *                                   constant, or null when undefined).
     * @param  mixed  $productOption  The product's raw configoption8 value.
     * @return string One of the MODE_* constants.
     */
    public static function resolveMode($globalMode, $productOption)
    {
        if ($globalMode !== null) {
            return $globalMode;
        }

        return self::normalizeMode($productOption, self::MODE_DEFAULT);
    }

    /** @return bool Whether $mode includes bandwidth polling. */
    public static function pollsBandwidth($mode)
    {
        return $mode === self::MODE_BANDWIDTH || $mode === self::MODE_FULL;
    }

    /** @return bool Whether $mode includes disk polling. */
    public static function pollsDisk($mode)
    {
        return $mode === self::MODE_DISK || $mode === self::MODE_FULL;
    }

    /**
     * Build the tblhosting update array (sans lastupdate) for one service from
     * its decoded API responses, honouring exactly which metrics $mode polls.
     *
     * Unit conventions (preserved from the original implementation):
     *   diskused  = round(sum(fsinfo[].used-bytes) / 1MiB)  [MB], only when > 0
     *   disklimit = settings.resources.storage (GB) * 1024   [MB]
     *   bwused    = round(monthly[0].total / 1MiB)           [MB]
     *   bwlimit   = monthly[0].limit (GB) * 1024, 0 => 0 (unlimited)  [MB]
     *
     * @param  string  $mode  One of the MODE_* constants.
     * @param  array|null  $server  Decoded /servers/{id}?remoteState=true 'data'.
     * @param  array|null  $traffic  Decoded /servers/{id}/traffic 'data'.
     * @return array Partial tblhosting column => value map (possibly empty).
     */
    public static function buildUpdate($mode, $server, $traffic)
    {
        $update = [];

        if (self::pollsDisk($mode) && is_array($server)) {
            // Disk usage — derived from qemu-agent fsinfo when available. Sum all
            // reported filesystems (root + extra mounts), bytes -> MB. If the
            // agent isn't running there are no entries and we leave diskused
            // untouched rather than zeroing a real value.
            $fsinfo = $server['remoteState']['agent']['fsinfo'] ?? null;
            if (is_array($fsinfo) && $fsinfo !== []) {
                $diskUsedBytes = 0;
                foreach ($fsinfo as $fs) {
                    if (isset($fs['used-bytes']) && is_numeric($fs['used-bytes'])) {
                        $diskUsedBytes += (int) $fs['used-bytes'];
                    }
                }
                if ($diskUsedBytes > 0) {
                    $update['diskused'] = (int) round($diskUsedBytes / 1048576);
                }
            }

            if (isset($server['settings']['resources']['storage'])) {
                // settings.resources.storage is GB; WHMCS disklimit is MB.
                $update['disklimit'] = (int) $server['settings']['resources']['storage'] * 1024;
            }
        }

        if (self::pollsBandwidth($mode)) {
            if (is_array($traffic)) {
                // monthly[0] is the current billing period; it exposes both the byte
                // counter and the GB limit, so one /traffic read yields used + limit.
                $currentPeriod = $traffic['monthly'][0] ?? null;
                if (is_array($currentPeriod)) {
                    if (isset($currentPeriod['total']) && is_numeric($currentPeriod['total'])) {
                        $update['bwused'] = (int) round($currentPeriod['total'] / 1048576);
                    }
                    if (isset($currentPeriod['limit']) && is_numeric($currentPeriod['limit'])) {
                        // limit is GB; 0 = unmetered, which WHMCS represents the same
                        // way (0 bwlimit = no cap).
                        $limitGB = (int) $currentPeriod['limit'];
                        $update['bwlimit'] = $limitGB > 0 ? $limitGB * 1024 : 0;
                    }
                }
            }

            // Fallback: in Full mode the server object (remoteState fetch) carries
            // settings.resources.traffic — the authoritative configured cap. Use it
            // for bwlimit when /traffic didn't supply one (e.g. a freshly provisioned
            // server whose monthly[] is still empty), preserving the original code's
            // always-write-bwlimit behaviour. Bandwidth-only mode has no $server, so
            // it simply leaves bwlimit untouched until the first period materialises.
            if (! isset($update['bwlimit']) && is_array($server) && isset($server['settings']['resources']['traffic'])) {
                $trafficGB = (int) $server['settings']['resources']['traffic'];
                $update['bwlimit'] = $trafficGB > 0 ? $trafficGB * 1024 : 0;
            }
        }

        return $update;
    }

    /**
     * Choose, per VirtFusion user, the single service whose product rule governs
     * that user's shared self-service balance this run.
     *
     * Self-service credit is a per-user shared balance, so each user must be topped
     * off at most once per run. Among a user's top-off-configured services (product
     * threshold AND amount > 0), the STRICTEST rule wins — highest threshold, ties
     * broken by lowest service id — making the pick deterministic regardless of DB
     * row order and never silently dropping a higher (breached) threshold.
     *
     * @param  array<int,array{product:object|null,userid:int}>  $topOff  Phase-1 candidates.
     * @return array<int,array{serviceId:int,product:object,threshold:float}>
     *                                                                        One entry per userid (the chosen service + its product rule).
     */
    public static function pickTopOffServices(array $topOff)
    {
        $byUser = [];
        foreach ($topOff as $serviceId => $entry) {
            $product = $entry['product'] ?? null;
            if (! $product) {
                continue;
            }
            $threshold = (float) ($product->configoption5 ?? 0);
            $amount = (float) ($product->configoption6 ?? 0);
            if ($threshold <= 0 || $amount <= 0) {
                continue; // product does not configure auto top-off
            }

            $userid = $entry['userid'] ?? 0;
            $current = $byUser[$userid] ?? null;
            if ($current === null
                || $threshold > $current['threshold']
                || ($threshold === $current['threshold'] && $serviceId < $current['serviceId'])) {
                $byUser[$userid] = [
                    'serviceId' => $serviceId,
                    'product' => $product,
                    'threshold' => $threshold,
                ];
            }
        }

        return $byUser;
    }

    /**
     * Decode a Curl::multiGet() result entry into the inner 'data' payload.
     *
     * Returns null unless the request returned HTTP 200 with a JSON body that has
     * a 'data' key — matching the original "skip on non-200 / malformed" guards,
     * so a stalled or failed read leaves the corresponding record untouched.
     *
     * @param  array|null  $result  One Curl::multiGet() entry, or null if absent.
     * @return array|null The decoded 'data' array, or null.
     */
    public static function decodeData($result)
    {
        if (! is_array($result) || ($result['http_code'] ?? 0) != 200) {
            return null;
        }

        $body = $result['body'] ?? null;
        if (! is_string($body) || $body === '') {
            return null;
        }

        $json = json_decode($body, true);
        if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
            return null;
        }

        return $json['data'];
    }

    // =====================================================================
    // Impure helpers (constants / network).
    // =====================================================================

    /**
     * Resolve the install-wide override, if the operator defined it.
     *
     * @return string|null A MODE_* constant when VFD_USAGE_UPDATE_MODE is defined,
     *                     otherwise null (meaning "defer to per-product setting").
     */
    public static function globalModeOverride()
    {
        if (! defined('VFD_USAGE_UPDATE_MODE')) {
            return null;
        }

        return self::normalizeMode(VFD_USAGE_UPDATE_MODE, self::MODE_DEFAULT);
    }

    /** @return int Bounded-concurrency window for the batched reads. */
    public static function concurrency()
    {
        $c = defined('VFD_USAGE_UPDATE_CONCURRENCY') ? (int) VFD_USAGE_UPDATE_CONCURRENCY : 10;

        return max(1, $c);
    }

    /** @return int Per-request timeout in seconds for the batched reads. */
    public static function timeout()
    {
        $t = defined('VFD_USAGE_UPDATE_TIMEOUT') ? (int) VFD_USAGE_UPDATE_TIMEOUT : 25;

        return max(1, $t);
    }

    /**
     * @return int Services processed per chunk. Bounds peak memory: only one
     *             chunk's response bodies are resident at a time. Default 300.
     */
    public static function chunkSize()
    {
        $c = defined('VFD_USAGE_UPDATE_CHUNK') ? (int) VFD_USAGE_UPDATE_CHUNK : 300;

        return max(1, $c);
    }

    /**
     * Self-service auto top-off for one service. Gated on the product's
     * threshold/amount (configoption5/6); a no-op otherwise.
     *
     * The caller (Phase 3b) invokes this AT MOST ONCE PER VIRTFUSION USER per run,
     * because self-service credit is a per-user shared balance (the API is keyed
     * byUserExtRelationId). Crediting it once per service would over-credit a client
     * who owns several self-service VPSes.
     *
     * @param  Module  $module  Shared Module instance for the API calls.
     * @param  int  $serviceId  WHMCS service id (selects the user + product rule).
     * @param  object|null  $product  The service's tblproducts row.
     */
    private function maybeTopOff(Module $module, $serviceId, $product)
    {
        if (! $product) {
            return;
        }

        $threshold = (float) ($product->configoption5 ?? 0);
        $topOffAmount = (float) ($product->configoption6 ?? 0);

        if ($threshold <= 0 || $topOffAmount <= 0) {
            return;
        }

        $usageData = $module->getSelfServiceUsage($serviceId);
        if (! $usageData) {
            return;
        }

        $usageInner = $usageData['data'] ?? $usageData;
        $credit = $usageInner['credit'] ?? $usageInner['balance'] ?? null;

        if ($credit !== null && (float) $credit < $threshold) {
            $module->addSelfServiceCredit($serviceId, $topOffAmount, 'Auto top-off');
            Log::insert(
                'UsageUpdate:autoTopOff',
                ['serviceId' => $serviceId, 'credit' => $credit, 'threshold' => $threshold],
                ['amount' => $topOffAmount],
            );
        }
    }
}
