# Changelog

All notable changes to the VirtFusion Direct Provisioning Module for WHMCS.

## [1.3.0] - 2026-04-17

### Bug Fixes
- **Critical: decrypt() corruption of plaintext addon API keys.** `Config::get()` was calling WHMCS's `decrypt()` on the raw `tbladdonmodules.value` for the PowerDNS API key and accepting whatever non-empty result came back. WHMCS addon password-type fields are actually stored **plaintext** (unlike `tblservers.password` which is encrypted), and `decrypt()` on plaintext input returns ~4 bytes of binary garbage instead of empty. That garbage was ending up in the `X-API-Key:` header, producing a baffling 401 from PowerDNS and an empty zone list — which then surfaced as **"no zone"** for every IP in the client-area rDNS panel. Fix: only use `decrypt()`'s output when it's printable ASCII; fall back to raw otherwise. Also `trim()` the chosen value so a stray paste-newline can't corrupt the header.

### Features
- **IPv6 subnet visibility + custom-host PTR flow.** VirtFusion allocates v6 as whole subnets (e.g. a /64 routed to the VPS) rather than discrete host addresses. The module previously filtered these silently; now subnets appear as first-class rows in the client rDNS panel with a collapsible "Add host PTR" form. Ownership verification uses **subnet containment** (`IpUtil::ipv6InSubnet()` via `inet_pton` + bit masking) so any address inside one of the VPS's allocated subnets is writeable, while addresses outside them are rejected. FCrDNS / rate-limit / CSRF guards all still apply.
- **Diagnose-an-IP tool** on the VirtFusion DNS addon admin page. Takes an IP input and runs the full PtrManager pipeline inline: config snapshot, fresh zone list (cache-bypassed), computed PTR name, matched zone, current PTR content. Every common failure mode (wrong key, wrong serverId, forgotten zone, mis-aligned RFC 2317 label, stale cache) produces a distinctive shape in that output, turning "support ticket" into "screenshot the diagnosis".
- **Actionable auth-error messages.** `Client::ping()` now returns structured guidance on 401/403 (check API key, `api-allow-from`, whitespace) and 404 (check `serverId`, it should be the literal `localhost`), replacing the previous "authentication failed (check API key)" / "unexpected HTTP 404" which gave no clue which of several causes was actually biting.

## [1.2.0] - 2026-04-17

### Features
- **PowerDNS reverse DNS (PTR) integration** — opt-in via companion `VirtFusionDns` addon module:
  - Automatic PTR sync on server create, rename, and terminate
  - Client-area "Reverse DNS" panel with one editable PTR per assigned IP and per-row status badges
  - Admin services-tab widget with Reconcile (additive) and Reconcile (force reset) buttons
  - Daily cron additive reconciliation (never overwrites existing PTRs)
  - Forward-confirmed reverse DNS (FCrDNS) enforcement — PTR writes rejected if forward A/AAAA doesn't resolve to the target IP
  - IPv4 + IPv6 support with full nibble-reversal for `ip6.arpa`
  - RFC 2317 classless delegation support (both CIDR-prefix `0/26` and block-size `64/64` conventions)
  - Automatic NOTIFY after every successful PATCH so slaves pick up SOA bumps immediately
  - PowerDNS zone ID `=2F` URL-encoding for zones containing `/`
- **Security hardening helpers** on the Module base class:
  - `requirePost()` — 405 on non-POST mutations
  - `requireSameOrigin()` — CSRF Origin/Referer check against WHMCS host
  - `requireServiceStatus()` — filter endpoints by `tblhosting.domainstatus`
  - Applied to all rDNS endpoints with successful-write audit logging
- Merged Test Connection — when the DNS addon is active the admin button verifies both VirtFusion AND PowerDNS in a single check

### Bug Fixes
- `IpUtil::parseClasslessZone` now rejects misaligned start addresses (e.g., `3/26.x.y.z` — /26 ranges must begin at a multiple of 64). Prevents silent write-into-wrong-zone on misconfigured zone names.

### Documentation
- Detailed design-rationale commentary added across the module for future-developer onboarding (Cache, Curl, Log, Database, ServerResource, ConfigureService) and throughout the new PowerDNS subsystem
- README updated with an extensive "Reverse DNS Addon (PowerDNS)" section covering activation, configuration, behaviour, and security posture
- CLAUDE.md updated with architecture notes and PowerDNS API compatibility details

## [1.0.0] - 2026-03-19

### Features
- OS template tile gallery with accordion categories, brand icons, and search
- Inline server rename with friendly name generator
- Traffic statistics canvas chart in resources panel
- Backup listing timeline in manage panel
- VNC enable/disable toggle with connection details and password copy
- Server root password reset with auto-clipboard copy
- Redis-backed API response caching with filesystem fallback
- Skeleton loading, action cooldowns, progress indicators
- Copy-to-clipboard buttons for IP addresses
- Client-side SSH Ed25519 key generator on checkout page
- VNC console support, resources panel, self-service billing
- Configurable option sliders on checkout page

### Bug Fixes
- XSS escaping, null guards, and proper error handling
- All state-mutating operations use POST instead of GET
- Explicit break after all output() calls in client.php
- Server-side regex validation on rename endpoint
- Error messages sanitized (no raw API errors exposed to clients)

### Removed
- Client IP removal capability (IPs managed by VirtFusion)
- IP add buttons (managed by VirtFusion during provisioning)
- Firewall panel (non-functional; managed in VirtFusion admin)

### Infrastructure
- Tag-based release workflow (compatible with Gitea and GitHub)
- Codebase consolidation: resolveServiceContext(), groupOsTemplates(), vfUrl(), vfShowAlert()

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
- GitHub Actions CI/CD
- Security policy (SECURITY.md)
- License (GPL v3)
