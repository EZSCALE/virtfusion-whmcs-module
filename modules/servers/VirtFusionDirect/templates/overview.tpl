<link href="{$systemURL}modules/servers/VirtFusionDirect/templates/css/module.css?v={$smarty.now}" rel="stylesheet">
<script src="{$systemURL}modules/servers/VirtFusionDirect/templates/js/module.js?v={$smarty.now}"></script>
<script src="{$systemURL}modules/servers/VirtFusionDirect/templates/js/keygen.js?v={$smarty.now}"></script>

{if $serviceStatus eq 'Active'}

{* Hypervisor maintenance banner — populated by vfServerData. Hidden by
   default; surfaces only when hypervisor.maintenance=true so the customer
   knows operations may be unavailable. *}
<div id="vf-maintenance-banner" class="alert alert-warning mb-3" style="display:none;">
    <strong>Hypervisor maintenance.</strong>
    Your server's hypervisor is currently in maintenance. Some operations may be temporarily unavailable.
</div>

{* Section navigation moved to the WHMCS Actions sidebar via the
   ClientAreaPrimarySidebar hook in hooks.php. The sidebar version stays
   visible while scrolling, which the inline strip never could. JS still
   walks the rendered links and hides ones whose target panels are hidden. *}

{* Server Overview Panel *}
<div id="vf-sec-overview" class="vf-billing-card mb-4" data-vf-nav-label="Overview">
    <div class="vf-billing-card-title d-flex justify-content-between align-items-center mb-3">
        <span><i class="fas fa-desktop"></i> Server Overview</span>
        <span id="vf-status-badge" class="vf-badge"></span>
    </div>
    
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

    {* Top meta bar *}
    <div id="vf-overview-meta" class="vf-overview-meta mb-3" style="display:none; border:none; background:#f8fafc; padding:10px 14px; border-radius:10px;">
        <span id="vf-data-location" class="vf-meta-chip" style="display:none; border:none; box-shadow:0 1px 2px rgba(0,0,0,0.05);"></span>
        <span id="vf-data-os" class="vf-meta-chip" style="display:none; border:none; box-shadow:0 1px 2px rgba(0,0,0,0.05);"></span>
        <span id="vf-data-created" class="vf-meta-chip vf-meta-chip-muted" style="display:none;"></span>
        <button id="vf-mask-ips-btn" type="button" class="btn btn-sm vf-btn-ghost vf-mask-ips-btn font-weight-bold" onclick="vfToggleIpMask()" title="Hide IPs and rDNS hostnames for screenshots" style="margin-left:auto; background:#fff; border:1px solid #e2e8f0; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <span id="vf-mask-ips-label">Mask Sensitive</span>
        </button>
    </div>

    <div id="vf-server-info" style="display:none;" class="mb-3">
        <div class="vf-billing-grid">
            <div class="vf-billing-row">
                <span class="vf-billing-label">Name</span>
                <span class="vf-billing-value">
                    <div class="vf-rename-row" style="margin:-4px 0; max-width:280px;">
                        <input type="text" id="vf-rename-input" class="vf-rename-input-field vf-sensitive" maxlength="63" placeholder="Server name">
                        <button id="vf-randomise-btn" onclick="vfShowNameDropdown('{$serviceid}','{$systemURL}')" type="button" class="vf-rename-btn-randomise" title="Randomise">
                            <i class="fas fa-redo"></i>
                        </button>
                        <span class="vf-rename-divider"></span>
                        <button id="vf-rename-save" onclick="vfRenameServer('{$serviceid}','{$systemURL}')" type="button" class="vf-rename-btn-save" title="Save">
                            <i class="fas fa-check"></i>
                        </button>
                    </div>
                    <div id="vf-name-dropdown" style="display:none;"></div>
                    <div id="vf-rename-alert" class="mt-1" style="display:none;"></div>
                </span>
            </div>
            <div class="vf-billing-row" id="vf-row-hostname">
                <span class="vf-billing-label">Hostname</span>
                <span class="vf-billing-value text-truncate vf-sensitive" id="vf-data-server-hostname" title=""></span>
            </div>
            <div class="vf-billing-row" id="vf-row-ipv4">
                <span class="vf-billing-label">IPv4</span>
                <span class="vf-billing-value" id="vf-data-server-ipv4"></span>
            </div>
            <div class="vf-billing-row" id="vf-row-ipv6">
                <span class="vf-billing-label">IPv6</span>
                <span class="vf-billing-value text-truncate" id="vf-data-server-ipv6" title=""></span>
            </div>
        </div>
    </div>

    <div id="vf-quick-actions" class="pt-3" style="border-top:1px solid #f1f5f9;">
        <div class="d-flex flex-wrap align-items-center" style="gap: 10px;">
            <button id="vf-power-boot" onclick="vfPowerAction('{$serviceid}','{$systemURL}','boot')" type="button" class="btn btn-sm vf-btn-power vf-btn-power-start">
                <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                <i class="fas fa-play"></i> Start
            </button>
            <button id="vf-power-restart" onclick="vfPowerAction('{$serviceid}','{$systemURL}','restart')" type="button" class="btn btn-sm vf-btn-power vf-btn-power-restart">
                <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                <i class="fas fa-sync-alt"></i> Restart
            </button>
            <button id="vf-power-shutdown" onclick="vfPowerAction('{$serviceid}','{$systemURL}','shutdown')" type="button" class="btn btn-sm vf-btn-power vf-btn-power-stop">
                <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                <i class="fas fa-stop"></i> Stop
            </button>
            <button id="vf-power-poweroff" onclick="vfPowerAction('{$serviceid}','{$systemURL}','poweroff')" type="button" class="btn btn-sm vf-btn-power vf-btn-power-force" title="Force Off">
                <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                <i class="fas fa-power-off"></i> Power Off
            </button>
            
            <div class="mx-1 d-none d-sm-block" style="border-left: 1px solid #e2e8f0; height: 28px;"></div>

            <button id="vf-vnc-button" onclick="vfOpenVnc('{$serviceid}','{$systemURL}')" type="button" class="btn btn-sm vf-btn-ghost d-flex align-items-center font-weight-bold" style="color:#475569;">
                <span id="vf-vnc-spinner" class="spinner-border spinner-border-sm vf-spinner-margin" style="display:none;"></span>
                <i class="fas fa-terminal mr-2"></i> Console
            </button>

            <div class="dropdown">
                <button class="btn btn-sm vf-btn-ghost dropdown-toggle font-weight-bold" style="color:#475569;" type="button" id="vfSettingsDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-cog mr-2"></i> Settings
                </button>
                <div class="dropdown-menu shadow-sm" style="border:1px solid #e2e8f0; border-radius:10px; padding:8px; min-width:200px;" aria-labelledby="vfSettingsDropdown">
                    <a class="dropdown-item" style="border-radius:6px; font-size:13.5px; padding:8px 12px;" href="#" onclick="vfResetServerPassword('{$serviceid}','{$systemURL}'); return false;">Reset Root Password</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger" style="border-radius:6px; font-size:13.5px; padding:8px 12px;" href="#" onclick="vfUserPasswordReset('{$serviceid}','{$systemURL}'); return false;">Reset Control Panel Login</a>
                </div>
            </div>

            <button id="vf-login-button" onclick="vfLoginAsServerOwner('{$serviceid}','{$systemURL}',true)" type="button" class="btn btn-sm btn-primary d-flex align-items-center ml-auto font-weight-bold" style="border-radius:8px; padding:6px 14px;">
                <span id="vf-login-button-spinner" class="spinner-border spinner-border-sm text-light vf-spinner-margin" style="display:none;"></span>
                Control Panel <i class="fas fa-external-link-alt ml-2" style="font-size:0.75rem;"></i>
            </button>
        </div>
        
        <div id="vf-login-error" class="alert alert-danger mt-3 mb-0" style="display:none; border-radius:8px;"></div>
        <div id="vf-password-reset-error" class="alert alert-danger mt-3 mb-0" style="display:none; border-radius:8px;">Oops! Something went wrong. Try again later.</div>
        <div id="vf-password-reset-success" class="alert alert-success mt-3 mb-0" style="display:none; border-radius:8px;">
            <div class="mb-2 font-weight-bold">Your new login credentials. These will only be displayed once.</div>
            <div class="font-weight-bold">Email: <span class="font-weight-normal" id="vf-data-user-email"></span></div>
            <div class="font-weight-bold">Password: <span class="font-weight-normal" id="vf-data-user-password"></span></div>
        </div>
        <div id="vf-server-password-alert" class="alert mt-3 mb-0" style="display:none; border-radius:8px;"></div>
        <div id="vf-power-alert" class="alert mt-3 mb-0" style="display: none; border-radius:8px;"></div>
        <div id="vf-vnc-alert" class="alert mt-3 mb-0" style="display: none; border-radius:8px;"></div>
    </div>
</div>

{* Tabbed Interface Navigation *}
<ul class="nav nav-tabs mb-3 mt-3" id="vf-tabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" id="vf-tab-billing" data-toggle="tab" href="#vf-pane-billing" role="tab" aria-controls="vf-pane-billing" aria-selected="true">Billing</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="vf-tab-resources" data-toggle="tab" href="#vf-pane-resources" role="tab" aria-controls="vf-pane-resources" aria-selected="false">Resources</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="vf-tab-rebuild" data-toggle="tab" href="#vf-pane-rebuild" role="tab" aria-controls="vf-pane-rebuild" aria-selected="false">Rebuild OS</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="vf-tab-network" data-toggle="tab" href="#vf-pane-network" role="tab" aria-controls="vf-pane-network" aria-selected="false">Network</a>
    </li>
    <li class="nav-item" id="vf-tab-settings-nav" style="display:none;">
        <a class="nav-link" id="vf-tab-settings" data-toggle="tab" href="#vf-pane-settings" role="tab" aria-controls="vf-pane-settings" aria-selected="false">Settings</a>
    </li>
</ul>

<div class="tab-content" id="vf-tabs-content">
    
    {* Billing Tab *}
    <div class="tab-pane fade show active" id="vf-pane-billing" role="tabpanel" aria-labelledby="vf-tab-billing">
        <div class="vf-billing-card">
            <div class="vf-billing-card-title">
                <i class="fas fa-file-invoice-dollar"></i> Subscription Details
            </div>
            <div class="vf-billing-grid">
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Product</span>
                    <span class="vf-billing-value text-truncate" title="{$groupname|escape:'htmlall'} - {$product|escape:'htmlall'}">{$groupname|escape:'htmlall'} - {$product|escape:'htmlall'}</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Reg Date</span>
                    <span class="vf-billing-value">{$regdate|escape:'htmlall'}</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Cost</span>
                    <span class="vf-billing-value">{$recurringamount|escape:'htmlall'} ({$billingcycle|escape:'htmlall'})</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Due Date</span>
                    <span class="vf-billing-value">{$nextduedate|escape:'htmlall'} {$nextduedateremaining}</span>
                </div>
            </div>
        </div>

        {if $selfServiceMode > 0}
        <div id="vf-selfservice-loader" class="d-flex align-items-center justify-content-center mt-3" style="min-height: 60px;">
            <div class="spinner-border spinner-border-sm"></div>
        </div>
        <div id="vf-selfservice-content" class="vf-billing-card mt-3" style="display:none;">
            <div class="vf-billing-card-title">
                <i class="fas fa-coins"></i> Hourly Cloud Usage
            </div>
            <div class="d-flex align-items-end mb-3" style="gap:16px;">
                <div>
                    <div class="text-muted vf-small mb-1">Credit Balance</div>
                    <div class="h5 mb-0 font-weight-bold" id="vf-ss-credit-balance">-</div>
                </div>
                <div style="flex:1;max-width:220px;">
                    <div class="text-muted vf-small mb-1">Add Credit</div>
                    <div class="input-group input-group-sm">
                        <input type="number" id="vf-ss-credit-amount" class="form-control" placeholder="Amount" min="1" step="1">
                        <div class="input-group-append">
                            <button id="vf-ss-add-credit-btn" onclick="vfAddCredit('{$serviceid}','{$systemURL}')" type="button" class="btn btn-primary">
                                <span id="vf-ss-add-credit-spinner" class="spinner-border spinner-border-sm" style="display:none;"></span>
                                Add
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-muted vf-small mb-1">Usage Breakdown</div>
            <div class="table-responsive" style="max-height:160px;overflow-y:auto;">
                <table class="table table-sm table-striped mb-0">
                    <tbody id="vf-ss-usage-table">
                        <tr><td colspan="2" class="text-muted">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="vf-selfservice-alert" class="alert mt-2 mb-0" style="display:none;"></div>
        </div>
        <script>vfLoadSelfServiceUsage('{$serviceid}', '{$systemURL}');</script>
        {/if}
    </div>

    {* Resources Tab (Live Stats, Resource limits & Traffic) *}
    <div class="tab-pane fade" id="vf-pane-resources" role="tabpanel" aria-labelledby="vf-tab-resources">

        {* ── Card 1: Resource Limits + Live Performance + Filesystem ── *}
        <div class="vf-billing-card mb-4">

            {* Resource Limits grid *}
            <div class="vf-billing-card-title">
                <i class="fas fa-server"></i> Allocated Resources
            </div>
            <div class="vf-billing-grid">
                <div class="vf-billing-row">
                    <span class="vf-billing-label">CPU Cores</span>
                    <span class="vf-billing-value" id="vf-res-cpu">-</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Storage</span>
                    <span class="vf-billing-value" id="vf-res-storage">-</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Memory</span>
                    <span class="vf-billing-value" id="vf-res-memory">-</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Traffic</span>
                    <span class="vf-billing-value" id="vf-res-traffic">-</span>
                </div>
            </div>

            {* Filesystem section — shown only when qemu-guest-agent provides data *}
            <div id="vf-fs-section" style="display:none;">
                <hr style="margin: 20px 0; border-color: #f0f2f5;">
                <div class="vf-billing-card-title mb-3">
                    <i class="fas fa-hdd"></i> Filesystem Usage
                </div>
                <div id="vf-fs-container"></div>
                <p class="vf-small text-muted mt-2 mb-0">Reported by qemu-guest-agent inside the VM. Install <code>qemu-guest-agent</code> if no filesystems show.</p>
            </div>

            {* Live Performance *}
            <hr style="margin: 20px 0; border-color: #f0f2f5;">
            <div class="vf-billing-card-title mb-3">
                <i class="fas fa-chart-line"></i> Live Performance
                <small class="text-muted ml-auto" id="vf-live-updated" style="font-size:11px; font-weight:normal; margin-left:auto;"></small>
            </div>

            <div id="vf-livestats-container" style="display:none;">
                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="vf-billing-label" style="border:none;padding:0;">CPU Load</span>
                        <span id="vf-live-cpu-pct" class="vf-small text-muted">-</span>
                    </div>
                    <div class="progress" style="height:6px;">
                        <div id="vf-live-cpu-bar" class="progress-bar bg-primary" style="width:0%;"></div>
                    </div>
                </div>
                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-1">
                        <div>
                            <span class="vf-billing-label" style="border:none;padding:0;">Memory Usage</span>
                            <small class="text-muted ms-2" style="font-size:0.85em;" id="vf-live-mem-text">-</small>
                        </div>
                        <span id="vf-live-mem-pct" class="vf-small text-muted">-</span>
                    </div>
                    <div class="progress" style="height:6px;">
                        <div id="vf-live-mem-bar" class="progress-bar bg-primary" style="width:0%;"></div>
                    </div>
                </div>
                <div>
                    <div class="vf-billing-label mb-2" style="border:none;padding:0;">
                        Disk I/O <small class="text-muted" style="font-weight:normal;">(since boot)</small>
                    </div>
                    <div class="d-flex justify-content-between vf-small mb-1">
                        <span class="text-muted">Read</span>
                        <span id="vf-live-disk-rd">-</span>
                    </div>
                    <div class="d-flex justify-content-between vf-small">
                        <span class="text-muted">Write</span>
                        <span id="vf-live-disk-wr">-</span>
                    </div>
                </div>
            </div>

            <div id="vf-livestats-offline" class="text-muted vf-small text-center py-3" style="border:1px dashed #e0e3e8; border-radius:8px;">
                Live performance metrics are currently unavailable. The server might be offline.
            </div>

        </div>

    </div>


    {* Rebuild OS Tab *}
    <div class="tab-pane fade" id="vf-pane-rebuild" role="tabpanel" aria-labelledby="vf-tab-rebuild">
        <div class="vf-billing-card">

            {* ── Warning ── *}
            <div class="vf-billing-card-title" style="color:#b45309;">
                <i class="fas fa-exclamation-triangle" style="color:#d97706;"></i> Danger Zone
            </div>
            <div class="vf-rebuild-warning-box">
                <i class="fas fa-shield-alt vf-rebuild-warning-icon"></i>
                <div>
                    <strong>This action will permanently erase all data.</strong><br>
                    <span class="text-muted vf-small">Rebuilding reinstalls the operating system from scratch. All files, databases, and configurations on this server will be lost and cannot be recovered.</span>
                </div>
            </div>
            <div id="vf-rebuild-alert" class="alert mt-3 mb-0" style="display:none;"></div>

            {* ── OS Selection ── *}
            <hr style="margin: 20px 0; border-color: #f0f2f5;">
            <div class="vf-billing-card-title mb-3">
                <i class="fas fa-compact-disc"></i> Select Operating System
            </div>

            <input type="hidden" id="vf-rebuild-os" value="">
            <div id="vf-rebuild-wizard-loader" class="mb-3">
                <div class="vf-skeleton" style="height:120px;"></div>
            </div>
            <div id="vf-rebuild-wizard" class="vf-rebuild-wizard" style="display:none;"></div>
            <input type="hidden" id="vf-rebuild-ssh" value="">
            <div id="vf-rebuild-auth-wrap" style="display:none;"></div>

            <div id="vf-rebuild-os-error" class="vf-rebuild-os-error">Please select an Operating System to continue.</div>

            <button id="vf-rebuild-button" onclick="vfRebuildServer('{$serviceid}','{$systemURL}')" type="button" class="btn btn-danger text-uppercase d-flex align-items-center mt-3">
                <span id="vf-rebuild-spinner" class="spinner-border spinner-border-sm vf-spinner-margin" style="display:none;"></span>
                <i class="fas fa-sync-alt mr-2"></i> Rebuild Server
            </button>
            <script>vfLoadOsTemplates('{$serviceid}', '{$systemURL}');</script>

        </div>
    </div>


    {* Network Tab — always visible; rDNS section is conditional *}
    <div class="tab-pane fade" id="vf-pane-network" role="tabpanel" aria-labelledby="vf-tab-network">

        {* ── Network Traffic Chart ── *}
        <div class="vf-billing-card mb-4" id="vf-traffic-chart-section">

            <div class="vf-billing-card-title">
                <i class="fas fa-exchange-alt"></i> Network Traffic
            </div>

            <div class="vf-chart-wrap">
                <canvas id="vf-traffic-chart" style="width:100%; display:block;"></canvas>
            </div>

            <div class="vf-traffic-stats-row">
                <div class="vf-traffic-stat">
                    <div class="vf-traffic-stat-label">Used</div>
                    <div class="vf-traffic-stat-value" id="vf-traffic-used">-</div>
                </div>
                <div class="vf-traffic-stat vf-traffic-stat--highlight">
                    <div class="vf-traffic-stat-label">Remaining</div>
                    <div class="vf-traffic-stat-value" id="vf-traffic-remaining">-</div>
                </div>
                <div class="vf-traffic-stat">
                    <div class="vf-traffic-stat-label">Limit</div>
                    <div class="vf-traffic-stat-value" id="vf-traffic-limit">-</div>
                </div>
            </div>

            <script>
            if (typeof vfLoadTrafficStats === 'function') {
                vfLoadTrafficStats('{$serviceid}', '{$systemURL}');
            }
            </script>

        </div>

        {* Network config + rDNS — shown only when rdns feature is enabled *}
        {if $rdnsEnabled}
        <div class="vf-billing-card" id="vf-network-info-card" style="display:none;">

            {* ── Network Configuration ── *}
            <div class="vf-billing-card-title">
                <i class="fas fa-network-wired"></i> Network Configuration
            </div>
            <div class="vf-billing-grid">
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Gateway</span>
                    <span class="vf-billing-value" id="vf-net-gateway">-</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Subnet Mask</span>
                    <span class="vf-billing-value" id="vf-net-netmask">-</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">Prefix (CIDR)</span>
                    <span class="vf-billing-value" id="vf-net-cidr">-</span>
                </div>
                <div class="vf-billing-row">
                    <span class="vf-billing-label">MAC Address</span>
                    <span class="vf-billing-value vf-sensitive" id="vf-net-mac">-</span>
                </div>
            </div>

            {* ── Reverse DNS ── *}
            <hr style="margin: 20px 0; border-color: #f0f2f5;">
            <div class="vf-billing-card-title mb-1">
                <i class="fas fa-exchange-alt"></i> Reverse DNS (PTR Records)
            </div>
            <p class="vf-small text-muted mb-3" style="margin-left:0;">Set a custom PTR record for each assigned IP. Forward DNS (A/AAAA) for the hostname must already resolve to the IP before the PTR can be saved.</p>
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
        {/if}

    </div>



    {* Settings Tab *}
    <div class="tab-pane fade" id="vf-pane-settings" role="tabpanel" aria-labelledby="vf-tab-settings">
        <div class="vf-billing-card" id="vf-backups-section" style="display:none;">
            <div class="vf-billing-card-title">
                <i class="fas fa-history"></i> Server Backups
            </div>
            <div id="vf-backups-loader" class="my-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>
            <div id="vf-backups-timeline" class="vf-timeline"></div>
            <button id="vf-backups-show-all" class="btn btn-sm btn-link mt-2" style="display:none;" onclick="$('.vf-timeline-item-hidden').show(); $(this).hide();">Show all backups</button>
            <script>
            if (typeof vfLoadBackups === 'function') {
                vfLoadBackups('{$serviceid}', '{$systemURL}');
            }
            </script>
        </div>
    </div>

</div>

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
