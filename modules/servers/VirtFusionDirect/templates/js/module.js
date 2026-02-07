/**
 * VirtFusion Direct Provisioning Module - Client JavaScript
 *
 * Handles client-side interactions for server management including:
 * - Server data display
 * - Power management (boot, shutdown, restart, power off)
 * - Control panel login (SSO)
 * - Password reset
 * - Server rebuild
 * - OS template loading
 */

function vfServerData(serviceId, systemUrl) {
    $("#vf-server-info-error").hide();
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=serverData"
    }).done(function (response) {
        if (response.success) {
            $("#vf-data-server-name").text(response.data.name);
            $("#vf-data-server-hostname").text(response.data.hostname);
            $("#vf-data-server-memory").text(response.data.memory);
            $("#vf-data-server-traffic").text(response.data.traffic);
            $("#vf-data-server-traffic-used").text(response.data.trafficUsed || "-");
            $("#vf-data-server-storage").text(response.data.storage);
            $("#vf-data-server-cpu").text(response.data.cpu);
            $("#vf-data-server-ipv4").text(response.data.primaryNetwork.ipv4);
            $("#vf-data-server-ipv6").text(response.data.primaryNetwork.ipv6);

            // Update status badge
            var statusBadge = $("#vf-status-badge");
            var status = (response.data.status || "unknown").toLowerCase();
            statusBadge.text(status.charAt(0).toUpperCase() + status.slice(1));
            if (status === "active" || status === "running") {
                statusBadge.addClass("vf-badge-active");
            } else if (status === "suspended") {
                statusBadge.addClass("vf-badge-suspended");
            } else {
                statusBadge.addClass("vf-badge-awaiting");
            }

            // Show/hide VNC panel based on API response
            if (response.data.vncEnabled) {
                $("#vf-vnc-panel").show();
            }

            // Populate resources panel
            var d = response.data;
            $("#vf-res-memory").text(d.memory || "-");
            $("#vf-res-cpu").text(d.cpu || "-");
            $("#vf-res-storage").text(d.storage || "-");

            var trafficUsed = d.trafficUsedRaw || 0;
            var trafficTotal = d.trafficRaw || 0;
            if (trafficTotal > 0) {
                $("#vf-res-traffic").text(trafficUsed + " / " + trafficTotal + " GB");
                var pct = Math.min(100, Math.round((trafficUsed / trafficTotal) * 100));
                $("#vf-res-traffic-bar").css("width", pct + "%");
                if (pct > 90) {
                    $("#vf-res-traffic-bar").addClass("bg-danger");
                } else if (pct > 70) {
                    $("#vf-res-traffic-bar").addClass("bg-warning");
                }
            } else {
                $("#vf-res-traffic").text(d.traffic || "Unlimited");
                $("#vf-res-traffic-bar").css("width", "0%");
            }

            var speedIn = d.networkSpeedInboundRaw || 0;
            var speedOut = d.networkSpeedOutboundRaw || 0;
            if (speedIn > 0 || speedOut > 0) {
                $("#vf-res-network-speed").text(speedIn + " / " + speedOut + " Mbps");
            } else {
                $("#vf-res-network-speed").text("-");
            }

            $("#vf-resources-panel").show();

            $("#vf-server-info").show();
        } else {
            $("#vf-server-info-error").show();
            $("#vf-server-info").hide();
        }
    }).fail(function () {
        $("#vf-server-info-error").show();
    }).always(function () {
        $("#vf-server-info-loader-container").hide();
    });
}

function vfServerDataAdmin(serviceId, systemUrl) {
    $("#vf-loader").show();
    $("#vf-server-info").hide();
    $("#vf-server-info-error").hide();
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/admin.php?serviceID=" + encodeURIComponent(serviceId) + "&action=serverData"
    }).done(function (response) {
        if (response.success) {
            $("#vf-data-server-name").text(response.data.name);
            $("#vf-data-server-hostname").text(response.data.hostname);
            $("#vf-data-server-memory").text(response.data.memory);
            $("#vf-data-server-traffic").text(response.data.traffic);
            $("#vf-data-server-storage").text(response.data.storage);
            $("#vf-data-server-cpu").text(response.data.cpu);
            $("#vf-data-server-ipv4").text(response.data.primaryNetwork.ipv4);
            $("#vf-data-server-ipv6").text(response.data.primaryNetwork.ipv6);
            $("#vf-server-info").show();
        } else {
            $("#vf-server-info-error").show();
            $("#vf-server-info-error-message").text(response.errors);
            $("#vf-server-info").hide();
        }
    }).fail(function () {
        $("#vf-server-info-error").show();
    }).always(function () {
        $("#vf-loader").hide();
    });
}

function vfUserPasswordReset(serviceId, systemUrl) {
    $("#vf-password-reset-button-spinner").show();
    $("#vf-password-reset-error").hide();
    $("#vf-password-reset-success").hide();
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=resetPassword"
    }).done(function (response) {
        if (response.success) {
            $("#vf-password-reset-success").show();
            $("#vf-data-user-email").text(response.data.email);
            $("#vf-data-user-password").text(response.data.password);
        } else {
            $("#vf-password-reset-error").show();
        }
    }).fail(function () {
        $("#vf-password-reset-error").show();
    }).always(function () {
        $("#vf-password-reset-button-spinner").hide();
    });
}

function vfLoginAsServerOwner(serviceId, systemUrl, newWindow) {
    newWindow = newWindow !== false;
    vfLoginError(false);
    $("#vf-login-button").prop("disabled", true);
    $("#vf-login-button-spinner").show();
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=loginAsServerOwner"
    }).done(function (response) {
        if (response.success && response.token_url) {
            if (newWindow) {
                window.open(response.token_url);
            } else {
                window.location.href = response.token_url;
            }
        } else {
            vfLoginError(true);
        }
    }).fail(function () {
        vfLoginError(true);
    }).always(function () {
        $("#vf-login-button-spinner").hide();
        $("#vf-login-button").prop("disabled", false);
    });
}

function vfLoginError(show, message) {
    message = message || "Unable to open the control panel. Please try again later.";
    if (show) {
        $("#vf-login-error").text(message);
        $("#vf-login-error").show();
    } else {
        $("#vf-login-error").hide();
    }
}

function vfPowerAction(serviceId, systemUrl, action) {
    var btn = $("#vf-power-" + action);
    var spinner = btn.find(".vf-btn-spinner");
    var alertDiv = $("#vf-power-alert");

    // Disable all power buttons during action
    $(".vf-btn-power").prop("disabled", true);
    spinner.show();
    alertDiv.hide();

    var actionLabels = {
        boot: "Starting",
        shutdown: "Shutting down",
        restart: "Restarting",
        poweroff: "Forcing off"
    };

    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=powerAction&powerAction=" + encodeURIComponent(action)
    }).done(function (response) {
        if (response.success) {
            alertDiv.removeClass("alert-danger").addClass("alert-success");
            alertDiv.text(response.data.message || (actionLabels[action] + " server..."));
        } else {
            alertDiv.removeClass("alert-success").addClass("alert-danger");
            alertDiv.text(response.errors || "Power action failed.");
        }
        alertDiv.show();
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    }).always(function () {
        spinner.hide();
        $(".vf-btn-power").prop("disabled", false);
    });
}

function vfLoadOsTemplates(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=osTemplates"
    }).done(function (response) {
        var select = $("#vf-rebuild-os");
        select.empty();
        if (response.success && response.data && response.data.length > 0) {
            select.append('<option value="">-- Select Operating System --</option>');
            $.each(response.data, function (i, template) {
                select.append('<option value="' + template.id + '">' + $('<span>').text(template.name).html() + '</option>');
            });
        } else {
            select.append('<option value="">No templates available</option>');
        }
    }).fail(function () {
        var select = $("#vf-rebuild-os");
        select.empty();
        select.append('<option value="">Error loading templates</option>');
    });
}

function vfRebuildServer(serviceId, systemUrl) {
    var osId = $("#vf-rebuild-os").val();
    var alertDiv = $("#vf-rebuild-alert");

    if (!osId) {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("Please select an operating system.");
        alertDiv.show();
        return;
    }

    if (!confirm("Are you sure you want to rebuild this server? ALL DATA WILL BE ERASED. This action cannot be undone.")) {
        return;
    }

    $("#vf-rebuild-button").prop("disabled", true);
    $("#vf-rebuild-spinner").show();
    alertDiv.hide();

    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=rebuild&osId=" + encodeURIComponent(osId)
    }).done(function (response) {
        if (response.success) {
            alertDiv.removeClass("alert-danger").addClass("alert-success");
            alertDiv.text(response.data.message || "Server rebuild initiated. You will receive an email when the process is complete.");
        } else {
            alertDiv.removeClass("alert-success").addClass("alert-danger");
            alertDiv.text(response.errors || "Rebuild failed.");
        }
        alertDiv.show();
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    }).always(function () {
        $("#vf-rebuild-spinner").hide();
        $("#vf-rebuild-button").prop("disabled", false);
    });
}

function impersonateServerOwner(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/admin.php?serviceID=" + encodeURIComponent(serviceId) + "&action=impersonateServerOwner"
    }).done(function (response) {
        if (response.success && response.user) {
            window.open(response.url + "/_imp/in/" + response.user.id + "/-");
        }
    });
}

// =========================================================================
// Network / IP Management
// =========================================================================

function vfLoadServerIPs(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=serverIPs"
    }).done(function (response) {
        if (response.success) {
            var ipv4List = $("#vf-ipv4-list");
            var ipv6List = $("#vf-ipv6-list");
            ipv4List.empty();
            ipv6List.empty();

            if (response.data.ipv4 && response.data.ipv4.length > 0) {
                $.each(response.data.ipv4, function (i, ip) {
                    var row = $('<div class="vf-ip-row"></div>');
                    row.append('<span class="vf-ip-address">' + $('<span>').text(ip).html() + '</span>');
                    if (i > 0) {
                        row.append(' <button class="btn btn-sm btn-outline-danger vf-ip-remove" onclick="vfRemoveIP(\'' + serviceId + '\',\'' + systemUrl + '\',\'removeIPv4\',\'' + encodeURIComponent(ip) + '\')">Remove</button>');
                    }
                    ipv4List.append(row);
                });
            } else {
                ipv4List.append('<span class="text-muted">No IPv4 addresses</span>');
            }

            if (response.data.ipv6 && response.data.ipv6.length > 0) {
                $.each(response.data.ipv6, function (i, subnet) {
                    var row = $('<div class="vf-ip-row"></div>');
                    row.append('<span class="vf-ip-address">' + $('<span>').text(subnet).html() + '</span>');
                    row.append(' <button class="btn btn-sm btn-outline-danger vf-ip-remove" onclick="vfRemoveIP(\'' + serviceId + '\',\'' + systemUrl + '\',\'removeIPv6\',\'' + encodeURIComponent(subnet) + '\')">Remove</button>');
                    ipv6List.append(row);
                });
            } else {
                ipv6List.append('<span class="text-muted">No IPv6 subnets</span>');
            }

            $("#vf-network-content").show();
        } else {
            $("#vf-network-content").show();
            $("#vf-ipv4-list").html('<span class="text-muted">Unable to load</span>');
            $("#vf-ipv6-list").html('<span class="text-muted">Unable to load</span>');
        }
    }).fail(function () {
        $("#vf-network-content").show();
        $("#vf-ipv4-list").html('<span class="text-muted">Unable to load</span>');
        $("#vf-ipv6-list").html('<span class="text-muted">Unable to load</span>');
    }).always(function () {
        $("#vf-network-loader").hide();
    });
}

function vfAddIP(serviceId, systemUrl, action) {
    var btn = $("#vf-add-" + (action === "addIPv4" ? "ipv4" : "ipv6"));
    var spinner = btn.find(".vf-btn-spinner");
    var alertDiv = $("#vf-network-alert");

    btn.prop("disabled", true);
    spinner.show();
    alertDiv.hide();

    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=" + encodeURIComponent(action)
    }).done(function (response) {
        if (response.success) {
            alertDiv.removeClass("alert-danger").addClass("alert-success");
            alertDiv.text(response.data.message || "IP address added successfully.");
            alertDiv.show();
            // Refresh IP list
            vfLoadServerIPs(serviceId, systemUrl);
        } else {
            alertDiv.removeClass("alert-success").addClass("alert-danger");
            alertDiv.text(response.errors || "Failed to add IP address.");
            alertDiv.show();
        }
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    }).always(function () {
        spinner.hide();
        btn.prop("disabled", false);
    });
}

function vfRemoveIP(serviceId, systemUrl, action, identifier) {
    if (!confirm("Are you sure you want to remove this IP address?")) {
        return;
    }

    var alertDiv = $("#vf-network-alert");
    alertDiv.hide();

    var paramName = action === "removeIPv4" ? "ip" : "subnet";

    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=" + encodeURIComponent(action) + "&" + paramName + "=" + identifier
    }).done(function (response) {
        if (response.success) {
            alertDiv.removeClass("alert-danger").addClass("alert-success");
            alertDiv.text(response.data.message || "IP address removed successfully.");
            alertDiv.show();
            vfLoadServerIPs(serviceId, systemUrl);
        } else {
            alertDiv.removeClass("alert-success").addClass("alert-danger");
            alertDiv.text(response.errors || "Failed to remove IP address.");
            alertDiv.show();
        }
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    });
}

// =========================================================================
// VNC Console
// =========================================================================

function vfOpenVnc(serviceId, systemUrl) {
    var btn = $("#vf-vnc-button");
    var spinner = $("#vf-vnc-spinner");
    var alertDiv = $("#vf-vnc-alert");

    btn.prop("disabled", true);
    spinner.show();
    alertDiv.hide();

    // Open window immediately in click context to avoid popup blockers
    var vncWindow = window.open("", "_blank");

    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=vnc"
    }).done(function (response) {
        if (response.success && response.data) {
            var data = response.data.data || response.data;
            if (data.url) {
                vncWindow.location.href = data.url;
            } else if (data.host && data.port) {
                // Build noVNC URL if available
                var vncUrl = "https://" + data.host + ":" + data.port;
                if (data.token) {
                    vncUrl += "?token=" + encodeURIComponent(data.token);
                }
                vncWindow.location.href = vncUrl;
            } else {
                vncWindow.close();
                alertDiv.removeClass("alert-danger").addClass("alert-success");
                alertDiv.text("VNC session is ready. Check your VirtFusion control panel for access.");
                alertDiv.show();
            }
        } else {
            vncWindow.close();
            alertDiv.removeClass("alert-success").addClass("alert-danger");
            alertDiv.text(response.errors || "VNC console is not available.");
            alertDiv.show();
        }
    }).fail(function () {
        vncWindow.close();
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("An error occurred. The server may be powered off.");
        alertDiv.show();
    }).always(function () {
        spinner.hide();
        btn.prop("disabled", false);
    });
}

// =========================================================================
// Self Service — Credit & Usage
// =========================================================================

function vfLoadSelfServiceUsage(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=selfServiceUsage"
    }).done(function (response) {
        if (response.success && response.data) {
            var data = response.data.data || response.data;

            // Credit balance
            var balance = "-";
            if (data.credit !== undefined) {
                balance = parseFloat(data.credit).toFixed(2);
            } else if (data.balance !== undefined) {
                balance = parseFloat(data.balance).toFixed(2);
            }
            $("#vf-ss-credit-balance").text(balance);

            // Usage breakdown
            var tbody = $("#vf-ss-usage-table");
            tbody.empty();

            var items = data.usage || data.items || [];
            if (Array.isArray(items) && items.length > 0) {
                $.each(items, function (i, item) {
                    var desc = item.description || item.name || item.server || "Item";
                    var cost = item.cost !== undefined ? parseFloat(item.cost).toFixed(2) : "-";
                    tbody.append('<tr><td>' + $('<span>').text(desc).html() + '</td><td class="text-right">' + $('<span>').text(cost).html() + '</td></tr>');
                });
            } else {
                tbody.append('<tr><td colspan="2" class="text-muted">No usage data available</td></tr>');
            }

            $("#vf-selfservice-content").show();
            $("#vf-selfservice-panel").show();
        }
    }).fail(function () {
        // Self-service not available — keep panel hidden
    }).always(function () {
        $("#vf-selfservice-loader").hide();
    });
}

function vfLoadSelfServiceReport(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=selfServiceReport"
    }).done(function (response) {
        if (response.success && response.data) {
            var data = response.data.data || response.data;
            var tbody = $("#vf-ss-usage-table");
            tbody.empty();

            var items = data.items || data.report || [];
            if (Array.isArray(items) && items.length > 0) {
                $.each(items, function (i, item) {
                    var desc = item.description || item.name || "Item";
                    var cost = item.cost !== undefined ? parseFloat(item.cost).toFixed(2) : "-";
                    tbody.append('<tr><td>' + $('<span>').text(desc).html() + '</td><td class="text-right">' + $('<span>').text(cost).html() + '</td></tr>');
                });
            } else {
                tbody.append('<tr><td colspan="2" class="text-muted">No report data available</td></tr>');
            }
        }
    });
}

function vfAddCredit(serviceId, systemUrl) {
    var amount = $("#vf-ss-credit-amount").val();
    var alertDiv = $("#vf-selfservice-alert");
    var btn = $("#vf-ss-add-credit-btn");
    var spinner = $("#vf-ss-add-credit-spinner");

    if (!amount || parseFloat(amount) <= 0) {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("Please enter a valid positive amount.");
        alertDiv.show();
        return;
    }

    btn.prop("disabled", true);
    spinner.show();
    alertDiv.hide();

    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=selfServiceAddCredit&tokens=" + encodeURIComponent(amount)
    }).done(function (response) {
        if (response.success) {
            alertDiv.removeClass("alert-danger").addClass("alert-success");
            alertDiv.text("Credit added successfully.");
            alertDiv.show();
            $("#vf-ss-credit-amount").val("");
            // Refresh usage data
            vfLoadSelfServiceUsage(serviceId, systemUrl);
        } else {
            alertDiv.removeClass("alert-success").addClass("alert-danger");
            alertDiv.text(response.errors || "Failed to add credit.");
            alertDiv.show();
        }
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    }).always(function () {
        spinner.hide();
        btn.prop("disabled", false);
    });
}
