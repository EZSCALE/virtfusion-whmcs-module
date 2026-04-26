# Changelog

All notable changes to the VirtFusion Direct Provisioning Module for WHMCS.

## [1.4.4] - 2026-04-25

### Bug Fixes
- **`install.sh`: "TMP: unbound variable" error at script exit, plus exit code 1 even on successful installs.** The cleanup `trap 'rm -rf "$TMP"' EXIT` referenced a `local TMP` from inside `cmd_sync()`. The EXIT trap doesn't fire until the *shell* exits — by which time the function-scoped local is out of scope — and `set -u` then exploded the trap body, masking the real exit code with `1`. Fix: drop `local` so `TMP` persists at script scope until cleanup runs, and switch the trap body to `${TMP:-}` so any future regression that tightens TMP's scope still survives the trap. Cosmetic in practice (the install/upgrade work itself completed before the trap ran), but the non-zero exit broke automated wrappers and cron-driven invocations that check `$?`.

## [1.4.3] - 2026-04-25

### Features
- **`install.sh` helper script with `install` / `upgrade` / `check` subcommands.** Single-file POSIX bash script that handles both first-time installation and upgrades, auto-detects the WHMCS web user from the parent directory's ownership and applies it to new files via rsync `--chown`, optionally syncs the PowerDNS reverse-DNS addon (`--with-addon`), accepts a pinned version (`--version v1.4.1`, default: latest published release), preserves any custom `config/ConfigOptionMapping.php` across the rsync `--delete`, and writes a `.installed-version` marker so the `check` subcommand can report installed-vs-latest without making changes. Pipeable via curl or wget. Exit codes for `check` (0=current, 1=outdated, 2=not installed) make it usable as a cron-driven update monitor. Closes the long-standing pitfall where rsyncing as root left files owned by `root:root` and the web server couldn't read them — the classic "module installed but invisible in WHMCS" symptom.

### Bug Fixes
- **Release workflow now force-publishes new releases to non-draft and marks them `--latest`.** `softprops/action-gh-release@v2` has a long-standing intermittent bug where it creates a release as a draft and silently fails to flip it to published, despite reporting success. v1.4.0, v1.4.1, and v1.4.2 all shipped as drafts because of this — meaning the GitHub `releases/latest` API returned v1.3.0, the install snippets and the new `install.sh` would all download v1.3.0, and users would never get the storage-type-code fix even after running the documented upgrade. Added a `make_latest: 'true'` input to the action and a follow-up `gh release edit --draft=false --latest` step that runs unconditionally as a safety net. v1.4.0/1/2 were manually re-published as a one-off cleanup.

### Documentation
- README install/upgrade sections rewritten to feature the `install.sh` script as the primary path (with both `curl` and `wget` examples), with the manual rsync recipe preserved in collapsible `<details>` blocks for users who prefer not to pipe scripts to bash. The manual recipe also gained a `stat -c '%U:%G'` ownership probe and `--chown="$OWNER"` flag, fixing the same root-owned-file pitfall the script handles automatically.

## [1.4.2] - 2026-04-25

### Documentation
- **Install/upgrade snippets now pull tagged releases instead of cloning `main`.** The previous `git clone` flow always pulled HEAD, which could include in-flight commits between releases — the same trap the v1.4.1 storage-type-code bug fell into for anyone who installed during the v1.4.0 release window. The new snippets default to the latest published release (queried live from the GitHub API at install time) and accept a `VERSION=vX.Y.Z` override for pinned installs and rollbacks. Pure POSIX — only requires `curl`, `sed`, `tar`, and `rsync`, all standard on any WHMCS host. The `archive/refs/tags/<TAG>.tar.gz` endpoint is public and cacheable, so only the version lookup hits the GitHub API (well under the 60/hr unauthenticated rate limit).

## [1.4.1] - 2026-04-25

### Bug Fixes
- **Critical: stock control returned qty=0 fleet-wide for packages with a `primaryStorageProfile`.** `StockControl::capForStorage()` was comparing the package's `primaryStorageProfile` against `otherStorage[].id`, but the VirtFusion API exposes that field as a **storage type code** (mirrors `server_packages.storage_type`) — a filter that should match `otherStorage[].storageType`. Pool ids are unique per hypervisor (e.g. 23/28/30 for the same logical mountpoint on three nodes) and almost never collide with the type-code domain (0=local, 4=mountpoint, etc.), so the check returned 0 for every hypervisor and silently zeroed inventory for any product that opted into stock control with a non-default storage profile. Symptoms: every stock-controlled VPS product showed qty=0 in WHMCS despite abundant memory/CPU/IPv4 capacity; only workarounds were disabling stock control or removing `primaryStorageProfile` from the package, both of which defeat the gating. Fix: match `pool.storageType` instead of `pool.id`; walk all pools that match (a hypervisor may carry multiple pools of the same type) and pick the one that fits the most VMs; treat a disabled pool as skip-and-continue rather than a hard zero, so an enabled peer of the same type still contributes. Also renamed the internal `$profileId` parameter to `$storageTypeId` so future readers don't fall into the same naming trap. Verified on a 3-hypervisor cluster: qty went from 0/0/0/0/0/0/0/0 to 66/32/15/7/3/1/32/15 across the VPS-1 through VPS-32 products with no other config change.

## [1.4.0] - 2026-04-24

### Features
- **Dynamic VPS stock control driven by live hypervisor capacity.** Opt-in per product via WHMCS's native `tblproducts.stockcontrol` toggle; when enabled, the module overwrites `tblproducts.qty` with the real number of VPSes the panel can still provision and WHMCS handles the "Out of Stock" badge, Add-to-Cart gating, and checkout refusal natively — no template work required. qty is derived by combining two authoritative sources:
  - `GET /packages/{packageId}` for the per-VPS resource footprint (`memory`, `cpuCores`, `primaryStorage`, `primaryStorageProfile`, `enabled`)
  - `GET /compute/hypervisors/groups/{id}/resources` for live per-hypervisor free/allocated data

  Algorithm sums `min(memory, cpu, storage)` across eligible hypervisors (enabled AND commissioned AND !prohibit) for every group the product can be placed in (default `configoption1` plus every numeric value of a `Location` configurable option), capped by the group-level IPv4 pool taken as `max()` within a group to avoid double-counting. Storage matching is strict against `package.primaryStorageProfile`; hypervisors without the named pool contribute 0. Confirmed-missing conditions (HTTP 404 on `/packages/{id}`, `package.enabled=false`) force qty=0; transient failures leave `qty` UNTOUCHED to avoid false out-of-stock during API blips.

- **Event-driven stock recalculation hooks:**
  - `AfterModuleCreate` — refreshes qty after every VirtFusion provision (capacity just decreased). Bursts of parallel provisions coalesce via a 30 s shared rate-limit.
  - `AfterModuleTerminate` — refreshes qty after every VirtFusion termination (capacity just increased). Shares the 30 s rate-limit with create.
  - `AfterCronJob` — every-2-hour safety net that catches capacity changes made directly in the VirtFusion panel without going through WHMCS. Interval tunable via `STOCK_CRON_INTERVAL_SECONDS` in `hooks.php`.
  - `ClientAreaPageCart` — opportunistic per-product refresh during the order flow, rate-limited to once per product per 60 s.

- **Order auto-accept after successful provision.** `AfterModuleCreate` calls WHMCS `AcceptOrder` (with `autosetup=false` so there's no double-provision) when the parent order is still in Pending status. Closes the gap for installs that rely on pending-order workflows for non-VF products but want VirtFusion provisions to auto-advance. Idempotent — already-accepted orders are skipped.

- **Admin-triggered full recalculation.** New `admin.php?action=stockRecalculate` action (POST + same-origin required) runs `StockControl::recalculateAll()` on demand and returns a JSON `{productId: qty}` map; the module log gets a compact summary (`{total, updated, zeroed, skipped}`) so it stays readable on stores with hundreds of products.

- **Per-product safety buffer.** New `stockSafetyBufferPct` config option (configoption7, default 10) reserves X% of each resource's `max` during stock calculation. Applied only to capped resources (unlimited resources with `max=0` skip the buffer). Admins can override per product in the module settings; blank falls back to 10% so existing products get sensible headroom without any config change.

- **Test Connection now probes `/compute/hypervisors/groups`.** A VirtFusion API token scoped only to `/servers` would pass the existing `/connect` check but silently break nightly stock updates. The admin's Test Connection button now surfaces missing `/compute` read scope at config time with a specific error rather than as unexplained nightly silence.

### Caching
- New cache keys: `pkg:{packageId}` (10 min TTL, package definitions rarely change) and `grpres:{groupId}` (120 s TTL, resources change minute-to-minute under load). Confirmed 404 responses are cached for 60 s so an admin re-creating a deleted package/group takes effect quickly.

### Safety Properties
- `Module::fetchPackage()` and `Module::fetchGroupResources()` return a tri-state `array | false | null`: `false` means "VirtFusion confirmed this doesn't exist → OOS is correct", `null` means "we can't tell right now → don't touch existing qty". Without this distinction the module would either zero out inventory during transient API blips, or show inventory for deleted packages.
- `\Throwable` catches on every stock-path entry point (not just `\Exception`) so a `TypeError` from a malformed API response can't escape the tri-state contract.
- Stock-control is gated by `tblproducts.stockcontrol=1` — products that opt out are never touched, even by the safety-net cron.

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
