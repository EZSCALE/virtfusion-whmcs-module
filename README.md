# VirtFusion Direct Provisioning Module for WHMCS

[![Automated Release](https://github.com/EZSCALE/virtfusion-whmcs-module/actions/workflows/publish-release.yml/badge.svg)](https://github.com/EZSCALE/virtfusion-whmcs-module/actions)
![GitHub](https://img.shields.io/github/license/EZSCALE/virtfusion-whmcs-module)
![GitHub issues](https://img.shields.io/github/issues/EZSCALE/virtfusion-whmcs-module)
![GitHub pull requests](https://img.shields.io/github/issues-pr/EZSCALE/virtfusion-whmcs-module)

A comprehensive WHMCS provisioning module for [VirtFusion](https://virtfusion.com) that enables automated VPS server provisioning, management, and client self-service directly from WHMCS.

## Table of Contents

- [Requirements](#requirements)
- [Features](#features)
- [Installation](#installation)
- [Upgrading](#upgrading)
- [Configuration](#configuration)
  - [Server Setup](#server-setup)
  - [Product Setup](#product-setup)
  - [Custom Fields](#custom-fields)
  - [Module Configuration Options](#module-configuration-options)
  - [Configurable Options (Dynamic Pricing)](#configurable-options-dynamic-pricing)
  - [Custom Option Name Mapping](#custom-option-name-mapping)
  - [Stock Control (Dynamic Inventory)](#stock-control-dynamic-inventory)
  - [Reverse DNS Addon (PowerDNS)](#reverse-dns-addon-powerdns)
- [Client Area Features](#client-area-features)
- [Admin Area Features](#admin-area-features)
- [Theme Compatibility](#theme-compatibility)
- [API Endpoints Used](#api-endpoints-used)
- [Usage Update (Cron)](#usage-update-cron)
- [Troubleshooting](#troubleshooting)
- [Known Issues](#known-issues)
- [Security](#security)
- [Contributing](#contributing)
- [License](#license)

## Requirements

| Requirement | Minimum Version | Notes |
|---|---|---|
| **VirtFusion** | v1.7.3+ | v6.1.0+ required for VNC console |
| **WHMCS** | 8.x+ | Tested with 8.0 through 8.10 |
| **PHP** | 8.0+ | With cURL extension enabled |
| **SSL** | Valid certificate | Required on VirtFusion panel |

You also need a VirtFusion API token with the following permissions:
- Server management (create, read, update, delete, power, build)
- User management (create, read, reset password, authentication tokens)
- Package and template read access
- Network management (if using IP management features)

## Features

### Server Provisioning
- Automatic server creation with VirtFusion user account linking
- Server suspension, unsuspension, and termination
- Package/plan upgrades and downgrades
- Configurable options mapping for dynamic resource allocation (CPU, RAM, disk, bandwidth, network speed)
- **Dry run validation** - Test server creation parameters before provisioning
- Automatic memory unit conversion (GB to MB for values < 1024)

### Client Area - Server Management
- **Server Overview** - Real-time server info (hostname, IPs, resources) with status badge
- **Power Management** - Start, restart, graceful shutdown, and force power off
- **Control Panel SSO** - One-click login to VirtFusion panel
- **Server Rebuild** - Reinstall with any available OS template
- **Password Reset** - Reset VirtFusion panel login credentials
- **Network Management** - View IPv4 addresses and IPv6 subnets with copy-to-clipboard
- **Resources Panel** - Current memory, CPU, storage, traffic allocation with usage bars
- **VNC Console** - Browser-based console access (panel auto-hides when VNC is disabled on the server)
- **Self-Service Billing** - Credit balance display, usage breakdown, and credit top-up (when enabled)
- **Bandwidth Usage** - Traffic usage display with allocation limits
- **Billing Overview** - Product, billing cycle, dates, and payment information

### Admin Area
- **Test Connection** - Verify API connectivity from WHMCS
- **Server Data Display** - Live server information from VirtFusion
- **Admin Impersonation** - Log into VirtFusion panel as server owner
- **Server ID Management** - Editable Server ID for manual adjustments
- **Server Object Viewer** - Full JSON response from VirtFusion API
- **Validate Server Config** - Dry run server creation to check configuration
- **Update Server Object** - Refresh cached server data from VirtFusion

### Ordering Process
- OS template tile gallery with accordion categories, search, and brand icons
- SSH key selection dropdown for users with saved keys, with option to paste a new public key
- **SSH Ed25519 key generator** — Client-side keypair generation using Web Crypto API
- Checkout validation ensuring OS selection before order placement
- **Resource sliders** - Configurable option dropdowns are replaced with interactive range sliders
- Compatible with all WHMCS order form templates
- **Order auto-accept after provision** — when a paid order's VirtFusion service provisions successfully, the module calls WHMCS `AcceptOrder` (with `autosetup=false` so there's no double-provision) to flip the order from Pending → Active automatically. Idempotent; already-accepted orders are untouched.

### Stock Control (Dynamic Inventory)
- **Out-of-stock badges driven by real hypervisor capacity** — opt-in per product via WHMCS's native Stock Control toggle. When enabled, the module keeps `tblproducts.qty` synced to the number of VPSes the panel can still actually provision, and WHMCS renders the "Out of Stock" badge, disables Add-to-Cart, and refuses checkout natively. No templates or JavaScript required.
- **Live-capacity math** — combines `/packages/{id}` (per-VPS resource footprint) with `/compute/hypervisors/groups/{id}/resources` (live per-hypervisor free/allocated) to compute qty across every group the product can be placed in. Storage matching is by **type code** (`pool.storageType`), so a package targeting e.g. mountpoint storage qualifies on every hypervisor that exposes a mountpoint pool — and picks the largest-fit pool when several share the same type. Group-level IPv4 pool accounted for without double-counting.
- **Event-driven refresh** — qty recalculates after every successful provision (`AfterModuleCreate`), termination (`AfterModuleTerminate`), and on cart/order page views for individual products. A 2-hour safety-net cron catches capacity changes made directly in the VirtFusion panel.
- **Per-product safety buffer** — `stockSafetyBufferPct` config option (default 10%) reserves headroom so the storefront stops selling before a hypervisor is literally at 100%.
- **Fail-safe under API outages** — transient VirtFusion API failures leave `qty` UNCHANGED instead of zeroing it, so a brief network blip doesn't take the catalogue offline.
- **Admin recalc on demand** — POST `admin.php?action=stockRecalculate` forces a full re-sweep.

### Usage Tracking
- **Automated bandwidth sync** - WHMCS daily cron pulls traffic usage from VirtFusion
- **Disk usage sync** - Storage usage updated automatically
- Visible in WHMCS client area and admin product details

### Backup Management
- Assign backup plans to servers via the VirtFusion API
- Remove backup plans from servers

### Resource Modification
- In-place modification of server resources (memory, CPU cores, traffic)
- No server rebuild required for resource changes
- **Package change** now also applies individual resource modifications from configurable options

### Self-Service Billing
- Credit balance display and top-up from client area
- Usage breakdown reporting
- Auto top-off via WHMCS cron when credit falls below threshold
- Self-service mode configurable per product (Hourly, Resource Packs, or Both)

### Reverse DNS (Optional PowerDNS Addon)
- **Automatic PTR sync** on server create, rename, and terminate
- **Client-editable rDNS** panel in the service overview — one input per assigned IP
- **Forward-confirmed reverse DNS (FCrDNS)** — every PTR write requires the hostname's A/AAAA to already resolve to the IP; mismatches are rejected with a clear error
- **IPv4 + IPv6** support out of the box (IPv6 nibble-reversal, `.ip6.arpa` zones)
- **RFC 2317 classless delegation** — supports both CIDR-prefix (`0/26`) and block-size (`64/64`) zone naming conventions
- **Admin reconciliation** — a "Reconcile" button on the services tab and an additive-only daily cron that creates any missing PTRs
- **Client-custom PTRs preserved across renames** — only PTRs whose content matches the previous hostname get rewritten
- **Auto NOTIFY + SOA bump** so slaves pick up changes immediately (when `soa_edit_api=INCREASE` is set on the zone)
- **Opt-in** via a companion WHMCS addon module — no impact on existing provisioning if not activated

## Installation

```bash
WHMCS=/path/to/whmcs
VERSION=${VERSION:-$(curl -fsSL https://api.github.com/repos/EZSCALE/virtfusion-whmcs-module/releases/latest \
  | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p')}
curl -fsSL "https://github.com/EZSCALE/virtfusion-whmcs-module/archive/refs/tags/${VERSION}.tar.gz" -o /tmp/vf.tar.gz \
  && mkdir -p /tmp/vf && tar -xzf /tmp/vf.tar.gz -C /tmp/vf --strip-components=1 \
  && rsync -ahP --delete /tmp/vf/modules/servers/VirtFusionDirect/ "$WHMCS/modules/servers/VirtFusionDirect/" \
  && rm -rf /tmp/vf /tmp/vf.tar.gz
```

Set `WHMCS` once at the top — it's reused in every path below. The snippet defaults to the latest published release (queried live from the GitHub API); to pin a specific version, prepend `VERSION=v1.4.1` (or any tag from [Releases](https://github.com/EZSCALE/virtfusion-whmcs-module/releases)) before the command. The database table, schema migrations, and custom fields are all created automatically on first load.

Then configure in WHMCS Admin:

1. **Add Server** — Configuration > System Settings > Servers > Add New Server. Set hostname to your VirtFusion panel (e.g. `cp.example.com`), type to "VirtFusion Direct Provisioning", and paste your API token in the Password field. Click **Test Connection** to verify.
2. **Create Product** — Configuration > System Settings > Products/Services. On the Module Settings tab, select "VirtFusion Direct Provisioning", choose your server, and set the Hypervisor Group ID, Package ID, and Default IPv4 count.
3. *(Optional)* **Install the Reverse DNS Addon** — also sync the `modules/addons/VirtFusionDns/` directory if you want PowerDNS-backed rDNS management. See [Reverse DNS Addon (PowerDNS)](#reverse-dns-addon-powerdns) below for activation and configuration.

That's it. Hooks activate automatically and custom fields are created on module load.

## Upgrading

```bash
WHMCS=/path/to/whmcs
VERSION=${VERSION:-$(curl -fsSL https://api.github.com/repos/EZSCALE/virtfusion-whmcs-module/releases/latest \
  | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p')}
curl -fsSL "https://github.com/EZSCALE/virtfusion-whmcs-module/archive/refs/tags/${VERSION}.tar.gz" -o /tmp/vf.tar.gz \
  && mkdir -p /tmp/vf && tar -xzf /tmp/vf.tar.gz -C /tmp/vf --strip-components=1 \
  && rsync -ahP --delete /tmp/vf/modules/servers/VirtFusionDirect/ "$WHMCS/modules/servers/VirtFusionDirect/" \
  && rsync -ahP --delete /tmp/vf/modules/addons/VirtFusionDns/ "$WHMCS/modules/addons/VirtFusionDns/" \
  && rm -rf /tmp/vf /tmp/vf.tar.gz
```

The second `rsync` line is only needed if you use the Reverse DNS addon; skip it otherwise. Addon settings live in `tbladdonmodules` and survive file updates.

The default behavior pulls the latest release. To pin a specific version (e.g. for a controlled rollout, or to roll back to a known-good version), prepend `VERSION=v1.4.1` (or any tag from [Releases](https://github.com/EZSCALE/virtfusion-whmcs-module/releases)) before the command.

> **Note:** If you have a custom `config/ConfigOptionMapping.php`, back it up first — `--delete` will remove it. Restore it after upgrading.

If you use theme-overridden templates, review them for any new template variables. Clear the WHMCS template cache after upgrading: **Configuration > System Settings > General Settings > clear template cache**.

## Configuration

### Server Setup

In WHMCS Admin under **Configuration > System Settings > Servers**:

| Field | Value |
|---|---|
| Hostname | Your VirtFusion panel domain (e.g., `cp.example.com`) |
| Password | Your VirtFusion API token |
| Type | VirtFusion Direct Provisioning |

**Important**: Do not include `https://` or `/api/v1` in the hostname. The module constructs the full URL automatically.

### Product Setup

Each WHMCS product using this module needs:
1. Module set to "VirtFusion Direct Provisioning"
2. A linked server (or the module will use any available VirtFusion server)
3. The three configuration options set (Hypervisor Group ID, Package ID, Default IPv4)
4. Custom fields created (see below)

### Custom Fields

The module requires two custom fields per product: **Initial Operating System** and **Initial SSH Key**. These are **automatically created** when the module loads — no manual setup required.

The fields are hidden text boxes that are dynamically replaced by dropdown selects via JavaScript hooks on the order form. They are created for every product with the module type set to "VirtFusion Direct Provisioning".

### Module Configuration Options

Each product has these module-specific settings:

| Option | Name | Description | Default |
|---|---|---|---|
| Config Option 1 | Hypervisor Group ID | VirtFusion hypervisor group for server placement | 1 |
| Config Option 2 | Package ID | VirtFusion package defining server resources | 1 |
| Config Option 3 | Default IPv4 | Number of IPv4 addresses to assign (0-10) | 1 |
| Config Option 4 | Self-Service Mode | Enable VirtFusion self-service billing (0=Disabled, 1=Hourly, 2=Resource Packs, 3=Both) | 0 |
| Config Option 5 | Auto Top-Off Threshold | Credit balance below which auto top-off triggers during cron (0=disabled) | 0 |
| Config Option 6 | Auto Top-Off Amount | Credit amount to add when auto top-off triggers | 100 |
| Config Option 7 | Stock Safety Buffer (%) | Headroom reserved per resource during stock calculation (0-100). Only effective with WHMCS Stock Control enabled on the product; blank falls back to the default. | 10 |

You can find your Hypervisor Group IDs and Package IDs in the VirtFusion admin panel.

### Configurable Options (Dynamic Pricing)

To allow customers to select different resource levels with pricing tiers, create WHMCS Configurable Options groups with these option names:

| VirtFusion Parameter | Default Option Name | Description | Unit |
|---|---|---|---|
| `packageId` | Package | VirtFusion package ID | ID |
| `hypervisorId` | Location | Hypervisor group for placement | ID |
| `ipv4` | IPv4 | Number of IPv4 addresses | Count |
| `storage` | Storage | Disk space | GB |
| `memory` | Memory | RAM (values < 1024 auto-converted from GB) | MB |
| `traffic` | Bandwidth | Monthly traffic allowance | GB |
| `cpuCores` | CPU Cores | Number of CPU cores | Count |
| `networkSpeedInbound` | Inbound Network Speed | Inbound speed | Mbps |
| `networkSpeedOutbound` | Outbound Network Speed | Outbound speed | Mbps |
| `networkProfile` | Network Type | VirtFusion network profile | ID |
| `storageProfile` | Storage Type | VirtFusion storage profile | ID |

### Custom Option Name Mapping

If your configurable option names differ from the defaults above:

1. Copy `config/ConfigOptionMapping-example.php` to `config/ConfigOptionMapping.php`
2. Edit the mapping array:

```php
return [
    'memory' => 'RAM',           // Your option name for memory
    'cpuCores' => 'vCPU Count',  // Your option name for CPU
    'traffic' => 'Data Transfer', // Your option name for bandwidth
    // ... add only the options that differ from defaults
];
```

### Stock Control (Dynamic Inventory)

Optional but recommended once the catalogue is backed by real hypervisor capacity. When enabled on a product, the module keeps `tblproducts.qty` synced with the number of VPSes the panel can still actually provision — then WHMCS renders "Out of Stock" badges, disables Add-to-Cart, and refuses checkout entirely on its own.

**Prerequisites:**
- The VirtFusion API token on the WHMCS server must have read access to both `/packages` and `/compute/hypervisors/groups`. The **Test Connection** button (Admin → System Settings → Servers) now probes the compute endpoint explicitly — if the token is missing that scope you'll see a clear error at config time instead of nightly silence.
- No addon to activate. Stock control is enabled per product via WHMCS's native toggle.

**Enabling it on a product:**

1. WHMCS Admin → **System Settings → Products/Services → Products/Services** → edit the product.
2. Under the **Details** tab, tick **Stock Control** and save. (Leave *Quantity* at 0 — the module will populate it on the next recalc.)
3. Optionally tune **Config Option 7 — Stock Safety Buffer (%)** in the **Module Settings** tab. Default 10% means the module reserves 10% of each resource's max before counting fits, so you stop selling before a hypervisor is at 100%. Set to 0 for no buffer, higher for more headroom.
4. Either wait for the next recalc event (within 2 hours) or force one immediately: POST to `modules/servers/VirtFusionDirect/admin.php?action=stockRecalculate` from an authenticated admin session.

**How qty is computed:**

For every stock-controlled VirtFusion product:

1. Resolve the set of hypervisor groups the product can be placed in — the default group (Config Option 1) plus every numeric value of the `Location` configurable option if one is attached.
2. Fetch the product's package via `GET /packages/{id}` for the per-VPS resource footprint (`memory`, `cpuCores`, `primaryStorage`, `primaryStorageProfile`).
3. For each eligible group, fetch live resources via `GET /compute/hypervisors/groups/{id}/resources`.
4. For each hypervisor in the group that passes eligibility (`enabled` AND `commissioned` AND `!prohibit`), compute `min(memory, cpu, storage)` fits — with the per-product buffer applied — against the matched storage pool. `package.primaryStorageProfile` is a **storage type code** (mirrors VirtFusion's `server_packages.storage_type` column — a *filter*, not a pool id), matched against each `otherStorage[].storageType`. If multiple pools on the same hypervisor share that type (e.g. several mountpoint pools), the one with the largest fit wins; disabled peers are skipped, not fatal. Falls back to `localStorage` only when the package has no profile set.
5. Sum across hypervisors in each group, cap by the group-level IPv4 pool (`max()` within a group to avoid double-counting the shared pool), then sum across groups → `qty`.

**Refresh triggers:**

| Event | Trigger | Rate limit |
|---|---|---|
| New provision | `AfterModuleCreate` hook | 30 s shared with termination |
| VPS termination | `AfterModuleTerminate` hook | 30 s shared with create |
| Cart / order page view | `ClientAreaPageCart` hook | 60 s per product |
| Out-of-band panel change safety net | `AfterCronJob` hook | 2 hours (tunable via `STOCK_CRON_INTERVAL_SECONDS` in `hooks.php`) |
| Admin manual recalc | `admin.php?action=stockRecalculate` (POST + same-origin) | On demand |

**Safety properties:**
- **Transient API failures leave `qty` UNCHANGED.** `Module::fetchPackage()` and `Module::fetchGroupResources()` return a tri-state `array | false | null`: `false` means "VirtFusion confirmed this doesn't exist → OOS is correct", `null` means "we can't tell right now → don't touch existing qty". Without this distinction the module would either zero out inventory during API blips or show inventory for deleted packages.
- **Confirmed-missing → qty=0.** HTTP 404 on the package or `package.enabled=false` forces qty=0, because the product genuinely cannot be provisioned.
- **Storage type mismatch → 0 for that hypervisor.** If the package targets storage type code `4` (mountpoint) but the hypervisor only exposes pools of type `0` (local default), that hypervisor contributes zero capacity — not a guess at "maybe placement will work out." This is a filter on `pool.storageType`, not on `pool.id`; identical type codes across different hypervisors all qualify, which is what makes multi-hypervisor mountpoint/datastore placement work.
- **Stock Control gate is absolute.** Products without `tblproducts.stockcontrol=1` are never touched, even by the cron safety net.
- **`\Throwable` catches** on every stock-path entry point (not just `\Exception`) so a `TypeError` from a malformed API response can't escape the tri-state contract.

**Caching:**
- `pkg:{packageId}` — 10 min TTL (package definitions rarely change)
- `grpres:{groupId}` — 120 s TTL (resources change minute-to-minute under load; shared across products that target the same group)
- Confirmed 404 responses cached 60 s so re-creating a deleted package/group takes effect quickly.

**Order auto-accept:** the `AfterModuleCreate` hook additionally calls WHMCS `AcceptOrder` with `autosetup=false` when the service's parent order is still in Pending status. This closes the loop for installs that rely on a pending-order workflow for non-VF products but want VirtFusion provisions to advance to Active automatically. Idempotent — already-accepted orders are skipped.

### Reverse DNS Addon (PowerDNS)

Optional. Activate the `VirtFusionDns` addon module to let the provisioning module manage PTR records in a PowerDNS instance automatically (and expose an rDNS editor to clients).

**Prerequisites:**
- PowerDNS Authoritative 4.x with the HTTP API enabled (`webserver=yes`, `api=yes`, and an `api-key=...` set)
- `api-allow-from=` must include the IP of your WHMCS host
- **All reverse zones you intend to use must already exist in PowerDNS.** The addon never creates zones; it only PATCHes PTR RRsets into zones that are already delegated to your nameservers.
- Zones should have `soa_edit_api=INCREASE` (or similar) so PowerDNS auto-bumps the SOA serial on API writes. The addon additionally calls `PUT /zones/{id}/notify` after every PATCH to push changes to slaves immediately.

**Activation:**

1. Copy the addon into your WHMCS install (see the Installation section for the `rsync` command).
2. In WHMCS Admin → **System Settings → Addon Modules**, find **VirtFusion DNS** and click **Activate**. Grant admin role access as needed.
3. Click **Configure** and fill in:

   | Field | Meaning |
   |---|---|
   | **Enable rDNS Sync** | Master switch. When off, every PowerDNS call short-circuits — the provisioning module behaves exactly as before the addon. |
   | **PowerDNS API Endpoint** | Scheme + host + port, no path (e.g. `https://ns1.example.com:8081` or `http://10.0.0.5:8081`). The module appends `/api/v1/…` itself. |
   | **PowerDNS API Key** | Password-type field. Encrypted at rest by WHMCS; decrypted server-side only when PowerDNS is called. |
   | **PowerDNS Server ID** | Almost always `localhost` — the PowerDNS API server identifier, not a hostname. |
   | **Default PTR TTL** | Applied to every PTR record the module creates. Default 3600. |
   | **Cache TTL** | How long zone listings and DNS-resolution lookups are cached. Default 60, minimum 10. |

4. Click **Save Changes**.
5. Open the addon's admin page (same menu, usually **Addons → VirtFusion DNS**) and click **Run Test**. You should see "OK — PowerDNS reachable and authenticated" followed by a list of visible zones. If you don't see your expected reverse zones here, the module won't find them either — fix PowerDNS first.

**How it behaves:**

| Event | Behavior |
|---|---|
| Server provisioning | Creates a PTR for every assigned IP pointing to the VirtFusion hostname — but only if that hostname's A/AAAA already resolves to the IP. Forward-missing IPs are logged and skipped (provisioning still succeeds). |
| Server rename (via client or admin) | Rewrites only PTRs whose current content equals the previous hostname. Client-customised PTRs are preserved. |
| Server termination | Deletes every PTR belonging to the server before the local record is purged. |
| Client edits PTR in the Reverse DNS panel | Validates IP ownership (cross-checked against a fresh VirtFusion fetch), PTR regex, per-IP 10-second rate limit, and forward-DNS match. Empty value deletes. |
| Daily cron | Creates PTRs for IPs that don't have one yet (and whose forward DNS resolves correctly). **Additive-only — never overwrites.** |
| Admin "Reconcile (force reset)" button | The only code path that overwrites a non-matching PTR — explicit admin action. |

**RFC 2317 classless delegations** are supported: the module parses zones like `64/64.38.186.66.in-addr.arpa.` (both CIDR-prefix and block-size conventions), matches IPs by range rather than suffix, and writes PTRs with the correct classless RRset name. The PowerDNS URL-safe zone ID encoding (`/` → `=2F`) is handled transparently.

**Security posture:**
- PowerDNS integration is **opt-in** — if the addon is deactivated or `Enable rDNS Sync` is off, the provisioning module behaves exactly as before.
- Every client-facing rDNS endpoint validates service ownership and re-verifies the IP is currently assigned to the requesting user's server (defends against stale-ownership after IP reassignment).
- The API key is stored encrypted in `tbladdonmodules` by WHMCS; it is never logged.
- DNS write failures never block VirtFusion operations — provisioning, rename, and termination all succeed regardless of PowerDNS state, and errors are recorded in the WHMCS Module Log for review.

## Client Area Features

### Server Overview
Displays real-time server information fetched from VirtFusion:
- Server name and hostname
- Memory, CPU cores, storage allocation
- IPv4 and IPv6 addresses
- Traffic usage vs. allocation
- Server status badge (Active, Suspended, etc.)

### Power Management
Four power control buttons:
- **Start** - Boot the server
- **Restart** - Graceful restart
- **Shutdown** - Graceful ACPI shutdown
- **Force Off** - Immediate power cut (use with caution)

### Network Management
- View all IPv4 addresses and IPv6 subnets assigned to the server
- Copy IP addresses to clipboard with one click

### VNC Console
- Opens a browser-based VNC console to the server
- Requires VirtFusion v6.1.0+ and the server must be running
- Opens in a new browser window/tab

### Server Rebuild
- Select from available OS templates (filtered by server package)
- Includes a confirmation dialog warning about data loss
- Triggers email notification on completion

### Control Panel SSO
- One-click login to the VirtFusion panel
- Opens in a new window (with fallback to same-window navigation)
- Password reset option for direct VirtFusion panel access

### Billing Overview
- Product name and group
- Recurring amount and billing cycle
- Registration and next due dates
- Payment method

### Reverse DNS *(requires the VirtFusion DNS addon)*
A panel listing every IP assigned to the server with an inline editor for the PTR record:
- One input per IP — populate to set a custom PTR, leave blank to delete
- Per-row status badge (OK / unverified / no PTR / no zone / error)
- Saves are rate-limited to one write per IP per 10 seconds
- Forward DNS must already resolve to the IP; mismatches show an inline error guiding the client to fix their A/AAAA first
- Hidden entirely when the addon is not activated

## Admin Area Features

### Admin Services Tab
When viewing a service in WHMCS admin, the module adds:
- **Server ID** - Editable field showing the VirtFusion server ID
- **Server Info** - Button to load live data from VirtFusion API
- **Server Object** - Full JSON response viewer
- **Options** - Admin impersonation link
- **Reverse DNS** *(when the VirtFusion DNS addon is activated)* - Live per-IP PTR status plus **Reconcile (additive)** and **Reconcile (force reset)** buttons

### Module Commands (Admin Buttons)
- **Create** - Provision a new server
- **Suspend** / **Unsuspend** - Manage server suspension
- **Terminate** - Delete the server (with 5-minute grace period in VirtFusion)
- **Change Package** - Update server to a different VirtFusion package
- **Update Server Object** - Refresh cached data from VirtFusion
- **Validate Server Config** - Dry run server creation to test configuration

## Theme Compatibility

This module is designed to work with **all WHMCS themes**:

| Theme | Status | Notes |
|---|---|---|
| Six (default) | Fully compatible | Bootstrap 3 |
| Twenty-One | Fully compatible | Bootstrap 4 |
| Lagom (ModulesGarden) | Fully compatible | Bootstrap 5 |
| Custom themes | Compatible | Uses dual CSS classes |

### How Theme Compatibility Works

The module uses dual CSS class names that work across Bootstrap versions:
- `panel card` - Works in BS3 (panel) and BS4/BS5 (card)
- `panel-heading card-header` - Works in BS3 and BS4/BS5
- `panel-body card-body` - Works in BS3 and BS4/BS5
- `panel-title card-title` - Works in BS3 and BS4/BS5

The order form hooks use vanilla JavaScript (no jQuery dependency) for maximum compatibility.

### Theme Override

To customize templates for a specific theme:

```
/templates/yourthemename/modules/servers/VirtFusionDirect/
  overview.tpl    # Client area template
  error.tpl       # Error template
```

WHMCS automatically loads theme-specific templates when they exist. Copy the originals from `modules/servers/VirtFusionDirect/templates/` as a starting point.

## API Endpoints Used

### Core Provisioning

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/connect` | Connection testing |
| `GET/POST` | `/users` | User lookup and creation |
| `GET` | `/users/{id}/byExtRelation` | Find VirtFusion user by WHMCS ID |
| `POST` | `/servers` | Server creation |
| `POST` | `/servers?dryRun=true` | Dry run validation |
| `POST` | `/servers/{id}/build` | OS installation / rebuild |
| `GET` | `/servers/{id}` | Server details (also used by UsageUpdate) |
| `DELETE` | `/servers/{id}` | Server termination |
| `POST` | `/servers/{id}/suspend` | Server suspension |
| `POST` | `/servers/{id}/unsuspend` | Server unsuspension |
| `PUT` | `/servers/{id}/package/{pkgId}` | Package changes |

### Client Management

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/servers/{id}/power/{action}` | Power management |
| `PATCH` | `/servers/{id}/name` | Server renaming |
| `POST` | `/users/{id}/serverAuthenticationTokens/{serverId}` | SSO token |
| `POST` | `/users/{id}/byExtRelation/resetPassword` | Password reset |
| `GET` | `/media/templates/fromServerPackageSpec/{id}` | OS templates |
| `GET` | `/ssh_keys/user/{id}` | SSH key listing |

### SSH Keys

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/ssh_keys` | Create SSH key for a user (checkout key paste) |

### Self-Service Billing

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/selfService/usage/byUserExtRelationId/{id}` | Usage data by WHMCS client ID |
| `GET` | `/selfService/report/byUserExtRelationId/{id}` | Billing report by WHMCS client ID |
| `POST` | `/selfService/credit/byUserExtRelationId/{id}` | Add credit by WHMCS client ID |
| `GET` | `/servers/{id}/traffic` | Traffic statistics |
| `GET` | `/backups/server/{id}` | Backup listing |
| `POST` | `/servers/{id}/vnc` | Toggle VNC on/off |
| `POST` | `/servers/{id}/resetPassword` | Reset server root password |

### Advanced

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/servers/{id}/vnc` | VNC console (v6.1.0+) |
| `PUT` | `/servers/{id}/modify/memory` | Modify memory (v6.2.0+) |
| `PUT` | `/servers/{id}/modify/cpuCores` | Modify CPU cores (v6.2.0+) |
| `PUT` | `/servers/{id}/modify/traffic` | Modify traffic (v6.0.0+) |
| `POST/DELETE` | `/servers/{id}/backup/plan` | Backup plan management (v4.3.0+) |

### PowerDNS (Reverse DNS addon, PowerDNS Authoritative 4.x+)

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/v1/servers/{id}` | Health check (Test Connection button) |
| `GET` | `/api/v1/servers/{id}/zones` | Zone discovery (cached per `cacheTtl`) |
| `GET` | `/api/v1/servers/{id}/zones/{zone}` | Fetch current RRsets for status + reads |
| `PATCH` | `/api/v1/servers/{id}/zones/{zone}` | Create / replace / delete PTR RRsets |
| `PUT` | `/api/v1/servers/{id}/zones/{zone}/notify` | NOTIFY slaves after every successful PATCH |

Authentication is via the `X-API-Key` header (configured in the addon). Zone IDs containing `/` (RFC 2317 classless) are URL-encoded as `=2F` per PowerDNS convention.

## Usage Update (Cron)

The module implements the `UsageUpdate` function that is called by the WHMCS daily cron. It automatically syncs:

- **Disk usage** (used and limit) from VirtFusion to WHMCS `tblhosting`
- **Bandwidth usage** (used and limit) from VirtFusion to WHMCS `tblhosting`

This data appears in the WHMCS client area and admin product details.

**Requirements**: The WHMCS cron must be running (`php -q /path/to/whmcs/crons/cron.php`). No additional configuration is needed - the module registers itself automatically.

**How it works**:
1. WHMCS calls `VirtFusionDirect_UsageUpdate()` once per configured server
2. The module queries all Active services assigned to that server
3. For each service, it fetches server data from VirtFusion API
4. Disk and bandwidth usage/limits are written to `tblhosting`

**Data format conversion**:
- VirtFusion traffic: bytes -> WHMCS expects: MB
- VirtFusion storage: bytes -> WHMCS expects: MB
- VirtFusion storage limit: GB -> WHMCS expects: MB
- VirtFusion traffic limit: GB -> WHMCS expects: MB (0 = unlimited)

## Troubleshooting

### Connection Test Fails

| Symptom | Cause | Solution |
|---|---|---|
| "Authentication failed" | Invalid or expired API token | Generate a new token in VirtFusion |
| "Connection failed" | Hostname unreachable or SSL issue | Verify hostname, check SSL cert validity |
| "Unexpected response" | API version mismatch or server issue | Check VirtFusion is running, verify API version |

### Server Creation Fails

| Symptom | Cause | Solution |
|---|---|---|
| "Service already exists" | Duplicate provisioning attempt | Run termination first, then create |
| "No Control server found" | No VirtFusion server in WHMCS | Add server in System Settings > Servers |
| "Unable to create user" | API permission issue | Check token has user create permission |
| "Server creation failed" | Invalid config options | Use "Validate Server Config" button to diagnose |
| HTTP 423 response | Server is locked | Wait and retry, or check VirtFusion for lock reason |

### OS Templates Not Showing on Order Form

1. Verify the **Package ID** (Config Option 2) is correct
2. Check that the package has OS templates assigned in VirtFusion
3. Ensure the **"Initial Operating System"** custom field exists (exact name match required)
4. Check that hooks are loading: re-save product settings to trigger hook detection
5. Inspect browser console for JavaScript errors

### Client Area Shows Error Template

1. Ensure a VirtFusion server is configured and linked to the product
2. Check the service status is Active or Suspended (not Pending/Terminated)
3. Review **Utilities > Logs > Module Log** for API errors
4. Verify the `mod_virtfusion_direct` table has an entry for the service

### SSO / Control Panel Login Fails

1. VirtFusion panel must be accessible from the client's browser
2. Verify the VirtFusion user exists (check by external relation ID in VirtFusion admin)
3. Ensure authentication token generation is enabled on the API token
4. Check for popup blockers if the new window doesn't open

### VNC Console Not Working

1. Requires VirtFusion v6.1.0 or higher
2. The server must be powered on and running
3. Check that VNC is enabled for the hypervisor in VirtFusion
4. Popup blockers may prevent the console window from opening

### UsageUpdate Not Syncing

1. Verify the WHMCS cron is running: `php -q /path/to/whmcs/crons/cron.php`
2. Check **Utilities > Logs > Module Log** for UsageUpdate errors
3. Ensure services are in "Active" status (other statuses are skipped)
4. The cron runs daily; wait for the next cycle after initial setup

## Known Issues

1. **VNC Console** - Requires VirtFusion v6.1.0+. Earlier versions do not expose a VNC API endpoint. The module gracefully handles this by showing an error message.

2. **Resource Modification** - Memory and CPU modification requires VirtFusion v6.2.0+. Traffic modification requires v6.0.0+. Backup management requires v4.3.0+.

3. **IPv6 Display** - IPv6 subnet display depends on the VirtFusion installation having IPv6 pools configured. If no IPv6 is assigned, the network panel shows "No IPv6 subnets".

4. **Order Form Custom Fields** - The custom fields ("Initial Operating System" and "Initial SSH Key") must be named exactly as specified. The module matches by field name with spaces removed and converted to lowercase.

5. **Hooks File Detection** - WHMCS detects the `hooks.php` file when the module is first activated. If you add the module files to an already-active installation, you may need to deactivate and reactivate the module, or re-save the product settings.

6. **Bootstrap 3 Themes** - While the module supports BS3 themes, some visual differences may exist (e.g., `d-flex` not available in BS3). The module uses `display: flex` in CSS as a fallback.

7. **Concurrent API Calls** - The module makes individual API calls for each feature panel on the client area page. If the VirtFusion API is slow, the page may take longer to fully load. All panels load asynchronously to minimize perceived delay.

8. **Self-Signed SSL Certificates** - SSL verification is enforced by default. VirtFusion panels using self-signed certificates will cause connection failures. Use a valid SSL certificate (e.g., Let's Encrypt) on your VirtFusion panel.

## Security

### Architecture
- All client API endpoints validate service ownership before processing
- Admin endpoints require WHMCS admin authentication
- Input sanitization on all user-supplied parameters (type casting, regex filtering, `filter_var`)
- Proper HTTP status codes (401, 403, 400, 500) for error responses
- XSS prevention via `htmlspecialchars()`, `encodeURIComponent()`, and jQuery `.text()`

### Best Practices
- **API Tokens**: Store only in the WHMCS server password field (encrypted at rest by WHMCS)
- **SSL Verification**: Enabled by default. Never disable in production.
- **File Access**: All PHP files include direct access prevention checks
- **Module Updates**: Keep updated for security patches
- **Permissions**: Use the minimum required API token permissions

### Reporting Vulnerabilities
If you discover a security vulnerability, please report it responsibly by emailing the maintainers rather than opening a public issue. See [SECURITY.md](SECURITY.md) for details.

## File Structure

```
modules/servers/VirtFusionDirect/
  VirtFusionDirect.php        # WHMCS module entry point (MetaData, ConfigOptions, all module functions)
  client.php                  # Client-facing AJAX API (authenticated, ownership-validated)
  admin.php                   # Admin-facing AJAX API (admin authentication required)
  hooks.php                   # WHMCS hooks (order form OS/SSH dropdowns, checkout validation, daily rDNS cron)
  lib/
    Module.php                # Base class: API communication, power, network, VNC, rebuild
    ModuleFunctions.php       # Provisioning: create, suspend, unsuspend, terminate, change package
    ConfigureService.php      # Order configuration: OS templates, SSH keys, server build init
    Database.php              # Database operations: custom table, WHMCS table queries
    Cache.php                 # Two-tier cache: Redis with filesystem fallback
    Curl.php                  # HTTP client: GET, POST, PUT, PATCH, DELETE with SSL verification
    ServerResource.php        # Data transformer: VirtFusion API response -> display format
    AdminHTML.php             # Admin interface: HTML generation for admin services tab
    Log.php                   # Logging: WHMCS module log integration
    PowerDns/
      Client.php              # PowerDNS HTTP API wrapper (X-API-Key, ping, listZones, getZone, patchRRset, notifyZone)
      Config.php              # Loads + decrypts addon settings from tbladdonmodules
      IpUtil.php              # PTR-name generation, IP extraction, RFC 2317 parsing, zone matching
      Resolver.php            # Forward-DNS verification (dns_get_record + CNAME chain, cached)
      PtrManager.php          # Orchestrator: syncServer, deleteForServer, listPtrs, setPtr, reconcile, reconcileAll
  templates/
    overview.tpl              # Client area Smarty template (all management panels)
    error.tpl                 # Error display template
    css/module.css            # Module styles (responsive, BS3/4/5 compatible)
    js/module.js              # Client JavaScript (all AJAX interactions)
    js/keygen.js              # SSH Ed25519 key generator (Web Crypto API)
  config/
    ConfigOptionMapping-example.php   # Example custom option name mapping

modules/addons/VirtFusionDns/   # Optional — only needed for reverse DNS support
  VirtFusionDns.php             # Addon entry point: _config(), _activate(), _deactivate(), _output() (Test Connection page)
```

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/your-feature`)
3. Commit your changes with clear messages
4. Push to your fork and open a Pull Request

For bug reports, please include:
- WHMCS version
- VirtFusion version
- PHP version
- Steps to reproduce
- Module Log output (Utilities > Logs > Module Log)

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE.md](LICENSE.md) file for details.

Copyright (c) EZSCALE
