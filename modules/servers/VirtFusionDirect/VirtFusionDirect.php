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
    ];
}

function VirtFusionDirect_TestConnection(array $params)
{
    try {
        $module = new Module();
        $cp = $module->getCP($params['serverid']);

        if (!$cp) {
            return ['success' => false, 'error' => 'Unable to retrieve server configuration. Please verify the server hostname and access hash/password.'];
        }

        $request = $module->initCurl($cp['token']);
        $data = $request->get($cp['url'] . '/connect');

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
    } catch (\Exception $e) {
        return ['success' => false, 'error' => 'Connection test failed: ' . $e->getMessage()];
    }
}

function VirtFusionDirect_AdminCustomButtonArray()
{
    return [
        "Update Server Object" => "updateServerObject",
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
