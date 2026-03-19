<?php

use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Database;

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

        $galleryData = [
            'baseUrl' => '',
            'categories' => \WHMCS\Module\Server\VirtFusionDirect\Module::groupOsTemplates($templates_data['data'] ?? [], true),
        ];

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

        $systemUrl = Database::getSystemUrl();

        return "
    <link href=\"" . htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8') . "modules/servers/VirtFusionDirect/templates/css/module.css?v=20260319\" rel=\"stylesheet\">
    <script src=\"" . htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8') . "modules/servers/VirtFusionDirect/templates/js/keygen.js?v=20260319\"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var osGalleryData = " . json_encode($galleryData, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";
        var sshKeys = " . json_encode($sshKeysOptions, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";

        var osInputField = document.querySelector('[name=\"customfield[" . (int) $osFieldId . "]\"]');
        var sshInputField = " . ($sshFieldId !== null ? "document.querySelector('[name=\"customfield[" . (int) $sshFieldId . "]\"]')" : "null") . ";
        var sshInputLabel = " . ($sshFieldId !== null ? "document.querySelector('[for=\"customfield" . (int) $sshFieldId . "\"]')" : "null") . ";

        if (!osInputField) return;

        // Brand color map (must match vfOsBrandColors in module.js)
        var brandColors = {
            'ubuntu':'#E95420','debian':'#A81D33','rocky':'#10B981','centos':'#932279',
            'almalinux':'#0F4266','alma':'#0F4266','windows':'#0078D4','fedora':'#51A2DA',
            'arch':'#1793D1','opensuse':'#73BA25','suse':'#73BA25','freebsd':'#AB2B28',
            'oracle':'#F80000','rhel':'#EE0000','red hat':'#EE0000','cloudlinux':'#0095D9',
            'gentoo':'#54487A','slackware':'#000','nixos':'#7EBAE4','alpine':'#0D597F'
        };
        function getBrandColor(name) {
            var l = (name || '').toLowerCase();
            for (var k in brandColors) { if (l.indexOf(k) !== -1) return brandColors[k]; }
            return '#6c757d';
        }

        // Build gallery container
        var galleryWrap = document.createElement('div');
        galleryWrap.style.marginTop = '8px';

        var searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'form-control vf-os-search';
        searchInput.placeholder = 'Search templates...';
        galleryWrap.appendChild(searchInput);

        var galleryContainer = document.createElement('div');
        galleryContainer.setAttribute('id', 'vf-checkout-os-gallery');
        galleryContainer.style.marginTop = '8px';

        if (osGalleryData.categories && osGalleryData.categories.length > 0) {
            osGalleryData.categories.forEach(function(cat, ci) {
                var section = document.createElement('div');
                section.className = 'vf-os-category';

                var header = document.createElement('div');
                header.className = 'vf-os-category-header';
                var catColor = getBrandColor(cat.name);

                var catIcon = document.createElement('span');
                catIcon.className = 'vf-os-category-icon';
                catIcon.style.background = catColor;
                catIcon.textContent = (cat.name || '?')[0].toUpperCase();

                var catTitle = document.createElement('span');
                catTitle.textContent = cat.name + ' (' + cat.templates.length + ')';

                var arrow = document.createElement('span');
                arrow.className = 'vf-os-category-arrow';
                arrow.textContent = ci === 0 ? '\u25BC' : '\u25B6';

                header.appendChild(catIcon);
                header.appendChild(catTitle);
                header.appendChild(arrow);
                section.appendChild(header);

                var grid = document.createElement('div');
                grid.className = 'vf-os-grid';
                if (ci !== 0) grid.style.display = 'none';

                header.addEventListener('click', function() {
                    var isOpen = grid.style.display !== 'none';
                    // Collapse all
                    galleryContainer.querySelectorAll('.vf-os-grid').forEach(function(g) { g.style.display = 'none'; });
                    galleryContainer.querySelectorAll('.vf-os-category-arrow').forEach(function(a) { a.textContent = '\u25B6'; });
                    // Toggle this one
                    if (!isOpen) {
                        grid.style.display = '';
                        arrow.textContent = '\u25BC';
                    }
                });

                cat.templates.forEach(function(tpl) {
                    var fullLabel = tpl.name + (tpl.version ? ' ' + tpl.version : '') + (tpl.variant ? ' ' + tpl.variant : '');
                    var card = document.createElement('div');
                    card.className = 'vf-os-card' + (tpl.eol ? ' vf-os-card-eol' : '');
                    card.setAttribute('data-id', tpl.id);
                    card.setAttribute('data-search', fullLabel.toLowerCase());

                    var iconDiv = document.createElement('div');
                    iconDiv.className = 'vf-os-icon';
                    iconDiv.style.background = catColor;
                    var sp = document.createElement('span');
                    sp.textContent = (tpl.name || '?')[0].toUpperCase();
                    iconDiv.appendChild(sp);
                    card.appendChild(iconDiv);

                    var labelDiv = document.createElement('div');
                    labelDiv.className = 'vf-os-label';
                    labelDiv.textContent = tpl.name;
                    card.appendChild(labelDiv);

                    var verDiv = document.createElement('div');
                    verDiv.className = 'vf-os-version';
                    verDiv.textContent = (tpl.version || '') + (tpl.variant ? ' ' + tpl.variant : '');
                    card.appendChild(verDiv);

                    if (tpl.eol) {
                        var eolBadge = document.createElement('span');
                        eolBadge.className = 'vf-os-eol-badge';
                        eolBadge.textContent = 'EOL';
                        card.appendChild(eolBadge);
                    }

                    card.addEventListener('click', function() {
                        galleryContainer.querySelectorAll('.vf-os-card').forEach(function(c) { c.classList.remove('vf-os-card-selected'); });
                        card.classList.add('vf-os-card-selected');
                        osInputField.value = tpl.id;
                        galleryContainer.style.borderColor = '';
                    });

                    grid.appendChild(card);
                });

                section.appendChild(grid);
                galleryContainer.appendChild(section);
            });
        }

        galleryWrap.appendChild(galleryContainer);

        // Search handler
        searchInput.addEventListener('keyup', function() {
            var q = this.value.toLowerCase();
            galleryContainer.querySelectorAll('.vf-os-card').forEach(function(c) {
                c.style.display = c.getAttribute('data-search').indexOf(q) !== -1 ? '' : 'none';
            });
            galleryContainer.querySelectorAll('.vf-os-category').forEach(function(s) {
                var cards = s.querySelectorAll('.vf-os-card');
                var hasVisible = false;
                cards.forEach(function(c) { if (c.style.display !== 'none') hasVisible = true; });
                s.style.display = hasVisible ? '' : 'none';
            });
        });

        // Validation: red border if no selection on form submit
        var form = osInputField.closest('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (!osInputField.value) {
                    galleryContainer.style.border = '2px solid #dc3545';
                    galleryContainer.style.borderRadius = '8px';
                    galleryContainer.style.padding = '4px';
                    galleryContainer.scrollIntoView({behavior: 'smooth', block: 'center'});
                }
            });
        }

        osInputField.parentNode.insertBefore(galleryWrap, osInputField.nextSibling);
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

            // Generate key button
            var generateBtn = document.createElement('button');
            generateBtn.type = 'button';
            generateBtn.className = 'btn btn-outline-secondary btn-sm';
            generateBtn.textContent = 'Generate a new key';
            generateBtn.style.marginTop = '8px';

            // Private key panel (hidden initially)
            var privKeyPanel = document.createElement('div');
            privKeyPanel.setAttribute('id', 'vf-privkey-panel');
            privKeyPanel.style.display = 'none';
            privKeyPanel.style.marginTop = '12px';
            privKeyPanel.style.border = '2px solid #dc3545';
            privKeyPanel.style.borderRadius = '6px';
            privKeyPanel.style.padding = '12px';

            var privKeyWarning = document.createElement('div');
            privKeyWarning.style.color = '#dc3545';
            privKeyWarning.style.fontWeight = 'bold';
            privKeyWarning.style.marginBottom = '8px';
            privKeyWarning.textContent = 'Private Key — Save This Now! It will not be shown again.';

            var privKeyArea = document.createElement('textarea');
            privKeyArea.className = 'form-control';
            privKeyArea.setAttribute('rows', '6');
            privKeyArea.setAttribute('readonly', 'readonly');
            privKeyArea.style.fontFamily = 'monospace';
            privKeyArea.style.fontSize = '12px';
            privKeyArea.style.marginBottom = '8px';

            var privKeyBtnRow = document.createElement('div');
            privKeyBtnRow.style.display = 'flex';
            privKeyBtnRow.style.gap = '8px';
            privKeyBtnRow.style.alignItems = 'center';
            privKeyBtnRow.style.flexWrap = 'wrap';

            var downloadBtn = document.createElement('button');
            downloadBtn.type = 'button';
            downloadBtn.className = 'btn btn-primary btn-sm';
            downloadBtn.textContent = 'Download';

            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'btn btn-default btn-secondary btn-sm';
            copyBtn.textContent = 'Copy to Clipboard';

            var pubKeyConfirm = document.createElement('span');
            pubKeyConfirm.style.color = '#28a745';
            pubKeyConfirm.style.fontWeight = 'bold';
            pubKeyConfirm.textContent = 'Public key set automatically.';

            privKeyBtnRow.appendChild(downloadBtn);
            privKeyBtnRow.appendChild(copyBtn);
            privKeyBtnRow.appendChild(pubKeyConfirm);
            privKeyPanel.appendChild(privKeyWarning);
            privKeyPanel.appendChild(privKeyArea);
            privKeyPanel.appendChild(privKeyBtnRow);

            downloadBtn.addEventListener('click', function() {
                vfDownloadFile('id_ed25519', privKeyArea.value);
            });

            copyBtn.addEventListener('click', function() {
                navigator.clipboard.writeText(privKeyArea.value).then(function() {
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function() { copyBtn.textContent = 'Copy to Clipboard'; }, 2000);
                });
            });

            // Error message for unsupported browsers
            var genErrorMsg = document.createElement('div');
            genErrorMsg.style.display = 'none';
            genErrorMsg.style.marginTop = '8px';
            genErrorMsg.style.color = '#dc3545';
            genErrorMsg.textContent = 'Your browser does not support Ed25519 key generation. Please paste your public key manually.';

            generateBtn.addEventListener('click', async function() {
                generateBtn.disabled = true;
                generateBtn.textContent = 'Generating...';
                try {
                    var keys = await vfGenerateSSHKey();
                    var sshSelect = document.getElementById('vf-ssh-select');
                    if (sshSelect) {
                        sshSelect.value = '__new__';
                        sshPasteContainer.style.display = 'block';
                    }
                    pasteArea.value = keys.publicKey;
                    sshInputField.value = keys.publicKey;
                    privKeyArea.value = keys.privateKey;
                    privKeyPanel.style.display = 'block';
                    genErrorMsg.style.display = 'none';
                } catch (e) {
                    genErrorMsg.style.display = 'block';
                    privKeyPanel.style.display = 'none';
                } finally {
                    generateBtn.disabled = false;
                    generateBtn.textContent = 'Generate a new key';
                }
            });

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
                sshPasteContainer.parentNode.insertBefore(generateBtn, sshPasteContainer.nextSibling);
                generateBtn.parentNode.insertBefore(genErrorMsg, generateBtn.nextSibling);
                genErrorMsg.parentNode.insertBefore(privKeyPanel, genErrorMsg.nextSibling);
                sshInputField.style.display = 'none';
            } else {
                // No existing keys — show the paste textarea directly
                sshPasteContainer.style.display = 'block';
                sshInputField.parentNode.insertBefore(sshPasteContainer, sshInputField.nextSibling);
                sshPasteContainer.parentNode.insertBefore(generateBtn, sshPasteContainer.nextSibling);
                generateBtn.parentNode.insertBefore(genErrorMsg, generateBtn.nextSibling);
                genErrorMsg.parentNode.insertBefore(privKeyPanel, genErrorMsg.nextSibling);
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
