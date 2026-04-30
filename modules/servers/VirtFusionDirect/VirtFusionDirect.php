<?php

/**
 * VirtFusion Direct Provisioning Module — WHMCS server module entry point.
 *
 * This file contains the non-namespaced functions WHMCS calls via its reflection-
 * based module dispatcher. They follow the naming convention:
 *
 *     {ModuleDirectoryName}_{FunctionName}(...)
 *
 * WHMCS looks for these on every relevant event (provisioning, UI rendering,
 * daily cron, test connection, etc.). Every function here is a thin shim that
 * instantiates ModuleFunctions (or Module) and delegates to a method — keeping
 * the dispatch surface small and the business logic in unit-exercisable classes.
 *
 * DO NOT add significant logic directly in these shims. If you need a new
 * lifecycle behaviour, add it as a method on ModuleFunctions and point the
 * shim at it. This makes the module predictable: one public function, one method.
 *
 * RESERVED NAMES — DO NOT CHANGE
 * ------------------------------
 * WHMCS looks up these specific function names by convention; renaming them
 * disables the corresponding feature in WHMCS silently:
 *   VirtFusionDirect_MetaData        → Displayed name + API version
 *   VirtFusionDirect_ConfigOptions   → Product-level settings fields
 *   VirtFusionDirect_TestConnection  → Admin "Test Connection" button
 *   VirtFusionDirect_CreateAccount   → Provisioning on order-activation
 *   VirtFusionDirect_SuspendAccount  → Suspension
 *   VirtFusionDirect_UnsuspendAccount → Unsuspension
 *   VirtFusionDirect_TerminateAccount → Termination
 *   VirtFusionDirect_ChangePackage   → Package change on upgrade/downgrade
 *   VirtFusionDirect_AdminServicesTabFields     → Admin services tab renderer
 *   VirtFusionDirect_AdminServicesTabFieldsSave → Admin services tab save handler
 *   VirtFusionDirect_ClientArea      → Client-area template + vars
 *   VirtFusionDirect_ServiceSingleSignOn → SSO button handler
 *   VirtFusionDirect_AdminCustomButtonArray → Custom admin action buttons
 *   VirtFusionDirect_UsageUpdate     → Daily cron bandwidth/disk usage sync
 */
if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VirtFusionDirect\Database;
use WHMCS\Module\Server\VirtFusionDirect\Log;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\ModuleFunctions;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Client as PowerDnsClient;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config as PowerDnsConfig;

/**
 * Returns module metadata consumed by WHMCS.
 *
 * @return array
 */
function VirtFusionDirect_MetaData()
{
    return [
        'DisplayName' => 'VirtFusion Direct Provisioning ezscale',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'ServiceSingleSignOnLabel' => 'Login to VirtFusion Panel',
        'AdminSingleSignOnLabel' => false,
    ];
}

/**
 * Returns product configuration options displayed in the WHMCS product editor.
 *
 * @return array
 */
function VirtFusionDirect_ConfigOptions()
{
    return [
        'defaultHypervisorGroupId' => [
            'FriendlyName' => 'Hypervisor Group ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'The default hypervisor group ID for server placement.',
            'Default' => '1',
        ],
        'packageID' => [
            'FriendlyName' => 'Package ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'The VirtFusion package ID that defines server resources.',
            'Default' => '1',
        ],
        'defaultIPv4' => [
            'FriendlyName' => 'Default IPv4',
            'Type' => 'dropdown',
            'Options' => '0,1,2,3,4,5,6,7,8,9,10',
            'Description' => 'The default number of IPv4 addresses to assign to each server.',
            'Default' => '1',
        ],
        'selfServiceMode' => [
            'FriendlyName' => 'Self-Service Mode',
            'Type' => 'dropdown',
            'Options' => '0|Disabled,1|Hourly,2|Resource Packs,3|Both',
            'Description' => 'Enable VirtFusion self-service billing for users created by this product.',
            'Default' => '0',
        ],
        'autoTopOffThreshold' => [
            'FriendlyName' => 'Auto Top-Off Threshold',
            'Type' => 'text',
            'Size' => '10',
            'Description' => 'Credit balance below which auto top-off triggers during cron. 0 = disabled.',
            'Default' => '0',
        ],
        'autoTopOffAmount' => [
            'FriendlyName' => 'Auto Top-Off Amount',
            'Type' => 'text',
            'Size' => '10',
            'Description' => 'Credit amount to add when auto top-off triggers.',
            'Default' => '100',
        ],
        'stockSafetyBufferPct' => [
            'FriendlyName' => 'Stock Safety Buffer (%)',
            'Type' => 'text',
            'Size' => '5',
            'Description' => 'Reserved headroom applied per resource when calculating stock. Only effective when the WHMCS Stock Control toggle is enabled on this product. 0-100; ignored for resources with no quota set in VirtFusion. Default is 10% if left blank.',
            'Default' => '10',
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
        $module = new Module;
        $request = $module->initCurl($password);
        $data = $request->get($url . '/connect');

        $httpCode = $request->getRequestInfo('http_code');

        if ($httpCode == 200) {
            // Probe the compute scope: stock control depends on read access to
            // /compute/hypervisors/groups. A token scoped only to /servers will pass the
            // /connect check above but silently break nightly stock recalculation, so we
            // surface the missing scope at config time rather than a week later.
            $groupsProbe = $module->initCurl($password);
            $groupsProbe->get($url . '/compute/hypervisors/groups?results=1');
            $groupsHttp = (int) $groupsProbe->getRequestInfo('http_code');

            if ($groupsHttp === 401 || $groupsHttp === 403) {
                return [
                    'success' => false,
                    'error' => 'VirtFusion OK but API token lacks read access to /compute/hypervisors/groups (HTTP ' . $groupsHttp . '). Stock Control will not work — re-issue the token with compute:read scope.',
                ];
            }

            if ($groupsHttp !== 200) {
                return [
                    'success' => false,
                    'error' => 'VirtFusion OK but /compute/hypervisors/groups returned HTTP ' . $groupsHttp . '. Stock Control may not work correctly.',
                ];
            }

            // Also verify PowerDNS health when the DNS addon is activated, so the
            // admin's Test Connection button reflects the full provisioning path.
            if (PowerDnsConfig::isEnabled()) {
                $pdns = (new PowerDnsClient)->ping();
                if (! $pdns['ok']) {
                    return [
                        'success' => false,
                        'error' => 'VirtFusion OK; PowerDNS unreachable — '
                            . ($pdns['error'] ?? 'unknown')
                            . ' (HTTP ' . (int) $pdns['http'] . '). Fix the VirtFusion DNS addon settings.',
                    ];
                }
            }

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
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Connection test failed: ' . $e->getMessage()];
    }
}

/**
 * Returns custom admin action buttons shown on the service management page.
 *
 * @return array Button label => function suffix pairs
 */
function VirtFusionDirect_AdminCustomButtonArray()
{
    return [
        'Update Server Object' => 'updateServerObject',
        'Validate Server Config' => 'validateServerConfig',
    ];
}

function VirtFusionDirect_ServiceSingleSignOn(array $params)
{
    try {
        $module = new Module;
        $token = $module->fetchLoginTokens($params['serviceid']);

        if ($token) {
            return ['success' => true, 'redirectTo' => $token];
        }

        return ['success' => false, 'errorMsg' => 'Unable to generate a login token. The server may not be active or the VirtFusion API may be unreachable.'];
    } catch (Exception $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

/**
 * Service functions
 */
function VirtFusionDirect_CreateAccount(array $params)
{
    return (new ModuleFunctions)->createAccount($params);
}

/**
 * Suspends the VirtFusion server associated with a WHMCS service.
 *
 * @param  array  $params  WHMCS module parameters
 * @return string 'success' or error message
 */
function VirtFusionDirect_SuspendAccount(array $params)
{
    return (new ModuleFunctions)->suspendAccount($params);
}

/**
 * Unsuspends the VirtFusion server associated with a WHMCS service.
 *
 * @param  array  $params  WHMCS module parameters
 * @return string 'success' or error message
 */
function VirtFusionDirect_UnsuspendAccount(array $params)
{
    return (new ModuleFunctions)->unsuspendAccount($params);
}

/**
 * Terminates (deletes) the VirtFusion server associated with a WHMCS service.
 *
 * @param  array  $params  WHMCS module parameters
 * @return string 'success' or error message
 */
function VirtFusionDirect_TerminateAccount(array $params)
{
    return (new ModuleFunctions)->terminateAccount($params);
}

/**
 * Admin custom action: refreshes the local server object from the VirtFusion API.
 *
 * @param  array  $params  WHMCS module parameters
 * @return string 'success' or error message
 */
function VirtFusionDirect_updateServerObject(array $params)
{
    return (new ModuleFunctions)->updateServerObject($params);
}

/**
 * Allows changing of the package of a server
 *
 * @return string
 */
function VirtFusionDirect_ChangePackage(array $params)
{
    return (new ModuleFunctions)->changePackage($params);
}

/**
 * Returns HTML fields rendered in the custom admin services tab.
 *
 * @param  array  $params  WHMCS module parameters
 * @return array Field name => HTML value pairs
 */
function VirtFusionDirect_AdminServicesTabFields(array $params)
{
    return (new ModuleFunctions)->adminServicesTabFields($params);
}

/**
 * Handles saving of custom admin services tab field values.
 *
 * @param  array  $params  WHMCS module parameters
 * @return void
 */
function VirtFusionDirect_AdminServicesTabFieldsSave(array $params)
{
    (new ModuleFunctions)->adminServicesTabFieldsSave($params);
}

/**
 * Returns the client area template variables and template name for the service overview page.
 *
 * @param  array  $params  WHMCS module parameters
 * @return array Smarty template variables and 'templatefile' key
 */
function VirtFusionDirect_ClientArea(array $params)
{
    return (new ModuleFunctions)->clientArea($params);
}

/**
 * Validates server configuration via dry run without creating the server.
 *
 * @return string 'success' or error message
 */
function VirtFusionDirect_validateServerConfig(array $params)
{
    return (new ModuleFunctions)->validateServerConfig($params);
}

/**
 * Usage Update - called by WHMCS daily cron to sync bandwidth and disk usage.
 *
 * Updates tblhosting with disk and bandwidth usage data from VirtFusion.
 * Fields updated: diskused, disklimit, bwused, bwlimit, lastupdate
 *
 * @param  array  $params  Server access credentials
 * @return string 'success' or error message
 */
function VirtFusionDirect_UsageUpdate(array $params)
{
    try {
        $module = new Module;
        $cp = $module->getCP($params['serverid']);

        if (! $cp) {
            return 'No control server found for usage update.';
        }

        $services = Capsule::table('tblhosting')
            ->where('server', $params['serverid'])
            ->where('domainstatus', 'Active')
            ->get();

        foreach ($services as $service) {
            try {
                $systemService = Database::getSystemService($service->id);
                if (! $systemService || empty($systemService->server_id)) {
                    // No VirtFusion server linked to this WHMCS service yet —
                    // either provisioning hasn't happened or it failed mid-create.
                    // Skipping is correct: there is nothing to read usage from.
                    continue;
                }

                // Fetch server settings (limits + storage profile) with remoteState=true
                // so the qemu-agent fsinfo block is included for disk usage. The agent
                // is best-effort — guests without qemu-agent installed will have no
                // fsinfo, in which case we simply skip the diskused write rather than
                // zeroing it.
                $request = $module->initCurl($cp['token']);
                $data = $request->get($cp['url'] . '/servers/' . (int) $systemService->server_id . '?remoteState=true');

                if ($request->getRequestInfo('http_code') != 200) {
                    continue;
                }

                $serverData = json_decode($data, true);
                if (! isset($serverData['data'])) {
                    continue;
                }

                $server = $serverData['data'];
                $update = [];

                // Disk usage (WHMCS expects MB) — derived from qemu-agent fsinfo when
                // available. Sum across all reported filesystems (root + any extra
                // mounts) and convert bytes -> MB. If the agent isn't running we get
                // no fsinfo entries and leave diskused untouched.
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
                    // settings.resources.storage is in GB; WHMCS disklimit is MB.
                    $update['disklimit'] = (int) $server['settings']['resources']['storage'] * 1024;
                }

                // Bandwidth usage (WHMCS expects MB) — fetched from the dedicated
                // /servers/{id}/traffic endpoint, which is the canonical source for
                // billing-period totals. The /servers/{id} response only exposes the
                // current period's window (start/end/limit), not the byte counter.
                $trafficRequest = $module->initCurl($cp['token']);
                $trafficData = $trafficRequest->get($cp['url'] . '/servers/' . (int) $systemService->server_id . '/traffic');
                if ($trafficRequest->getRequestInfo('http_code') == 200) {
                    $trafficJson = json_decode($trafficData, true);
                    $currentPeriod = $trafficJson['data']['monthly'][0] ?? null;
                    if (is_array($currentPeriod) && isset($currentPeriod['total']) && is_numeric($currentPeriod['total'])) {
                        $update['bwused'] = (int) round($currentPeriod['total'] / 1048576);
                    }
                }
                if (isset($server['settings']['resources']['traffic'])) {
                    // settings.resources.traffic is in GB; 0 means unlimited, which
                    // WHMCS represents the same way (0 bwlimit = no cap).
                    $trafficGB = (int) $server['settings']['resources']['traffic'];
                    $update['bwlimit'] = $trafficGB > 0 ? $trafficGB * 1024 : 0;
                }

                if (! empty($update)) {
                    $update['lastupdate'] = date('Y-m-d H:i:s');
                    Capsule::table('tblhosting')
                        ->where('id', $service->id)
                        ->update($update);
                }

                // Self-service auto top-off
                $product = Capsule::table('tblproducts')
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
                                Log::insert(
                                    'UsageUpdate:autoTopOff',
                                    ['serviceId' => $service->id, 'credit' => $credit, 'threshold' => $threshold],
                                    ['amount' => $topOffAmount],
                                );
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Log but continue processing other services
                Log::insert('UsageUpdate:service:' . $service->id, [], $e->getMessage());
                continue;
            }
        }

        return 'success';
    } catch (Exception $e) {
        return 'Usage update failed: ' . $e->getMessage();
    }
}
