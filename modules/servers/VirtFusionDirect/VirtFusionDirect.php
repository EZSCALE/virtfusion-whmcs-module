<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Module\Server\VirtFusionDirect\ModuleFunctions;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\Database;

function VirtFusionDirect_MetaData()
{
    return [
        'DisplayName' => 'VirtFusion Direct Provisioning',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'ServiceSingleSignOnLabel' => 'Login to VirtFusion Panel',
        'AdminSingleSignOnLabel' => false,
    ];
}

function VirtFusionDirect_ConfigOptions()
{
    return [
        "defaultHypervisorGroupId" => [
            "FriendlyName" => "Hypervisor Group ID",
            "Type" => "text",
            "Size" => "20",
            "Description" => "The default hypervisor group ID for server placement.",
            "Default" => "1",
        ],
        "packageID" => [
            "FriendlyName" => "Package ID",
            "Type" => "text",
            "Size" => "20",
            "Description" => "The VirtFusion package ID that defines server resources.",
            "Default" => "1",
        ],
        "defaultIPv4" => [
            "FriendlyName" => "Default IPv4",
            "Type" => "dropdown",
            "Options" => "0,1,2,3,4,5,6,7,8,9,10",
            "Description" => "The default number of IPv4 addresses to assign to each server.",
            "Default" => "1",
        ],
        "selfServiceMode" => [
            "FriendlyName" => "Self-Service Mode",
            "Type" => "dropdown",
            "Options" => "0|Disabled,1|Hourly,2|Resource Packs,3|Both",
            "Description" => "Enable VirtFusion self-service billing for users created by this product.",
            "Default" => "0",
        ],
        "autoTopOffThreshold" => [
            "FriendlyName" => "Auto Top-Off Threshold",
            "Type" => "text",
            "Size" => "10",
            "Description" => "Credit balance below which auto top-off triggers during cron. 0 = disabled.",
            "Default" => "0",
        ],
        "autoTopOffAmount" => [
            "FriendlyName" => "Auto Top-Off Amount",
            "Type" => "text",
            "Size" => "10",
            "Description" => "Credit amount to add when auto top-off triggers.",
            "Default" => "100",
        ],
    ];
}

function VirtFusionDirect_TestConnection(array $params)
{
    try {
        $hostname = trim($params['serverhostname'] ?? '');
        $password = $params['serverpassword'] ?? '';

        if (empty($hostname) || empty($password)) {
            return ['success' => false, 'error' => 'Server hostname and password are required. Please verify the server configuration.'];
        }

        $url = 'https://' . $hostname . '/api/v1';
        $module = new Module();
        $request = $module->initCurl($password);
        $data = $request->get($url . '/connect');

        $httpCode = $request->getRequestInfo('http_code');

        if ($httpCode == 200) {
            return ['success' => true, 'error' => ''];
        }

        if ($httpCode == 401) {
            return ['success' => false, 'error' => 'Authentication failed. Please verify your API token is correct and has not expired.'];
        }

        if ($httpCode == 0) {
            $curlError = $request->getRequestInfo('curl_error');
            return ['success' => false, 'error' => 'Connection failed: ' . ($curlError ?: 'Unable to reach the VirtFusion server. Verify the hostname and that SSL certificates are valid.')];
        }

        return ['success' => false, 'error' => 'Unexpected response from VirtFusion API (HTTP ' . $httpCode . '). Please check the server configuration.'];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => 'Connection test failed: ' . $e->getMessage()];
    }
}

function VirtFusionDirect_AdminCustomButtonArray()
{
    return [
        "Update Server Object" => "updateServerObject",
        "Validate Server Config" => "validateServerConfig",
    ];
}

function VirtFusionDirect_ServiceSingleSignOn(array $params)
{
    try {
        $module = new Module();
        $token = $module->fetchLoginTokens($params['serviceid']);

        if ($token) {
            return ['success' => true, 'redirectTo' => $token];
        }

        return ['success' => false, 'errorMsg' => 'Unable to generate a login token. The server may not be active or the VirtFusion API may be unreachable.'];
    } catch (\Exception $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

/**
 * Service functions
 */
function VirtFusionDirect_CreateAccount(array $params)
{
    return (new ModuleFunctions())->createAccount($params);
}

function VirtFusionDirect_SuspendAccount(array $params)
{
    return (new ModuleFunctions())->suspendAccount($params);
}

function VirtFusionDirect_UnsuspendAccount(array $params)
{
    return (new ModuleFunctions())->unsuspendAccount($params);
}

function VirtFusionDirect_TerminateAccount(array $params)
{
    return (new ModuleFunctions())->terminateAccount($params);
}

function VirtFusionDirect_updateServerObject(array $params)
{
    return (new ModuleFunctions())->updateServerObject($params);
}

/**
 * Allows changing of the package of a server
 *
 * @param array $params
 * @return string
 */
function VirtFusionDirect_ChangePackage(array $params)
{
    return (new ModuleFunctions())->changePackage($params);
}

function VirtFusionDirect_AdminServicesTabFields(array $params)
{
    return (new ModuleFunctions())->adminServicesTabFields($params);
}

function VirtFusionDirect_AdminServicesTabFieldsSave(array $params)
{
    (new ModuleFunctions())->adminServicesTabFieldsSave($params);
}

function VirtFusionDirect_ClientArea(array $params)
{
    return (new ModuleFunctions())->clientArea($params);
}

/**
 * Validates server configuration via dry run without creating the server.
 *
 * @param array $params
 * @return string 'success' or error message
 */
function VirtFusionDirect_validateServerConfig(array $params)
{
    return (new ModuleFunctions())->validateServerConfig($params);
}

/**
 * Usage Update - called by WHMCS daily cron to sync bandwidth and disk usage.
 *
 * Updates tblhosting with disk and bandwidth usage data from VirtFusion.
 * Fields updated: diskused, disklimit, bwused, bwlimit, lastupdate
 *
 * @param array $params Server access credentials
 * @return string 'success' or error message
 */
function VirtFusionDirect_UsageUpdate(array $params)
{
    try {
        $module = new Module();
        $cp = $module->getCP($params['serverid']);

        if (!$cp) {
            return 'No control server found for usage update.';
        }

        $services = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('server', $params['serverid'])
            ->where('domainstatus', 'Active')
            ->get();

        foreach ($services as $service) {
            try {
                $systemService = Database::getSystemService($service->id);
                if (!$systemService) {
                    continue;
                }

                $request = $module->initCurl($cp['token']);
                $data = $request->get($cp['url'] . '/servers/' . (int) $systemService->server_id);

                if ($request->getRequestInfo('http_code') != 200) {
                    continue;
                }

                $serverData = json_decode($data, true);
                if (!isset($serverData['data'])) {
                    continue;
                }

                $server = $serverData['data'];
                $update = [];

                // Disk usage (WHMCS expects MB)
                if (isset($server['usage']['storage']['used'])) {
                    $update['diskused'] = round($server['usage']['storage']['used'] / 1048576);
                }
                if (isset($server['settings']['resources']['storage'])) {
                    $update['disklimit'] = (int) $server['settings']['resources']['storage'] * 1024;
                }

                // Bandwidth usage (WHMCS expects MB)
                if (isset($server['usage']['traffic']['used'])) {
                    $update['bwused'] = round($server['usage']['traffic']['used'] / 1048576);
                }
                if (isset($server['settings']['resources']['traffic'])) {
                    $trafficGB = (int) $server['settings']['resources']['traffic'];
                    $update['bwlimit'] = $trafficGB > 0 ? $trafficGB * 1024 : 0;
                }

                if (!empty($update)) {
                    $update['lastupdate'] = date('Y-m-d H:i:s');
                    \WHMCS\Database\Capsule::table('tblhosting')
                        ->where('id', $service->id)
                        ->update($update);
                }

                // Self-service auto top-off
                $product = \WHMCS\Database\Capsule::table('tblproducts')
                    ->where('id', $service->packageid)
                    ->first();

                if ($product) {
                    $threshold = (float) ($product->configoption5 ?? 0);
                    $topOffAmount = (float) ($product->configoption6 ?? 0);

                    if ($threshold > 0 && $topOffAmount > 0) {
                        $usageData = $module->getSelfServiceUsage($service->id);
                        if ($usageData) {
                            $usageInner = $usageData['data'] ?? $usageData;
                            $credit = $usageInner['credit'] ?? $usageInner['balance'] ?? null;
                            if ($credit !== null && (float) $credit < $threshold) {
                                $module->addSelfServiceCredit($service->id, $topOffAmount, 'Auto top-off');
                                \WHMCS\Module\Server\VirtFusionDirect\Log::insert(
                                    'UsageUpdate:autoTopOff',
                                    ['serviceId' => $service->id, 'credit' => $credit, 'threshold' => $threshold],
                                    ['amount' => $topOffAmount]
                                );
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log but continue processing other services
                \WHMCS\Module\Server\VirtFusionDirect\Log::insert('UsageUpdate:service:' . $service->id, [], $e->getMessage());
                continue;
            }
        }

        return 'success';
    } catch (\Exception $e) {
        return 'Usage update failed: ' . $e->getMessage();
    }
}
