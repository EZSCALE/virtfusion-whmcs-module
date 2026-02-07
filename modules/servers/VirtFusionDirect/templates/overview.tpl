<link href="{$systemURL}modules/servers/VirtFusionDirect/templates/css/module.css" rel="stylesheet">
<script src="{$systemURL}modules/servers/VirtFusionDirect/templates/js/module.js"></script>

{if $serviceStatus eq 'Active'}

{* Server Overview Panel *}
<div class="panel card panel-default mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">
            Server Overview
            <span id="vf-status-badge" class="vf-badge" style="float: right;"></span>
        </h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-server-info-loader-container">
            <div id="vf-server-info-loader" class="d-flex align-items-center justify-content-center">
                <div class="spinner-border"></div>
            </div>
        </div>
        <script>vfServerData('{$serviceid}', '{$systemURL}');</script>
        <div id="vf-server-info-error">
            <div class="alert alert-warning mb-0">Information unavailable. Try again later.</div>
        </div>
        <div id="vf-server-info" class="row mb-2">
            <div class="col-12">
                <div class="row">
                    <div class="col-md-6">
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">Name:</div>
                            <div class="col-xs-8 col-8" id="vf-data-server-name"></div>
                        </div>
                        <div class="row p-1">
                            <div class="col-xs-4 col-4 text-right vf-bold">Hostname:</div>
                            <div class="col-xs-8 col-8" id="vf-data-server-hostname"></div>
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
    </div>
</div>

{* Power Management Panel *}
<div class="panel card panel-default mb-3">
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
<div class="panel card panel-default mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Manage</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div class="row">
            <div class="col-12">
                <div id="vf-login-error" class="alert alert-danger"></div>
                <p>Manage your server via our dedicated control panel. You will be automatically authenticated and the control panel will open in a new window.</p>
                <button id="vf-login-button" onclick="vfLoginAsServerOwner('{$serviceid}','{$systemURL}',true)" type="button" class="btn btn-primary text-uppercase d-flex align-items-center">
                    <div id="vf-login-button-spinner" class="spinner-border spinner-border-sm text-light vf-spinner-margin"></div>
                    Open Control Panel
                </button>
            </div>
            <div class="col-12">
                <p class="mb-0 pt-3 vf-small">Having trouble opening the control panel in a new window? <a href="#" onclick="vfLoginAsServerOwner('{$serviceid}','{$systemURL}',false); return false;">Click here</a> to open in this window.</p>
            </div>
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
        </div>
    </div>
</div>

{* Rebuild Panel *}
<div class="panel card panel-default mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Rebuild Server</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-rebuild-alert" class="alert" style="display: none;"></div>
        <div class="alert alert-warning">
            <strong>Warning:</strong> Rebuilding your server will erase all data on the server and reinstall the operating system. This action cannot be undone.
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group mb-3">
                    <label for="vf-rebuild-os">Operating System</label>
                    <select id="vf-rebuild-os" class="form-control">
                        <option value="">Loading...</option>
                    </select>
                </div>
            </div>
        </div>
        <button id="vf-rebuild-button" onclick="vfRebuildServer('{$serviceid}','{$systemURL}')" type="button" class="btn btn-danger text-uppercase d-flex align-items-center">
            <span id="vf-rebuild-spinner" class="spinner-border spinner-border-sm vf-spinner-margin" style="display:none;"></span>
            Rebuild Server
        </button>
        <script>vfLoadOsTemplates('{$serviceid}', '{$systemURL}');</script>
    </div>
</div>

{* Network Management Panel *}
<div class="panel card panel-default mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Network</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-network-alert" class="alert" style="display: none;"></div>
        <div id="vf-network-loader" class="d-flex align-items-center justify-content-center" style="min-height: 60px;">
            <div class="spinner-border spinner-border-sm"></div>
        </div>
        <div id="vf-network-content" style="display: none;">
            <div class="row mb-3">
                <div class="col-md-6">
                    <h5 class="vf-bold">IPv4 Addresses</h5>
                    <div id="vf-ipv4-list" class="mb-2"></div>
                    <button id="vf-add-ipv4" onclick="vfAddIP('{$serviceid}','{$systemURL}','addIPv4')" type="button" class="btn btn-sm btn-outline-primary">
                        <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                        Add IPv4
                    </button>
                </div>
                <div class="col-md-6">
                    <h5 class="vf-bold">IPv6 Subnets</h5>
                    <div id="vf-ipv6-list" class="mb-2"></div>
                    <button id="vf-add-ipv6" onclick="vfAddIP('{$serviceid}','{$systemURL}','addIPv6')" type="button" class="btn btn-sm btn-outline-primary">
                        <span class="vf-btn-spinner spinner-border spinner-border-sm" style="display:none;"></span>
                        Add IPv6
                    </button>
                </div>
            </div>
        </div>
        <script>vfLoadServerIPs('{$serviceid}', '{$systemURL}');</script>
    </div>
</div>

{* Resources Panel — populated by JS after server data loads *}
<div id="vf-resources-panel" class="panel card panel-default mb-3" style="display: none;">
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
                <div class="vf-resource-item mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="vf-bold">Network Speed</span>
                        <span id="vf-res-network-speed"></span>
                    </div>
                </div>
            </div>
        </div>
        <a href="clientarea.php?action=upgrade&id={$serviceid}" class="btn btn-outline-primary mt-2">Upgrade / Downgrade Resources</a>
    </div>
</div>

{* VNC Console Panel — hidden by default, shown by JS if VNC is enabled *}
<div id="vf-vnc-panel" class="panel card panel-default mb-3" style="display: none;">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">VNC Console</h3>
    </div>
    <div class="panel-body card-body p-4">
        <div id="vf-vnc-alert" class="alert" style="display: none;"></div>
        <p>Access your server's console directly in your browser. The server must be running for VNC access.</p>
        <button id="vf-vnc-button" onclick="vfOpenVnc('{$serviceid}','{$systemURL}')" type="button" class="btn btn-primary text-uppercase d-flex align-items-center">
            <span id="vf-vnc-spinner" class="spinner-border spinner-border-sm vf-spinner-margin" style="display:none;"></span>
            Open Console
        </button>
    </div>
</div>

{* Self Service — Billing & Usage Panel *}
<div id="vf-selfservice-panel" class="panel card panel-default mb-3" style="display: none;">
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
                        <div class="input-group-append">
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

{elseif $serviceStatus eq 'Suspended'}

<div class="panel card panel-default mb-3">
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
<div class="panel card panel-default mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Billing Overview</h3>
    </div>
    <div class="panel-body card-body">
        <div class="row">
            <div class="col-lg-6">
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">Product:</div>
                    <div class="col-xs-6 col-6">{$groupname} - {$product}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.recurringamount}:</div>
                    <div class="col-xs-6 col-6">{$recurringamount}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.orderbillingcycle}:</div>
                    <div class="col-xs-6 col-6">{$billingcycle}</div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.clientareahostingregdate}:</div>
                    <div class="col-xs-6 col-6">{$regdate}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.clientareahostingnextduedate}:</div>
                    <div class="col-xs-6 col-6">{$nextduedate}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right vf-bold">{$LANG.orderpaymentmethod}:</div>
                    <div class="col-xs-6 col-6">{$paymentmethod}</div>
                </div>
            </div>
        </div>
    </div>
</div>
