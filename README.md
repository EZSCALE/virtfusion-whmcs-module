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
- **Network Management** - View and remove IPv4 addresses; view IPv6 subnets
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
- Dynamic OS template dropdown populated from VirtFusion API
- SSH key selection dropdown for users with saved keys, with option to paste a new public key
- **SSH Ed25519 key generator** — Client-side keypair generation using Web Crypto API
- Checkout validation ensuring OS selection before order placement
- **Resource sliders** - Configurable option dropdowns are replaced with interactive range sliders
- Compatible with all WHMCS order form templates

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

## Installation

### Step 1: Download & Install

Download the latest release from the [releases](https://github.com/EZSCALE/virtfusion-whmcs-module/releases) page, or install directly via the command line:

```bash
cd /tmp
git clone https://github.com/EZSCALE/virtfusion-whmcs-module.git
rsync -ahP --delete /tmp/virtfusion-whmcs-module/modules/servers/VirtFusionDirect/ /path/to/whmcs/modules/servers/VirtFusionDirect/
rm -rf /tmp/virtfusion-whmcs-module
```

Replace `/path/to/whmcs` with your actual WHMCS installation root.

The resulting file structure should be:

```
modules/servers/VirtFusionDirect/
  VirtFusionDirect.php       # Main module file
  client.php                 # Client AJAX API
  admin.php                  # Admin AJAX API
  hooks.php                  # WHMCS hooks
  modify.sql                 # Custom field setup SQL
  lib/
    Module.php               # Core module class
    ModuleFunctions.php      # Provisioning functions
    ConfigureService.php     # OS/SSH config service
    Database.php             # Database operations
    Curl.php                 # HTTP client
    ServerResource.php       # Data transformer
    AdminHTML.php            # Admin interface HTML
    Log.php                  # Logging
  templates/
    overview.tpl             # Client area template
    error.tpl                # Error template
    css/module.css           # Styles
    js/module.js             # Client JavaScript
    js/keygen.js             # SSH Ed25519 key generator
  config/
    ConfigOptionMapping-example.php  # Config mapping example
```

### Step 2: Set Up Server in WHMCS

1. Go to **Configuration > System Settings > Servers**
2. Click **Add New Server**
3. Fill in:
   - **Name**: Anything descriptive (e.g., "VirtFusion Production")
   - **Hostname**: Your VirtFusion panel hostname (e.g., `cp.example.com`)
   - **Type**: VirtFusion Direct Provisioning
   - **Password/Access Hash**: Your VirtFusion API token
4. Click **Test Connection** to verify
5. Click **Save Changes**

### Step 3: Create Product

1. Go to **Configuration > System Settings > Products/Services**
2. Create a new product or edit an existing one
3. On the **Module Settings** tab:
   - Set **Module Name** to "VirtFusion Direct Provisioning"
   - Select your VirtFusion server
   - Set **Hypervisor Group ID**, **Package ID**, and **Default IPv4** count
4. Save the product

### Step 4: Set Up Custom Fields

See [Custom Fields](#custom-fields) section below.

### Step 5: Activate Hooks

The hooks file (`hooks.php`) is automatically detected by WHMCS when the module is active. If you add the module files to an existing installation, you may need to re-save the product settings or clear the WHMCS template cache for hooks to take effect.

## Upgrading

1. Back up your existing `modules/servers/VirtFusionDirect/` directory
2. Back up `config/ConfigOptionMapping.php` if you have a custom mapping
3. Download and deploy the new version:

```bash
cd /tmp
git clone https://github.com/EZSCALE/virtfusion-whmcs-module.git
rsync -ahP --delete /tmp/virtfusion-whmcs-module/modules/servers/VirtFusionDirect/ /path/to/whmcs/modules/servers/VirtFusionDirect/
rm -rf /tmp/virtfusion-whmcs-module
```

4. Restore your custom `config/ConfigOptionMapping.php` if applicable
5. If you have theme-overridden templates, review them for any new template variables
6. Clear the WHMCS template cache: **Configuration > System Settings > General Settings > clear template cache**

The module database table (`mod_virtfusion_direct`) is automatically migrated on first load.

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

You **must** create two custom fields on each product that uses this module:

| Field Name | Field Type | Show on Order Form | Admin Only | Required |
|---|---|---|---|---|
| Initial Operating System | Text Box | Yes | No | No |
| Initial SSH Key | Text Box | Yes | No | No |

These fields are hidden text boxes that are dynamically replaced by dropdown selects via JavaScript hooks on the order form.

**Automated setup**: Run the SQL from [modify.sql](modify.sql) to auto-create these fields for all VirtFusion products:

```bash
mysql -u whmcs_user -p whmcs_database < modules/servers/VirtFusionDirect/modify.sql
```

### Module Configuration Options

Each product has three module-specific settings:

| Option | Name | Description | Default |
|---|---|---|---|
| Config Option 1 | Hypervisor Group ID | VirtFusion hypervisor group for server placement | 1 |
| Config Option 2 | Package ID | VirtFusion package defining server resources | 1 |
| Config Option 3 | Default IPv4 | Number of IPv4 addresses to assign (0-10) | 1 |
| Config Option 4 | Self-Service Mode | Enable VirtFusion self-service billing (0=Disabled, 1=Hourly, 2=Resource Packs, 3=Both) | 0 |
| Config Option 5 | Auto Top-Off Threshold | Credit balance below which auto top-off triggers during cron (0=disabled) | 0 |
| Config Option 6 | Auto Top-Off Amount | Credit amount to add when auto top-off triggers | 100 |

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
- Remove secondary IPv4 addresses (primary cannot be removed)

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

## Admin Area Features

### Admin Services Tab
When viewing a service in WHMCS admin, the module adds:
- **Server ID** - Editable field showing the VirtFusion server ID
- **Server Info** - Button to load live data from VirtFusion API
- **Server Object** - Full JSON response viewer
- **Options** - Admin impersonation link

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

### Network

| Method | Endpoint | Purpose |
|---|---|---|
| `DELETE` | `/servers/{id}/ipv4` | Remove IPv4 address |

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
| `GET` | `/selfService/currencies` | Available self-service currencies |

### Advanced

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/servers/{id}/vnc` | VNC console (v6.1.0+) |
| `PUT` | `/servers/{id}/modify/memory` | Modify memory (v6.2.0+) |
| `PUT` | `/servers/{id}/modify/cpuCores` | Modify CPU cores (v6.2.0+) |
| `PUT` | `/servers/{id}/modify/traffic` | Modify traffic (v6.0.0+) |
| `POST/DELETE` | `/servers/{id}/backup/plan` | Backup plan management (v4.3.0+) |

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

8. **Primary IPv4 Protection** - The first IPv4 address cannot be removed through the client area interface. This is by design to prevent users from accidentally removing their primary IP address.

9. **Self-Signed SSL Certificates** - SSL verification is enforced by default. VirtFusion panels using self-signed certificates will cause connection failures. Use a valid SSL certificate (e.g., Let's Encrypt) on your VirtFusion panel.

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
  hooks.php                   # WHMCS hooks (order form OS/SSH dropdowns, checkout validation)
  modify.sql                  # SQL for creating custom fields
  lib/
    Module.php                # Base class: API communication, power, network, VNC, rebuild
    ModuleFunctions.php       # Provisioning: create, suspend, unsuspend, terminate, change package
    ConfigureService.php      # Order configuration: OS templates, SSH keys, server build init
    Database.php              # Database operations: custom table, WHMCS table queries
    Curl.php                  # HTTP client: GET, POST, PUT, PATCH, DELETE with SSL verification
    ServerResource.php        # Data transformer: VirtFusion API response -> display format
    AdminHTML.php             # Admin interface: HTML generation for admin services tab
    Log.php                   # Logging: WHMCS module log integration
  templates/
    overview.tpl              # Client area Smarty template (all management panels)
    error.tpl                 # Error display template
    css/module.css            # Module styles (responsive, BS3/4/5 compatible)
    js/module.js              # Client JavaScript (all AJAX interactions)
    js/keygen.js              # SSH Ed25519 key generator (Web Crypto API)
  config/
    ConfigOptionMapping-example.php   # Example custom option name mapping
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
