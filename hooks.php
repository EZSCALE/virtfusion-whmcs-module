<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * You'll need to configure the following constrants in your configuration.php file.
 * This is only temporary and will be replaced with pulling the token from the database in the future.
 *
 * const VIRTFUSION_API_URL = "https://your-virtfusion-url.com/api/v1";
 *
 * You can create a token in the VirtFusion control panel under System > API.
 *
 * const VIRT_TOKEN = "your-virtfusion-token";
 */

/**
 * If the constants are not defined, return null to prevent errors.
 */
if (!defined("VIRTFUSION_API_URL") || !defined("VIRT_TOKEN")) {
    return null;
}

if (!function_exists('fetchPackageId')) {
    /**
     * @param string $packageName
     * @return int|null
     * @throws JsonException
     */
    function fetchPackageId(string $packageName): ?int
    {
        $url = sprintf("%s/packages", VIRTFUSION_API_URL);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                sprintf("Authorization: Bearer %s", VIRT_TOKEN)
            ]
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error: " . $err);
        }

        $packages = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        foreach ($packages['data'] as $package) {
            if ($package['name'] === $packageName && $package['enabled'] === true) {
                return $package['id'];
            }
        }

        return null;
    }
}

if (!function_exists('fetchTemplates')) {
    /**
     * @param int $serverPackageId
     * @return array|null
     * @throws JsonException
     * @throws Exception
     */
    function fetchTemplates(int $serverPackageId): ?array
    {
        $url = sprintf("%s/media/templates/fromServerPackageSpec/%d", VIRTFUSION_API_URL, $serverPackageId);

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                sprintf("Authorization: Bearer %s", VIRT_TOKEN)
            )
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error: " . $err);
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }
}

if (!function_exists('custom_os_templates_hook')) {
    /**
     * @param array $vars
     * @return array|null[]
     * @throws Exception
     */
    function custom_os_templates_hook(array $vars): array
    {
        try {
            $serverPackageId = fetchPackageId($vars['productinfo']['name']); // Replace with the appropriate server package ID

            if ($serverPackageId === null) {
                return [
                    'templates' => null,
                ];
            }

            $templates = fetchTemplates($serverPackageId);

            // Assign the generated dropdown menu to a Smarty template variable
            return [
                'templates' => $templates,
            ];
        } catch (JsonException $e) {
            return [
                'templates' => null,
            ];
        }
    }
}

if (!function_exists('add_hook_os_templates')) {
    /**
     * @param array $vars
     * @return array|null
     * @throws Exception
     */
    function add_hook_os_templates(array $vars): ?array
    {
        if (!isset($vars['productinfo']['module']) || $vars['productinfo']['module'] !== 'VirtFusionDirect') {
            return null;
        }

        $templates_data = custom_os_templates_hook($vars)['templates'];

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

        $newOption = [
            'id' => 'os_template',
            'optionname' => 'Initial Operating System',
            'optiontype' => 1,
            'options' => $dropdownOptions,
            'selectedvalue' => ''
        ];
        $configurableoptions = $vars['configurableoptions'];
        $configurableoptions[] = $newOption;

        return [
            'configurableoptions' => $configurableoptions,
        ];
    }

    add_hook('ClientAreaPageCart', 1, 'add_hook_os_templates');
}


