<?php

use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\User\User;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Shopping Cart Validation Hook
 *
 * Validates that an operating system has been selected before checkout
 * for all VirtFusion products in the cart.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    $errors = [];

    if (!isset($_SESSION['cart']['products']) || !is_array($_SESSION['cart']['products'])) {
        return $errors;
    }

    foreach ($_SESSION['cart']['products'] as $key => $product) {
        $pid = $product['pid'] ?? null;
        if (!$pid) {
            continue;
        }

        $dbProduct = \WHMCS\Database\Capsule::table('tblproducts')
            ->where('id', $pid)
            ->where('servertype', 'VirtFusionDirect')
            ->first();

        if (!$dbProduct) {
            continue;
        }

        // Check if Initial Operating System custom field has a value
        if (isset($product['customfields']) && is_array($product['customfields'])) {
            $osSelected = false;
            $customFields = \WHMCS\Database\Capsule::table('tblcustomfields')
                ->where('relid', $pid)
                ->where('type', 'product')
                ->get();

            foreach ($customFields as $field) {
                if (strtolower(str_replace(' ', '', $field->fieldname)) === 'initialoperatingsystem') {
                    $fieldValue = $product['customfields'][$field->id] ?? '';
                    if (!empty($fieldValue) && is_numeric($fieldValue)) {
                        $osSelected = true;
                    }
                    break;
                }
            }

            if (!$osSelected) {
                $errors[] = 'Please select an Operating System for your VPS order.';
            }
        }
    }

    return $errors;
});

/**
 * Client Area Footer Output Hook
 *
 * Dynamically converts hidden text fields for OS templates and SSH keys
 * into dropdown selects populated from the VirtFusion API.
 * Works with all WHMCS themes by using vanilla JavaScript and standard form-control classes.
 */
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (!isset($vars['productinfo']['module']) || $vars['productinfo']['module'] !== 'VirtFusionDirect') {
        return null;
    }

    try {
        $cs = new ConfigureService();

        $templates_data = $cs->fetchTemplates(
            $cs->fetchPackageByDbId($vars['productinfo']['pid']) ?? $cs->fetchPackageId($vars['productinfo']['name'])
        );

        if (empty($templates_data)) {
            return null;
        }

        $dropdownOptions = [];

        foreach ($templates_data['data'] as $osCategory) {
            foreach ($osCategory['templates'] as $template) {
                $optionValue = $template['id'];
                $optionLabel = htmlspecialchars($template['name'] . " " . $template['version'] . " " . $template['variant'], ENT_QUOTES, 'UTF-8');
                $dropdownOptions[] = ['id' => $optionValue, 'name' => $optionLabel];
            }
        }

        usort($dropdownOptions, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $sshKeys = [];
        $sshKeysOptions = [];
        if (isset($vars['loggedinuser']) && $vars['loggedinuser']) {
            $sshKeysData = $cs->getUserSshKeys($vars['loggedinuser']);
            if ($sshKeysData && isset($sshKeysData['data'])) {
                $sshKeysOptions = array_values(array_filter(array_map(function ($sshKey) {
                    if ($sshKey['enabled'] === false) {
                        return null;
                    }
                    return [
                        'id' => $sshKey['id'],
                        'name' => htmlspecialchars($sshKey['name'], ENT_QUOTES, 'UTF-8')
                    ];
                }, $sshKeysData['data'])));
            }
        }

        $osID = array_values(array_filter(array_map(function ($option) {
            if ($option['textid'] === 'initialoperatingsystem') {
                return $option['id'];
            }
        }, $vars['customfields'] ?? [])));

        $sshID = array_values(array_filter(array_map(function ($option) {
            if ($option['textid'] === 'initialsshkey') {
                return $option['id'];
            }
        }, $vars['customfields'] ?? [])));

        $osFieldId = $osID[0] ?? null;
        $sshFieldId = $sshID[0] ?? null;

        if ($osFieldId === null) {
            return null;
        }

        return "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var osTemplates = " . json_encode($dropdownOptions, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";
        var sshKeys = " . json_encode($sshKeysOptions, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";

        var osInputField = document.querySelector('[name=\"customfield[" . (int) $osFieldId . "]\"]');
        var sshInputField = " . ($sshFieldId !== null ? "document.querySelector('[name=\"customfield[" . (int) $sshFieldId . "]\"]')" : "null") . ";
        var sshInputLabel = " . ($sshFieldId !== null ? "document.querySelector('[for=\"customfield" . (int) $sshFieldId . "\"]')" : "null") . ";

        if (!osInputField) return;

        // Create OS dropdown
        var osSelect = document.createElement('select');
        osSelect.className = 'form-control';
        osSelect.setAttribute('id', 'vf-os-select');

        var defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.text = '-- Select Operating System --';
        osSelect.appendChild(defaultOption);

        osTemplates.forEach(function(template) {
            var option = document.createElement('option');
            option.value = template.id;
            option.text = template.name;
            osSelect.appendChild(option);
        });

        osSelect.addEventListener('change', function() {
            osInputField.value = this.value;
        });

        osInputField.parentNode.insertBefore(osSelect, osInputField.nextSibling);
        osInputField.style.display = 'none';

        // Handle SSH keys
        if (sshInputField) {
            if (sshKeys.length > 0) {
                var sshSelect = document.createElement('select');
                sshSelect.className = 'form-control';
                sshSelect.setAttribute('id', 'vf-ssh-select');

                var sshDefaultOption = document.createElement('option');
                sshDefaultOption.value = '';
                sshDefaultOption.text = '-- No SSH Key (Optional) --';
                sshSelect.appendChild(sshDefaultOption);

                sshKeys.forEach(function(sshkey) {
                    var option = document.createElement('option');
                    option.value = sshkey.id;
                    option.text = sshkey.name;
                    sshSelect.appendChild(option);
                });

                sshSelect.addEventListener('change', function() {
                    sshInputField.value = this.value;
                });

                sshInputField.parentNode.insertBefore(sshSelect, sshInputField.nextSibling);
                sshInputField.style.display = 'none';
            } else {
                sshInputField.style.display = 'none';
                if (sshInputLabel) sshInputLabel.style.display = 'none';
                // Also hide the parent container if it exists
                var sshContainer = sshInputField.closest('.form-group');
                if (sshContainer) sshContainer.style.display = 'none';
            }
        }
    });
    </script>
    ";
    } catch (\Exception $e) {
        // Silently fail - don't break the checkout page
        return null;
    }
});
