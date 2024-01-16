<?php

use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\User\User;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (!isset($vars['productinfo']['module']) || $vars['productinfo']['module'] !== 'VirtFusionDirect') {
        return null;
    }

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
            $optionLabel = $template['name']." ".$template['version']." ".$template['variant'];
            $dropdownOptions[] = ['id' => $optionValue, 'name' => $optionLabel];
        }
    }

    // Sort dropdownOptions alphabetically by the 'name' key
    usort($dropdownOptions, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    $sshKeys = $cs->getUserSshKeys($vars['loggedinuser']);
    $sshKeysOptions = array_map(function ($sshKey) {
        if ($sshKey['enabled'] === false) {
            return null;
        }

        return [
            'id' => $sshKey['id'],
            'name' => $sshKey['name']
        ];
    }, $sshKeys['data'] ?? []);

    $osID = array_values(array_filter(array_map(function ($option) {
        if ($option['textid'] === 'initialoperatingsystem') {
            return $option['id'];
        }
    }, $vars['customfields'])));

    $sshID = array_values(array_filter(array_map(function ($option) {
        if ($option['textid'] === 'initialsshkey') {
            return $option['id'];
        }
    }, $vars['customfields'])));

    // Construct the JavaScript code
    return "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        let osTemplates = ".json_encode($dropdownOptions, JSON_THROW_ON_ERROR).";
        let sshKeys = ".json_encode($sshKeysOptions, JSON_THROW_ON_ERROR).";

        const osInputField = document.querySelector('[name=\"customfield[".($osID[0] ?? null)."]\"]');
        const osInputLabel = document.querySelector('[for=\"customfield".($osID[0] ?? null)."\"]');
        const sshInputField = document.querySelector('[name=\"customfield[".($sshID[0] ?? null)."]\"]');
        const sshInputLabel = document.querySelector('[for=\"customfield".($sshID[0] ?? null)."\"]');
        
        // Create dropdown options menu, then add it to the DOM then on change, update the regular input.
        let osSelect = document.createElement('select');
        osSelect.className = 'form-control';

        osTemplates.forEach(function(template) {
            let option = document.createElement('option');
            option.value = template.id;
            option.text = template.name;
            osSelect.appendChild(option);
        });
        
        // Set the default value of the input field to the first option in the dropdown.
        osInputField.value = osSelect.options[0].value;
        
        osSelect.addEventListener('change', function() {
            osInputField.value = this.value;
            console.log(this.value);
        });
        
        osInputField.parentNode.insertBefore(osSelect, osInputField.nextSibling);
        osInputField.style.display = 'none';
        
        if (sshKeys.length > 0) {
            // Create dropdown options menu, then add it to the DOM then on change, update the regular input.
            let sshSelect = document.createElement('select');
            sshSelect.className = 'form-control';
    
            sshKeys.forEach(function(sshkey) {
                let option = document.createElement('option');
                option.value = sshkey.id;
                option.text = sshkey.name;
                sshSelect.appendChild(option);
            });
            
            // Set the default value of the input field to the first option in the dropdown.
            sshInputField.value = sshSelect.options[0].value;
            
            sshSelect.addEventListener('change', function() {
                sshInputField.value = this.value;
            });
            
            sshInputField.parentNode.insertBefore(sshSelect, sshInputField.nextSibling);
            sshInputField.style.display = 'none';
        } else {
            sshInputField.style.display = 'none';
            sshInputLabel.style.display = 'none';
        }   
    });
    </script>
    ";
});
