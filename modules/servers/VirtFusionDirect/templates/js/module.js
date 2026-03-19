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
 * - Traffic statistics
 * - Backup listing
 * - VNC management
 * - Server naming
 */

// =========================================================================
// Progress Indicator
// =========================================================================

var _vfProgressTimer = null;

function vfShowProgress(label) {
    var startTime = Date.now();
    $("#vf-action-progress-text").text(label);
    $("#vf-action-progress-timer").text("0s");
    $("#vf-action-progress").show();

    _vfProgressTimer = setInterval(function () {
        var elapsed = Math.floor((Date.now() - startTime) / 1000);
        $("#vf-action-progress-timer").text(elapsed + "s");
    }, 1000);
}

function vfHideProgress() {
    if (_vfProgressTimer) {
        clearInterval(_vfProgressTimer);
        _vfProgressTimer = null;
    }
    $("#vf-action-progress").hide();
}

function vfServerData(serviceId, systemUrl) {
    $("#vf-server-info-error").hide();
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=serverData"
    }).done(function (response) {
        if (response.success) {
            $("#vf-rename-input").val(response.data.name);
            $("#vf-data-server-hostname").text(response.data.hostname);
            $("#vf-data-server-memory").text(response.data.memory);
            $("#vf-data-server-traffic").text(response.data.traffic);
            $("#vf-data-server-traffic-used").text(response.data.trafficUsed || "-");
            $("#vf-data-server-storage").text(response.data.storage);
            $("#vf-data-server-cpu").text(response.data.cpu);
            var pn = response.data.primaryNetwork || {};
            $("#vf-data-server-ipv4").text(pn.ipv4 || "-");
            $("#vf-data-server-ipv6").text(pn.ipv6 || "-");

            // Update status badge
            var statusBadge = $("#vf-status-badge");
            var status = (response.data.status || "unknown").toLowerCase();
            statusBadge.text(status.charAt(0).toUpperCase() + status.slice(1));
            statusBadge.removeClass("vf-badge-active vf-badge-suspended vf-badge-awaiting");
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
                $("#vf-res-traffic-bar").css("width", pct + "%").removeClass("bg-danger bg-warning");
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

            // Populate network panel from server data
            var ipv4List = $("#vf-ipv4-list");
            var ipv6List = $("#vf-ipv6-list");
            ipv4List.empty();
            ipv6List.empty();

            var net = response.data.primaryNetwork || {};
            var ipv4Arr = net.ipv4Unformatted || [];
            var ipv6Arr = net.ipv6Unformatted || [];

            if (ipv4Arr.length > 0) {
                $.each(ipv4Arr, function (i, ip) {
                    var row = $('<div class="vf-ip-row"></div>');
                    row.append('<span class="vf-ip-address">' + $('<span>').text(ip).html() + '</span>');
                    row.append(vfCopyButton(ip));
                    ipv4List.append(row);
                });
            } else {
                ipv4List.append('<span class="text-muted">No IPv4 addresses</span>');
            }

            if (ipv6Arr.length > 0) {
                $.each(ipv6Arr, function (i, subnet) {
                    var row = $('<div class="vf-ip-row"></div>');
                    row.append('<span class="vf-ip-address">' + $('<span>').text(subnet).html() + '</span>');
                    row.append(vfCopyButton(subnet));
                    ipv6List.append(row);
                });
            } else {
                ipv6List.append('<span class="text-muted">No IPv6 subnets</span>');
            }

            $("#vf-network-content").show();

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
            var pnAdmin = response.data.primaryNetwork || {};
            $("#vf-data-server-ipv4").text(pnAdmin.ipv4 || "-");
            $("#vf-data-server-ipv6").text(pnAdmin.ipv6 || "-");
            $("#vf-server-info").show();
        } else {
            $("#vf-server-info-error").show();
            $("#vf-server-info-error-message").text("Unable to retrieve server information.");
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
        type: "POST",
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
        type: "POST",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=powerAction",
        data: { powerAction: action }
    }).done(function (response) {
        if (response.success) {
            alertDiv.removeClass("alert-danger").addClass("alert-success");
            alertDiv.text(response.data.message || (actionLabels[action] + " server..."));
        } else {
            alertDiv.removeClass("alert-success").addClass("alert-danger");
            alertDiv.text("Power action failed. Please try again.");
        }
        alertDiv.show();
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    }).always(function () {
        spinner.hide();
        // Cooldown: keep buttons disabled for 3 seconds
        setTimeout(function () {
            $(".vf-btn-power").prop("disabled", false);
        }, 3000);
    });
}

var vfOsBrandColors = {
    "ubuntu": "#E95420", "debian": "#A81D33", "rocky": "#10B981", "centos": "#932279",
    "almalinux": "#0F4266", "alma": "#0F4266", "windows": "#0078D4", "fedora": "#51A2DA",
    "arch": "#1793D1", "opensuse": "#73BA25", "suse": "#73BA25", "freebsd": "#AB2B28",
    "oracle": "#F80000", "rhel": "#EE0000", "red hat": "#EE0000", "cloudlinux": "#0095D9",
    "gentoo": "#54487A", "slackware": "#000", "nixos": "#7EBAE4", "alpine": "#0D597F"
};

function vfGetBrandColor(name) {
    var lower = (name || "").toLowerCase();
    for (var key in vfOsBrandColors) {
        if (lower.indexOf(key) !== -1) return vfOsBrandColors[key];
    }
    return "#6c757d";
}

function vfRenderOsGallery(container, data, hiddenInput) {
    var $container = $(container);
    $container.empty();

    if (!data || !data.categories || data.categories.length === 0) {
        $container.append($('<p class="text-muted"></p>').text("No templates available"));
        $container.show();
        return;
    }

    var baseUrl = data.baseUrl || "";

    $.each(data.categories, function (ci, category) {
        var section = $('<div class="vf-os-category"></div>').attr("data-category", ci);
        var title = $('<h5 class="vf-os-category-title"></h5>').text(category.name);
        section.append(title);
        var grid = $('<div class="vf-os-grid"></div>');

        $.each(category.templates, function (ti, tpl) {
            var label = tpl.name + (tpl.version ? " " + tpl.version : "") + (tpl.variant ? " " + tpl.variant : "");
            var brandColor = vfGetBrandColor(category.name || tpl.name);
            var card = $('<div class="vf-os-card"></div>')
                .attr("data-id", tpl.id)
                .attr("data-search", label.toLowerCase());
            if (tpl.eol) card.addClass("vf-os-card-eol");

            var iconDiv = $('<div class="vf-os-icon"></div>').css("background", brandColor);
            if (tpl.icon && baseUrl) {
                var img = $('<img alt="">').attr("src", baseUrl + "/storage/os/" + encodeURIComponent(tpl.icon));
                img.on("error", function () {
                    $(this).parent().empty().append($('<span></span>').text((tpl.name || "?")[0].toUpperCase()));
                });
                iconDiv.append(img);
            } else {
                iconDiv.append($('<span></span>').text((tpl.name || "?")[0].toUpperCase()));
            }

            card.append(iconDiv);
            card.append($('<div class="vf-os-label"></div>').text(tpl.name));
            card.append($('<div class="vf-os-version"></div>').text((tpl.version || "") + (tpl.variant ? " " + tpl.variant : "")));
            if (tpl.eol) {
                card.append($('<span class="vf-os-eol-badge"></span>').text("EOL"));
            }

            card.on("click", function () {
                $container.find(".vf-os-card").removeClass("vf-os-card-selected");
                $(this).addClass("vf-os-card-selected");
                $(hiddenInput).val(tpl.id);

                var details = $("#vf-os-details");
                details.empty();
                details.append($('<strong></strong>').text(label));
                if (tpl.description) {
                    details.append($('<p class="mb-0 mt-1 text-muted"></p>').text(tpl.description));
                }
                details.show();
            });

            grid.append(card);
        });

        section.append(grid);
        $container.append(section);
    });

    $container.show();
}

function vfLoadOsTemplates(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=osTemplates"
    }).done(function (response) {
        $("#vf-os-gallery-loader").hide();
        if (response.success && response.data) {
            vfRenderOsGallery("#vf-os-gallery", response.data, "#vf-rebuild-os");

            // Bind search after gallery is rendered
            $("#vf-os-search").on("keyup", function () {
                var query = $(this).val().toLowerCase();
                $("#vf-os-gallery .vf-os-card").each(function () {
                    var match = $(this).data("search").indexOf(query) !== -1;
                    $(this).toggle(match);
                });
                $("#vf-os-gallery .vf-os-category").each(function () {
                    var hasVisible = $(this).find(".vf-os-card:visible").length > 0;
                    $(this).toggle(hasVisible);
                });
            });
        } else {
            $("#vf-os-gallery").append($('<p class="text-muted"></p>').text("No templates available")).show();
        }
    }).fail(function () {
        $("#vf-os-gallery-loader").hide();
        $("#vf-os-gallery").append($('<p class="text-danger"></p>').text("Error loading templates")).show();
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
    vfShowProgress("Rebuilding server...");

    $.ajax({
        type: "POST",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=rebuild",
        data: { osId: osId }
    }).done(function (response) {
        if (response.success) {
            alertDiv.removeClass("alert-danger").addClass("alert-success");
            alertDiv.text(response.data.message || "Server rebuild initiated. You will receive an email when the process is complete.");
        } else {
            alertDiv.removeClass("alert-success").addClass("alert-danger");
            alertDiv.text("Rebuild failed. Please try again.");
        }
        alertDiv.show();
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    }).always(function () {
        vfHideProgress();
        $("#vf-rebuild-spinner").hide();
        // Cooldown: keep button disabled for 30 seconds after rebuild
        setTimeout(function () {
            $("#vf-rebuild-button").prop("disabled", false);
        }, 30000);
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
    if (!vncWindow) {
        alertDiv.removeClass("alert-success").addClass("alert-danger");
        alertDiv.text("Popup blocked. Please allow popups for this site and try again.");
        alertDiv.show();
        spinner.hide();
        btn.prop("disabled", false);
        return;
    }

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
            alertDiv.text("VNC console is not available.");
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

function vfToggleVnc(serviceId, systemUrl, enabled) {
    var toggle = $("#vf-vnc-toggle");
    toggle.prop("disabled", true);

    $.ajax({
        type: "POST",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=toggleVnc",
        data: { enabled: enabled ? "1" : "0" }
    }).done(function (response) {
        if (response.success) {
            if (enabled && response.data) {
                var data = response.data.data || response.data;
                if (data.ip || data.host) {
                    $("#vf-vnc-ip").text(data.ip || data.host || "-");
                    $("#vf-vnc-port").text(data.port || "-");
                    $("#vf-vnc-details").show();
                }
            } else {
                $("#vf-vnc-details").hide();
            }
        } else {
            toggle.prop("checked", !enabled);
        }
    }).fail(function () {
        toggle.prop("checked", !enabled);
    }).always(function () {
        toggle.prop("disabled", false);
    });
}

function vfCopyVncPassword(serviceId, systemUrl) {
    var confirmSpan = $("#vf-vnc-copy-confirm");

    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=vnc"
    }).done(function (response) {
        if (response.success && response.data) {
            var data = response.data.data || response.data;
            var password = data.password || "";
            if (password) {
                navigator.clipboard.writeText(password).then(function () {
                    confirmSpan.text("Copied!").show();
                    setTimeout(function () { confirmSpan.hide(); }, 2000);
                }).catch(function () {
                    confirmSpan.text("Copy failed").show();
                    setTimeout(function () { confirmSpan.hide(); }, 2000);
                });
            }
        }
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
        type: "POST",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=selfServiceAddCredit",
        data: { tokens: amount }
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
            alertDiv.text("Failed to add credit. Please try again.");
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

// =========================================================================
// Server Password Reset
// =========================================================================

function vfResetServerPassword(serviceId, systemUrl) {
    if (!confirm("Are you sure you want to reset the server root password? This will change the password immediately.")) {
        return;
    }

    var btn = $("#vf-server-password-btn");
    var spinner = $("#vf-server-password-spinner");
    var alertDiv = $("#vf-server-password-alert");

    btn.prop("disabled", true);
    spinner.show();
    alertDiv.hide();

    $.ajax({
        type: "POST",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=resetServerPassword"
    }).done(function (response) {
        if (response.success && response.data) {
            var data = response.data.data || response.data;
            var password = data.password || data.newPassword || "";
            if (password) {
                navigator.clipboard.writeText(password).then(function () {
                    alertDiv.removeClass("alert-danger").addClass("alert alert-success");
                    alertDiv.text("New password copied to clipboard.");
                    alertDiv.show();
                }).catch(function () {
                    alertDiv.removeClass("alert-danger").addClass("alert alert-warning");
                    alertDiv.text("Password reset successful. Unable to copy to clipboard automatically.");
                    alertDiv.show();
                });
            } else {
                alertDiv.removeClass("alert-danger").addClass("alert alert-success");
                alertDiv.text("Password reset initiated. Check your email for the new credentials.");
                alertDiv.show();
            }
        } else {
            alertDiv.removeClass("alert-success").addClass("alert alert-danger");
            alertDiv.text("Password reset failed. Please try again.");
            alertDiv.show();
        }
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    }).always(function () {
        spinner.hide();
        btn.prop("disabled", false);
    });
}

// =========================================================================
// Backup Listing
// =========================================================================

function vfLoadBackups(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=backups"
    }).done(function (response) {
        if (response.success && response.data) {
            var backups = response.data.data || response.data;
            if (!Array.isArray(backups)) backups = [];

            if (backups.length > 0) {
                var timeline = $("#vf-backups-timeline");
                timeline.empty();

                $.each(backups, function (i, backup) {
                    var rawDate = backup.created_at || backup.date || "";
                    var date = rawDate;
                    try { if (rawDate) date = new Date(rawDate).toLocaleString(); } catch (e) {}
                    var size = backup.size ? (backup.size >= 1024 ? (backup.size / 1024).toFixed(2) + " GB" : backup.size + " MB") : "-";
                    var status = backup.status || "completed";
                    var dotClass = status === "completed" ? "vf-timeline-dot-success" : "vf-timeline-dot-pending";

                    var item = $('<div class="vf-timeline-item"></div>');
                    if (i >= 10) item.addClass("vf-timeline-item-hidden").hide();
                    item.append('<div class="vf-timeline-dot ' + dotClass + '"></div>');
                    item.append($('<div class="vf-timeline-content"></div>')
                        .append($('<div class="vf-bold"></div>').text(date))
                        .append($('<div class="text-muted"></div>').text("Size: " + size + " | Status: " + status))
                    );
                    timeline.append(item);
                });

                if (backups.length > 10) {
                    $("#vf-backups-show-all").show();
                }

                $("#vf-backups-section").show();
            }
        }
    }).always(function () {
        $("#vf-backups-loader").hide();
    });
}

// =========================================================================
// Traffic Statistics Chart
// =========================================================================

function vfDrawTrafficChart(canvasId, entries) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !canvas.getContext) return;

    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = 200 * dpr;
    canvas.style.height = "200px";
    canvas.style.width = "100%";

    var ctx = canvas.getContext("2d");
    ctx.scale(dpr, dpr);
    var w = rect.width;
    var h = 200;

    if (!entries || entries.length === 0) {
        ctx.fillStyle = "#888";
        ctx.font = "13px sans-serif";
        ctx.textAlign = "center";
        ctx.fillText("No traffic data available", w / 2, h / 2);
        return;
    }

    var maxVal = 0;
    entries.forEach(function (e) {
        var total = (e.inbound || 0) + (e.outbound || 0);
        if (total > maxVal) maxVal = total;
    });
    if (maxVal === 0) maxVal = 1;

    var padding = { top: 10, right: 10, bottom: 30, left: 50 };
    var chartW = w - padding.left - padding.right;
    var chartH = h - padding.top - padding.bottom;
    var barGroupW = chartW / entries.length;
    var barW = Math.max(4, (barGroupW * 0.35));

    // Y axis
    ctx.strokeStyle = "#dee2e6";
    ctx.lineWidth = 1;
    for (var i = 0; i <= 4; i++) {
        var y = padding.top + chartH - (chartH * i / 4);
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(w - padding.right, y);
        ctx.stroke();
        ctx.fillStyle = "#888";
        ctx.font = "10px sans-serif";
        ctx.textAlign = "right";
        var labelVal = (maxVal * i / 4);
        ctx.fillText(labelVal >= 1024 ? (labelVal / 1024).toFixed(1) + " TB" : labelVal.toFixed(0) + " GB", padding.left - 5, y + 3);
    }

    entries.forEach(function (e, idx) {
        var inVal = e.inbound || 0;
        var outVal = e.outbound || 0;
        var inH = (inVal / maxVal) * chartH;
        var outH = (outVal / maxVal) * chartH;
        var x = padding.left + idx * barGroupW + (barGroupW - barW * 2 - 2) / 2;

        ctx.fillStyle = "#337ab7";
        ctx.fillRect(x, padding.top + chartH - inH, barW, inH);

        ctx.fillStyle = "#28a745";
        ctx.fillRect(x + barW + 2, padding.top + chartH - outH, barW, outH);

        // X label
        ctx.fillStyle = "#888";
        ctx.font = "10px sans-serif";
        ctx.textAlign = "center";
        ctx.fillText(e.label || (idx + 1), padding.left + idx * barGroupW + barGroupW / 2, h - 8);
    });

    // Legend
    ctx.fillStyle = "#337ab7";
    ctx.fillRect(padding.left, h - 15, 10, 10);
    ctx.fillStyle = "#888";
    ctx.font = "10px sans-serif";
    ctx.textAlign = "left";
    ctx.fillText("In", padding.left + 14, h - 6);
    ctx.fillStyle = "#28a745";
    ctx.fillRect(padding.left + 32, h - 15, 10, 10);
    ctx.fillStyle = "#888";
    ctx.fillText("Out", padding.left + 46, h - 6);
}

function vfLoadTrafficStats(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=trafficStats"
    }).done(function (response) {
        if (response.success && response.data) {
            var data = response.data.data || response.data;
            var entries = data.entries || data.traffic || [];
            var used = data.used || data.totalUsed || 0;
            var limit = data.limit || data.allowance || 0;

            if (entries.length > 0 || used > 0) {
                vfDrawTrafficChart("vf-traffic-chart", entries);
                $("#vf-traffic-used").text(used >= 1024 ? (used / 1024).toFixed(2) + " TB" : used + " GB");
                $("#vf-traffic-limit").text(limit > 0 ? (limit >= 1024 ? (limit / 1024).toFixed(2) + " TB" : limit + " GB") : "Unlimited");
                var remaining = limit > 0 ? Math.max(0, limit - used) : 0;
                $("#vf-traffic-remaining").text(limit > 0 ? (remaining >= 1024 ? (remaining / 1024).toFixed(2) + " TB" : remaining + " GB") : "-");
                $("#vf-traffic-chart-section").show();

                // Debounced resize redraw
                var resizeTimer;
                $(window).on("resize.vfTraffic", function () {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(function () {
                        vfDrawTrafficChart("vf-traffic-chart", entries);
                    }, 250);
                });
            }
        }
    });
}

// =========================================================================
// Server Naming
// =========================================================================

function vfGenerateFriendlyName() {
    var adjectives = ["swift","bold","calm","keen","fair","brave","cool","sage","free","warm"];
    var nouns = ["cloud","node","core","link","bolt","wave","star","peak","edge","dock"];
    var adj = adjectives[Math.floor(Math.random() * adjectives.length)];
    var noun = nouns[Math.floor(Math.random() * nouns.length)];
    var num = String(Math.floor(Math.random() * 90) + 10);
    return adj + "-" + noun + "-" + num;
}

function vfShowNameDropdown(serviceId, systemUrl) {
    var dropdown = $("#vf-name-dropdown");
    dropdown.empty();

    for (var i = 0; i < 4; i++) {
        var name = vfGenerateFriendlyName();
        var opt = $('<div class="vf-name-option"></div>').text(name);
        (function (n) {
            opt.on("click", function () {
                $("#vf-rename-input").val(n);
                dropdown.hide();
            });
        })(name);
        dropdown.append(opt);
    }

    var refreshBtn = $('<div class="vf-name-option text-muted" style="text-align:center;cursor:pointer;">&#x21bb; More options</div>');
    refreshBtn.on("click", function () {
        vfShowNameDropdown(serviceId, systemUrl);
    });
    dropdown.append(refreshBtn);
    dropdown.show();
}

function vfRenameServer(serviceId, systemUrl) {
    var name = $("#vf-rename-input").val().trim().toLowerCase();
    var alertDiv = $("#vf-rename-alert");
    alertDiv.hide();

    if (!name || !/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/.test(name)) {
        alertDiv.removeClass("alert-success").addClass("alert alert-danger");
        alertDiv.text("Invalid name. Use lowercase letters, numbers, and hyphens (2-63 chars, must start/end with alphanumeric).");
        alertDiv.show();
        return;
    }

    var btn = $("#vf-rename-save");
    btn.prop("disabled", true);

    $.ajax({
        type: "POST",
        dataType: "json",
        url: systemUrl + "modules/servers/VirtFusionDirect/client.php?serviceID=" + encodeURIComponent(serviceId) + "&action=rename",
        data: { name: name }
    }).done(function (response) {
        if (response.success) {
            alertDiv.removeClass("alert-danger").addClass("alert alert-success");
            alertDiv.text("Server renamed successfully.");
        } else {
            alertDiv.removeClass("alert-success").addClass("alert alert-danger");
            alertDiv.text("Rename failed. Please try again.");
        }
        alertDiv.show();
    }).fail(function () {
        alertDiv.removeClass("alert-success").addClass("alert alert-danger");
        alertDiv.text("An error occurred. Please try again.");
        alertDiv.show();
    }).always(function () {
        btn.prop("disabled", false);
        setTimeout(function () { alertDiv.fadeOut(); }, 3000);
    });
}

// =========================================================================
// Utility — Copy to Clipboard
// =========================================================================

function vfCopyButton(text) {
    var btn = $('<button type="button" class="btn btn-sm vf-ip-copy" title="Copy"></button>');
    btn.html('<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M4 2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H6z"/><path d="M2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1H2z"/></svg>');
    btn.on("click", function () {
        var $this = $(this);
        navigator.clipboard.writeText(text).then(function () {
            $this.html('<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M13.485 1.929a.75.75 0 0 1 .086 1.057l-7.5 9a.75.75 0 0 1-1.1.043l-3.5-3.5a.75.75 0 0 1 1.06-1.06l2.915 2.915 6.982-8.382a.75.75 0 0 1 1.057-.073z"/></svg>');
            var tooltip = $('<span class="vf-copy-tooltip">Copied!</span>');
            $this.parent().append(tooltip);
            setTimeout(function () {
                tooltip.remove();
                $this.html('<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M4 2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H6z"/><path d="M2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1H2z"/></svg>');
            }, 1500);
        }).catch(function () {
            var tooltip = $('<span class="vf-copy-tooltip" style="background:#dc3545;">Failed</span>');
            $this.parent().append(tooltip);
            setTimeout(function () { tooltip.remove(); }, 1500);
        });
    });
    return btn;
}
