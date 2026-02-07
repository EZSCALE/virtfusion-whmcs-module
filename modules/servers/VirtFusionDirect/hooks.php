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
            // Create the paste-key textarea (hidden initially if keys exist)
            var sshPasteContainer = document.createElement('div');
            sshPasteContainer.setAttribute('id', 'vf-ssh-paste-container');
            sshPasteContainer.style.display = 'none';
            sshPasteContainer.style.marginTop = '8px';

            var pasteLabel = document.createElement('label');
            pasteLabel.textContent = 'Paste your SSH public key:';
            pasteLabel.style.display = 'block';
            pasteLabel.style.marginBottom = '4px';

            var pasteArea = document.createElement('textarea');
            pasteArea.className = 'form-control';
            pasteArea.setAttribute('id', 'vf-ssh-paste');
            pasteArea.setAttribute('rows', '3');
            pasteArea.setAttribute('placeholder', 'ssh-rsa AAAA... or ssh-ed25519 AAAA...');

            pasteArea.addEventListener('input', function() {
                sshInputField.value = this.value.trim();
            });

            sshPasteContainer.appendChild(pasteLabel);
            sshPasteContainer.appendChild(pasteArea);

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

                // Add new key option
                var addNewOption = document.createElement('option');
                addNewOption.value = '__new__';
                addNewOption.text = 'Add new key...';
                sshSelect.appendChild(addNewOption);

                sshSelect.addEventListener('change', function() {
                    if (this.value === '__new__') {
                        sshPasteContainer.style.display = 'block';
                        sshInputField.value = '';
                    } else {
                        sshPasteContainer.style.display = 'none';
                        document.getElementById('vf-ssh-paste').value = '';
                        sshInputField.value = this.value;
                    }
                });

                sshInputField.parentNode.insertBefore(sshSelect, sshInputField.nextSibling);
                sshSelect.parentNode.insertBefore(sshPasteContainer, sshSelect.nextSibling);
                sshInputField.style.display = 'none';
            } else {
                // No existing keys — show the paste textarea directly
                sshPasteContainer.style.display = 'block';
                sshInputField.parentNode.insertBefore(sshPasteContainer, sshInputField.nextSibling);
                sshInputField.style.display = 'none';
            }
        }

        // Slider UI: enhance known configurable option selects with range sliders
        var sliderResourceNames = ['Memory', 'CPU Cores', 'Storage', 'Bandwidth', 'Inbound Network Speed', 'Outbound Network Speed'];
        var sliderUnits = {
            'Memory': 'MB', 'CPU Cores': 'Core(s)', 'Storage': 'GB',
            'Bandwidth': 'GB', 'Inbound Network Speed': 'Mbps', 'Outbound Network Speed': 'Mbps'
        };

        var configSelects = document.querySelectorAll('select[name^=\"configoption[\"]');
        configSelects.forEach(function(sel) {
            // Find the label for this select
            var label = null;
            var labelEl = sel.closest('.form-group, .row');
            if (labelEl) {
                label = labelEl.querySelector('label');
            }
            if (!label) return;

            var labelText = label.textContent.trim();
            var matchedResource = null;
            sliderResourceNames.forEach(function(name) {
                if (labelText.indexOf(name) !== -1) {
                    matchedResource = name;
                }
            });
            if (!matchedResource) return;

            var options = [];
            for (var i = 0; i < sel.options.length; i++) {
                options.push({
                    value: sel.options[i].value,
                    label: sel.options[i].text
                });
            }
            if (options.length < 2) return;

            var unit = sliderUnits[matchedResource] || '';

            // Create slider container
            var container = document.createElement('div');
            container.className = 'vf-slider-container';

            var valueDisplay = document.createElement('div');
            valueDisplay.className = 'vf-slider-value';
            valueDisplay.textContent = options[sel.selectedIndex || 0].label + (unit ? ' ' + unit : '');

            var slider = document.createElement('input');
            slider.type = 'range';
            slider.className = 'vf-slider form-range';
            slider.min = '0';
            slider.max = String(options.length - 1);
            slider.step = '1';
            slider.value = String(sel.selectedIndex || 0);

            slider.addEventListener('input', function() {
                var idx = parseInt(this.value);
                sel.selectedIndex = idx;
                valueDisplay.textContent = options[idx].label + (unit ? ' ' + unit : '');
                // Trigger change event on hidden select for WHMCS pricing
                var evt = new Event('change', { bubbles: true });
                sel.dispatchEvent(evt);
            });

            container.appendChild(valueDisplay);
            container.appendChild(slider);

            sel.parentNode.insertBefore(container, sel.nextSibling);
            sel.style.display = 'none';
        });
    });
    </script>
    ";
    } catch (\Throwable $e) {
        // Silently fail - don't break the checkout page
        return null;
    }
});
