/**
 * VirtFusion Direct Provisioning Module - Client JavaScript
 *
 * ========================================================================
 * ARCHITECTURE
 * ========================================================================
 *
 * This file is the single client-side script that powers both:
 *   - The client area (service overview panel, loaded on every service page)
 *   - The admin services tab (server info + rDNS widget)
 *
 * It uses vanilla JS + jQuery. jQuery is available because WHMCS's built-in
 * admin UI depends on it; we inherit that dependency rather than adding a
 * new one. The order form hooks (keygen.js, OS-gallery injector in hooks.php)
 * use vanilla JS only because those run on pre-auth checkout pages where
 * jQuery availability varies by theme.
 *
 * CONVENTION: every function is prefixed with "vf" to avoid collisions with
 * whatever else the page loads. Internal helpers start with "_vf".
 *
 * ========================================================================
 * SECTIONS (roughly in order below)
 * ========================================================================
 *
 *   Shared Helpers              — vfUrl, vfShowAlert
 *   Progress Indicator          — vfShowProgress / vfHideProgress
 *   Server Data Display         — vfServerData, vfServerDataAdmin
 *   Power Management            — vfPowerAction
 *   SSO Login                   — vfLoginAsServerOwner
 *   Password Reset              — vfUserPasswordReset, vfResetServerPassword
 *   Server Rebuild              — vfRebuildServer, vfLoadOsTemplates, vfRenderOsGallery
 *   Server Rename               — vfRenameServer, vfShowNameDropdown
 *   Traffic / Backups           — vfLoadTrafficStats, vfDrawTrafficChart, vfLoadBackups
 *   VNC Console                 — vfOpenVnc, vfToggleVnc
 *   Self-Service Billing        — vfLoadSelfServiceUsage, vfAddCredit
 *   Reverse DNS (PowerDNS)      — vfLoadRdns, vfRenderRdnsPanel, vfUpdateRdns,
 *                                 vfAdminLoadRdns, vfAdminReconcileRdns
 *
 * ========================================================================
 * AJAX REQUEST SHAPE
 * ========================================================================
 *
 *   URL: {systemUrl}modules/servers/VirtFusionDirect/{endpoint}.php
 *          ?serviceID={id}&action={action}
 *     where endpoint is "client" (default) or "admin".
 *
 *   Method: GET for reads, POST for writes (server-side requirePost() gate
 *           enforces this for rDNS mutations; other mutations rely on $_POST
 *           being empty for GET → validation fails naturally).
 *
 *   Response:
 *     { success: true,  data: { ... } }
 *     { success: false, errors: "human message" }
 *
 * ========================================================================
 * ERROR HANDLING
 * ========================================================================
 *
 * Every AJAX call handles three outcomes:
 *   1. Network failure (.fail) → show a generic error in the panel's alert div
 *   2. Server returned success:false → show response.errors to the user
 *   3. Server returned success:true → render data into the DOM
 *
 * Error text ALWAYS comes from the server (we don't invent user-facing error
 * copy client-side). That way a server-side change to error phrasing
 * propagates everywhere without JS changes.
 *
 * ========================================================================
 * DOM UPDATE PATTERNS
 * ========================================================================
 *
 *   Read actions render into named containers with id="vf-data-*".
 *   Status badges use CSS classes "vf-badge-*" for color coding.
 *   Text content is always set via .text() not .html() to prevent XSS
 *     from whatever the API returned. Exception: panels built entirely
 *     from server-trusted structured data use .append() with new jQuery
 *     elements, not string concatenation.
 *
 * Handles client-side interactions for:
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
 * - Reverse DNS (PowerDNS addon)
 */

// =========================================================================
// Shared Helpers
// =========================================================================

function vfUrl(systemUrl, serviceId, action, endpoint) {
    return (systemUrl || "") + "modules/servers/VirtFusionDirect/" + (endpoint || "client") + ".php?serviceID=" + encodeURIComponent(serviceId) + "&action=" + encodeURIComponent(action);
}

function vfShowAlert(alertDiv, type, message) {
    alertDiv.removeClass("alert-danger alert-success alert-warning alert");
    alertDiv.addClass("alert alert-" + type);
    alertDiv.text(message);
    alertDiv.show();
}

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
        url: vfUrl(systemUrl, serviceId, "serverData")
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
        url: vfUrl(systemUrl, serviceId, "serverData", "admin")
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
        url: vfUrl(systemUrl, serviceId, "resetPassword")
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
        url: vfUrl(systemUrl, serviceId, "loginAsServerOwner")
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
        url: vfUrl(systemUrl, serviceId, "powerAction"),
        data: { powerAction: action }
    }).done(function (response) {
        if (response.success) {
            vfShowAlert(alertDiv, "success",response.data.message || (actionLabels[action] + " server..."));
        } else {
            vfShowAlert(alertDiv, "danger","Power action failed. Please try again.");
        }
        alertDiv.show();
    }).fail(function () {
        vfShowAlert(alertDiv, "danger","An error occurred. Please try again.");
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
        var brandColor = vfGetBrandColor(category.name);

        // Accordion header
        var header = $('<div class="vf-os-category-header"></div>');
        var iconSpan = $('<span class="vf-os-category-icon"></span>');
        if (category.icon && baseUrl) {
            var catImg = $('<img alt="">').attr("src", baseUrl + "/img/logo/" + encodeURIComponent(category.icon));
            catImg.on("error", function () {
                $(this).parent().css("background", brandColor);
                $(this).replaceWith($('<span></span>').text((category.name || "?")[0].toUpperCase()));
            });
            iconSpan.append(catImg);
        } else if (category.name === "Other") {
            iconSpan.css("background", "#6c757d").html('<svg width="16" height="16" viewBox="0 0 16 16" fill="#fff"><path d="M3 2a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1H3zm1 2h8v2H4V4zm0 3h8v1H4V7zm0 2h5v1H4V9z"/></svg>');
        } else {
            iconSpan.css("background", brandColor).text((category.name || "?")[0].toUpperCase());
        }
        var titleSpan = $('<span></span>').text(category.name + " (" + category.templates.length + ")");
        var arrow = $('<span class="vf-os-category-arrow">' + (ci === 0 ? '&#9660;' : '&#9654;') + '</span>');
        header.append(iconSpan).append(titleSpan).append(arrow);
        section.append(header);

        // Collapsible grid — first category open by default
        var grid = $('<div class="vf-os-grid"></div>');
        if (ci !== 0) grid.hide();

        header.on("click", function () {
            var isVisible = grid.is(":visible");
            // Collapse all other categories
            $container.find(".vf-os-grid").slideUp(200);
            $container.find(".vf-os-category-arrow").html('&#9654;');
            // Toggle this one
            if (!isVisible) {
                grid.slideDown(200);
                arrow.html('&#9660;');
            }
        });

        $.each(category.templates, function (ti, tpl) {
            var label = tpl.name + (tpl.version ? " " + tpl.version : "") + (tpl.variant ? " " + tpl.variant : "");
            var card = $('<div class="vf-os-card"></div>')
                .attr("data-id", tpl.id)
                .attr("data-search", label.toLowerCase());
            if (tpl.eol) card.addClass("vf-os-card-eol");

            var iconDiv = $('<div class="vf-os-icon"></div>');
            if (tpl.icon && baseUrl) {
                var tplImg = $('<img alt="">').attr("src", baseUrl + "/img/logo/" + encodeURIComponent(tpl.icon));
                tplImg.on("error", function () {
                    $(this).parent().css("background", brandColor);
                    $(this).replaceWith($('<span></span>').text((tpl.name || "?")[0].toUpperCase()));
                });
                iconDiv.append(tplImg);
            } else {
                iconDiv.css("background", brandColor);
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
        url: vfUrl(systemUrl, serviceId, "osTemplates")
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
        vfShowAlert(alertDiv, "danger","Please select an operating system.");
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
        url: vfUrl(systemUrl, serviceId, "rebuild"),
        data: { osId: osId }
    }).done(function (response) {
        if (response.success) {
            vfShowAlert(alertDiv, "success",response.data.message || "Server rebuild initiated. You will receive an email when the process is complete.");
        } else {
            vfShowAlert(alertDiv, "danger","Rebuild failed. Please try again.");
        }
        alertDiv.show();
    }).fail(function () {
        vfShowAlert(alertDiv, "danger","An error occurred. Please try again.");
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
        url: vfUrl(systemUrl, serviceId, "impersonateServerOwner", "admin")
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
        vfShowAlert(alertDiv, "danger","Popup blocked. Please allow popups for this site and try again.");
        spinner.hide();
        btn.prop("disabled", false);
        return;
    }

    $.ajax({
        type: "GET",
        dataType: "json",
        url: vfUrl(systemUrl, serviceId, "vnc")
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
                vfShowAlert(alertDiv, "success","VNC session is ready. Check your VirtFusion control panel for access.");
            }
        } else {
            vncWindow.close();
            vfShowAlert(alertDiv, "danger","VNC console is not available.");
        }
    }).fail(function () {
        vncWindow.close();
        vfShowAlert(alertDiv, "danger","An error occurred. The server may be powered off.");
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
        url: vfUrl(systemUrl, serviceId, "toggleVnc"),
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
        url: vfUrl(systemUrl, serviceId, "vnc")
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
        url: vfUrl(systemUrl, serviceId, "selfServiceUsage")
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

function vfAddCredit(serviceId, systemUrl) {
    var amount = $("#vf-ss-credit-amount").val();
    var alertDiv = $("#vf-selfservice-alert");
    var btn = $("#vf-ss-add-credit-btn");
    var spinner = $("#vf-ss-add-credit-spinner");

    if (!amount || parseFloat(amount) <= 0) {
        vfShowAlert(alertDiv, "danger","Please enter a valid positive amount.");
        return;
    }

    btn.prop("disabled", true);
    spinner.show();
    alertDiv.hide();

    $.ajax({
        type: "POST",
        dataType: "json",
        url: vfUrl(systemUrl, serviceId, "selfServiceAddCredit"),
        data: { tokens: amount }
    }).done(function (response) {
        if (response.success) {
            vfShowAlert(alertDiv, "success","Credit added successfully.");
            $("#vf-ss-credit-amount").val("");
            // Refresh usage data
            vfLoadSelfServiceUsage(serviceId, systemUrl);
        } else {
            vfShowAlert(alertDiv, "danger","Failed to add credit. Please try again.");
        }
    }).fail(function () {
        vfShowAlert(alertDiv, "danger","An error occurred. Please try again.");
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
        url: vfUrl(systemUrl, serviceId, "resetServerPassword")
    }).done(function (response) {
        if (response.success && response.data) {
            var data = response.data.data || response.data;
            var password = data.password || data.newPassword || "";
            if (password) {
                navigator.clipboard.writeText(password).then(function () {
                    vfShowAlert(alertDiv, "success","New password copied to clipboard.");
                }).catch(function () {
                    vfShowAlert(alertDiv, "warning","Password reset successful. Unable to copy to clipboard automatically.");
                });
            } else {
                vfShowAlert(alertDiv, "success","Password reset initiated. Check your email for the new credentials.");
            }
        } else {
            vfShowAlert(alertDiv, "danger","Password reset failed. Please try again.");
        }
    }).fail(function () {
        vfShowAlert(alertDiv, "danger","An error occurred. Please try again.");
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
        url: vfUrl(systemUrl, serviceId, "backups")
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
        url: vfUrl(systemUrl, serviceId, "trafficStats")
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
        vfShowAlert(alertDiv, "danger","Invalid name. Use lowercase letters, numbers, and hyphens (2-63 chars, must start/end with alphanumeric).");
        return;
    }

    var btn = $("#vf-rename-save");
    btn.prop("disabled", true);

    $.ajax({
        type: "POST",
        dataType: "json",
        url: vfUrl(systemUrl, serviceId, "rename"),
        data: { name: name }
    }).done(function (response) {
        if (response.success) {
            vfShowAlert(alertDiv, "success","Server renamed successfully.");
        } else {
            vfShowAlert(alertDiv, "danger","Rename failed. Please try again.");
        }
        alertDiv.show();
    }).fail(function () {
        vfShowAlert(alertDiv, "danger","An error occurred. Please try again.");
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

// =========================================================================
// Reverse DNS (PowerDNS)
// =========================================================================
//
// Feature gate: this section only activates when the VirtFusionDns addon is
// installed AND enabled. The PHP side renders the rDNS panel in overview.tpl
// only when $rdnsEnabled is true; if the panel isn't in the DOM, these
// functions are never called.
//
// Admin-side counterparts (vfAdminLoadRdns, vfAdminReconcileRdns) target
// admin.php instead of client.php and are used by the rdnsSection() admin
// widget rendered via AdminHTML::rdnsSection().
//
// Status badge colours match what most operators expect:
//   OK (green)        = PTR present, forward DNS agrees (FCrDNS passes)
//   unverified (amber) = PTR present but forward DNS no longer agrees
//   missing (gray)     = No PTR exists yet
//   no-zone (red)      = The IP's reverse zone isn't hosted in PowerDNS
//   error (red)        = PowerDNS unreachable or similar
//
// The server-side always decides the status; we just colour it.

/** Badge metadata used by vfRdnsBadge(). Kept here so colours/labels are tweakable in one place. */
var VF_RDNS_STATUS = {
    "ok":           { label: "OK",          bg: "#28a745", fg: "#fff" },
    "unverified":   { label: "unverified",  bg: "#f0ad4e", fg: "#000" },
    "missing":      { label: "no PTR",      bg: "#6c757d", fg: "#fff" },
    "no-zone":      { label: "no zone",     bg: "#dc3545", fg: "#fff" },
    "error":        { label: "error",       bg: "#dc3545", fg: "#fff" },
    "disabled":     { label: "disabled",    bg: "#6c757d", fg: "#fff" },
    "subnet-only":  { label: "subnet",      bg: "#17a2b8", fg: "#fff" }
};

function vfRdnsBadge(status) {
    var s = VF_RDNS_STATUS[status] || VF_RDNS_STATUS["error"];
    var span = $('<span class="vf-rdns-badge"></span>');
    span.text(s.label);
    span.css({ background: s.bg, color: s.fg });
    return span;
}

function vfLoadRdns(serviceId, systemUrl) {
    var list = $("#vf-rdns-list");
    $.ajax({
        url: vfUrl(systemUrl, serviceId, "rdnsList"),
        method: "GET",
        dataType: "json"
    }).done(function (resp) {
        if (!resp || !resp.success) {
            list.html('<div class="text-muted">Unable to load reverse DNS.</div>');
            return;
        }
        if (!resp.data.enabled) {
            list.closest(".panel").hide();
            return;
        }
        vfRenderRdnsPanel(serviceId, systemUrl, resp.data.ips || []);
    }).fail(function () {
        list.html('<div class="text-muted">Unable to load reverse DNS.</div>');
    });
}

function vfRenderRdnsPanel(serviceId, systemUrl, ips) {
    var list = $("#vf-rdns-list");
    list.empty();
    if (!ips.length) {
        list.html('<div class="text-muted">No IP addresses assigned to this server yet.</div>');
        return;
    }
    ips.forEach(function (row) {
        // Subnet-only rows (IPv6 /64 allocations) render as a distinct informational
        // anchor with an expandable "Add host PTR" form — the customer types a
        // specific address inside the subnet + hostname, backend verifies containment.
        if (row.status === "subnet-only") {
            list.append(vfRenderSubnetRow(serviceId, systemUrl, row));
            return;
        }
        list.append(vfRenderIpRow(serviceId, systemUrl, row));
    });
}

/** Standard per-IP row with inline PTR editor. Used for v4 addresses + discrete v6 hosts. */
function vfRenderIpRow(serviceId, systemUrl, row) {
    var wrap = $('<div class="vf-rdns-row"></div>');
    var ipLabel = $('<div class="vf-rdns-ip"></div>').text(row.ip);
    var badge = vfRdnsBadge(row.status);

    var input = $('<input type="text" class="form-control form-control-sm vf-rdns-input" maxlength="253" placeholder="host.example.com (blank to delete)">');
    input.val(row.ptr || "");

    var saveBtn = $('<button type="button" class="btn btn-sm btn-primary">Save</button>');
    var msg = $('<div class="vf-rdns-msg"></div>');

    saveBtn.on("click", function () {
        vfUpdateRdns(serviceId, systemUrl, row.ip, input, saveBtn, msg, badge);
    });
    input.on("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); saveBtn.click(); }
    });

    var editor = $('<div class="vf-rdns-edit"></div>').append(input).append(saveBtn);
    return wrap.append(ipLabel).append(editor).append(badge).append(msg);
}

/**
 * Subnet-only row: shows "2602:2f3:0:5d::/64" with a collapsible "Add host PTR" form.
 *
 * Why collapsed by default: most customers won't set custom v6 PTRs, so burying
 * the form until explicitly requested keeps the panel uncluttered for the common
 * case. Adding a host PTR is a power-user operation (needs a pre-existing AAAA
 * record) so surfacing it as a secondary action is UX-appropriate.
 */
function vfRenderSubnetRow(serviceId, systemUrl, row) {
    var wrap = $('<div class="vf-rdns-row vf-rdns-subnet-row"></div>');
    var label = $('<div class="vf-rdns-ip"></div>').text(row.subnet + "/" + row.cidr);
    var badge = vfRdnsBadge(row.status);

    var toggleBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary">+ Add host PTR</button>');
    var form = $('<div class="vf-rdns-subnet-form" style="display:none;"></div>');

    var ipInput = $('<input type="text" class="form-control form-control-sm vf-rdns-input" placeholder="Host IPv6 address inside this subnet (e.g. 2602:2f3:0:5d::10)">');
    var ptrInput = $('<input type="text" class="form-control form-control-sm vf-rdns-input" maxlength="253" placeholder="Hostname for PTR (e.g. mail.example.com)">');
    var addBtn = $('<button type="button" class="btn btn-sm btn-primary">Add PTR</button>');
    var cancelBtn = $('<button type="button" class="btn btn-sm btn-link">Cancel</button>');
    var msg = $('<div class="vf-rdns-msg"></div>');

    toggleBtn.on("click", function () {
        form.toggle();
        toggleBtn.text(form.is(":visible") ? "− Hide" : "+ Add host PTR");
    });
    cancelBtn.on("click", function () {
        form.hide();
        toggleBtn.text("+ Add host PTR");
        ipInput.val(""); ptrInput.val(""); msg.hide();
    });

    addBtn.on("click", function () {
        var ip = (ipInput.val() || "").trim();
        var ptr = (ptrInput.val() || "").trim();
        if (!ip) { msg.text("Enter a host IPv6 address.").css("color", "#dc3545").show(); return; }
        if (!ptr) { msg.text("Enter a hostname for the PTR.").css("color", "#dc3545").show(); return; }
        // Same server-side validation guards apply; we reuse the normal update flow.
        vfUpdateRdns(serviceId, systemUrl, ip, ptrInput, addBtn, msg, null, function () {
            // On success, refresh the whole panel so the new host PTR shows up as its own row
            // alongside the subnet it came from.
            setTimeout(function () { vfLoadRdns(serviceId, systemUrl); }, 1500);
        });
    });
    ipInput.on("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); ptrInput.focus(); } });
    ptrInput.on("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); addBtn.click(); } });

    var inputsRow = $('<div class="vf-rdns-subnet-inputs"></div>').append(ipInput).append(ptrInput);
    var actionsRow = $('<div class="vf-rdns-subnet-actions"></div>').append(addBtn).append(cancelBtn);
    form.append(inputsRow).append(actionsRow).append(msg);

    var editorWrap = $('<div class="vf-rdns-edit"></div>').append(toggleBtn);
    return wrap.append(label).append(editorWrap).append(badge).append(form);
}

function vfUpdateRdns(serviceId, systemUrl, ip, input, saveBtn, msg, badge, onSuccess) {
    var ptr = (input.val() || "").trim();
    // Light client-side regex mirrors the server-side one — strict enforcement is on the server.
    if (ptr !== "" && !/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}\.?$/.test(ptr)) {
        msg.text("Invalid hostname.").css("color", "#dc3545").show();
        return;
    }
    saveBtn.prop("disabled", true);
    msg.hide();

    $.ajax({
        url: vfUrl(systemUrl, serviceId, "rdnsUpdate"),
        method: "POST",
        data: { ip: ip, ptr: ptr },
        dataType: "json"
    }).done(function (resp) {
        saveBtn.prop("disabled", false);
        if (resp && resp.success) {
            var verb = (ptr === "") ? "deleted" : "saved";
            msg.text("rDNS " + verb + ".").css("color", "#28a745").show();
            setTimeout(function () { msg.fadeOut(); }, 2500);
            // Badge may be null (e.g. when called from the subnet row's Add-PTR form
            // which has no per-row badge to update). Guard rather than crash.
            if (badge) {
                // Optimistically update the badge; a background refresh will correct it.
                if (ptr === "") {
                    badge.replaceWith(vfRdnsBadge("missing"));
                } else {
                    badge.replaceWith(vfRdnsBadge("ok"));
                }
            }
            if (typeof onSuccess === "function") { onSuccess(); }
        } else {
            var err = (resp && resp.errors) ? resp.errors : "Save failed.";
            msg.text(err).css("color", "#dc3545").show();
        }
    }).fail(function (xhr) {
        saveBtn.prop("disabled", false);
        var err = "Save failed.";
        try {
            var r = JSON.parse(xhr.responseText);
            if (r && r.errors) err = r.errors;
        } catch (e) {}
        msg.text(err).css("color", "#dc3545").show();
    });
}

// Admin-side wrappers — different endpoint ("admin"), no ownership check on server side.

function vfAdminLoadRdns(serviceId, systemUrl) {
    var list = $("#vf-rdns-list");
    $.ajax({
        url: vfUrl(systemUrl, serviceId, "rdnsStatus", "admin"),
        method: "GET",
        dataType: "json"
    }).done(function (resp) {
        if (!resp || !resp.success) {
            list.html('<em class="text-muted">Unable to load PTR state.</em>');
            return;
        }
        if (!resp.data.enabled) {
            list.html('<em class="text-muted">Reverse DNS addon is not activated.</em>');
            return;
        }
        list.empty();
        if (!resp.data.ips.length) {
            list.html('<em class="text-muted">No IPs assigned.</em>');
            return;
        }
        resp.data.ips.forEach(function (row) {
            var line = $('<div class="vf-rdns-admin-row"></div>');
            $('<span class="vf-rdns-ip-admin"></span>').text(row.ip).appendTo(line);
            $('<span class="vf-rdns-ptr-admin"></span>').text(row.ptr || "(no PTR)").appendTo(line);
            vfRdnsBadge(row.status).appendTo(line);
            list.append(line);
        });
    }).fail(function () {
        list.html('<em class="text-muted">Unable to load PTR state.</em>');
    });
}

function vfAdminReconcileRdns(serviceId, systemUrl, force) {
    var out = $("#vf-rdns-report");
    out.text("Reconciling…").css("color", "#555");
    $.ajax({
        url: vfUrl(systemUrl, serviceId, "rdnsReconcile", "admin"),
        method: "POST",
        data: { force: force ? 1 : 0 },
        dataType: "json"
    }).done(function (resp) {
        if (resp && resp.success) {
            var s = resp.data;
            var parts = [];
            ["added", "reset", "preserved", "forward_missing", "no_zone", "errors"].forEach(function (k) {
                if (s[k] > 0) parts.push(k + "=" + s[k]);
            });
            out.text(parts.length ? parts.join(" ") : "no changes needed").css("color", "#28a745");
            vfAdminLoadRdns(serviceId, systemUrl);
        } else {
            out.text((resp && resp.errors) ? resp.errors : "Reconcile failed").css("color", "#dc3545");
        }
    }).fail(function () {
        out.text("Reconcile failed").css("color", "#dc3545");
    });
}
