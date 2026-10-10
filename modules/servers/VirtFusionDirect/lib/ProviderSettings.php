<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Validated per-panel capabilities with conservative upgrade defaults.
 *
 * All new customer features start off. Firewall/download prerequisites require
 * an evidence reference; recovery requires a separately configured role list.
 */
class ProviderSettings
{
    const TABLE = 'mod_virtfusion_direct_panels';

    /**
     * Return disabled feature gates, empty recovery roles and the default five-entry limit.
     */
    public static function defaults(): array
    {
        return [
            'power' => false, 'rdns' => false, 'rdnsBackend' => 'powerdns', 'network' => false, 'disks' => false, 'backups' => false,
            'rescue' => false, 'elevation' => false, 'iso' => false, 'firewall' => false,
            'adminTools' => false, 'isoLibrary' => false,
            'firewallVerified' => false, 'urlIsolationVerified' => false,
            'firewallEvidence' => '', 'urlIsolationEvidence' => '',
            'showProviderRules' => false, 'isoLimit' => 5,
            'approvedPresets' => [], 'recoveryRoles' => [], 'trafficUnitBytes' => 0,
        ];
    }

    /**
     * Create additive settings storage keyed by the WHMCS panel ID.
     */
    public static function schema(): void
    {
        if (! DB::schema()->hasTable(self::TABLE)) {
            DB::schema()->create(self::TABLE, function ($table) {
                $table->engine = 'InnoDB';
                $table->unsignedBigInteger('panel_id')->primary();
                $table->text('settings');
                $table->unsignedBigInteger('actor_id');
                $table->timestamps();
            });
        }
    }

    /**
     * Merge persisted settings with defaults without rewriting existing allocations.
     */
    public static function get(int $panel): array
    {
        self::schema();
        $row = DB::table(self::TABLE)->where('panel_id', $panel)->first();

        return array_replace(self::defaults(), $row ? (json_decode($row->settings, true) ?: []) : []);
    }

    /**
     * Validate exact setting names/types and require evidence for native prerequisites.
     *
     * Missing fields use disabled/default values; this is a replacement settings
     * object, not an unrestricted patch to persisted JSON.
     *
     * @throws \InvalidArgumentException Invalid flags, ID lists or evidence references.
     */
    public static function validate(array $input): array
    {
        $result = self::defaults();
        if (array_diff(array_keys($input), array_keys($result))) {
            throw new \InvalidArgumentException('Unknown provider setting.');
        }
        foreach ($input as $key => $value) {
            if (is_bool($result[$key])) {
                if (! is_bool($value)) {
                    throw new \InvalidArgumentException('Settings flags must be booleans.');
                }
            } elseif (in_array($key, ['approvedPresets', 'recoveryRoles'], true)) {
                if (! is_array($value)) {
                    throw new \InvalidArgumentException('Expected an ID list.');
                }
                $value = array_values(array_unique(array_map([ServiceTools::class, 'positiveId'], $value)));
            } elseif ($key === 'isoLimit') {
                $value = ServiceTools::positiveId($value);
                if ($value > 1000) {
                    throw new \InvalidArgumentException('ISO limit must not exceed 1000.');
                }
            } elseif (in_array($key, ['firewallEvidence', 'urlIsolationEvidence'], true)) {
                if (! is_string($value) || strlen($value) > 500) {
                    throw new \InvalidArgumentException('Verification evidence must be a short reference.');
                }
            } elseif ($key === 'rdnsBackend') {
                if (! in_array($value, ['powerdns', 'virtfusion'], true)) {
                    throw new \InvalidArgumentException('Invalid PTR backend.');
                }
            } elseif (! in_array($value, [0, 1000000000, 1073741824], true)) {
                throw new \InvalidArgumentException('Invalid traffic unit.');
            }
            $result[$key] = $value;
        }

        foreach (['firewallVerified' => 'firewallEvidence', 'urlIsolationVerified' => 'urlIsolationEvidence'] as $flag => $evidence) {
            if ($result[$flag] && strlen(trim($result[$evidence])) < 3) {
                throw new \InvalidArgumentException('Record the verification evidence before enabling this prerequisite.');
            }
        }

        return $result;
    }

    /**
     * Require Configure Servers permission, validate settings and audit the change.
     *
     * The HTTP entry point must enforce POST/CSRF before calling this method.
     */
    public static function save(int $panel, array $input): void
    {
        $actor = Access::admin('Configure Servers');
        if (! Database::getWhmcsServer($panel)) {
            throw new \RuntimeException('Panel not found.', 404);
        }
        $settings = self::validate($input);
        self::schema();
        DB::table(self::TABLE)->updateOrInsert(['panel_id' => $panel], [
            'settings' => json_encode($settings), 'actor_id' => $actor, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Log::insert('ProviderSettings', ['panel' => $panel, 'actor' => $actor], $settings);
    }
}
