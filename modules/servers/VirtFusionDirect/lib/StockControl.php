<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Computes accurate stock quantities for VirtFusionDirect products and writes them
 * to tblproducts.qty, leveraging WHMCS's native stock-control feature (badges,
 * disabled Add-to-Cart, checkout block) instead of building parallel UI.
 *
 * HOW THE NUMBER IS DERIVED
 * -------------------------
 * For every product with tblproducts.stockcontrol=1:
 *
 *   qty = Σ groupCapacity(g, package, ipv4Req, bufferPct)  for every eligible group g
 *
 * where groupCapacity is computed from live /compute/hypervisors/groups/{id}/resources
 * data and package is the VirtFusion /packages/{id} response — the authoritative
 * per-VPS resource footprint. Each hypervisor's per-metric capacity is
 * min(memory, cpu, storage), summed across hypervisors in the group; IPv4 is a
 * group-level pool so its cap is taken as the per-hypervisor max within the group
 * (not summed) to avoid double-counting.
 *
 * ELIGIBLE GROUPS
 * ---------------
 * The default group (tblproducts.configoption1) plus every value of the Location
 * configurable option, if the product exposes one. Location is detected by matching
 * the configurable option name against the "hypervisorId" label from
 * config/ConfigOptionMapping.php (falls back to "Location") — same convention
 * ModuleFunctions::createAccount() uses to map configoptions to VirtFusion fields.
 * This lets a single product span multiple regions and still get a meaningful qty.
 *
 * ELIGIBLE HYPERVISORS
 * --------------------
 * enabled=true AND commissioned=true AND prohibit=false. Everything else is skipped
 * with zero contribution to the group total.
 *
 * FAIL-SAFE INVARIANT
 * -------------------
 * CRITICAL: if the computation cannot complete (missing CP, transient API failure,
 * malformed response, no groups resolved), recalculateForProduct() returns null and
 * the caller MUST NOT touch tblproducts.qty. The reason: a false zero during a
 * transient failure would pull every product out of the storefront, causing
 * lost-order incidents that take human intervention to recover. Better to keep a
 * slightly-stale qty than to silently take the catalogue offline.
 *
 * Confirmed-missing cases (package 404 or package.enabled=false) DO return 0 —
 * that's the right answer, the product genuinely cannot be provisioned.
 *
 * CACHING
 * -------
 * Packages cached 10 min (rarely change), group resources cached 120 s (change
 * meaningfully minute-to-minute under load). Both handled inside Module's
 * fetchPackage / fetchGroupResources helpers, keyed 'pkg:{id}' / 'grpres:{id}' so
 * multiple products in a cron sweep share cached data for the same upstream call.
 */
class StockControl
{
    /** Default mapping from internal VF key → WHMCS configurable-option label.
     *  Kept in sync with $configOptionDefaultNaming in ModuleFunctions::createAccount(). */
    private const DEFAULT_OPTION_LABELS = [
        'ipv4' => 'IPv4',
        'packageId' => 'Package',
        'hypervisorId' => 'Location',
        'storage' => 'Storage',
        'memory' => 'Memory',
        'traffic' => 'Bandwidth',
        'networkSpeedInbound' => 'Inbound Network Speed',
        'networkSpeedOutbound' => 'Outbound Network Speed',
        'cpuCores' => 'CPU Cores',
        'networkProfile' => 'Network Type',
        'storageProfile' => 'Storage Type',
    ];

    /** @var Module Shared for its CP memoisation + initCurl/fetchPackage/fetchGroupResources helpers. */
    private $module;

    /** @var array<string,string>|null Resolved per-request once. */
    private $optionLabelMap = null;

    public function __construct(?Module $module = null)
    {
        // Dependency-inject for testability; default wires up a real Module so production
        // callers (hooks.php, admin.php) don't have to know about the dependency.
        $this->module = $module ?? new Module;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Recalculate qty for every VirtFusionDirect product that has WHMCS stock control enabled.
     *
     * Called from the every-2-hour AfterCronJob safety-net hook, from the post-provision
     * and post-termination event hooks in hooks.php, and from the admin stockRecalculate
     * AJAX endpoint in admin.php. Returns a map of productId => resulting qty (or null
     * where the product was skipped / left untouched), useful for the admin endpoint's
     * JSON response and for per-event logging.
     *
     * @return array<int,int|null>
     */
    public function recalculateAll(): array
    {
        $results = [];

        try {
            $products = DB::table('tblproducts')
                ->where('servertype', 'VirtFusionDirect')
                ->where('stockcontrol', 1)
                ->get();

            foreach ($products as $product) {
                $results[(int) $product->id] = $this->recalculateForProduct((int) $product->id);
            }
        } catch (\Throwable $e) {
            Log::insert('StockControl:recalculateAll', [], $e->getMessage());
        }

        return $results;
    }

    /**
     * Recalculate qty for a single product.
     *
     * Returns the new qty on success, or null on any unrecoverable failure — in which case
     * tblproducts.qty is left unchanged (fail-safe invariant).
     */
    public function recalculateForProduct(int $productId): ?int
    {
        try {
            $product = DB::table('tblproducts')->where('id', $productId)->first();
            if (! $product) {
                return null;
            }
            if ($product->servertype !== 'VirtFusionDirect') {
                return null;
            }
            if ((int) $product->stockcontrol !== 1) {
                // Stock control disabled on this product — don't manage qty.
                return null;
            }

            $qty = $this->computeQtyForProduct($product);
            if ($qty === null) {
                // Transient / unrecoverable — preserve existing qty.
                return null;
            }

            DB::table('tblproducts')
                ->where('id', $productId)
                ->update(['qty' => (int) $qty]);

            Log::insert(
                'StockControl:recalculate',
                [
                    'productId' => $productId,
                    'packageId' => (int) $product->configoption2,
                    'defaultGroupId' => (int) $product->configoption1,
                ],
                ['qty' => $qty],
            );

            return $qty;
        } catch (\Throwable $e) {
            Log::insert('StockControl:recalculateForProduct', ['productId' => $productId], $e->getMessage());

            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Computation
    // -----------------------------------------------------------------------

    /**
     * Compute the qty integer without touching the DB.
     *
     * @param  object  $product  tblproducts row.
     * @return int|null Non-negative qty, or null when the computation cannot complete.
     */
    private function computeQtyForProduct($product): ?int
    {
        $productId = (int) $product->id;

        $packageId = (int) $product->configoption2;
        if ($packageId <= 0) {
            Log::insert(
                'StockControl:compute',
                ['productId' => $productId],
                'no packageId in configoption2 — skipped',
            );

            return null;
        }

        $package = $this->module->fetchPackage($packageId);
        if ($package === null) {
            // Transient — preserve qty.
            return null;
        }
        if ($package === false) {
            // Confirmed 404: package deleted in VirtFusion. Product is unfulfillable.
            Log::insert(
                'StockControl:compute',
                ['productId' => $productId, 'packageId' => $packageId],
                'package 404 — qty forced to 0',
            );

            return 0;
        }
        if (empty($package['enabled'])) {
            Log::insert(
                'StockControl:compute',
                ['productId' => $productId, 'packageId' => $packageId],
                'package disabled in VirtFusion — qty forced to 0',
            );

            return 0;
        }

        $groupIds = $this->resolveHypervisorGroupIds($product);
        if (empty($groupIds)) {
            Log::insert(
                'StockControl:compute',
                ['productId' => $productId],
                'no hypervisor groups resolved — qty untouched',
            );

            return null;
        }

        $ipv4Required = max(1, (int) ($product->configoption3 ?? 1));
        $bufferPct = $this->bufferPctForProduct($product);

        $total = 0;
        foreach ($groupIds as $groupId) {
            $resources = $this->module->fetchGroupResources($groupId);
            if ($resources === null) {
                // Transient failure on any group aborts the whole computation — we can't
                // safely reduce qty to a partial total and risk under-reporting stock.
                return null;
            }
            if ($resources === false) {
                // Group 404 — deleted; contributes 0. Keep going so other eligible groups still count.
                Log::insert(
                    'StockControl:compute',
                    ['productId' => $productId, 'groupId' => $groupId],
                    'group 404 — contributing 0 capacity',
                );

                continue;
            }

            $total += $this->groupCapacity($resources, $package, $ipv4Required, $bufferPct);
        }

        return max(0, $total);
    }

    /**
     * Sum of per-hypervisor minimums (mem/cpu/storage), capped by the group-level IPv4 pool.
     *
     * IPv4 CAP IS MAX-WITHIN-GROUP, NOT SUMMED
     * ----------------------------------------
     * network.total.ipv4.free in the API is a group-level pool visible from every hypervisor
     * in the group — the same number is reported on each. Summing per-hypervisor IPv4 caps
     * would overcount the pool by the hypervisor count. Taking max() within a group, then
     * summing across groups, reflects the real constraint.
     */
    private function groupCapacity(array $resources, array $package, int $ipv4Required, float $bufferPct): int
    {
        $hypervisors = $resources['data'] ?? [];
        if (! is_array($hypervisors) || empty($hypervisors)) {
            return 0;
        }

        $hypMinSum = 0;
        $ipv4CapForGroup = 0;

        foreach ($hypervisors as $h) {
            $hyp = $h['hypervisor'] ?? [];
            if (empty($hyp['enabled']) || empty($hyp['commissioned']) || ! empty($hyp['prohibit'])) {
                continue;
            }

            $res = $h['resources'] ?? [];
            if (! is_array($res)) {
                continue;
            }

            $memCap = self::capFor($res['memory'] ?? null, (int) ($package['memory'] ?? 0), $bufferPct);
            $cpuCap = self::capFor($res['cpuCores'] ?? null, (int) ($package['cpuCores'] ?? 0), $bufferPct);
            $storeCap = self::capForStorage(
                $res,
                (int) ($package['primaryStorageProfile'] ?? 0),
                (int) ($package['primaryStorage'] ?? 0),
                $bufferPct,
            );

            $hypMinSum += min($memCap, $cpuCap, $storeCap);

            $ipv4Free = (int) ($res['network']['total']['ipv4']['free'] ?? 0);
            if ($ipv4Free > 0) {
                $ipv4Cap = intdiv($ipv4Free, max(1, $ipv4Required));
                if ($ipv4Cap > $ipv4CapForGroup) {
                    $ipv4CapForGroup = $ipv4Cap;
                }
            }
        }

        // If no hypervisor reported any ipv4 data (unusual but defensible), don't let
        // the cap kill an otherwise-valid count — treat as "no IPv4 constraint known".
        if ($ipv4CapForGroup === 0) {
            foreach ($hypervisors as $h) {
                if (isset($h['resources']['network']['total']['ipv4']['free'])) {
                    // There WAS an ipv4 value (possibly 0); the cap is genuinely 0.
                    return 0;
                }
            }

            // No ipv4 data anywhere in the response → don't apply the cap.
            return max(0, $hypMinSum);
        }

        return min($hypMinSum, $ipv4CapForGroup);
    }

    /**
     * How many VPSes fit into a single (free, max, buffer) cell for one resource.
     *
     * Handles three edge cases consistent with live API behaviour:
     *   - need <= 0        → unlimited fit (nothing consumed for this dimension)
     *   - resource.max = 0 → unlimited quota; free can be negative but we don't care
     *   - negative/zero available after buffer → 0 (clamp; never negative qty)
     */
    private static function capFor($resource, int $need, float $bufferPct): int
    {
        if ($need <= 0) {
            return PHP_INT_MAX;
        }
        if (! is_array($resource)) {
            return 0;
        }

        $max = (int) ($resource['max'] ?? 0);
        $free = (int) ($resource['free'] ?? 0);

        if ($max === 0) {
            // Unlimited quota — buffer doesn't apply (X% of 0 is 0).
            return PHP_INT_MAX;
        }

        $reserve = (int) ceil(((float) $max) * ($bufferPct / 100.0));
        $available = $free - $reserve;

        if ($available <= 0) {
            return 0;
        }

        return intdiv($available, $need);
    }

    /**
     * Storage variant of capFor() that respects the package's primaryStorageProfile.
     *
     * Rules:
     *   - profileId > 0  → must match an otherStorage[].id on the hypervisor; if the
     *                      matched pool is disabled or missing, this hypervisor has
     *                      zero storage capacity for this product (can't place there).
     *   - profileId <= 0 → fall back to localStorage. If local is disabled, 0.
     */
    private static function capForStorage(array $res, int $profileId, int $needGb, float $bufferPct): int
    {
        if ($needGb <= 0) {
            return PHP_INT_MAX;
        }

        if ($profileId > 0) {
            foreach ($res['otherStorage'] ?? [] as $pool) {
                if ((int) ($pool['id'] ?? 0) !== $profileId) {
                    continue;
                }
                if (empty($pool['enabled'])) {
                    return 0;
                }

                return self::capFor(
                    ['max' => (int) ($pool['max'] ?? 0), 'free' => (int) ($pool['free'] ?? 0)],
                    $needGb,
                    $bufferPct,
                );
            }

            // Storage profile not present on this hypervisor — cannot place the VM.
            return 0;
        }

        $local = $res['localStorage'] ?? null;
        if (is_array($local) && ! empty($local['enabled'])) {
            return self::capFor(
                ['max' => (int) ($local['max'] ?? 0), 'free' => (int) ($local['free'] ?? 0)],
                $needGb,
                $bufferPct,
            );
        }

        return 0;
    }

    /**
     * The admin-tunable safety buffer (configoption7), clamped to [0, 100].
     *
     * Default is 10% when the field is blank or non-numeric — reserves 10% of each
     * resource's max so we stop selling a product before the hypervisor is literally
     * at 100%, which is where placement timing issues and fragmentation start biting.
     * Admins can override per product (including down to 0) in the module settings.
     */
    private function bufferPctForProduct($product): float
    {
        $raw = $product->configoption7 ?? '';
        if ($raw === null || $raw === '') {
            return 10.0;
        }
        $val = is_numeric($raw) ? (float) $raw : 10.0;

        return max(0.0, min(100.0, $val));
    }

    // -----------------------------------------------------------------------
    // Hypervisor-group resolution
    // -----------------------------------------------------------------------

    /**
     * Collect every hypervisor group ID this product could be provisioned into:
     * the default (configoption1) plus every numeric value of the "Location"
     * configurable option (if one is attached).
     *
     * @return int[] Deduplicated list of group IDs, strictly positive.
     */
    private function resolveHypervisorGroupIds($product): array
    {
        $groups = [];

        $defaultGroup = (int) ($product->configoption1 ?? 0);
        if ($defaultGroup > 0) {
            $groups[] = $defaultGroup;
        }

        $locationLabel = $this->optionLabelFor('hypervisorId');
        if ($locationLabel !== null && $locationLabel !== '') {
            foreach ($this->fetchConfigurableOptionValues((int) $product->id, $locationLabel) as $value) {
                $asInt = (int) $value;
                if ($asInt > 0) {
                    $groups[] = $asInt;
                }
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * Look up every sub-option value for a given configurable option name on a product.
     *
     * WHMCS stores option names as either "Location" or "Location|Display Override" —
     * this method normalises both by comparing just the part before the pipe.
     *
     * @return array<int,string> Raw sub-option names (callers decide numeric parsing).
     */
    private function fetchConfigurableOptionValues(int $productId, string $label): array
    {
        try {
            $options = DB::table('tblproductconfiglinks as l')
                ->join('tblproductconfigoptions as o', 'o.gid', '=', 'l.gid')
                ->where('l.pid', $productId)
                ->select('o.id', 'o.optionname')
                ->get();

            $matchedIds = [];
            foreach ($options as $opt) {
                $name = (string) $opt->optionname;
                $pipe = strpos($name, '|');
                if ($pipe !== false) {
                    $name = substr($name, 0, $pipe);
                }
                if ($name === $label) {
                    $matchedIds[] = (int) $opt->id;
                }
            }

            if (empty($matchedIds)) {
                return [];
            }

            return DB::table('tblproductconfigoptionssub')
                ->whereIn('configid', $matchedIds)
                ->pluck('optionname')
                ->toArray();
        } catch (\Throwable $e) {
            Log::insert('StockControl:fetchConfigurableOptionValues', ['productId' => $productId, 'label' => $label], $e->getMessage());

            return [];
        }
    }

    /**
     * Resolve the WHMCS configurable-option label for an internal key, respecting
     * config/ConfigOptionMapping.php overrides — same contract as ModuleFunctions::createAccount().
     */
    private function optionLabelFor(string $key): ?string
    {
        if ($this->optionLabelMap === null) {
            $this->optionLabelMap = self::DEFAULT_OPTION_LABELS;

            try {
                // Resolve the mapping file directly relative to this class — avoids
                // depending on WHMCS's ROOTDIR, which isn't defined when the module
                // is loaded outside a full WHMCS request (cron tooling, tests).
                // __DIR__ is .../modules/servers/VirtFusionDirect/lib, so the config
                // directory is one level up.
                $overridePath = dirname(__DIR__) . '/config/ConfigOptionMapping.php';
                if (is_file($overridePath)) {
                    $override = require $overridePath;
                    if (is_array($override)) {
                        $this->optionLabelMap = array_merge($this->optionLabelMap, $override);
                    }
                }
            } catch (\Throwable $e) {
                // Swallow — mapping override is best-effort; defaults still work.
            }
        }

        return $this->optionLabelMap[$key] ?? null;
    }
}
