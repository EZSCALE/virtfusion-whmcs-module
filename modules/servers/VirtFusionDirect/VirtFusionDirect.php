<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Module\Server\VirtFusionDirect\ModuleFunctions;

function VirtFusionDirect_MetaData()
{
    return [
        'DisplayName' => 'VirtFusion Direct Provisioning',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'ServiceSingleSignOnLabel' => false,
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
            "Description" => "The default hypervisor group ID",
            "Default" => "1",
        ],
        "packageID" => [
            "FriendlyName" => "Package ID",
            "Type" => "text",
            "Size" => "20",
            "Description" => "The package ID",
            "Default" => "1",
        ],
        "defaultIPv4" => [
            "FriendlyName" => "Default IPv4",
            "Type" => "dropdown",
            "Options" => "0,1,2,3,4,5,6,7,8,9,10",
            "Description" => "The default amount of IPv4 addresses to assign to the server.",
            "Default" => "1",
        ],
    ];
}

function VirtFusionDirect_AdminCustomButtonArray()
{
    $buttonarray = array(
        "Update Server Object" => "updateServerObject",
    );
    return $buttonarray;
}

/**
 *
 *
 * Service functions
 *
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
 * @author https://github.com/BlinkohHost/virtfusion-whmcs-module
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