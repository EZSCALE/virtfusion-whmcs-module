# Changelog

All notable changes to the VirtFusion Direct Provisioning Module for WHMCS.

## [Unreleased]

### Security

- **S2: Client password reset requires the SSO permission.** The `resetPassword` route and `Module::resetUserPassword()` now require the `productsso` account permission and an Active service, so a sub-account without single sign-on access can no longer mint VirtFusion panel credentials.

- **S3: Order-time template and SSH key are validated before the build.** `initServerBuild` resolves the Initial Operating System against the package's enabled templates and a numeric Initial SSH Key against the service owner's own keys before any POST; either failing skips the build with a log entry. Checkout rejects unoffered numeric templates when the catalogue is reachable, but an unusable catalogue response (error body, expired token, 429/5xx) no longer blocks every order. The rebuild path also had a latent bug: its template-eligibility lookup matched the category rows of the grouped `fromServerPackageSpec` payload instead of the templates inside them. Rebuild now uses the same `eligibleTemplate()` helper and sends the resolved template ID.

- **S4: Operation locks are always released or settled.** Accepted fire-and-forget writes with no job ID (power, native PTR, settings-type writes) now complete instead of holding the VM reservation forever. Mutations rejected before anything is sent fail, release the lock and surface the reason. Admins with `Perform Module Command Operations` can reconcile job-less operations; job-bearing ones still need the recovery role. Resource pack lifecycle validates the Deletion Delay and reserves the operation lock before moving the binding, and reverts its own change when no native write was sent, so a held lock or a bad setting no longer strands the binding.

- **S5: Auto top-off is billed, idempotent and stops at termination.** Top-off now creates a WHMCS billable item before granting panel credit, claims a per-service, per-day ledger row so re-runs cannot bill or grant twice, and is skipped while the client has unpaid invoices. Terminating a client's last Active or Suspended service on a panel revokes hourly self-service; a later Create under the same VirtFusion user turns it back on when the product enables it.

- **S5: One billing type per client account.** VirtFusion self-service is a per-user setting, and Create used to overwrite it with the new product's mode, so ordering an hourly product switched a client's live resource pack off (and vice versa). A product's billing type is its Self-Service Mode (`0|Disabled` style values accepted; blank means Disabled, or Resource Packs for pack products). Checkout now refuses a cart whose VirtFusion products disagree with each other, and, for a logged-in client, a cart that disagrees with the client's Active, Suspended or Pending VirtFusion services. CreateAccount and ChangePackage (server and pack) refuse before any VirtFusion call when the product conflicts with the client's other services, and refuse (fail closed) when that check hits a database error. An order must match every existing service, so accounts whose services already disagree accept no new VirtFusion orders until an operator resolves them; ChangePackage on such an account still allows a type the client already holds. Create no longer overwrites an existing user's different non-zero self-service mode, and a pack Create under an existing user whose self-service was revoked turns it back on.

  - Abandoned unpaid orders no longer pin a client's billing type forever. A new daily job (`UnpaidOrderCanceller`, run from `DailyCronJob`) cancels Pending orders older than the panel's `unpaidOrderCancelDays` provider setting (default 3, `0` disables, 0–365) through the WHMCS `CancelOrder` API, without emails and without cancelling subscriptions, and cancels the invoice if it is still Unpaid afterwards. Only orders whose every item is a Pending VirtFusionDirect service on that panel and whose invoice is Unpaid with no payment or applied credit qualify; mixed, partly provisioned or partly paid orders are left alone. At most 100 orders are cancelled per run.

- **S6: Server-mode CreateAccount is idempotent.** A pending reservation and a `server:create` operation are recorded before `POST /servers`. A 4xx releases them; timeouts and 5xx keep them, and the next Create reconciles against the user's server list (adopt exactly one new owned server, create fresh if none, refuse if several). Admin binding and termination settle any unresolved create operation, so re-provisioning is never blocked.

- **S7: Package downgrades are verified.** ChangePackage reads current and target resources before the update and re-reads the server afterwards. It returns an error, instead of success, when a modification failed or a requested reduction (including storage, which VirtFusion never shrinks) was not applied.

- **S8: Rescue credentials need module-command permission.** `rescueStatus` strips SSH credentials for admins without `Perform Module Command Operations`. Customers still see them for their own verified VM.

- **S9: Lifecycle actions check live ownership.** Suspend, unsuspend and terminate confirm through the live VirtFusion server and the ext-relation lookup that the bound VM still belongs to the service's client, and refuse on a mismatch or a failed check. Terminate and rename manage PTRs from a fresh server object instead of the stored one. The legacy power, rebuild, rename and server-password routes require an Active service and refuse with 409 while a VM lock is held.

- **S10: Logs and caches no longer leak secrets.** Lifecycle error paths log an allow-listed projection of `$params` instead of the full array (server password, access hash, client PII). Redaction now also covers access hashes, `*_hash`, notes, credentials and private keys. The file cache moves from the shared `/tmp/vfd_cache` to a private per-install `0700` directory and refuses symlinked, foreign-owned or group/world-accessible directories. The new `VFD_REDIS_AUTH` constant enables Redis AUTH. `Cache::health()` reports the usable tiers; when neither Redis nor the file cache works, the admin services tab shows a red warning (also appended to a failed Test Connection) instead of a System Activity Log line on every PHP request.

- **S11: PTR, serverData and hostname hardening.** PTR writes on both the native VirtFusion and PowerDNS backends require a valid hostname and a forward-confirmed (FCrDNS) match. The client `serverData` view is sanitised, cached for 20 seconds per VM and rate-limited on misses; the admin `serverData` route now requires POST with CSRF. The tblhosting sync copies only a valid RFC 1123 hostname into `domain`, never the free-text server name, and no longer writes the password column. This also fixes a latent bug where every client `serverData` call blanked `tblhosting.password`. Rebuild hostnames are validated (RFC 1123, maximum 63 characters) instead of silently stripped.

Deployment and panel-side findings (S1, S12–S17) are tracked separately.

### Added

- **Customer SSH key management.** A new SSH Keys panel in the client area (also in the "On This Page" sidebar) lists the keys on the customer's VirtFusion account (name and SHA256 fingerprint, never the key body) and lets them add a key (name plus an ssh-ed25519, ssh-rsa of at least 2048 bits, ecdsa-sha2-nistp256/384/521 or sk-* public key, 16 KiB maximum) or delete one with an inline confirmation. New `client.php` actions `sshKeyAdd` and `sshKeyDelete` require POST, the session CSRF token, service ownership and an Active service, are rate-limited (adds per client every 5 s, deletes per key every 2 s), and act only on the VirtFusion user resolved from the client: a key ID outside that user's own inventory is a 404 and nothing is deleted. The account lookup is strict: only a 404 from VirtFusion means "no account yet" (409, created with the first server); a relation mismatch is a 403 and an unreachable or failing panel is a 502, never a write. If the key list cannot be re-read after a completed add or delete, the action still reports success and the page reloads the list. Added keys appear straight away in the Rebuild key dropdown and in the order form's Initial SSH Key list. Rebuild now rejects a malformed pasted key with a 400, and refuses (404 for a key ID the customer does not own, 502 when a pasted key cannot be created) instead of building without the requested key, and the order form no longer HTML-escapes key names twice.

- **Keep-disk plan changes.** A new optional `Disk on upgrade` configurable option (mapping key `keepDisk`) with sub-options `Grow disk to the plan size` (default) and `Keep my current disk size` lets customers change memory/CPU without growing the disk. Any sub-option name containing "keep" selects it, including friendly `1|Keep…` names. ChangePackage then applies the package, cancels the queued primary-disk growth (`DELETE /servers/{id}/disks/{disk}/pending`) and verifies it is gone; if that fails it returns "Package change incomplete: the disk resize could not be cancelled …" and does not restart. In keep-disk mode storage is not verified as a requested reduction, and the only refusal is an actual disk larger than the target plan's disk.

- **Automatic restart after package changes.** New provider setting `restartOnPackageChange` (default on, toggle in Panel configuration). After a successful server-mode package change (customer or admin), a running server with pending power-change actions is restarted (`POST /servers/{id}/power/restart`), and the module checks for at most 15 s (3 × 5 s) only to log whether the actions cleared, so the WHMCS request (often the invoice-paid callback) is never blocked for long. Logged outcomes: (`changePackage:restart`, `restartApplied`, `restartPending`, `restartSkipped`, `restartFailed`). A powered-off server is not started, and a failed restart never fails the change.

- **Plan change notice.** The WHMCS 9 plan-change pages (`upgrade`, `upgrade-configure`, `upgradesummary`, and `viewcart`/`checkout` when the cart holds a VirtFusionDirect service upgrade) for a customer's own service show an alert and, once per service per browser, a dismissible in-page modal: "Your server will restart automatically to apply this plan change once it is processed (usually under a minute of downtime). Please save your work." When the product offers keep-disk, a second line explains how to set "Disk on upgrade" to "Keep my current disk size" under Upgrade/Downgrade Options before upgrading (a product upgrade cannot choose options).

### Fixed

- **VNC console opens in Chromium again.** The console popup posts a hidden form into an `about:blank` window, which Chromium sends with `Origin: null` and no Referer, so `requireSameOrigin()` refused it as "missing origin". The verified WHMCS session CSRF token is now the binding check: a null or absent Origin with no Referer is accepted (logged once at debug level), while any explicit Origin or Referer naming another host is still refused.

- **Client routes return the right status for guard failures.** A POST without a CSRF token (or a missing permission) escaped to `client.php`'s outer catch and came back as HTTP 500 "An unexpected error occurred". RuntimeExceptions carrying 401/403/404/405/409/429 now keep their status and message, as in `ToolsRouter`; anything else is still a generic 500.

- **A rebuild typo no longer locks rebuild for a minute.** The operating-system ID and hostname are validated before the 60-second rebuild rate limit is stamped; ownership, provisioning, status and operation-lock guards still run first.

- **"Server busy" instead of a generic failure.** When VirtFusion answers 423 (another task holds the server), power actions, server password resets and rebuilds return 409 "The server is busy with another task. Try again in a minute." The client area now shows the server's error text for these actions.

- **The disk rule is enforced before the order and on every path.** Checkout (`ShoppingCartValidateCheckout`) refuses a cart product upgrade whose plan disk (`primaryStorage` on the service's panel, cached 10 min) is smaller than the server's current disk: "This plan has a smaller disk than your server. Disks can grow but never shrink, so this change is not available." It fails open on errors. ChangePackage now:
  - uses the larger of the effective storage and the primary disk's actual size as the current disk;
  - refuses an unknown target disk before the PUT;
  - adds a System Activity Log alert when it refuses a disk downgrade;
  - matches pending storage actions to the primary disk id;
  - after a keep-disk cancel and at the final verification, re-reads the primary disk and fails if it is unreadable or grew;
  - restarts only Active, non-suspended servers, at most once per VM per 2 minutes.

  Rebuild with an unreadable key list returns 502. The keep-disk option lookup is limited to the service's own rows and matches names without SQL wildcards.

- **Package changes are verified against pending actions.** VirtFusion leaves `settings.resources` at the old values after a package update and queues the change as a pending action (`payload.memory` / `payload.storage` / CPU `{current, new}`). Verification now uses the pending `new` value when one exists, so a memory or CPU downgrade waiting for the restart counts as applied (and the automatic restart runs) instead of being reported as "reduction not applied". In keep-disk mode the final read must show no queued storage resize.

- **Disk downgrades are refused before any VirtFusion write.** VirtFusion cannot shrink disks, so a package change to a smaller disk used to switch the WHMCS product and then fail at the panel with HTTP 422. ChangePackage now compares the target disk (package or Storage option) with the server's current disk, counting a queued but not yet applied disk growth as current, and refuses before the package update with "Package downgrades are not supported: the disk cannot be shrunk. Choose a plan with at least the current disk size." (logged as `changePackage:diskDowngradeRefused`). Memory and CPU reductions are still allowed and verified.

- **Admin route errors and duplicate operations.** `admin.php`'s outer catch now keeps CSRF/permission status codes (401/403/404/405/409/429) like `client.php`. A reused operation request ID returns the generic "This request was already submitted." instead of the internal operation ID and state (still logged).

- **A missing CSRF token is a 403 on WHMCS 9.** WHMCS 9's `check_token()` throws its own exception instead of returning false, so a POST without a token still returned HTTP 500. `Access::csrf()` now turns any failure (a thrown exception or `false`) into a 403 "Invalid CSRF token.".

- **An unreachable panel is a 503, not "service <> owner mismatch".** `verifyVmOwner()` separates a decision (404 or a mismatching identity or owner: 403) from a panel that could not be asked (transport error, timeout, other non-200, undecodable body: 503). `validateUserOwnsService()` rethrows the 503, so client routes, service tools and SSO answer "The control panel is temporarily unreachable. Please try again in a moment." Both cases fail closed and perform nothing. Any 503 reaching a customer now uses that generic text.

- **Panel requests ride out brief connect stalls.** The connect timeout drops from 10 s to 5 s, and a GET whose connection never got established is retried once (logged `Curl:retry`); writes are never retried. The new optional constant `VFD_PANEL_IP_RESOLVE` (`'v4'` or `'v6'`) forces one address family when the other path to the panel is broken.

### Upgrade notes

- New table `mod_virtfusion_direct_topoffs` (auto-created).
- Optional: `define('VFD_PANEL_IP_RESOLVE', 'v4');` in `configuration.php` when the WHMCS host's IPv6 path to the panel is unreliable.
- Configure WHMCS upgrade paths to larger plans only, or offer the `Disk on upgrade` keep-disk option (see README "Plan Changes"). The module refuses any package change that would shrink the disk.
- Package changes now restart running servers automatically so pending memory/CPU/disk changes apply (`restartOnPackageChange`, default on; turn it off per panel in Panel configuration). ChangePackage waits at most about 15 seconds after the restart.
- Clients cannot mix billing types: checkout and provisioning refuse a VirtFusion product whose self-service mode differs from the client's existing VirtFusion services.
- Unpaid VirtFusion-only orders are cancelled after 3 days by default (Provider settings → 0 to disable).
- An existing customer who logs in on the checkout form (rather than before checkout) with a conflicting product is not refused at checkout, so the response never reveals another account's services; the order is refused at provisioning, after payment, and needs a manual refund or product change. It is logged as `AccountType:checkoutLoginConflict`.
- List only products with the same Self-Service Mode (billing type) as upgrade/downgrade targets of each other. ChangePackage refuses a target whose billing type differs from the client's other VirtFusion services; WHMCS has already switched the product by then, so the operator must revert it.
- The file cache directory moves to `sys_get_temp_dir()/vfd_cache_<hash>`. The old `/tmp/vfd_cache` can be deleted.
- The file cache needs a private directory (owned by the PHP user, mode `0700`); otherwise it disables itself (logged once per process as `Cache:fileTierDisabled` in the module log) and rate limits then depend on Redis. Without ext-posix, the owner check uses a probe file. When neither Redis nor the file cache is usable, the admin services tab shows a red warning and a failed Test Connection appends it; nothing is written to the WHMCS activity log.
- New optional constant `VFD_REDIS_AUTH` (define in `configuration.php`) for Redis servers that require AUTH.
- The admin `serverData` route now requires POST and the `Perform Module Command Operations` permission.
- ChangePackage can now return an error when a downgrade was not applied.
- Auto top-off now creates WHMCS billable items.

## [1.6.2] - 2026-09-06

> **Tested against:** WHMCS 9.0.3 and VirtFusion v7.0.0 Build 9.

### Bug Fixes

- **Stock control ignored each hypervisor's default mountpoint, undercounting inventory.** When a package named a storage profile (`primaryStorageProfile > 0`), `StockControl::capForStorage()` scanned **only** `otherStorage[]` for a pool whose `storageType` matched, and returned from inside that branch — so `resources.localStorage`, the "Local (Default mountpoint)" pool, was never a candidate. But `localStorage` carries the same `storageType` field in the same numeric domain as every `otherStorage[]` entry; it is a first-class pool, not a fallback. Any hypervisor serving the product's storage from its default mountpoint therefore contributed `storeCap = 0`, and because per-hypervisor capacity is `min(memory, cpu, storage)` that zeroed the node entirely — silently, with no error. Reported from the field: on a two-node cluster where the same NVMe storage was the *default mountpoint* on one node and an *additional pool* on the other, the group's whole qty was derived from the single node that happened to expose it as an additional pool. Fix: search the default mountpoint alongside the additional pools, matching on `storageType` in both, via a new `storagePools()` helper; largest-fit selection, the disabled-pool skip, the "no pool of this type → 0" rule, and the `primaryStorageProfile <= 0` fallback are all unchanged.

  Confirmed against a live affected instance: `storageType` is a **medium/tier code that is consistent across hypervisors and independent of how the pool is attached** — on one node the default mountpoint was type 1 (SSD) with an additional NVMe pool of type 2, and on its sibling the default mountpoint was type 2 (NVMe) with an additional SSD pool of type 1. So matching `primaryStorageProfile` against `storageType` is correct, and the default mountpoint had to be a candidate for it to work.

- **Fatal `TypeError` when every resource on a hypervisor reported an unlimited quota.** `capFor()` returns `PHP_INT_MAX` for `max = 0` (unlimited), and `cpuCores.max = 0` is common in the wild. A hypervisor unlimited on memory, CPU *and* storage contributed `PHP_INT_MAX` to `groupCapacity()`'s running total; the next `+=` promoted the sum to float, and the `: int` return type then threw `Return value must be of type int, float returned`. `recalculateForProduct()` caught it, logged, and returned null — so the product's qty was silently never managed again, with only a log line to show for it. The sum now saturates at `PHP_INT_MAX` instead of overflowing, and a group that reports no bounded resource at all propagates as "unbounded" and leaves qty untouched under the existing fail-safe rather than inventing a ceiling. Where an IPv4 pool *is* reported it remains the binding cap, as before.

- **Hypervisor groups larger than 20 nodes were silently truncated.** `GET /compute/hypervisors/groups/{id}/resources` is paginated and serves **20 hypervisors per page** by default. `Module::fetchGroupResources()` sent no `results` parameter and read only the first page, so on a group of 30 nodes the last 10 contributed nothing to the capacity sum — stock was undercounted with no error, no log line, and no symptom other than a qty that looked plausible but was too low. The fetch now requests the documented maximum page size (200) and follows `last_page` when a group exceeds even that. If any page after the first fails, the whole fetch degrades to a transient null and qty is left untouched, rather than caching a truncated hypervisor list for the next two minutes.

### Diagnostics

- **Per-hypervisor stock breakdown in the module log.** Each group recalculation now logs `StockControl:groupBreakdown` with, per hypervisor, the memory/CPU/storage fit counts, free IPv4, and the resulting `min()` — plus an explicit reason for any hypervisor that was skipped. This makes it possible to see *which axis* is holding a qty down without shell access to the install. Costs nothing unless Module Debug Logging is enabled.

  Note for operators seeing an unexpected `qty = 0`: the safety buffer (configoption7, default 10%) reserves its percentage of each resource's **`max`**, not of what is free. A node already past that headroom — e.g. 96% memory allocated with a 10% buffer — contributes 0 by design, and memory commonly binds before storage does. The breakdown log now shows this directly.


## [1.6.1] - 2026-07-01

> **Tested against:** WHMCS 9.0.3 and VirtFusion v7.0.0 Build 9.

### Bug Fixes

- **Windows guests can now reset their password.** The server password reset only ever targeted `root`, so Windows Server templates (which use the `Administrator` account) could never reset. The reset now sends the target account to VirtFusion (`root` or `Administrator`, allow-listed server-side) and the client area picks the correct account from the guest OS. Thanks to **@ThakorRohan (FlashRDP)** for reporting and prototyping this in #9.

- **VNC console: eliminated sporadic `1006` connection drops.** Rotating the VNC token spins the console process up on the hypervisor; loading the noVNC bundle immediately could race that startup, and the wss proxy would drop the socket with a `1006`. The viewer now briefly delays the bundle load so the hypervisor binds the port first. Reimplemented with a strict per-request CSP **nonce** (no `'unsafe-inline'`). Timing fix contributed by **@ThakorRohan (FlashRDP)** in #9.

## [1.6.0] - 2026-06-30

> **Tested against:** WHMCS 9.0.3 and VirtFusion v7.0.0 Build 9.

### Bug Fixes

- **Critical: the daily `UsageUpdate` cron never completed on large fleets.** WHMCS calls `VirtFusionDirect_UsageUpdate()` once per control server, and the old loop fired one or two **synchronous** VirtFusion reads *per service*. The worst, `GET /servers/{id}?remoteState=true`, forces a live libvirt round-trip to the hypervisor for qemu-agent disk stats. On a 6000–7000 VPS fleet that meant thousands of serial calls, a large fraction stalling near the timeout — the job ran 20+ hours and was cut off by the next cron before finishing. The sync now batches the needed reads concurrently, polls only the metrics the operator selects, and processes services in memory-bounded chunks, so it completes in minutes.

- **Self-service auto top-off is now de-duplicated per user.** Top-off was applied once per *service*, but self-service credit is a per-VirtFusion-user **shared** balance — a client owning several self-service VPSes could have the balance credited multiple times in one run (e.g. amount 25 applied to balance 10 → 35 → 60…). It now tops off at most once per user per run; when a client has services across different top-off products, the strictest rule wins deterministically. Top-off was also decoupled from usage polling so disabling polling never silently stops billing.

### Features

- **Per-product "Usage Polling" option** (configoption8): `Disabled`, `Bandwidth only` (default), `Disk only`, or `Bandwidth + Disk`. The operator chooses the cost: bandwidth needs only one light `/servers/{id}/traffic` call (its `monthly[0]` carries both the used bytes and the GB limit), while the expensive `remoteState` call is incurred only by Disk/Both modes.
- **Bounded-concurrency batch reads** via a new static `Curl::multiGet()` (a `curl_multi` rolling window, same TLS hardening as the single-request client), collapsing wall-clock to ~O(count / concurrency).
- **Install-wide tunables** (define in WHMCS `configuration.php`):
  - `VFD_USAGE_UPDATE_MODE` — `off` | `bandwidth` | `disk` | `full`. Overrides the per-product option for every service; `off` is the emergency kill switch that makes the whole job a no-op.
  - `VFD_USAGE_UPDATE_CONCURRENCY` (default 10), `VFD_USAGE_UPDATE_TIMEOUT` (default 25s), `VFD_USAGE_UPDATE_CHUNK` (default 300).

### Upgrade Notes

- **Default polling is now Bandwidth-only**, so **disk usage (`diskused`/`disklimit`) is no longer auto-refreshed** unless a product is set to `Disk only` or `Bandwidth + Disk`. Bandwidth — the metric WHMCS most commonly bills on — stays current. To restore disk reporting, set the product's *Usage Polling* option. To stop all polling, choose *Disabled* (per product) or define `VFD_USAGE_UPDATE_MODE=off` (install-wide).
- **Minimum PHP is now declared as 8.0** in `composer.json` (matching WHMCS 8.x; WHMCS 9.x needs 8.2+). A new `composer php-compat` script runs a PHPCompatibility scan against the floor.

## [1.5.1] - 2026-06-02

> **Tested against:** WHMCS 9.0.3 and VirtFusion v7.0.0 Build 9.

### Bug Fixes

- **Critical: package upgrades/downgrades reported "Update package request failed. VirtFusion API returned HTTP 200" even though the change succeeded.** `ChangePackage` issues `PUT /servers/{id}/package/{packageId}`, and VirtFusion v7 answers a successful change with **HTTP 200** (carrying an `info[]` body describing what was/wasn't synced from the new package) — but the success whitelist only accepted **204**, so every real package change fell through to the default branch and surfaced a failure to WHMCS. Because that branch returns early, the follow-up Memory/CPU/Bandwidth tuning never ran either, and WHMCS recorded a failure for an operation VirtFusion had actually applied (state drift). Fixed by accepting 200 (and keeping 204 for older builds); the returned `info[]` array is now logged so an operator can see when the panel skipped part of a change.

- **Per-resource tuning during a package change silently failed on VirtFusion v7.** `modifyResource()` sent the CPU value under the field name `cpuCores`, but the `/servers/{id}/modify/cpuCores` endpoint requires the field `cores` — the request omitted the required property and was rejected by the API. It also only accepted HTTP 200/204 as success, while v7 returns **201 Created** on every `/modify/*` call (the same version shift already handled for the rename endpoint in 1.5.0). Both are fixed: CPU is now sent as `cores`, and 201 is added to the success whitelist for memory, CPU, and traffic. These failures had been masked by the HTTP 200 bug above (which returned before the tuning loop) and were swallowed silently — the loop now logs any `modifyResource()` failure instead of discarding it.

> **Disk-downgrade safety (unchanged, confirmed):** the module cannot shrink a primary disk and never could. It sends no disk flag on the package change, `modifyResource()` excludes storage entirely, no disk-modify endpoint exists, and VirtFusion itself refuses to lower a primary disk (_"primary disk not updated. It either matches or is lower than the current value"_). A downgrade leaves the disk at its larger size — there is no data-loss path.

### Documentation

- Vendored the VirtFusion v7 OpenAPI spec at `docs/openapi.yaml` for offline API reference when auditing endpoint behavior.

## [1.5.0] - 2026-04-28

> **Tested against:** WHMCS 9.0.3 and VirtFusion v7.0.0 Build 9.

### Features

- **In-page section navigation in the WHMCS Actions sidebar.** Customers viewing a VirtFusion service get an "On This Page" group in the sidebar with jump-links to every visible panel (Overview, Traffic, Live Stats, Power, Manage, Rebuild, Reverse DNS, Resources, Billing & Usage, Billing Overview). Rendered server-side via a `ClientAreaPrimarySidebar` hook that's gated to productdetails pages for VF services only — no impact on other modules. Each link smooth-scrolls to its target via a delegated click handler. Conditionally-shown panels (Live Stats when remoteState is unavailable, Reverse DNS when PowerDNS isn't configured, Resources/Self-Service before their data loads) auto-hide their corresponding sidebar links so customers don't see dead jumps. VNC is intentionally excluded — its panel is the first thing on the page already. Theme-agnostic — WHMCS handles sidebar rendering for Six, Twenty-One, Lagom, etc.

- **Traffic chart panel showing the last 12 months of monthly aggregates.** New panel between Server Overview and Power Management, sourced from `/servers/{id}/traffic`'s `monthly[]` array. Renders side-by-side rx (blue) and tx (green) bars per month with month-or-month/year labels at the bottom and a centered legend with breathing room. Current period's used/limit/remaining tile sits below the chart. Replaces the previously broken in-resources chart that read non-existent `data.entries` / `data.used` paths and silently never rendered for any service since it was first added.

- **Live Stats panel — CPU, memory, disk I/O with 30s auto-refresh.** Surfaces `remoteState.cpu`, `remoteState.memory.{actual,unused}`, and `remoteState.disk.vda.{rd.bytes,wr.bytes}` from VirtFusion's libvirt introspection. CPU and memory render as colored progress bars with `bg-warning` at 75% and `bg-danger` at 90%. Disk I/O shows cumulative bytes since boot. Refresh polls `serverData` every 30s while the panel is visible AND the page has focus — pauses on `visibilitychange` to avoid hammering libvirt when the customer alt-tabs away. Hidden entirely when the upstream call returns no remoteState block.

- **Filesystem usage in the Resources panel.** Per-mount usage rows sourced from qemu-guest-agent's fsinfo block (requires `qemu-guest-agent` installed on the VM). Pseudo-FS (proc/sysfs/devtmpfs/tmpfs/cgroup/etc.) plus `/boot*` and `/run*` are filtered out — only customer-meaningful mounts show. Each row has a progress bar with the same 75%/90% warning thresholds. Hidden when the agent isn't running, with a one-line hint that customers can install qemu-guest-agent to populate the section.

- **Server Overview meta bar — Location, OS, lifetime.** Top of the panel now shows three chips: data-center location with country flag emoji (from `hypervisor.group.{name,icon}`, country codes mapped via Regional Indicator Symbols), the OS template name preferring the qemu-agent's pretty name when available (with kernel version on hover), and a relative "Created N days ago" chip with the absolute timestamp on hover.

- **Hypervisor maintenance banner.** Yellow alert at the very top of the page when `hypervisor.maintenance=true`. Prepares the customer to expect "operation may be unavailable" errors so they don't open support tickets for what's actually known maintenance.

- **"Mask Sensitive" toggle for screenshot-safe viewing.** Button on the Server Overview meta bar. When active, masks IPv4 (keeps first two octets — `205.186.•••.•••`), IPv6 (keeps first two hextets — `2602:2f3:••••::•`), hostnames (keeps first char of each dot-separated label — `m•••.e••••••.c••`). Covers the Server Name input, Hostname row, IPv4/IPv6 cells in Server Overview, AND in the Reverse DNS panel rows. Input fields mask via CSS `text-security: disc` (preserves the underlying value for editing — focus reveals); text cells via attribute swap with the original cached on `data-vf-ip-original`. State persists in `sessionStorage` so screenshots taken across page refreshes stay masked.

- **Per-IP copy buttons in Server Overview cells.** Each IPv4 / IPv6 address renders as a row with a copy button (replaces the standalone Network panel that duplicated this information).

### Bug Fixes

- **Critical: UsageUpdate cron silently never recorded any usage.** `VirtFusionDirect_UsageUpdate()` was reading `server.usage.traffic.used` and `server.usage.storage.used` — neither path exists in any VirtFusion API response, so `tblhosting.bwused` and `diskused` stayed at 0 forever for every service. Bandwidth now sourced from `/servers/{id}/traffic`'s `monthly[0].total` (canonical billing-period bytes); disk usage from `/servers/{id}?remoteState=true`'s `agent.fsinfo[]` summed (best-effort — only updates when qemu-guest-agent is running). Limits (`settings.resources.{storage,traffic}`) were already correct and untouched. Verified on live data: usage columns now populate correctly, unblocking suspend-on-overage rules and customer-facing usage bars.

- **Critical: WHMCS 9 multi-service order short-circuit.** WHMCS 9 added a batch order-acceptance loop that terminates as soon as the order leaves Pending status — calling `localAPI('AcceptOrder')` mid-batch (which our `AfterModuleCreate` hook did to advance the order) caused subsequent VF services in the same order to be skipped, leaving them Active in `tblhosting` but with no `mod_virtfusion_direct` row and no actual VPS in VirtFusion. Fix: defer the auto-accept until every VF service in the order has been provisioned. The hook fires once per service, so the last one to complete sees no unprovisioned siblings and triggers the actual accept. WHMCS 8 was unaffected (its loop ignored order status mid-batch); deferring there is harmless.

- **Service detail page returned generic 500s for orphaned services.** When a service exists in `tblhosting` but has no row (or NULL `server_id`) in `mod_virtfusion_direct` — e.g., the multi-service order bug above, or a failed CreateAccount — every client.php endpoint silently bailed via `resolveServiceContext()` and the customer saw a string of generic 500s with no actionable message. Added `Module::requireProvisionedService()` helper that emits a single clean 409 ("Server has not been provisioned yet. Please contact support if this is unexpected.") on the first endpoint call. Wired into all 17 client.php cases after `validateUserOwnsService()` so customers see one clear message instead of six broken sections.

- **Traffic display in Server Overview showed `- / Unlimited` even for servers with measured usage.** Same root cause as the UsageUpdate bug: `ServerResource::process()` read the non-existent `usage.traffic.used` path. Fixed by having `Module::fetchServerData()` make a secondary call to `/servers/{id}/traffic` and merge the current period's total bytes onto the server object as `trafficUsedBytes`; ServerResource reads from that stable field. Also renamed "Unlimited" → "Unmetered" everywhere — limit=0 means no cap, but traffic is still tracked per period, so Unmetered is more accurate.

- **VNC viewer popup didn't render — wrong URL pattern.** The `/vnc/?token=...` URL VirtFusion returns is the raw WebSocket endpoint and rejects HTTP GET with 405. The actual noVNC viewer is a tiny HTML shell that loads `<vfBaseUrl>/vnc/vnc.js`. Fixed by serving the shell ourselves from a same-origin authenticated PHP route (`client.php?action=vncViewer`) — see the Security section below for the full shape.

- **VNC toggle was lying and was removed.** The PHP `Module::toggleVnc()` was POSTing `{enabled: bool}` to VirtFusion when the API parameter is actually `vnc: bool` — silent no-op. Even after fixing the param, the API's `vnc.enabled` response field stayed `false` regardless of toggle state, and the wss endpoint accepts connections regardless of the panel toggle (per VirtFusion's current implementation, the toggle only manages a firewall flag that's currently broken at their panel level). Toggle removed entirely; VNC is treated as always-available, gated by WHMCS session + service ownership at our layer.

- **OS gallery's "Other" category icon was hardcoded to a generic SVG.** Forced override in `Module::groupOsTemplates()` and matching branches in `module.js` and `hooks.php` were nulling out the icon VirtFusion provides for the category. Reverted to use the upstream icon — applies in both the client-area Rebuild gallery and the checkout-side OS picker. Singleton-collected templates (those merged into a synthetic "Other" bucket) inherit the icon from VF's "Other" category if one was present in the source data.

- **Save button squished on the Server Name rename row.** Single flex row with 6px gap and a 200px max-width input was packing the randomise + save buttons into too-narrow columns on mid-width viewports. Wrapped the inputs in a flex-wrap container with explicit min-widths so all three controls stay readable down to mobile.

- **Server rename was force-lowercasing the input.** Customer typed "VPS-01", got "vps-01" back. Both client and server validation enforced an RFC-1123-style hostname regex, but VirtFusion's `name` field is a display label that accepts any printable string up to 63 chars. Validation relaxed to non-empty + length cap + reject control characters. Mid-flight, the input field, Save, and Randomise buttons all freeze together so customers can't double-submit or edit while the rename is in flight.

- **Server rename hit the wrong VirtFusion endpoint** (and silently treated success as failure). The PHP module was using `PATCH /servers/{id}/name`, which VirtFusion v7 returns 404 for — the path moved to `PUT /servers/{id}/modify/name` (consistent with `/modify/memory`, `/modify/cpu`, etc.). Even after fixing the URL, success was treated as failure because v7 returns HTTP 201 (Created) on rename and our success whitelist only accepted 200/204. Fixed both: switched to PUT + new path, added 201 to the whitelist.

### Security

- **CSRF protection added to every destructive client.php action.** `rebuild`, `resetPassword`, `resetServerPassword`, `powerAction`, `rename`, `selfServiceAddCredit`, `toggleVnc`, and `vncViewer` now require `requirePost()` + `requireSameOrigin()`. Closes a class of attacks where a malicious page (or compromised ad slot) could embed a hidden form targeting `client.php?serviceID=X&action=rebuild` and destroy the customer's data on the next page load they made while logged in. Previously only `rdnsUpdate` carried these gates.

- **Open-redirect defence on SSO.** `Module::fetchLoginTokens()` now validates that the URL constructed from VirtFusion's `endpoint_complete` resolves to the same hostname as the configured VirtFusion panel before returning it for redirect. Defends against a hostname-mismatched response (compromised VF panel, tampered `tblservers` row, etc.) being used to phish customers.

- **VNC viewer popup served from a same-origin authenticated POST route.** Click → hidden form-submit (POST) to `client.php?action=vncViewer` → `requirePost()` + `requireSameOrigin()` + `isAuthenticated()` + `validateUserOwnsService()` + `requireProvisionedService()` → POST `/vnc {vnc:true}` to rotate the wss token → return `text/html` with the noVNC shell embedded. The wss URL never appears in any URL bar, browser history, or shareable link — it lives only in the HTTP response body delivered to a logged-in session. Each open rotates the token, so any prior credential exposure is short-lived. Response carries `X-Frame-Options: DENY`, `Cache-Control: no-store, private`, and a strict `Content-Security-Policy` (`default-src 'none'`, `script-src 'self' <vf-panel>`, `connect-src wss://<vf-panel> <vf-panel>`, `frame-ancestors 'none'`) so the viewer cannot be re-hosted, embedded, or opened in a way that loads scripts from anywhere outside our WHMCS host or the configured VirtFusion panel.

- **Per-action rate limiting** on destructive endpoints via the new `Module::requireRateLimit()` helper (Cache-backed, Redis-or-filesystem). Limits: `rebuild` 60 s, `resetPassword` / `resetServerPassword` 30 s, `powerAction` 10 s, `vncViewer` / `toggleVnc` / `selfServiceAddCredit` 5 s — all per-(action, serviceID). Defence against runaway browser scripts and accidental double-submits hammering the VirtFusion API. Returns 429 with a clear "Too many requests" message instead of letting the request through.

- **IP and hostname masking covers screenshot-sensitive cells.** See the Mask Sensitive feature above — reduces leakage when customers screen-share or attach screenshots for support.

- **IPv6 examples in the Reverse DNS placeholder + comment switched to the IANA documentation prefix `2001:db8::/32`** (RFC 3849), eliminating an inadvertent reference to a deployer-specific IPv6 block that previously appeared in customer-facing UI.

### Removed

- **Network panel** (full removal) — duplicated Server Overview's IP rows. Per-IP copy buttons moved into the Overview cells via `vfRenderIpCells()`.
- **Network Speed row** from the Resources panel — VirtFusion's `inAverage`/`inPeak`/`inBurst` and matching out-fields all return 0 in our setup (network speed isn't capped at the package level), so the row was always empty.
- **VNC enable/disable toggle** — see Bug Fixes above.

### Internal

- **`Module::fetchServerData()` now passes `?remoteState=true`** on the upstream call so the response includes live CPU/memory, disk I/O counters, and qemu-agent fsinfo. Adds one libvirt round-trip per page load on the hypervisor side; revisit caching if hypervisor load becomes a concern.
- **`ServerResource::process()`** exposes new fields: `osName`, `osPretty`, `osKernel`, `osDistro`, `osIcon`, `location`, `locationIcon`, `hypervisorMaintenance`, `createdAt`, `builtAt`, plus a nested `live.{state, cpu, memoryActualKB, memoryUnusedKB, memoryAvailableKB, memoryRssKB, diskRdBytes, diskWrBytes, filesystems[]}` block.
- **`Module::toggleVnc()`** corrected to post `{vnc: bool}` (the actual API parameter) instead of `{enabled: bool}` (silent no-op). Also returns `baseUrl` alongside the API envelope so the new vncViewer route can build the wss URL without re-deriving it.
- **`Module::getVncConsole()`** also returns `baseUrl` for the same reason.
- **Panel margins tightened** from `mb-3` (16px) to `mb-2` (8px) across all 11 client-area panels for a tighter visual rhythm post-additions.

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
