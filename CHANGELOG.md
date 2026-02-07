# Changelog

All notable changes to the VirtFusion Direct Provisioning Module for WHMCS.

## [Unreleased]

### Added
- **Power management** — Start, restart, graceful shutdown, and force power off controls in client area
- **Server rebuild** — Reinstall with any available OS template from client area with confirmation dialog
- **Server rename** — Change server display name via client area
- **Network management** — View, add, and remove IPv4 addresses and IPv6 subnets from client area
- **VNC console** — Browser-based console access (VirtFusion v6.1.0+)
- **VNC runtime check** — VNC panel auto-hides when VNC is disabled on the server
- **Backup management** — Assign and remove backup plans via API
- **Resource modification** — In-place memory, CPU, and traffic changes (VirtFusion v6.2.0+)
- **Resources panel** — Client area panel showing current memory, CPU, storage, traffic allocation with progress bars and upgrade/downgrade link
- **UsageUpdate cron** — Automated bandwidth and disk usage sync from VirtFusion to WHMCS
- **Dry run validation** — Test server creation parameters before provisioning
- **Admin "Validate Server Config" button** — Dry run from admin services tab
- **TestConnection** — Validate API credentials from WHMCS server settings
- **ServiceSingleSignOn** — Native WHMCS SSO integration for VirtFusion panel
- **Server status badge** — Visual indicator of server state in overview
- **Traffic usage display** — Bandwidth used vs allocated
- **Checkout validation** — `ShoppingCartValidateCheckout` hook ensures OS selection before order placement
- **SSH key paste at checkout** — Users can paste a raw SSH public key during checkout; key is created via `POST /ssh_keys` during provisioning
- **Order form sliders** — Configurable option dropdowns replaced with styled range sliders for resource selection
- **Self-service billing** — Credit balance display, usage breakdown, and credit top-up from client area
- **Self-service config options** — Product config options 4-6: Self-Service Mode, Auto Top-Off Threshold, Auto Top-Off Amount
- **Auto top-off** — During WHMCS daily cron, automatically adds credit when balance falls below threshold
- **Self-service user creation** — New VirtFusion users created with self-service billing settings when enabled
- **CLAUDE.md** — Project architecture and development guidance for Claude Code

### Changed
- Enable SSL/TLS certificate verification by default (was disabled)
- Remove `error_reporting(0)` that silenced all errors
- Add input sanitization on all user parameters (type casting, regex filtering)
- Return proper HTTP status codes (401, 403, 400, 500) instead of always 200
- Add XSS protection with `htmlspecialchars()` and `encodeURIComponent()`
- Readable, unminified JavaScript with JSDoc header
- Dual panel/card CSS classes for Bootstrap 3/4/5 theme compatibility
- `changePackage()` now applies individual resource modifications from configurable options after updating the package
- `initServerBuild()` accepts optional VF user ID parameter for SSH key creation
- `ServerResource::process()` returns raw numeric resource values and `vncEnabled` boolean
- Comprehensive README rewrite with installation, configuration, troubleshooting, and API reference

### Fixed
- Add `isset()` guards before `count()` on ipv4/ipv6 arrays in ServerResource to prevent PHP 8.0+ TypeError
- Add null checks after `getWhmcsService()` and `getCP()` in all Module/ModuleFunctions methods to prevent fatal null dereference
- Fix HTTP status codes throughout admin.php (404, 400, 500, 502 instead of always 200)
- Guard ConfigureService methods against `$this->cp === false`
- Replace `exit()` with `RuntimeException` in Curl.php
- Change `catch(Exception)` to `catch(Throwable)` in hooks.php for PHP 8.0+ compatibility
- Open VNC window before AJAX call to avoid popup blocker
- Memory conversion checks key name instead of display name

### Removed
- Firewall feature (non-functional — rulesets must be created in VirtFusion admin panel)

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
