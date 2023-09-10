<?php

use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\User\User;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

if (!function_exists('add_hook_os_templates')) {
    /**
     * @param array $vars
     * @return array|null
     * @throws JsonException
     */
    function add_hook_os_templates(array $vars): ?array
    {
        if (!isset($vars['productinfo']['module']) || $vars['productinfo']['module'] !== 'VirtFusionDirect') {
            return null;
        }

        $cs = new ConfigureService();

        $templates_data = $cs->fetchTemplates(
            $cs->fetchPackageId($vars['productinfo']['name'])
        );

        if (empty($templates_data)) {
            return null;
        }

        $dropdownOptions = [];

        foreach ($templates_data['data'] as $osCategory) {
            foreach ($osCategory['templates'] as $template) {
                $optionValue = $template['id'];
                $optionLabel = $template['name'] . " " . $template['version'] . " " . $template['variant'];
                $dropdownOptions[] = ['id' => $optionValue, 'name' => $optionLabel];
            }
        }

        // Sort dropdownOptions alphabetically by the 'name' key
        usort($dropdownOptions, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $osTemplates = [
            'id' => 'os_template',
            'optionname' => 'Initial Operating System',
            'optiontype' => 1,
            'options' => $dropdownOptions,
            'selectedvalue' => ''
        ];

        $sshKeys = $cs->getUserSshKeys($vars['loggedinuser']);

        $sshKeysOptions = [
            'id' => 'ssh_key',
            'optionname' => 'Initial SSH Key',
            'optiontype' => 1,
            'options' => array_map(function ($sshKey) {
                if ($sshKey['enabled'] === false) {
                    return null;
                }

                return [
                    'id' => $sshKey['id'],
                    'name' => $sshKey['name']
                ];
            }, $sshKeys['data'] ?? []),
            'selectedvalue' => ''
        ];

        $configurableoptions = $vars['configurableoptions'];

        array_push(
            $configurableoptions,
            $osTemplates,
            $sshKeysOptions
        );

        return [
            'configurableoptions' => $configurableoptions,
        ];
    }

    add_hook('ClientAreaPageCart', 1, 'add_hook_os_templates');
}