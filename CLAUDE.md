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

## Development Rules

- **Error handling:** Always use try...catch blocks around API calls, database operations, and any code that may throw exceptions. Never let exceptions bubble up unhandled to the user. Log caught exceptions via `Log::insert()`.
- **Ownership validation:** Every client-facing action MUST verify service ownership via `validateUserOwnsService()` before performing any operation. Server IDs must be cross-referenced against the authenticated client to prevent cross-customer data access.
- **Security:** All input must be validated server-side. Never trust client-side validation alone. Cast IDs to `(int)`, validate strings with regex, escape output with `htmlspecialchars()`.
- **Control flow:** Every `$vf->output()` call in switch cases must be followed by `break`. Do not rely on `exit()` inside `output()` for flow control.
- **HTTP methods:** Read-only operations use GET. State-mutating operations (power, rebuild, rename, password reset, credit, VNC toggle) use POST with data in the request body.
- **Caching:** Use the `Cache` class for slow-changing API responses. Never cache real-time data (server status, VNC sessions, login tokens) or mutation responses.

## Release Process

Releases are triggered by pushing a git tag:
```bash
git tag v1.1.0
git push origin v1.1.0
```

The `publish-release.yml` workflow creates a GitHub/Gitea release with auto-generated notes from the commit log. Use **conventional commits** for clear changelogs:
- `fix:` → patch-level change
- `feat:` → feature addition
- `refactor:` → code improvement without behavior change

## Architecture

**Namespace:** `WHMCS\Module\Server\VirtFusionDirect`

### Entry Points

| File | Purpose |
|------|---------|
| `VirtFusionDirect.php` | WHMCS module interface — non-namespaced functions (`VirtFusionDirect_CreateAccount()`, etc.) that delegate to library classes |
| `client.php` | Client-facing AJAX API — authenticated by WHMCS session + service ownership validation. POST for mutations, GET for reads. |
| `admin.php` | Admin-facing AJAX API — requires WHMCS admin authentication |
| `hooks.php` | WHMCS hooks — checkout validation (OS selection), OS gallery + SSH key UI injection, slider UI for configurable options |

### Core Classes (in `lib/`)

| Class | Role |
|-------|------|
| `Module` | Base class with API integration, auth checks, and all feature methods (power, network, VNC, backup, resource, self-service, traffic, rename, password reset). Contains `resolveServiceContext()` for DRY service lookups and `groupOsTemplates()` for shared OS category logic. |
| `ModuleFunctions` | Extends `Module`. Service lifecycle: create, suspend, unsuspend, terminate, change package, usage updates, client area rendering. |
| `ConfigureService` | Extends `Module`. Order-time operations: package discovery, OS template fetching, server build initialization, SSH key retrieval and creation. |
| `Database` | Static methods for `mod_virtfusion_direct` table operations and WHMCS DB queries. Auto-creates/migrates schema on first use. |
| `Curl` | HTTP client wrapper with Bearer token auth, SSL verification, 30s timeout. Methods: `get`, `post`, `put`, `patch`, `delete`. Single-use — each instance makes one request. |
| `Cache` | Two-tier caching: Redis (if `ext-redis` available) with atomic filesystem fallback. TTLs: OS templates 10min, traffic/backups 2min, packages 10min. |
| `ServerResource` | Transforms VirtFusion API response into flat key-value format for Smarty templates. |
| `AdminHTML` | Static methods generating admin services tab HTML (server ID editor, JSON viewer, action buttons). |
| `Log` | Thin wrapper around WHMCS module logging. |

### Class Hierarchy

`ModuleFunctions` and `ConfigureService` both extend `Module`. Most business logic lives in `Module` — it handles API calls, auth, validation, and all feature-specific operations. The `resolveServiceContext()` method provides a standardized way to look up service → WHMCS service → control panel → curl client in a single call, eliminating boilerplate across all API methods.

### Client-Side

- **`templates/overview.tpl`** — Smarty template for client area (server info, power, network, rebuild with OS gallery, resources with traffic chart, VNC toggle, self-service billing, billing overview, backups timeline, server rename, password reset)
- **`templates/js/module.js`** — Vanilla JS + jQuery handling AJAX calls, DOM updates, status badges, power actions, all management UIs. Key helpers: `vfUrl()` (URL builder), `vfShowAlert()` (alert display), `vfRenderOsGallery()` (accordion gallery), `vfDrawTrafficChart()` (canvas chart)
- **`templates/js/keygen.js`** — Client-side SSH Ed25519 key generator using Web Crypto API (loaded on checkout page)
- **`templates/css/module.css`** — Cross-theme styles with Bootstrap 3/4/5 dual class support (`panel card`, `panel-body card-body`)

### Removed Features

- **Firewall** — Removed (non-functional; rulesets must be created in VirtFusion admin panel)
- **IP add/remove buttons** — Removed; IPs are managed by VirtFusion during provisioning
- **Upgrade/Downgrade link** — Removed from resources panel

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

- All PHP files start with `if (!defined("WHMCS")) die()` to prevent direct access (except entry points using `init.php`)
- Client endpoints validate WHMCS session AND service ownership before any operation
- API tokens stored encrypted in WHMCS server password field (decrypted via `localAPI('DecryptPassword')`)
- Input validation: type casting (`(int)`), regex filtering, `filter_var()` for IP addresses
- Output escaping: `htmlspecialchars()` in PHP, `$('<span>').text()` in jQuery, `{escape:'htmlall'}` in Smarty
- SSL verification enabled on all API calls (`CURLOPT_SSL_VERIFYPEER` + `CURLOPT_SSL_VERIFYHOST = 2`)
- Server rename validated both client-side and server-side with RFC 1123 regex

## VirtFusion API Compatibility

- **API reference (OpenAPI spec):** https://docs.virtfusion.com/api/openapi.yaml
- **Base features:** VirtFusion v1.7.3+
- **VNC console:** v6.1.0+
- **Resource modification:** v6.2.0+
- **Self-service billing:** Requires self-service feature enabled in VirtFusion
- **OS icon path:** `{baseUrl}/img/logo/{icon_filename}` (public, no auth required)

## Product Config Options

| Option | Name | Description | Default |
|--------|------|-------------|---------|
| configoption1 | Hypervisor Group ID | VirtFusion hypervisor group for server placement | 1 |
| configoption2 | Package ID | VirtFusion package defining server resources | 1 |
| configoption3 | Default IPv4 | Number of IPv4 addresses to assign (0-10) | 1 |
| configoption4 | Self-Service Mode | 0=Disabled, 1=Hourly, 2=Resource Packs, 3=Both | 0 |
| configoption5 | Auto Top-Off Threshold | Credit balance below which auto top-off triggers | 0 |
| configoption6 | Auto Top-Off Amount | Credit amount to add on auto top-off | 100 |

## WHMCS Compatibility

- WHMCS 8.x+ (tested 8.0–8.10)
- PHP 8.0+ with cURL extension
- Redis extension optional (improves caching performance, falls back to filesystem)
- All WHMCS themes supported (Six, Twenty-One, Lagom, custom) via Bootstrap 3/4/5 dual classes
