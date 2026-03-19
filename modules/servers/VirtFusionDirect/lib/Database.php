<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Handles all database operations for the module's custom table (mod_virtfusion_direct)
 * and queries against core WHMCS tables (tblhosting, tblclients, tblservers, etc.).
 */
class Database
{
    const SYSTEM_TABLE = 'mod_virtfusion_direct';

    /** @var bool Tracks whether custom field existence has already been verified this request. */
    private static $fieldsChecked = false;

    /**
     * Creates or migrates the module table schema and ensures custom fields exist.
     *
     * Creates mod_virtfusion_direct with service_id and server_id columns if absent,
     * adds the server_object column if missing, then calls ensureCustomFields().
     *
     * @return void
     */
    public static function schema()
    {
        if (! DB::schema()->hasTable(self::SYSTEM_TABLE)) {
            try {
                DB::schema()->create(self::SYSTEM_TABLE, function ($table) {
                    $table->unsignedBigInteger('service_id')->nullable()->default(null)->index();
                    $table->unsignedBigInteger('server_id')->nullable()->default(null);
                    $table->timestamps();
                });
            } catch (\Exception $e) {
                Log::insert(__FUNCTION__, [], $e->getMessage());
            }
        }

        if (! DB::schema()->hasColumn(self::SYSTEM_TABLE, 'server_object')) {
            try {
                DB::schema()->table(self::SYSTEM_TABLE, function ($table) {
                    $table->longText('server_object')->nullable()->default(null);
                });
            } catch (\Exception $e) {
                Log::insert(__FUNCTION__, [], $e->getMessage());
            }
        }

        self::ensureCustomFields();
    }

    /**
     * Ensures the "Initial Operating System" and "Initial SSH Key" custom fields exist
     * for every VirtFusionDirect product, creating them via upsert if absent.
     *
     * @return void
     */
    public static function ensureCustomFields()
    {
        if (self::$fieldsChecked) {
            return;
        }
        self::$fieldsChecked = true;

        try {
            $productIds = DB::table('tblproducts')
                ->where('servertype', 'VirtFusionDirect')
                ->pluck('id');

            foreach ($productIds as $productId) {
                foreach (['Initial Operating System', 'Initial SSH Key'] as $fieldName) {
                    DB::table('tblcustomfields')->updateOrInsert(
                        ['type' => 'product', 'relid' => $productId, 'fieldname' => $fieldName],
                        [
                            'fieldtype' => 'text',
                            'description' => '',
                            'fieldoptions' => '',
                            'regexpr' => '',
                            'adminonly' => '',
                            'required' => '',
                            'showorder' => 'on',
                            'showinvoice' => '',
                            'sortorder' => 0,
                            'updated_at' => DB::raw('UTC_TIMESTAMP()'),
                        ],
                    );
                }
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }
    }

    /**
     * Fetches a VirtFusionDirect server record from tblservers.
     *
     * When $server is non-zero, returns the matching server by ID.
     * When $any is true and $server is 0, returns the first enabled server.
     *
     * @param  int  $server  WHMCS server ID to look up (0 to skip ID filter).
     * @param  bool  $any  If true, fall back to the first active server.
     * @return object|false Row object on success, false on failure or not found.
     */
    public static function getWhmcsServer(int $server, $any = false)
    {
        try {
            if ($server) {
                return DB::table('tblservers')->where('type', 'VirtFusionDirect')->where('id', $server)->first();
            }

            if ($any) {
                return DB::table('tblservers')->where('type', 'VirtFusionDirect')->where('disabled', 0)->first();
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }

        return false;
    }

    /**
     * Checks whether a WHMCS service belongs to the given client.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @param  int  $userId  WHMCS client ID.
     * @return bool True if the service is owned by the client, false otherwise.
     */
    public static function userWhmcsService(int $serviceId, int $userId)
    {
        try {
            return DB::table('tblhosting')->where('id', $serviceId)->where('userid', $userId)->exists();
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Returns the WHMCS system URL from tblconfiguration.
     *
     * @return string The system URL, or an empty string if not found or on error.
     */
    public static function getSystemUrl()
    {
        try {
            $url = DB::table('tblconfiguration')->where('setting', '=', 'SystemURL')->first();
            if (! $url) {
                return '';
            }

            return $url->value;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return '';
        }
    }

    /**
     * Fetches a WHMCS client record by ID.
     *
     * @param  int  $id  WHMCS client ID.
     * @return object|null Row object on success, null on failure or not found.
     */
    public static function getUser(int $id)
    {
        try {
            return DB::table('tblclients')->where('id', $id)->first();
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }

    /**
     * Fetches a WHMCS hosting service record by ID.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @return object|null Row object on success, null on failure or not found.
     */
    public static function getWhmcsService(int $serviceId)
    {
        try {
            return DB::table('tblhosting')->where('id', $serviceId)->first();
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }

    /**
     * Upserts the VirtFusion server ID for a given WHMCS service in the module table.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @param  int  $serverId  VirtFusion server ID.
     * @return void
     */
    public static function updateSystemServiceServerId(int $serviceId, int $serverId)
    {
        try {
            DB::table(self::SYSTEM_TABLE)->updateOrInsert(
                [
                    'service_id' => $serviceId,
                ],
                [
                    'server_id' => $serverId,
                ],
            );
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }
    }

    /**
     * Updates one or more WHMCS tables with the provided data for a given service ID.
     *
     * $data is keyed by table name; each value is an associative array of column => value
     * pairs passed to an update() WHERE id = $serviceId.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @param  array  $data  Map of table name to column-value pairs to update.
     * @return void
     */
    public static function updateWhmcsServiceParams(int $serviceId, $data)
    {
        try {
            if (count($data)) {
                foreach ($data as $key => $items) {
                    DB::table($key)->where('id', $serviceId)->update($items);
                }
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }
    }

    /**
     * Checks whether a module table record exists for the given service.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @return bool True if a record exists, false otherwise.
     */
    public static function checkSystemService(int $serviceId)
    {
        try {
            return DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->exists();
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return false;
        }
    }

    /**
     * Deletes the module table record for the given service.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @return void
     */
    public static function deleteSystemService(int $serviceId)
    {
        try {
            DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->delete();
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }
    }

    /**
     * Persists the raw VirtFusion server API response as JSON in the module table.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @param  mixed  $data  Server object from the VirtFusion API (will be JSON-encoded).
     * @return void
     */
    public static function updateSystemServiceServerObject(int $serviceId, $data)
    {
        try {
            DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->update(['server_object' => json_encode($data, JSON_PRETTY_PRINT)]);
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }
    }

    /**
     * Inserts or updates the module table record immediately after a VirtFusion server is created.
     *
     * Stores both the VirtFusion server ID (from $data->data->id) and the full server
     * object JSON. Uses update if a record already exists, otherwise inserts.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @param  mixed  $data  Full API response object from the VirtFusion server creation call.
     * @return void
     */
    public static function systemOnServerCreate(int $serviceId, $data)
    {
        try {
            if (DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->exists()) {
                DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->update(['server_id' => $data->data->id, 'server_object' => json_encode($data, JSON_PRETTY_PRINT)]);
            } else {
                DB::table(self::SYSTEM_TABLE)->insert(['service_id' => $serviceId, 'server_id' => $data->data->id, 'server_object' => json_encode($data, JSON_PRETTY_PRINT)]);
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());
        }
    }

    /**
     * Fetches the module table record for the given service.
     *
     * @param  int  $serviceId  WHMCS hosting service ID.
     * @return object|null Row object on success, null on failure or not found.
     */
    public static function getSystemService(int $serviceId)
    {
        try {
            return DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->first();
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, [], $e->getMessage());

            return null;
        }
    }
}
