# 1.0.0 (2026-02-07)


### Bug Fixes

* add null/false guards, proper error handling, and VNC popup fix ([49fdd9e](https://github.com/EZSCALE/virtfusion-whmcs-module/commit/49fdd9e49ba87bfb4b72dd741e15f790c1050033))
* TestConnection for unsaved servers, traffic display, and cache-busting ([e8d2eb0](https://github.com/EZSCALE/virtfusion-whmcs-module/commit/e8d2eb0aa1f173f13bb0b8d7dfca0acebb821ac7))
* XSS escaping, null guards, JS bug fixes, and documentation updates ([6c7cdc6](https://github.com/EZSCALE/virtfusion-whmcs-module/commit/6c7cdc6421678390746adcee4877a7ade8f2a061))


### Features

* add client-side SSH Ed25519 key generator on order page ([209e01d](https://github.com/EZSCALE/virtfusion-whmcs-module/commit/209e01deb6832dce76a307410fbab28b1e420093))
* add VNC check, SSH key paste, resources panel, sliders, and self-service billing ([1e471af](https://github.com/EZSCALE/virtfusion-whmcs-module/commit/1e471affd0ae9a68358afa5704523bce9bb413d0))
* streamline network panel, conditional self-service, remove IP add endpoints ([e73e85c](https://github.com/EZSCALE/virtfusion-whmcs-module/commit/e73e85c5a9faa79b50e4949328c1d2a3cbc49ddf))

# Changelog

All notable changes to the VirtFusion Direct Provisioning Module for WHMCS.

## [0.0.18] - 2025-10-01

### Changed
- Updated GitHub Actions publish workflow
- Moved custom field SQL to `modify.sql` file
- Minor code tweaks

## [0.0.17] - 2024-01-16

### Fixed
- Fix in hooks.php (PR #2 by Prophet731)

## [0.0.16] - 2023-09-11

### Added
- GitHub issue templates

## [0.0.15] - 2023-09-10

### Fixed
- Typo fixes in module code

## [0.0.14] - 2023-09-10

### Fixed
- Fix hook event registration placement

## [0.0.13] - 2023-09-10

### Added
- Contributions from BlinkohHost
- Database-first package ID lookup with API fallback by product name
- Server build initialization on successful server creation

### Changed
- Custom fields changed to not required
- Removed linter workflow (not needed for this project)
- Code cleanup

## [0.0.9] - 2023-09-10

### Changed
- Refactored codebase to object-oriented architecture (OOP)
- Updated README with badges and documentation

## [0.0.6] - 2023-09-10

### Added
- Initial release
- Core provisioning: server create, suspend, unsuspend, terminate
- WHMCS hooks for dynamic OS template and SSH key dropdowns
- Checkout validation for OS selection
- Client area overview template with server information
- Admin services tab with server ID management
- Package change (upgrade/downgrade) support
- Configurable option mapping for dynamic resource allocation
- GitHub Actions CI/CD with semantic-release
- Security policy (SECURITY.md)
- License (GPL v3)
