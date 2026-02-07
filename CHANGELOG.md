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
