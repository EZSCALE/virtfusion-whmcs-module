# VirtFusion Direct Provisioning Module for WHMCS

[![GitHub Super-Linter](https://github.com/EZSCALE/virtfusion-whmcs-module/actions/workflows/publish-release.yml/badge.svg)](https://github.com/EZSCALE/virtfusion-whmcs-module/actions)
![GitHub](https://img.shields.io/github/license/EZSCALE/virtfusion-whmcs-module)
![GitHub issues](https://img.shields.io/github/issues/EZSCALE/virtfusion-whmcs-module)
![GitHub pull requests](https://img.shields.io/github/issues-pr/EZSCALE/virtfusion-whmcs-module)

This module requires VirtFusion v1.7.3 or higher as this is what it's based on. Please refer to the official [documenataion](https://docs.virtfusion.com/integrations/whmcs).

## Installation

1. Download the latest release from the [releases](https://github.com/EZSCALE/virtfusion-whmcs-module/releases) page.
2. Extract the contents of the archive and upload the modules folder to your WHMCS installation directory.

## :heavy_exclamation_mark: Important Notes :heavy_exclamation_mark:

You must create two custom fields in WHMCS for this module to work. You need to configure the following custom fields on
each product you want to use this module with.

| Field Name               | Field Type | Description              | Validation  | Select Options | Admin Only | Required Field     | Show on Order Form | Show on Invoice |
|--------------------------|------------|--------------------------|-------------|----------------|------------|--------------------|--------------------|-----------------|
| Initial Operating System | Text Box   | Set to whatever you want | Leave Blank | Leave Blank    | :x:        | :white_check_mark: | :white_check_mark: | :x:             |
| Initial SSH Key          | Text Box   | Set to whatever you want | Leave Blank | Leave Blank    | :x:        | :x:                | :white_check_mark: | :x:             |

## What does this module change?

This module changes the following things:

- Adds configurable options to the product configuration page to allow the user to select the operating system and add
  an ssh key to the initial deployment.

## TODO

- [ ] Add post checkout checks to ensure the user has selected an operating system and added a ssh key.