<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Customer-controlled "Disk on plan changes" switch (grow | keep).
 *
 * Backed by the product's "Disk on upgrade" configurable option (mapping key
 * `keepDisk`), which operators keep hidden so it does not show on the order
 * form. The stored sub-option is what ModuleFunctions::keepDiskSelected()
 * reads during ChangePackage. Writes go through WHMCS localAPI
 * UpdateClientProduct, so WHMCS keeps its own audit trail.
 */
class DiskMode
{
    public const GROW = 'grow';

    public const KEEP = 'keep';

    /**
     * The option and its two sub-option IDs for the service's product, or null.
     *
     * Matches the option by name ("Disk on upgrade", "x|Disk on upgrade", …) in
     * the option groups linked to the product, hidden or not. Sub-options are
     * classified by the part before "|" (grow / keep), falling back to whether
     * the name contains "keep".
     *
     * @return array{optionId: int, growId: int, keepId: int}|null
     */
    public static function option(int $serviceId, string $optionName): ?array
    {
        $service = DB::table('tblhosting')->where('id', $serviceId)->first(['packageid']);
        if (! $service) {
            return null;
        }
        $groups = DB::table('tblproductconfiglinks')->where('pid', (int) $service->packageid)->pluck('gid')->all();
        if (! $groups) {
            return null;
        }
        $optionId = null;
        foreach (DB::table('tblproductconfigoptions')->whereIn('gid', $groups)->orderBy('id')->get(['id', 'optionname']) as $row) {
            if (ModuleFunctions::optionNameMatches((string) $row->optionname, $optionName)) {
                $optionId = (int) $row->id;
                break;
            }
        }
        if ($optionId === null) {
            return null;
        }
        $grow = $keep = null;
        $fallbackGrow = $fallbackKeep = null;
        foreach (DB::table('tblproductconfigoptionssub')->where('configid', $optionId)->orderBy('id')->get(['id', 'optionname']) as $sub) {
            $name = (string) $sub->optionname;
            $value = strtolower(trim(explode('|', $name, 2)[0]));
            if ($value === self::GROW && $grow === null) {
                $grow = (int) $sub->id;
            } elseif ($value === self::KEEP && $keep === null) {
                $keep = (int) $sub->id;
            } elseif (ModuleFunctions::isKeepDiskValue($name)) {
                $fallbackKeep = $fallbackKeep ?? (int) $sub->id;
            } else {
                $fallbackGrow = $fallbackGrow ?? (int) $sub->id;
            }
        }
        $grow = $grow ?? $fallbackGrow;
        $keep = $keep ?? $fallbackKeep;
        if ($grow === null || $keep === null) {
            return null;
        }

        return ['optionId' => $optionId, 'growId' => $grow, 'keepId' => $keep];
    }

    /** Stored mode; no stored value (or any other sub-option) reads as grow. */
    public static function current(int $serviceId, array $option): string
    {
        $stored = DB::table('tblhostingconfigoptions')->where('relid', $serviceId)->where('configid', $option['optionId'])->value('optionid');

        return (int) $stored === $option['keepId'] ? self::KEEP : self::GROW;
    }

    /**
     * Read the switch for a service.
     *
     * @return array{status: int, data?: array{mode: string}, errors?: string}
     */
    public static function get(int $serviceId, string $optionName): array
    {
        try {
            $option = self::option($serviceId, $optionName);
            if ($option === null) {
                return ['status' => 404, 'errors' => 'This setting is not available for this service.'];
            }

            return ['status' => 200, 'data' => ['mode' => self::current($serviceId, $option)]];
        } catch (\Throwable $e) {
            Log::insert('diskModeGet', ['serviceID' => $serviceId], $e->getMessage());

            return ['status' => 500, 'errors' => 'Unable to read the setting.'];
        }
    }

    /**
     * Change the switch. Callers must already have enforced POST, CSRF,
     * ownership and an Active service.
     *
     * Refuses while a plan change is pending (tblupgrades Pending) or a module
     * operation is unresolved, because ChangePackage reads this value.
     *
     * @param  mixed  $mode  Must be exactly 'grow' or 'keep'.
     * @return array{status: int, data?: array{mode: string}, errors?: string}
     */
    public static function set(Module $module, int $serviceId, string $optionName, $mode): array
    {
        if ($mode !== self::GROW && $mode !== self::KEEP) {
            return ['status' => 400, 'errors' => 'Choose either grow or keep.'];
        }

        try {
            $option = self::option($serviceId, $optionName);
            if ($option === null) {
                return ['status' => 404, 'errors' => 'This setting is not available for this service.'];
            }
            $pending = DB::table('tblupgrades')->where('relid', $serviceId)->where('status', 'Pending')->exists();
            if ($pending || $module->unresolvedOperation($serviceId) !== null) {
                return ['status' => 409, 'errors' => 'Finish or cancel the pending plan change first.'];
            }
            $subId = $mode === self::KEEP ? $option['keepId'] : $option['growId'];
            $result = localAPI('UpdateClientProduct', [
                'serviceid' => $serviceId,
                'configoptions' => base64_encode(serialize([$option['optionId'] => $subId])),
            ]);
            if (! is_array($result) || ($result['result'] ?? '') !== 'success') {
                Log::insert('diskModeSet', ['serviceID' => $serviceId, 'mode' => $mode, 'saved' => false], is_array($result) ? ($result['message'] ?? 'error') : 'error');

                return ['status' => 502, 'errors' => 'Could not save the setting. Please try again.'];
            }
            $stored = self::current($serviceId, $option);
            Log::insert('diskModeSet', ['serviceID' => $serviceId, 'mode' => $mode, 'saved' => $stored === $mode], 'Disk on plan changes updated');

            return ['status' => 200, 'data' => ['mode' => $stored]];
        } catch (\Throwable $e) {
            Log::insert('diskModeSet', ['serviceID' => $serviceId], $e->getMessage());

            return ['status' => 502, 'errors' => 'Could not save the setting. Please try again.'];
        }
    }
}
