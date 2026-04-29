<link href="{$systemURL}modules/servers/VirtFusionDirect/templates/css/module.css?v={$smarty.now}" rel="stylesheet">
<script src="{$systemURL}modules/servers/VirtFusionDirect/templates/js/module.js?v={$smarty.now}"></script>

{if $serviceStatus eq 'Active'}

{* Hypervisor maintenance banner — populated by vfServerData. Hidden by
   default; surfaces only when hypervisor.maintenance=true so the customer
   knows operations may be unavailable. *}
<div id="vf-maintenance-banner" class="alert alert-warning mb-3" style="display:none;">
    <strong>Hypervisor maintenance.</strong>
    Your server's hypervisor is currently in maintenance. Some operations may be temporarily unavailable.
</div>

{* VNC Console — placed at the very top so it's the first action the
   customer reaches. No toggle (VirtFusion's VNC enable/disable was a
   broken firewall flag), no IP/port/password panel — just the button.
   Click → noVNC popup. *}
<div id="vf-vnc-panel" class="panel card panel-default mb-2">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">VNC Console</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-vnc-alert" class="alert" style="display: none;"></div>
        <p class="mb-3">Access your server's console directly in your browser. The server must be running for VNC access.</p>
        <button id="vf-vnc-button" onclick="vfOpenVnc('{$serviceid}','{$systemURL}')" type="button" class="btn btn-primary d-flex align-items-center">
            <span id="vf-vnc-spinner" class="spinner-border spinner-border-sm vf-spinner-margin" style="display:none;"></span>
            Open Console
        </button>
    </div>
</div>

{* Section navigation moved to the WHMCS Actions sidebar via the
   ClientAreaPrimarySidebar hook in hooks.php. The sidebar version stays
   visible while scrolling, which the inline strip never could. JS still
   walks the rendered links and hides ones whose target panels are hidden. *}

{* Server Overview Panel *}
<div id="vf-sec-overview" class="panel card panel-default mb-2" data-vf-nav-label="Overview">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">
            Server Overview
            <span id="vf-status-badge" class="vf-badge" style="float: right;"></span>
        </h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-action-progress" style="display:none;">
            <div class="spinner-border spinner-border-sm text-light"></div>
            <span id="vf-action-progress-text"></span>
            <span id="vf-action-progress-timer" class="ml-auto" style="margin-left:auto;"></span>
        </div>
        <div id="vf-server-info-loader-container">
            <div id="vf-server-info-loader">
                <div class="row">
                    <div class="col-md-6">
                        <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-medium"></div>
                        <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-short"></div>
                        <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-medium"></div>
                        <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-short"></div>
                    </div>
                    <div class="col-md-6">
                        <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-medium"></div>
                        <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-short"></div>
                        <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-medium"></div>
                        <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-short"></div>
                    </div>
                </div>
            </div>
        </div>
        <script>vfServerData('{$serviceid}', '{$systemURL}');</script>
        <div id="vf-server-info-error">
            <div class="alert alert-warning mb-0">Information unavailable. Try again later.</div>
        </div>

        {* Top meta bar — populated by JS once server data loads. Holds the
           data-center chip (flag + city), OS chip, lifetime chip, and the
           Mask IPs toggle. The toggle stays visible on every overview load
           regardless of which other chips have data. *}
        <div id="vf-overview-meta" class="vf-overview-meta mb-3" style="display:none;">
            <span id="vf-data-location" class="vf-meta-chip" style="display:none;"></span>
            <span id="vf-data-os" class="vf-meta-chip" style="display:none;"></span>
            <span id="vf-data-created" class="vf-meta-chip vf-meta-chip-muted" style="display:none;"></span>
            <button id="vf-mask-ips-btn" type="button" class="btn btn-sm btn-outline-secondary vf-mask-ips-btn" onclick="vfToggleIpMask()" title="Hide IPs and rDNS hostnames for screenshots">
                <span id="vf-mask-ips-label">Mask Sensitive</span>
            </button>
        </div>

        <div id="vf-server-info" class="row mb-2">
            <div class="col-12">
                <div class="row">
                    <div class="col-md-6">
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">Name:</div>
                            <div class="col-xs-8 col-8">
                                <div class="vf-rename-row">
                                    <input type="text" id="vf-rename-input" class="form-control form-control-sm vf-rename-input-field vf-sensitive" maxlength="63" placeholder="Server name">
                                    <button id="vf-randomise-btn" onclick="vfShowNameDropdown('{$serviceid}','{$systemURL}')" type="button" class="btn btn-sm btn-outline-secondary vf-rename-btn-randomise" title="Randomise">&#x21bb;</button>
                                    <button id="vf-rename-save" onclick="vfRenameServer('{$serviceid}','{$systemURL}')" type="button" class="btn btn-sm btn-primary vf-rename-btn-save">Save</button>
                                </div>
                                <div id="vf-name-dropdown" style="display:none;"></div>
                                <div id="vf-rename-alert" class="mt-1" style="display:none;"></div>
                            </div>
                        </div>
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">Hostname:</div>
                            <div class="col-xs-8 col-8 vf-sensitive" id="vf-data-server-hostname"></div>
                        </div>
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">Memory:</div>
                            <div class="col-xs-8 col-8" id="vf-data-server-memory"></div>
                        </div>
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">CPU:</div>
                            <div class="col-xs-8 col-8" id="vf-data-server-cpu"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">IPv4:</div>
                            <div class="col-xs-8 col-8" id="vf-data-server-ipv4"></div>
                        </div>
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">IPv6:</div>
                            <div class="col-xs-8 col-8" id="vf-data-server-ipv6"></div>
                        </div>
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">Storage:</div>
                            <div class="col-xs-8 col-8" id="vf-data-server-storage"></div>
                        </div>
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">Traffic:</div>
                            <div class="col-xs-8 col-8">
                                <span id="vf-data-server-traffic-used"></span>
                                <span id="vf-data-server-traffic-sep"> / </span>
                                <span id="vf-data-server-traffic"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {* Server Overview footer — Login to Control Panel SSO. Was briefly
           moved to the WHMCS Actions sidebar via _CustomActions, but the
           sidebar dispatch path didn't carry the SSO redirect through cleanly
           in this WHMCS 9 install. Inline button is reliable: vfLoginAsServerOwner
           opens a new tab and navigates it to the upstream SSO URL fetched
           via fetchLoginTokens. *}
        <div id="vf-overview-footer" class="vf-overview-footer mt-3 pt-3" style="border-top:1px solid #e6e8eb;">
            <div id="vf-login-error" class="alert alert-danger" style="display:none;"></div>
            <button id="vf-login-button" onclick="vfLoginAsServerOwner('{$serviceid}','{$systemURL}',true)" type="button" class="btn btn-primary d-flex align-items-center">
                <span id="vf-login-button-spinner" class="spinner-border spinner-border-sm text-light vf-spinner-margin" style="display:none;"></span>
                Login to Control Panel
            </button>
            <p class="mb-0 mt-2 vf-small text-muted">Opens VirtFusion in a new tab. Trouble? <a href="#" onclick="vfLoginAsServerOwner('{$serviceid}','{$systemURL}',false); return false;">Open in this tab instead</a>.</p>
        </div>
    </div>
</div>

{* Traffic Panel — last N months of monthly aggregates from VF. Renders
   full-width (own row) — side-by-side with Live Stats was tested and felt
   too cramped. *}
<div id="vf-sec-traffic" class="panel card panel-default mb-2" style="display:none;" data-vf-nav-label="Traffic">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Traffic</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-traffic-chart-section">
            <canvas id="vf-traffic-chart" style="width:100%; height:240px;"></canvas>
            <div class="row mt-3 text-center">
                <div class="col-4"><small class="text-muted">This Period Used</small><div id="vf-traffic-used" class="vf-bold">-</div></div>
                <div class="col-4"><small class="text-muted">Period Limit</small><div id="vf-traffic-limit" class="vf-bold">-</div></div>
                <div class="col-4"><small class="text-muted">Remaining</small><div id="vf-traffic-remaining" class="vf-bold">-</div></div>
            </div>
        </div>
        <script>
        if (typeof vfLoadTrafficStats === 'function') {
            vfLoadTrafficStats('{$serviceid}', '{$systemURL}');
        }
        </script>
    </div>
</div>

{* Live Stats Panel — CPU, memory, disk I/O sourced from VirtFusion's
   ?remoteState=true introspection (libvirt + qemu-agent). Hidden by default;
   surfaces only when the upstream call returns a remoteState block. Auto-
   refreshes every 30s; refresh stops when the panel scrolls out of view to
   keep hypervisor load proportional to actual customer attention. *}
<div id="vf-sec-livestats" class="panel card panel-default mb-2" style="display:none;" data-vf-nav-label="Live Stats">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">
            Live Stats
            <small class="text-muted vf-livestats-updated" id="vf-live-updated" style="float:right; font-size:11px; font-weight:normal;"></small>
        </h3>
    </div>
    <div class="panel-body card-body p-4">
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="vf-bold mb-2">CPU</div>
                <div class="vf-live-gauge">
                    <div class="vf-live-bar"><div id="vf-live-cpu-bar" class="vf-live-bar-fill" style="width:0%;"></div></div>
                    <div class="d-flex justify-content-between vf-small mt-1">
                        <span id="vf-live-cpu-pct">-</span>
                        <span class="text-muted">load</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="vf-bold mb-2">Memory</div>
                <div class="vf-live-gauge">
                    <div class="vf-live-bar"><div id="vf-live-mem-bar" class="vf-live-bar-fill" style="width:0%;"></div></div>
                    <div class="d-flex justify-content-between vf-small mt-1">
                        <span id="vf-live-mem-text">-</span>
                        <span id="vf-live-mem-pct" class="text-muted">-</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="vf-bold mb-2">Disk I/O <small class="text-muted">(since boot)</small></div>
                <div class="d-flex justify-content-between vf-small">
                    <span class="text-muted">Read</span>
                    <span id="vf-live-disk-rd">-</span>
                </div>
                <div class="d-flex justify-content-between vf-small mt-1">
                    <span class="text-muted">Write</span>
                    <span id="vf-live-disk-wr">-</span>
                </div>
            </div>
        </div>
    </div>
</div>

{* Power Management Panel *}
<div id="vf-sec-power" class="panel card panel-default mb-2" data-vf-nav-label="Power">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Power Management</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-power-alert" class="alert" style="display: none;"></div>
        <div class="row">
            <div class="col-12">
                <div class="vf-power-buttons">
                    <button id="vf-power-boot" onclick="vfPowerAction('{$serviceid}','{$systemURL}','boot')" type="button" class="btn btn-success vf-btn-power">
                        <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                        Start
                    </button>
                    <button id="vf-power-restart" onclick="vfPowerAction('{$serviceid}','{$systemURL}','restart')" type="button" class="btn btn-warning vf-btn-power">
                        <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                        Restart
                    </button>
                    <button id="vf-power-shutdown" onclick="vfPowerAction('{$serviceid}','{$systemURL}','shutdown')" type="button" class="btn btn-info vf-btn-power">
                        <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                        Shutdown
                    </button>
                    <button id="vf-power-poweroff" onclick="vfPowerAction('{$serviceid}','{$systemURL}','poweroff')" type="button" class="btn btn-danger vf-btn-power">
                        <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                        Force Off
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{* Manage Panel *}
<div id="vf-sec-manage" class="panel card panel-default mb-2" data-vf-nav-label="Manage">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Manage</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div class="row">
            {* Inline "Open Control Panel" button removed — WHMCS already
               surfaces this in the Actions sidebar via the module's
               ServiceSingleSignOnLabel ("Login to VirtFusion Panel").
               Keeping both was a duplicate. *}
            {if $serverHostname}
            <div class="col-12">
                <hr>
                <div id="vf-password-reset-error" class="alert alert-danger">Oops! Something went wrong. Try again later.</div>
                <div id="vf-password-reset-success" class="alert alert-success">
                    <div class="mb-2 font-weight-bold">Your new login credentials. These will only be displayed once.</div>
                    <div class="font-weight-bold">Email: <span class="font-weight-normal" id="vf-data-user-email"></span></div>
                    <div class="font-weight-bold">Password: <span class="font-weight-normal" id="vf-data-user-password"></span></div>
                </div>
                <p class="pt-0">Alternatively you may directly access the control panel at <a target="_blank" href="https://{$serverHostname|escape:'htmlall'}">{$serverHostname|escape:'htmlall'}</a></p>
                <button id="vf-password-reset-button" onclick="vfUserPasswordReset('{$serviceid}','{$systemURL}')" type="button" class="btn btn-primary text-uppercase d-flex align-items-center">
                    <div id="vf-password-reset-button-spinner" class="spinner-border spinner-border-sm text-light vf-spinner-margin"></div>
                    Reset Login Credentials
                </button>
            </div>
            {/if}
            <div class="col-12">
                <hr>
                <div id="vf-server-password-alert" class="alert" style="display:none;"></div>
                <p class="vf-small text-muted">Reset the server's root password. The new password will be copied to your clipboard automatically.</p>
                <button id="vf-server-password-btn" onclick="vfResetServerPassword('{$serviceid}','{$systemURL}')" type="button" class="btn btn-warning text-uppercase d-flex align-items-center">
                    <span id="vf-server-password-spinner" class="spinner-border spinner-border-sm vf-spinner-margin" style="display:none;"></span>
                    Reset Server Password
                </button>
            </div>
            <div class="col-12" id="vf-backups-section" style="display:none;">
                <hr>
                <h5 class="vf-bold">Backups</h5>
                <div id="vf-backups-loader"><div class="spinner-border spinner-border-sm"></div></div>
                <div id="vf-backups-timeline" class="vf-timeline"></div>
                <button id="vf-backups-show-all" class="btn btn-sm btn-link" style="display:none;" onclick="$('.vf-timeline-item-hidden').show(); $(this).hide();">Show all</button>
            </div>
            <script>
            if (typeof vfLoadBackups === 'function') {
                vfLoadBackups('{$serviceid}', '{$systemURL}');
            }
            </script>
        </div>
    </div>
</div>

{* Rebuild Panel *}
<div id="vf-sec-rebuild" class="panel card panel-default mb-2" data-vf-nav-label="Rebuild">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Rebuild Server</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-rebuild-alert" class="alert" style="display: none;"></div>
        <div class="alert alert-warning">
            <strong>Warning:</strong> Rebuilding your server will erase all data on the server and reinstall the operating system. This action cannot be undone.
        </div>
        <input type="hidden" id="vf-rebuild-os" value="">
        <div class="form-group mb-3">
            <label>Operating System</label>
            <input type="text" id="vf-os-search" class="form-control vf-os-search" placeholder="Search templates...">
        </div>
        <div id="vf-os-gallery-loader" class="mb-3">
            <div class="vf-skeleton" style="height:120px;"></div>
        </div>
        <div id="vf-os-gallery" class="mb-3" style="display:none;"></div>
        <div id="vf-os-details" class="mb-3" style="display:none;"></div>
        <button id="vf-rebuild-button" onclick="vfRebuildServer('{$serviceid}','{$systemURL}')" type="button" class="btn btn-danger text-uppercase d-flex align-items-center">
            <span id="vf-rebuild-spinner" class="spinner-border spinner-border-sm vf-spinner-margin" style="display:none;"></span>
            Rebuild Server
        </button>
        <script>vfLoadOsTemplates('{$serviceid}', '{$systemURL}');</script>
    </div>
</div>

{* The standalone Network panel was removed — its IP list duplicated the
   Server Overview's IPv4/IPv6 rows. The unique value (per-IP copy buttons)
   was folded into the Overview cells via vfRenderIpCells in module.js. *}

{if $rdnsEnabled}
{* Reverse DNS Panel *}
<div id="vf-sec-rdns" class="panel card panel-default mb-2" data-vf-nav-label="Reverse DNS">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Reverse DNS</h3>
    </div>
    <div class="panel-body card-body p-4">
        <p class="vf-small text-muted mb-3">Set a custom PTR record for each assigned IP. Forward DNS (A/AAAA) for the hostname must already resolve to the IP before the PTR can be saved.</p>
        <div id="vf-rdns-alert" class="alert" style="display:none;"></div>
        <div id="vf-rdns-list">
            <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-medium"></div>
            <div class="vf-skeleton vf-skeleton-line vf-skeleton-line-medium"></div>
        </div>
        <script>
        if (typeof vfLoadRdns === 'function') {
            vfLoadRdns('{$serviceid}', '{$systemURL}');
        }
        </script>
    </div>
</div>
{/if}

{* Resources Panel — populated by JS after server data loads *}
<div id="vf-resources-panel" class="panel card panel-default mb-2" style="display: none;" data-vf-nav-label="Resources">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Resources</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div class="row">
            <div class="col-md-6">
                <div class="vf-resource-item mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="vf-bold">Memory</span>
                        <span id="vf-res-memory"></span>
                    </div>
                </div>
                <div class="vf-resource-item mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="vf-bold">CPU Cores</span>
                        <span id="vf-res-cpu"></span>
                    </div>
                </div>
                <div class="vf-resource-item mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="vf-bold">Storage</span>
                        <span id="vf-res-storage"></span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="vf-resource-item mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="vf-bold">Traffic</span>
                        <span id="vf-res-traffic"></span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div id="vf-res-traffic-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>
        {* Note: dedicated Traffic panel near the top of the page (vf-sec-traffic)
           handles the chart + period tile. Resources panel here just lists the
           configured limits — no chart duplication. Network speed row was
           removed: VirtFusion's API returns 0 for inAverage/inPeak/inBurst
           when speed isn't capped at the package level, which is the
           common case for our setup — there's nothing useful to show. *}

        {* Filesystem usage — only renders when qemu-guest-agent is running on
           the guest. vfRenderFilesystems() shows or hides the section based
           on whether remoteState.agent.fsinfo came back populated. *}
        <div id="vf-fs-section" class="mt-4" style="display:none;">
            <hr>
            <h5 class="vf-bold mb-3">Filesystem Usage</h5>
            <div id="vf-fs-container"></div>
            <p class="vf-small text-muted mt-2 mb-0">Reported by qemu-guest-agent inside the VM. Install <code>qemu-guest-agent</code> if no filesystems show.</p>
        </div>
    </div>
</div>

{* VNC panel relocated to the very top of the page (above Server Overview).
   See its definition there. This block is intentionally left as a comment
   marker so future readers know where the panel used to live. *}

{* Self Service — Billing & Usage Panel *}
{if $selfServiceMode > 0}
<div id="vf-selfservice-panel" class="panel card panel-default mb-2" style="display: none;" data-vf-nav-label="Billing & Usage">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Billing & Usage</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-selfservice-alert" class="alert" style="display: none;"></div>
        <div id="vf-selfservice-loader" class="d-flex align-items-center justify-content-center" style="min-height: 60px;">
            <div class="spinner-border spinner-border-sm"></div>
        </div>
        <div id="vf-selfservice-content" style="display: none;">
            <div class="row mb-3">
                <div class="col-md-6">
                    <h5 class="vf-bold">Credit Balance</h5>
                    <div class="h4 mb-3" id="vf-ss-credit-balance">-</div>
                </div>
                <div class="col-md-6">
                    <h5 class="vf-bold">Add Credit</h5>
                    <div class="input-group mb-2">
                        <input type="number" id="vf-ss-credit-amount" class="form-control" placeholder="Amount" min="1" step="1">
                        <div class="input-group-append input-group-btn">
                            <button id="vf-ss-add-credit-btn" onclick="vfAddCredit('{$serviceid}','{$systemURL}')" type="button" class="btn btn-primary">
                                <span id="vf-ss-add-credit-spinner" class="spinner-border spinner-border-sm" style="display:none;"></span>
                                Add Credit
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <h5 class="vf-bold">Usage Breakdown</h5>
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="text-right">Cost</th>
                        </tr>
                    </thead>
                    <tbody id="vf-ss-usage-table">
                        <tr><td colspan="2" class="text-muted">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <script>vfLoadSelfServiceUsage('{$serviceid}', '{$systemURL}');</script>
    </div>
</div>
{/if}

{elseif $serviceStatus eq 'Suspended'}

<div class="panel card panel-default mb-2">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Service Suspended</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div class="alert alert-danger mb-0">
            Your service is currently suspended. Please contact support or pay any outstanding invoices to restore access.
        </div>
    </div>
</div>

{/if}

{* Billing Overview - Always visible *}
<div id="vf-sec-billing" class="panel card panel-default mb-2" data-vf-nav-label="Billing Overview">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Billing Overview</h3>
    </div>
    <div class="panel-body card-body">
        <div class="row">
            <div class="col-lg-6">
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">Product:</div>
                    <div class="col-xs-6 col-6">{$groupname|escape:'htmlall'} - {$product|escape:'htmlall'}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.recurringamount}:</div>
                    <div class="col-xs-6 col-6">{$recurringamount|escape:'htmlall'}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.orderbillingcycle}:</div>
                    <div class="col-xs-6 col-6">{$billingcycle|escape:'htmlall'}</div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.clientareahostingregdate}:</div>
                    <div class="col-xs-6 col-6">{$regdate|escape:'htmlall'}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.clientareahostingnextduedate}:</div>
                    <div class="col-xs-6 col-6">{$nextduedate|escape:'htmlall'}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.orderpaymentmethod}:</div>
                    <div class="col-xs-6 col-6">{$paymentmethod|escape:'htmlall'}</div>
                </div>
            </div>
        </div>
    </div>
</div>
