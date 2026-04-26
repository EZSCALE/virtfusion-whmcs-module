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
| `modules/servers/VirtFusionDirect/VirtFusionDirect.php` | WHMCS module interface — non-namespaced functions (`VirtFusionDirect_CreateAccount()`, etc.) that delegate to library classes |
| `modules/servers/VirtFusionDirect/client.php` | Client-facing AJAX API — authenticated by WHMCS session + service ownership validation. POST for mutations, GET for reads. |
| `modules/servers/VirtFusionDirect/admin.php` | Admin-facing AJAX API — requires WHMCS admin authentication |
| `modules/servers/VirtFusionDirect/hooks.php` | WHMCS hooks — checkout validation (OS selection), OS gallery + SSH key UI injection, slider UI for configurable options, daily PowerDNS reconciliation |
| `modules/addons/VirtFusionDns/VirtFusionDns.php` | Optional companion addon — holds PowerDNS settings and provides a Test Connection admin page. See "Reverse DNS (PowerDNS)" below. |

### Core Classes (in `lib/`)

| Class | Role |
|-------|------|
| `Module` | Base class with API integration, auth checks, and all feature methods (power, network, VNC, backup, resource, self-service, traffic, rename, password reset). Contains `resolveServiceContext()` for DRY service lookups and `groupOsTemplates()` for shared OS category logic. |
| `ModuleFunctions` | Extends `Module`. Service lifecycle: create, suspend, unsuspend, terminate, change package, usage updates, client area rendering. |
| `ConfigureService` | Extends `Module`. Order-time operations: package discovery, OS template fetching, server build initialization, SSH key retrieval and creation. |
| `Database` | Static methods for `mod_virtfusion_direct` table operations and WHMCS DB queries. Auto-creates/migrates schema on first use. |
| `Curl` | HTTP client wrapper with Bearer token auth, SSL verification, 30s timeout. Methods: `get`, `post`, `put`, `patch`, `delete`. Single-use — each instance makes one request. |
| `Cache` | Two-tier caching: Redis (if `ext-redis` available) with atomic filesystem fallback. TTLs: OS templates 10min, traffic/backups 2min, packages 10min. |
| `ServerResource` | Transforms VirtFusion API response into flat key-value format for Smarty templates. Only reads `interfaces[0]`; for rDNS use `PowerDns\IpUtil::extractIps()` which walks all interfaces. |
| `AdminHTML` | Static methods generating admin services tab HTML (server ID editor, JSON viewer, action buttons, `rdnsSection()` widget). |
| `Log` | Thin wrapper around WHMCS module logging. |
| `PowerDns\Client` | PowerDNS HTTP API wrapper (`X-API-Key` auth): `ping`, `listZones`, `getZone`, `patchRRset`, `notifyZone`. PATCH success triggers an automatic NOTIFY so slaves pick up the SOA bump immediately. |
| `PowerDns\Config` | Loads settings from `tbladdonmodules` (module="virtfusiondns") and decrypts `apiKey` via WHMCS `decrypt()`. `isEnabled()` gates every PowerDNS call site. |
| `PowerDns\IpUtil` | Pure helpers: `ptrNameForIp` (v4/v6 nibble reversal), `expandIpv6`, `extractIps` (all interfaces), `findZoneAndPtrName` (standard + RFC 2317 classless), `parseClasslessZone`. |
| `PowerDns\Resolver` | Forward-DNS verification via `dns_get_record()` with up-to-5-hop CNAME following. Cached per (hostname, ip) pair. |
| `PowerDns\PtrManager` | Orchestrator: `syncServer`, `deleteForServer`, `listPtrs`, `setPtr`, `reconcile`, `reconcileAll`. Per-request zone cache. 10s per-IP write rate limit. Enforces FCrDNS before writes. |
| `StockControl` | Orchestrator for dynamic inventory. `recalculateForProduct()` and `recalculateAll()` compute per-product qty from live `/packages/{id}` + `/compute/hypervisors/groups/{id}/resources` data and write to `tblproducts.qty`. Fail-safe: null return = qty untouched. |

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
7. If the PowerDNS addon is enabled, calls `PowerDns\PtrManager::syncServer()` to write PTRs (non-blocking; failures log but never fail provisioning)
8. Calls `ConfigureService::initServerBuild()` with selected OS + SSH key

Custom fields (`Initial Operating System`, `Initial SSH Key`) are auto-created by `Database::ensureCustomFields()` on module load for all products using this module. No manual SQL setup required.

### Reverse DNS (PowerDNS)

Opt-in integration via the companion `VirtFusionDns` addon module. Loose-coupled: the server module never requires addon code at runtime; it queries the addon's `tbladdonmodules` row and short-circuits when `enabled=0` or the addon isn't activated. Activate via WHMCS Admin → Addon Modules → VirtFusion DNS.

**Settings** (`tbladdonmodules`, module="virtfusiondns"): `enabled` (yesno), `endpoint` (e.g. `https://ns1.example.com:8081`), `apiKey` (encrypted by WHMCS), `serverId` (usually `localhost`), `defaultTtl` (3600), `cacheTtl` (60).

**Lifecycle hooks:**
- `createAccount` → sync PTRs to server hostname (forward DNS must match before each write)
- `renameServer` → update only PTRs whose current content equals the old hostname (preserves client-custom PTRs)
- `terminateAccount` → delete every PTR before `Database::deleteSystemService()`
- `VirtFusionDirect_TestConnection` → merged VirtFusion + PowerDNS health check
- `DailyCronJob` → `PtrManager::reconcileAll()` — additive-only (never overwrites)

**Client-facing actions** (`client.php`): `rdnsList`, `rdnsUpdate`. Admin (`admin.php`): `rdnsStatus`, `rdnsReconcile` (accepts `force=1` for explicit reset).

**Client UI:** Reverse DNS panel in `templates/overview.tpl` (rendered by `vfLoadRdns()` / `vfRenderRdnsPanel()` / `vfUpdateRdns()` in `module.js`). Admin services tab gets a status widget via `AdminHTML::rdnsSection()`.

**FCrDNS rule:** Every PTR write (auto or client-initiated) requires the hostname's forward DNS (A/AAAA) to already resolve to the target IP. On mismatch, auto-sync logs and skips; client edits return a 400 with guidance.

**Zone handling:** Zones are operator-managed — the module never creates zones. Zone discovery uses `GET /zones` (cached for `cacheTtl`) + longest-suffix match. RFC 2317 classless delegations (`X/Y.octet.octet.octet.in-addr.arpa.`) are supported: both CIDR-prefix (`0/26`) and block-size (`64/64`) conventions are parsed, and PTRs are written with the classless sub-zone label in the record name.

**SOA / NOTIFY:** PowerDNS auto-bumps SOA serials when `soa_edit_api=INCREASE` is set on the zone. After every successful PATCH the module issues an explicit `PUT /zones/{id}/notify` so slaves refresh immediately rather than waiting for the next scheduled poll.

**Safety properties:**
- PowerDNS failures never block VirtFusion operations (try/catch at every call site)
- Cron is additive-only — never auto-overwrites a PTR
- Admin Reconcile button supports `force=1` for explicit reset to hostname
- Client edits are IP-ownership-checked against a *fresh* VirtFusion fetch (not cached `server_object`), defending against reassigned-IP stale-ownership
- Per-IP write rate limit (10s, via `Cache`) prevents save-button abuse

### Configurable Option Mapping

Custom option names can be mapped in `config/ConfigOptionMapping.php` (copy from `-example.php`). Default mapping keys: `packageId`, `hypervisorId`, `ipv4`, `storage`, `memory`, `traffic`, `cpuCores`, `networkSpeedInbound`, `networkSpeedOutbound`, `networkProfile`, `storageProfile`.

### Inventory / Stock Control

Opt-in per product via WHMCS's native stock-control toggle (`tblproducts.stockcontrol=1`). When enabled, the module overwrites `tblproducts.qty` with the real number of VPSes that can still be provisioned — WHMCS then handles the "Out of Stock" badge, Add-to-Cart gating, and checkout refusal natively. No templates or JS required.

**Data sources (authoritative):**
- `GET /packages/{id}` — per-VPS resource footprint (`memory`, `cpuCores`, `primaryStorage`, `primaryStorageProfile`, `enabled`)
- `GET /compute/hypervisors/groups/{id}/resources` — live free/allocated per hypervisor with per-metric quotas, storage pools (filtered by `pool.storageType` against the package's `primaryStorageProfile` *type code* — see Safety properties), and a group-level IPv4 pool

**Algorithm:** for every group the product can be placed in (default `configoption1` plus every numeric value of the `Location` configurable option), sum `min(memory, cpu, storage)` across eligible hypervisors (enabled AND commissioned AND !prohibit) and cap by the group-level IPv4 pool (`max` across hypervisors, not summed — IPv4 is a single group-wide pool). Sum across groups → qty.

**Triggers:**
- `AfterModuleCreate` — post-provision refresh; bursts rate-limited to one recalc per 30 s via `stockrefresh:event` cache key.
- `AfterModuleTerminate` — post-termination refresh; shares the same 30 s rate-limit key.
- `AfterCronJob` — every-2-hour safety net (captures out-of-band VirtFusion panel changes). Tunable via `STOCK_CRON_INTERVAL_SECONDS` constant in `hooks.php`.
- `ClientAreaPageCart` — opportunistic per-product refresh on cart/order pages with a 60 s rate-limit key (`stockrefresh:{pid}`). The `grpres:{id}` cache (120 s TTL) naturally coalesces bursts.
- `admin.php?action=stockRecalculate` — admin-triggered full recalc (POST + same-origin required); returns JSON `{productId: qty}` map.

**Order auto-accept:** `AfterModuleCreate` additionally calls WHMCS `AcceptOrder` with `autosetup=false` when the service's parent order is still Pending. Closes the loop for installs that rely on pending-order workflows for non-VF products but want VF provisions to auto-advance.

**Caching:** `pkg:{id}` 600 s (package definitions rarely change), `grpres:{id}` 120 s (resources change under load). Confirmed 404s cached 60 s so re-creating a deleted package/group takes effect quickly.

**Safety properties:**
- Transient API failures (null from `fetchPackage` / `fetchGroupResources`) leave `qty` UNTOUCHED — never silently takes the catalogue offline.
- Confirmed-missing conditions (HTTP 404 on package, `package.enabled=false`) return qty=0 — the product genuinely cannot be provisioned.
- IPv4 cap is max-within-group (not summed across hypervisors) to avoid double-counting the shared pool.
- Storage matching uses the package's `primaryStorageProfile` as a **storage type code** (it mirrors VirtFusion's `server_packages.storage_type` column — a *filter*, not a pool id). The hypervisor must expose at least one `otherStorage[]` pool whose `storageType` equals that code; if multiple match (e.g. several mountpoint pools on the same hypervisor) the one that fits the most VMs wins. A disabled pool is skipped, not fatal — an enabled peer of the same type still contributes. Hypervisors with no pool of the matching type contribute 0. Falls back to `localStorage` only when the package has no profile set (`primaryStorageProfile <= 0`).
- Stock control is gated by `tblproducts.stockcontrol=1` per product — the module never touches qty on products that opt out.

**Per-product setting:** `stockSafetyBufferPct` (configoption7, default 10). Reserves X% of each resource's `max` before computing fits; ignored for unlimited resources (`max=0`) and for IPv4 (no per-hypervisor `max` in the response). Admins can override per product in the module settings; blank falls back to 10%.

**API scope required:** the VirtFusion API token must have read access to both `/packages` and `/compute/hypervisors/groups`. The Test Connection button probes the compute endpoint and shows a clear error if scope is missing.

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

## PowerDNS API Compatibility

- **API reference:** https://doc.powerdns.com/authoritative/http-api/
- **Tested against:** PowerDNS Authoritative 4.8+
- **Auth:** `X-API-Key` header (not Bearer)
- **Required endpoints:** `GET /servers/{id}`, `GET /servers/{id}/zones`, `GET /servers/{id}/zones/{zone}`, `PATCH /servers/{id}/zones/{zone}`, `PUT /servers/{id}/zones/{zone}/notify`
- **Zone ID URL encoding:** `/` in zone names (RFC 2317) must be encoded as `=2F` not `%2F` — handled by `Client::zoneIdEncode()`
- **`api-allow-from`:** must include the WHMCS host's IP (PowerDNS's own ACL)
- **Recommended zone config:** `soa_edit_api: INCREASE` for automatic serial bumping on API-driven changes

## Product Config Options

| Option | Name | Description | Default |
|--------|------|-------------|---------|
| configoption1 | Hypervisor Group ID | VirtFusion hypervisor group for server placement | 1 |
| configoption2 | Package ID | VirtFusion package defining server resources | 1 |
| configoption3 | Default IPv4 | Number of IPv4 addresses to assign (0-10) | 1 |
| configoption4 | Self-Service Mode | 0=Disabled, 1=Hourly, 2=Resource Packs, 3=Both | 0 |
| configoption5 | Auto Top-Off Threshold | Credit balance below which auto top-off triggers | 0 |
| configoption6 | Auto Top-Off Amount | Credit amount to add on auto top-off | 100 |
| configoption7 | Stock Safety Buffer (%) | Headroom reserved per resource during stock calculation (0-100). Only effective with WHMCS stock control enabled. Blank falls back to the default. | 10 |

## WHMCS Compatibility

- WHMCS 8.x+ (tested 8.0–8.10)
- PHP 8.0+ with cURL extension
- Redis extension optional (improves caching performance, falls back to filesystem)
- All WHMCS themes supported (Six, Twenty-One, Lagom, custom) via Bootstrap 3/4/5 dual classes
