# VirtFusion Direct Provisioning Module for WHMCS

[![GitHub Super-Linter](https://github.com/EZSCALE/virtfusion-whmcs-module/actions/workflows/publish-release.yml/badge.svg)](https://github.com/EZSCALE/virtfusion-whmcs-module/actions)
![GitHub](https://img.shields.io/github/license/EZSCALE/virtfusion-whmcs-module)
![GitHub issues](https://img.shields.io/github/issues/EZSCALE/virtfusion-whmcs-module)
![GitHub pull requests](https://img.shields.io/github/issues-pr/EZSCALE/virtfusion-whmcs-module)

A comprehensive WHMCS provisioning module for [VirtFusion](https://virtfusion.com) that enables automated VPS server provisioning, management, and client self-service directly from WHMCS.

## Requirements

- **VirtFusion** v1.7.3 or higher
- **WHMCS** 8.x or higher
- **PHP** 8.0 or higher
- A valid VirtFusion API token with appropriate permissions

## Features

### Provisioning
- Automatic server creation, suspension, unsuspension, and termination
- Package/plan upgrades and downgrades
- Automatic VirtFusion user creation linked to WHMCS client accounts
- Configurable options mapping for dynamic resource allocation

### Client Area
- **Server Overview** - Real-time server information (hostname, IP, resources, status badge)
- **Power Management** - Start, restart, shutdown, and force power off controls
- **Control Panel SSO** - One-click login to VirtFusion panel via authentication tokens
- **Server Rebuild** - Reinstall with any available OS template directly from WHMCS
- **Password Reset** - Reset VirtFusion panel login credentials
- **Bandwidth Usage** - Traffic usage display with allocation limits
- **Billing Overview** - Product, billing cycle, and payment information

### Admin Area
- Server connection testing (Test Connection button)
- Server information display with live data from VirtFusion
- Admin impersonation for VirtFusion panel access
- Editable Server ID field for manual adjustments
- Full server object JSON viewer

### Ordering Process
- Dynamic OS template dropdown populated from VirtFusion API
- SSH key selection dropdown for users with saved keys
- Checkout validation ensuring OS selection before order placement
- Compatible with all WHMCS order form templates

### Theme Compatibility
- Works with **all WHMCS themes** including Six, Twenty-One, Lagom, and custom themes
- Uses dual `panel`/`card` CSS classes for Bootstrap 3/4/5 compatibility
- Framework-agnostic HTML structure
- Responsive design with mobile-friendly layouts
- Templates support the WHMCS theme override system

### Security
- SSL/TLS certificate verification enabled by default
- Input sanitization on all user-supplied parameters
- Service ownership validation on all client API endpoints
- Proper HTTP status codes for error responses (401, 403, 400, 500)
- XSS protection via `htmlspecialchars()` and `encodeURIComponent()`
- Direct file access prevention on all PHP files

## Installation

1. Download the latest release from the [releases](https://github.com/EZSCALE/virtfusion-whmcs-module/releases) page.
2. Extract the archive and upload the `modules/` folder to your WHMCS installation directory.
3. In WHMCS Admin, go to **Configuration > System Settings > Servers** and add a new server:
   - **Type**: VirtFusion Direct Provisioning
   - **Hostname**: Your VirtFusion panel hostname (e.g., `cp.example.com`)
   - **Password/Access Hash**: Your VirtFusion API token
4. Click **Test Connection** to verify the API connection.
5. Create or edit a product and set the **Module** to "VirtFusion Direct Provisioning".

## Custom Fields Setup

You **must** create two custom fields on each product that uses this module:

| Field Name               | Field Type | Show on Order Form | Admin Only | Required |
|--------------------------|------------|--------------------| ---------- | -------- |
| Initial Operating System | Text Box   | Yes                | No         | No       |
| Initial SSH Key          | Text Box   | Yes                | No         | No       |

You can run the included SQL to auto-create these fields for all VirtFusion products:

```sql
-- See modify.sql for the complete query
```

Or run the SQL from the [modify.sql](modify.sql) file.

## Module Configuration Options

Each product using this module has three configuration options:

| Option | Name | Description |
|--------|------|-------------|
| Config Option 1 | Hypervisor Group ID | The VirtFusion hypervisor group ID for server placement (default: 1) |
| Config Option 2 | Package ID | The VirtFusion package ID that defines server resources (default: 1) |
| Config Option 3 | Default IPv4 | Number of IPv4 addresses to assign (0-10, default: 1) |

## Configurable Options (Dynamic Pricing)

To allow customers to select different resource levels with pricing, create WHMCS Configurable Options groups with these option names:

| VirtFusion Parameter | Default Option Name | Description |
|---------------------|--------------------| ----------- |
| `packageId` | Package | VirtFusion package ID |
| `hypervisorId` | Location | Hypervisor group for server placement |
| `ipv4` | IPv4 | Number of IPv4 addresses |
| `storage` | Storage | Disk space in GB |
| `memory` | Memory | RAM in MB (values < 1024 auto-converted from GB) |
| `traffic` | Bandwidth | Monthly traffic allowance in GB |
| `cpuCores` | CPU Cores | Number of CPU cores |
| `networkSpeedInbound` | Inbound Network Speed | Inbound speed in Mbps |
| `networkSpeedOutbound` | Outbound Network Speed | Outbound speed in Mbps |
| `networkProfile` | Network Type | VirtFusion network profile ID |
| `storageProfile` | Storage Type | VirtFusion storage profile ID |

### Custom Option Name Mapping

If your configurable option names differ from the defaults, create a mapping file:

1. Copy `config/ConfigOptionMapping-example.php` to `config/ConfigOptionMapping.php`
2. Edit the mapping array to match your option names

## Theme Override

To customize the module templates for a specific theme, copy the template files to:

```
/templates/yourthemename/modules/servers/VirtFusionDirect/
```

WHMCS will automatically use theme-specific templates when available.

## API Endpoints Used

This module uses the following VirtFusion API v1 endpoints:

| Endpoint | Purpose |
|----------|---------|
| `GET /connect` | Connection testing |
| `GET/POST /users` | User lookup and creation |
| `POST /servers` | Server creation |
| `POST /servers/{id}/build` | OS installation |
| `GET /servers/{id}` | Server details retrieval |
| `DELETE /servers/{id}` | Server termination |
| `POST /servers/{id}/suspend` | Server suspension |
| `POST /servers/{id}/unsuspend` | Server unsuspension |
| `PUT /servers/{id}/package/{pkgId}` | Package changes |
| `POST /servers/{id}/power/*` | Power management (boot/shutdown/restart/poweroff) |
| `PATCH /servers/{id}/name` | Server renaming |
| `POST /users/{id}/serverAuthenticationTokens/{serverId}` | SSO token generation |
| `POST /users/{id}/byExtRelation/resetPassword` | Password reset |
| `GET /packages` | Package listing |
| `GET /media/templates/fromServerPackageSpec/{id}` | OS template listing |
| `GET /ssh_keys/user/{id}` | SSH key listing |

## Troubleshooting

### Connection Test Fails
- Verify the VirtFusion panel hostname is correct and accessible
- Ensure the API token has not expired
- Check that SSL certificates on the VirtFusion panel are valid (self-signed certificates will cause connection failures)

### Server Creation Fails
- Check the Module Log in WHMCS Admin (Utilities > Logs > Module Log) for detailed error messages
- Verify the Package ID and Hypervisor Group ID are correct
- Ensure the VirtFusion API token has permission to create servers

### OS Templates Not Showing
- Confirm the Package ID (Config Option 2) is set correctly
- Verify the package has OS templates assigned in VirtFusion
- Check that the "Initial Operating System" custom field exists on the product

### Client Area Shows Error
- Ensure a VirtFusion server is configured in WHMCS Server Settings
- Check that the service has been provisioned (not in Pending status)
- Review the Module Log for API communication errors

### SSO / Control Panel Login Fails
- The VirtFusion panel must be accessible from the client's browser
- Verify the VirtFusion user exists (check by external relation ID)
- Ensure authentication token generation permissions are enabled on the API token

## Security Considerations

- **API Tokens**: Store API tokens only in the WHMCS server password field. WHMCS encrypts this value at rest.
- **SSL Verification**: SSL certificate verification is enabled by default. Do not disable it in production environments.
- **Module Updates**: Keep the module updated to receive security patches.
- **Access Control**: The module validates service ownership on every client API call. Admin endpoints require WHMCS admin authentication.

## Contributing

Contributions are welcome. Please open an issue or pull request on the [GitHub repository](https://github.com/EZSCALE/virtfusion-whmcs-module).

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE.md](LICENSE.md) file for details.
