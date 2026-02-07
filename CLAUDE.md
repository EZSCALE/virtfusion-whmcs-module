# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

VirtFusion Direct Provisioning Module for WHMCS — a PHP module that integrates WHMCS with the VirtFusion control panel API for automated VPS provisioning, management, and client self-service. No build system or package manager; the module is pure PHP installed by copying `modules/servers/VirtFusionDirect/` into a WHMCS installation.

## Development & Testing

There is no automated test suite, linter, or build step. Testing is manual:

- **Test connection:** WHMCS Admin → System Settings → Servers → Test Connection button
- **Dry run validation:** `VirtFusionDirect_validateServerConfig()` tests configuration without creating a server
- **Module logging:** WHMCS Admin → Utilities → Logs → Module Log captures all API calls and responses
- **Server object viewer:** Admin services tab shows full JSON response from VirtFusion API

## Release Process

Releases are automated via GitHub Actions using semantic-release on pushes to `main`. Use **conventional commits**:
- `fix:` → patch release
- `feat:` → minor release
- `BREAKING CHANGE:` in commit body → major release

## Architecture

**Namespace:** `WHMCS\Module\Server\VirtFusionDirect`

### Entry Points

| File | Purpose |
|------|---------|
| `VirtFusionDirect.php` | WHMCS module interface — non-namespaced functions (`VirtFusionDirect_CreateAccount()`, etc.) that delegate to library classes |
| `client.php` | Client-facing AJAX API — authenticated by WHMCS session + service ownership validation |
| `admin.php` | Admin-facing AJAX API — requires WHMCS admin authentication |
| `hooks.php` | WHMCS hooks — checkout validation (OS selection) and dynamic dropdown injection |

### Core Classes (in `lib/`)

| Class | Role |
|-------|------|
| `Module` | Base class with API integration, auth checks, power/firewall/network/VNC/backup/resource methods. All client/admin actions route through here. |
| `ModuleFunctions` | Extends `Module`. Service lifecycle: create, suspend, unsuspend, terminate, change package, usage updates, client area rendering. |
| `ConfigureService` | Extends `Module`. Order-time operations: package discovery, OS template fetching, server build initialization, SSH key retrieval. |
| `Database` | Static methods for `mod_virtfusion_direct` table operations and WHMCS DB queries. Auto-creates/migrates schema on first use. |
| `Curl` | HTTP client wrapper with Bearer token auth, SSL verification, 30s timeout. Methods: `get`, `post`, `put`, `patch`, `delete`. |
| `ServerResource` | Transforms VirtFusion API response into flat key-value format for Smarty templates. |
| `AdminHTML` | Static methods generating admin services tab HTML (server ID editor, JSON viewer, action buttons). |
| `Log` | Thin wrapper around WHMCS module logging. |

### Class Hierarchy

`ModuleFunctions` and `ConfigureService` both extend `Module`. Most business logic lives in `Module` (888 lines) — it handles API calls, auth, validation, and all feature-specific operations (power, firewall, network, VNC, backup, resource modification). `ModuleFunctions` orchestrates the WHMCS service lifecycle (provisioning flow, suspension, termination).

### Client-Side

- **`templates/overview.tpl`** — Smarty template for client area (server info, power, firewall, network, rebuild, VNC, backups, resource modification, billing)
- **`templates/js/module.js`** — Vanilla JS (1000+ lines) handling AJAX calls to `client.php`, DOM updates, status badges, power actions, all management UIs
- **`templates/css/module.css`** — Cross-theme styles with Bootstrap 3/4/5 dual class support (`panel card`, `panel-body card-body`)

### Data Flow: Server Creation

1. WHMCS calls `VirtFusionDirect_CreateAccount()` → `ModuleFunctions::createAccount()`
2. Checks/creates VirtFusion user via external relation ID (WHMCS client ID)
3. Reads configurable options (Package, Location, IPv4, Memory, CPU, Bandwidth, etc.)
4. Dry-run validation → actual API POST to `/servers`
5. Stores server ID in `mod_virtfusion_direct` table
6. Updates WHMCS hosting record (IP, username, password, domain)
7. Calls `ConfigureService::initServerBuild()` with selected OS + SSH key

### Configurable Option Mapping

Custom option names can be mapped in `config/ConfigOptionMapping.php` (copy from `-example.php`). Default mapping keys: `packageId`, `hypervisorId`, `ipv4`, `storage`, `memory`, `traffic`, `cpuCores`, `networkSpeedInbound`, `networkSpeedOutbound`, `networkProfile`, `storageProfile`.

## Security Patterns

- All PHP files start with `if (!defined("WHMCS")) die()` to prevent direct access
- Client endpoints validate WHMCS session AND service ownership before any operation
- API tokens stored encrypted in WHMCS server password field (decrypted via `localAPI('DecryptPassword')`)
- Input validation: type casting, regex filtering, `filter_var()` for IP addresses
- Output escaping: `htmlspecialchars()` in Smarty, `encodeURIComponent()` / `.text()` in JS
- SSL verification enabled on all API calls (`CURLOPT_SSL_VERIFYPEER` + `CURLOPT_SSL_VERIFYHOST = 2`)

## VirtFusion API Compatibility

- **API reference (OpenAPI spec):** https://docs.virtfusion.com/api/openapi.yaml
- **Base features:** VirtFusion v1.7.3+
- **VNC console:** v6.1.0+
- **Resource modification:** v6.2.0+
- Firewall endpoints use `{interface}` path parameter (primary/secondary): `/servers/{id}/firewall/{interface}`

## WHMCS Compatibility

- WHMCS 8.x+ (tested 8.0–8.10)
- PHP 8.0+ with cURL extension
- All WHMCS themes supported (Six, Twenty-One, Lagom, custom) via Bootstrap 3/4/5 dual classes
