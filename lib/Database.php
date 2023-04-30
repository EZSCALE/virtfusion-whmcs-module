<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

class Database
{
    const SYSTEM_TABLE = 'mod_virtfusion_direct';

    public static function schema()
    {
        if (!DB::schema()->hasTable(self::SYSTEM_TABLE)) {
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

        if (!DB::schema()->hasColumn(self::SYSTEM_TABLE, 'server_object')) {
            try {
                DB::schema()->table(self::SYSTEM_TABLE, function ($table) {
                    $table->longText('server_object')->nullable()->default(null);
                });
            } catch (\Exception $e) {
                Log::insert(__FUNCTION__, [], $e->getMessage());
            }
        }
    }

    public static function getWhmcsServer(int $server, $any = false)
    {
        if ($server) {
            return DB::table('tblservers')->where('type', 'VirtFusionDirect')->where('id', $server)->first();
        }

        if ($any) {
            return DB::table('tblservers')->where('type', 'VirtFusionDirect')->where('disabled', 0)->first();
        }

        return false;
    }

    public static function userWhmcsService(int $serviceId, int $userId)
    {
        return DB::table('tblhosting')->where('id', $serviceId)->where('userid', $userId)->exists();
    }

    public static function getSystemUrl()
    {
        $url = DB::table('tblconfiguration')->where('setting', '=', 'SystemURL')->first();
        return $url->value;
    }

    public static function getUser(int $id)
    {
        return DB::table('tblclients')->where('id', $id)->first();
    }

    public static function getWhmcsService(int $serviceId)
    {
        return DB::table('tblhosting')->where('id', $serviceId)->first();
    }

    public static function updateSystemServiceServerId(int $serviceId, int $serverId)
    {

        DB::table(self::SYSTEM_TABLE)->updateOrInsert(
            [
                "service_id" => $serviceId
            ],
            [
                'server_id' => $serverId
            ]
        );
    }

    public static function updateWhmcsServiceParams(int $serviceId, $data)
    {
        if (count($data)) {
            foreach ($data as $key => $items) {
                DB::table($key)->where('id', $serviceId)->update($items);
            }
        }
    }

    public static function checkSystemService(int $serviceId)
    {
        return DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->exists();
    }

    public static function deleteSystemService(int $serviceId)
    {
        DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->delete();
    }

    public static function updateSystemServiceServerObject(int $serviceId, $data)
    {
        DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->update(['server_object' => json_encode($data, JSON_PRETTY_PRINT)]);
    }

    public static function systemOnServerCreate(int $serviceId, $data)
    {
        if (DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->exists()) {
            DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->update(['server_id' => $data->data->id, 'server_object' => json_encode($data, JSON_PRETTY_PRINT)]);
        } else {
            DB::table(self::SYSTEM_TABLE)->insert(['service_id' => $serviceId, 'server_id' => $data->data->id, 'server_object' => json_encode($data, JSON_PRETTY_PRINT)]);
        }
    }

    public static function getSystemService(int $serviceId)
    {
        return DB::table(self::SYSTEM_TABLE)->where('service_id', $serviceId)->first();
    }

}