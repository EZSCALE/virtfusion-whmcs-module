# VirtFusion Direct Provisioning Module for WHMCS

[![GitHub Super-Linter](https://github.com/EZSCALE/virtfusion-whmcs-module/actions/workflows/publish-release.yml/badge.svg)](https://github.com/EZSCALE/virtfusion-whmcs-module/actions)
![GitHub](https://img.shields.io/github/license/EZSCALE/virtfusion-whmcs-module)
![GitHub issues](https://img.shields.io/github/issues/EZSCALE/virtfusion-whmcs-module)
![GitHub pull requests](https://img.shields.io/github/issues-pr/EZSCALE/virtfusion-whmcs-module)

This module requires VirtFusion v1.7.3 or higher as this is what it's based on. Please refer to the official [documenataion](https://docs.virtfusion.com/integrations/whmcs).

## Installation

1. Download the latest release from the [releases](https://github.com/EZSCALE/virtfusion-whmcs-module/releases) page.
2. Extract the contents of the archive and upload the modules folder to your WHMCS installation directory.

## What does this module change?

This module changes the following things:

- Adds configurable options to the product configuration page to allow the user to select the operating system and add
  a ssh key to the initial deployment.

## TODO

- [ ] Add post checkout checks to ensure the user has selected an operating system and added a ssh key.