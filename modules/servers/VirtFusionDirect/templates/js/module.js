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
 *   Server Rebuild              — vfRebuildServer, vfLoadOsTemplates, vfRenderRebuildWizard
 *   Server Rename               — vfRenameServer, vfShowNameDropdown
 *   Traffic / Backups           — vfLoadTrafficStats, vfDrawTrafficChart, vfLoadBackups
 *   VNC Console                 — vfOpenVnc
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

/**
 * vfConfirm(options)
 * Renders a premium in-page confirmation modal instead of the browser's
 * native confirm() dialog. Returns nothing — callback-based.
 *
 *   options.title   — modal heading
 *   options.message — body text
 *   options.danger  — (bool) if true, confirm button is red
 *   options.ok      — label for confirm button (default "Confirm")
 *   options.cancel  — label for cancel button (default "Cancel")
 *   options.onOk    — function called when user clicks confirm
 */
function vfConfirm(options) {
    var id = "vf-confirm-modal-" + Date.now();
    var dangerClass = options.danger ? "vf-confirm-btn-danger" : "vf-confirm-btn-primary";
    var html = '<div class="vf-confirm-backdrop" id="' + id + '">' +
        '<div class="vf-confirm-dialog" role="dialog" aria-modal="true">' +
            '<div class="vf-confirm-icon ' + (options.danger ? "vf-confirm-icon-danger" : "") + '">' +
                '<svg width="22" height="22" viewBox="0 0 24 24" fill="none">' +
                    '<path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"' +
                    ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>' +
            '</div>' +
            '<div class="vf-confirm-body">' +
                '<div class="vf-confirm-title">' + $('<span>').text(options.title || "Are you sure?").html() + '</div>' +
                '<div class="vf-confirm-msg">' + $('<span>').text(options.message || "").html() + '</div>' +
            '</div>' +
            '<div class="vf-confirm-actions">' +
                '<button class="vf-confirm-btn vf-confirm-btn-cancel" id="' + id + '-cancel">' + (options.cancel || "Cancel") + '</button>' +
                '<button class="vf-confirm-btn ' + dangerClass + '" id="' + id + '-ok">' + (options.ok || "Confirm") + '</button>' +
            '</div>' +
        '</div>' +
    '</div>';
    var $modal = $(html);
    $('body').append($modal);
    // Animate in
    requestAnimationFrame(function() { $modal.addClass('vf-confirm-visible'); });
    function close() { $modal.removeClass('vf-confirm-visible'); setTimeout(function() { $modal.remove(); }, 200); }
    $('#' + id + '-cancel').on('click', function() { close(); });
    $('#' + id + '-ok').on('click', function() { close(); if (typeof options.onOk === 'function') options.onOk(); });
    // Close on backdrop click
    $modal.on('click', function(e) { if ($(e.target).is($modal)) close(); });
    // Focus confirm button for keyboard users
    setTimeout(function() { $('#' + id + '-ok').focus(); }, 50);
}

// -------------------------------------------------------------------------
// Display helpers — country flag emoji from ISO-2 code, relative-date string,
// and an IP-mask toggle for screenshots.
// -------------------------------------------------------------------------

// Convert a 2-letter country code ("us", "GB") into the corresponding flag
// emoji using Unicode Regional Indicator Symbols. Returns "" for invalid
// inputs so callers can safely concatenate without sanity checks.
function vfCountryFlag(code) {
    if (!code || typeof code !== "string" || code.length !== 2) return "";
    var c = code.toUpperCase();
    var a = c.charCodeAt(0), b = c.charCodeAt(1);
    if (a < 65 || a > 90 || b < 65 || b > 90) return "";
    var offset = 0x1F1E6 - 65;
    try { return String.fromCodePoint(a + offset, b + offset); }
    catch (e) { return ""; }
}

// Produce a friendly relative-time string ("3 days ago", "in 2 hours") from
// any value Date can parse (ISO 8601 from the VF API works directly).
function vfRelativeDate(input) {
    if (!input) return "";
    var t = new Date(input).getTime();
    if (isNaN(t)) return "";
    var seconds = Math.round((Date.now() - t) / 1000);
    var future = seconds < 0;
    var abs = Math.abs(seconds);
    var units = [
        { s: 60, label: "second" },
        { s: 3600, label: "minute", div: 60 },
        { s: 86400, label: "hour", div: 3600 },
        { s: 604800, label: "day", div: 86400 },
        { s: 2629800, label: "week", div: 604800 },
        { s: 31557600, label: "month", div: 2629800 },
        { s: Infinity, label: "year", div: 31557600 }
    ];
    for (var i = 0; i < units.length; i++) {
        if (abs < units[i].s) {
            var v = Math.max(1, Math.floor(units[i].div ? abs / units[i].div : abs));
            var unit = units[i].label + (v === 1 ? "" : "s");
            return future ? ("in " + v + " " + unit) : (v + " " + unit + " ago");
        }
    }
    return "";
}

// IP masking — keeps enough of the address visible to convey "same network"
// while hiding the host-identifying portion. Per IPv4: mask the last two
// octets (1.2.•••.•••). Per IPv6: keep the first two hextets and replace
// everything else with a placeholder, preserving any /CIDR suffix. Comma-
// separated lists are masked element-by-element.
//
// State persists in sessionStorage so the customer's preference survives a
// page refresh during a screenshot session.
function _vfMaskAny(s) {
    var str = String(s == null ? "" : s).trim();
    if (!str) return str;
    // IPv4 dotted-quad with optional CIDR.
    var v4 = str.match(/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}(\/\d+)?$/);
    if (v4) return v4[1] + "." + v4[2] + ".•••.•••" + (v4[3] || "");
    // IPv6 — at least one ":" and only hex/colon/slash chars allowed (the
    // strict regex avoids masking unrelated text like "Memory: 8 GB").
    if (str.indexOf(":") !== -1 && /^[0-9a-fA-F:\/]+$/.test(str)) {
        var slash = str.indexOf("/");
        var cidr = slash !== -1 ? str.substring(slash) : "";
        var addr = slash !== -1 ? str.substring(0, slash) : str;
        var parts = addr.split(":");
        var visible = [];
        for (var i = 0; i < parts.length && visible.length < 2; i++) {
            if (parts[i] !== "") visible.push(parts[i]);
        }
        if (visible.length === 0) {
            return str.replace(/[0-9a-fA-F]/g, "•");
        }
        return visible.join(":") + ":••••::•" + cidr;
    }
    // Hostname-shaped (alphanumeric + . _ -) with at least one letter.
    // Mask each dot-separated label after its first character so the
    // structure ("a.b.c") and TLD shape ("•••.com" → "•••.•••") stays
    // hinted at without leaking the full hostname.
    if (/^[a-zA-Z0-9._-]+$/.test(str) && /[a-zA-Z]/.test(str)) {
        return str.split(".").map(function (part) {
            if (part.length <= 1) return part;
            return part[0] + part.slice(1).replace(/[a-zA-Z0-9_-]/g, "•");
        }).join(".");
    }
    // Not recognised — leave unchanged.
    return str;
}

function vfMaskString(s) {
    if (!s) return "";
    var str = String(s).trim();
    if (str.indexOf(",") !== -1) {
        return str.split(",").map(function (x) { return _vfMaskAny(x.trim()); }).join(", ");
    }
    return _vfMaskAny(str);
}

function vfApplyIpMask() {
    var masked = sessionStorage.getItem("vfIpMasked") === "1";
    var label = document.getElementById("vf-mask-ips-label");
    if (label) label.textContent = masked ? "Unmask" : "Mask Sensitive";

    // Toggle a body-level class so CSS can mask <input> fields (text-security
    // on input.vf-sensitive). Text content (IPs in cells) is masked below
    // via attribute swap because text-security doesn't apply to non-input
    // elements without breaking layout/selection.
    document.body.classList.toggle("vf-mask-active", masked);

    // .vf-ip / .vf-ip-address: IP-bearing text cells.
    // .vf-sensitive (non-input): hostname/name text cells.
    // Inputs marked .vf-sensitive are masked by CSS text-security and
    // skipped here so we don't replace the editable value.
    var nodes = document.querySelectorAll(".vf-ip, .vf-ip-address, .vf-sensitive");
    nodes.forEach(function (el) {
        if (el.tagName === "INPUT" || el.tagName === "TEXTAREA") return;
        var orig = el.getAttribute("data-vf-ip-original");
        if (masked) {
            // Cache original text on first mask (or refresh if upstream
            // re-rendered the cell with new content while masked).
            if (orig === null || (orig !== el.textContent && el.textContent.indexOf("•") === -1)) {
                orig = el.textContent;
                el.setAttribute("data-vf-ip-original", orig);
            }
            if (orig) el.textContent = vfMaskString(orig);
        } else if (orig !== null) {
            el.textContent = orig;
            el.removeAttribute("data-vf-ip-original");
        }
    });
}

function vfToggleIpMask() {
    var masked = sessionStorage.getItem("vfIpMasked") === "1";
    sessionStorage.setItem("vfIpMasked", masked ? "0" : "1");
    vfApplyIpMask();
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
            var data = response.data;
            $("#vf-rename-input").val(data.name);
            
            if (data.hostname && data.hostname !== '-' && data.hostname.toLowerCase() !== 'none') {
                $("#vf-data-server-hostname").text(data.hostname);
                $("#vf-row-hostname").show();
            } else {
                $("#vf-row-hostname").hide();
            }
            
            $("#vf-data-server-memory").text(data.memory);
            $("#vf-data-server-traffic").text(data.traffic);
            $("#vf-data-server-traffic-used").text(data.trafficUsed || "-");
            $("#vf-data-server-storage").text(data.storage);
            $("#vf-data-server-cpu").text(data.cpu);
            var pn = data.primaryNetwork || {};
            
            var ipv4List = Array.isArray(pn.ipv4Unformatted) ? pn.ipv4Unformatted.filter(function(ip) { return ip && ip !== '-'; }) : [];
            if (ipv4List.length > 0) {
                vfRenderIpCells("#vf-data-server-ipv4", ipv4List);
                $("#vf-row-ipv4").show();
            } else {
                $("#vf-row-ipv4").hide();
            }
            
            var ipv6List = Array.isArray(pn.ipv6Unformatted) ? pn.ipv6Unformatted.filter(function(ip) { return ip && ip !== '-'; }) : [];
            if (ipv6List.length > 0) {
                vfRenderIpCells("#vf-data-server-ipv6", ipv6List);
                $("#vf-row-ipv6").show();
            } else {
                $("#vf-row-ipv6").hide();
            }

            // -- Network Configuration card (Network tab) ---------------
            // Populate gateway, subnet mask, CIDR, and MAC from the primary
            // interface. Show the card only if we have real data (not just "-").
            var gateway = pn.gateway || "-";
            var netmask = pn.netmask || "-";
            var cidr    = pn.cidr    || "-";
            var mac     = pn.mac     || "-";
            $("#vf-net-gateway").text(gateway);
            $("#vf-net-netmask").text(netmask === "-" ? "-" : netmask);
            $("#vf-net-cidr").text(cidr === "-" ? "-" : "/" + cidr);
            $("#vf-net-mac").text(mac);
            if (gateway !== "-" || netmask !== "-" || mac !== "-") {
                $("#vf-network-info-card").show();
            }

            // -- Top meta bar (location, OS, lifetime) -----------------
            $("#vf-overview-meta").show();
            if (data.location && data.location !== "-") {
                var flag = vfCountryFlag(data.locationIcon);
                $("#vf-data-location").show().html("")
                    .append(flag ? document.createTextNode(flag + " ") : "")
                    .append(document.createTextNode(data.location));
            }
            if (data.osName && data.osName !== "-") {
                var osChip = $("#vf-data-os").show().empty();
                // Prefer the qemu-agent's pretty name (more accurate point-in-time)
                // and fall back to the template name otherwise.
                var primaryLabel = data.osPretty || data.osName;
                osChip.text(primaryLabel);
                if (data.osKernel) {
                    osChip.attr("title", "Kernel: " + data.osKernel);
                }
            }
            if (data.createdAt) {
                $("#vf-data-created").show().text("Created " + vfRelativeDate(data.createdAt))
                    .attr("title", new Date(data.createdAt).toLocaleString());
            }

            // -- Hypervisor maintenance banner -------------------------
            if (data.hypervisorMaintenance) {
                $("#vf-maintenance-banner").show();
            } else {
                $("#vf-maintenance-banner").hide();
            }

            // -- Live Stats panel + Filesystem rows --------------------
            // Both are derived from the same remoteState/agent payload, so
            // we render them together. live.* fields are null when the
            // upstream call didn't include remoteState — defensive guards
            // hide each section independently in that case.
            vfRenderLiveStats(data.live, data.memoryRaw, serviceId);
            vfRenderFilesystems(data.live ? data.live.filesystems : []);

            // Kick off the 30s auto-refresh now that we have valid args.
            // Subsequent vfServerData calls will reuse the same timer
            // (vfStartLiveStatsRefresh clears + re-schedules each time).
            if (typeof window.vfStartLiveStatsRefresh === "function") {
                window.vfStartLiveStatsRefresh(serviceId, systemUrl);
            }

            // Apply current mask state to the IPs we just rendered (and
            // any other .vf-ip elements already on the page).
            vfApplyIpMask();

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

            // VNC has no useful enable/disable state from VF (the panel-side
            // toggle was a firewall flag that's currently broken). Open
            // Console is always available; details panel only appears after
            // first Open Console click.

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
                $("#vf-res-traffic").text(d.traffic || "Unmetered");
                $("#vf-res-traffic-bar").css("width", "0%");
            }

            $("#vf-resources-panel").show();

            // Re-apply mask state to the IP cells we just (re)rendered.
            vfApplyIpMask();

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

function vfRenderRebuildWizard(container, data, sshKeys, hiddenInput) {
    var $container = $(container);
    $container.empty();
    var authWrap = $("#vf-rebuild-auth-wrap").empty().hide();

    if (!data || !data.categories || data.categories.length === 0) {
        $container.append($('<p class="text-muted"></p>').text("No templates available"));
        $container.show();
        return;
    }

    var baseUrl = data.baseUrl || "";

    // -- Row bar header (OS Family | Version) --
    var rowbar = $('<div class="vf-rebuild-rowbar"></div>');
    rowbar.append($('<span class="vf-rebuild-rowbar-col vf-left"></span>').text("Operating System"));
    rowbar.append('<span class="vf-rebuild-rowbar-divider"></span>');
    rowbar.append($('<span class="vf-rebuild-rowbar-col vf-right"></span>').text("Version"));
    $container.append(rowbar);

    // -- Family pills row --
    var familyRow = $('<div class="vf-rebuild-family-row"></div>');

    // -- Version panel (hidden until family selected) --
    var versionsWrap = $('<div class="vf-rebuild-versions-wrap"></div>');
    var versionPanel = $('<div class="vf-rebuild-version-panel"></div>');
    var versionsList = $('<div class="vf-rebuild-versions-list"></div>');
    versionPanel.append(versionsList);
    versionsWrap.append(versionPanel);

    // -- Auth Panel DOM --
    var authHtml = `
    <div id="vf-ssh-title" class="mt-4">Authentication Method</div>
    <div id="vf-ssh-subtitle">Choose how you want to access your server.</div>
    <div class="vf-auth-error" id="vf-rebuild-auth-error">Please select an authentication method to continue.</div>
    <div id="vf-auth-method">
        <div id="vf-auth-grid">
            <!-- Password Card -->
            <div class="vf-auth-option" id="vf-rebuild-auth-pwd">
                <div class="vf-auth-left">
                    <div class="vf-auth-title-row">
                        <span class="vf-auth-icon">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.8"/></svg>
                        </span>
                        <span class="vf-auth-title">Password Authentication</span>
                    </div>
                    <div class="vf-auth-desc">Quick access with password. Ideal for quick trials or remote login.</div>
                </div>
                <div class="vf-auth-cta-area">
                    <button type="button" class="vf-auth-cta" id="vf-rebuild-btn-pwd">Continue with Password</button>
                    <span class="vf-auth-check"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>Selected</span>
                </div>
            </div>
            <!-- SSH Card -->
            <div class="vf-auth-option vf-ssh-collapsed" id="vf-rebuild-auth-ssh">
                <div class="vf-auth-left">
                    <div class="vf-auth-title-row">
                        <span class="vf-auth-icon">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M7 14a5 5 0 1 1 3.9 4.8L9 21H7v-2H5v-2h2l1.2-1.2A5 5 0 0 1 7 14Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="vf-auth-title">SSH Key Authentication</span>
                        <span class="vf-auth-badge">Recommended</span>
                    </div>
                    <div class="vf-auth-desc">Secure and password-less login using a public key.</div>
                </div>
                <div class="vf-auth-cta-area">
                    <button type="button" class="vf-auth-cta" id="vf-rebuild-btn-ssh">Continue with SSH Key</button>
                    <span class="vf-auth-check"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>Selected</span>
                </div>
                <!-- SSH Details -->
                <div class="vf-ssh-details w-100">
                    <div class="vf-auth-hint">Generate a new key or provide your existing public key.</div>
                    <div class="vf-auth-flow">
                        <div class="vf-auth-flow-title">Use Existing Public Key</div>
                        <div class="vf-key-options" id="vf-rebuild-ssh-keys"></div>
                        <textarea class="form-control" id="vf-rebuild-ssh-paste" rows="3" placeholder="ssh-rsa AAAA... or ssh-ed25519 AAAA..."></textarea>
                        <div class="vf-auth-actions">
                            <button type="button" id="vf-rebuild-ssh-continue" disabled>Continue with This Public Key</button>
                        </div>
                    </div>
                    <div class="vf-auth-divider">OR</div>
                    <div class="vf-auth-flow">
                        <div class="vf-auth-flow-title">Generate a New Key</div>
                        <button type="button" id="vf-rebuild-ssh-generate">Generate New SSH Key</button>
                        <div id="vf-rebuild-ssh-gen-err" style="display:none;margin-top:8px;color:#dc3545;">Browser does not support key generation. Paste your key manually.</div>
                        <div id="vf-rebuild-privkey-panel" style="display:none;">
                            <div class="vf-privkey-title">Private Key - Save This Now!</div>
                            <textarea class="form-control" id="vf-rebuild-privkey-area" rows="6" readonly></textarea>
                            <div class="vf-privkey-actions">
                                <button type="button" class="btn btn-primary btn-sm" id="vf-rebuild-privkey-dl">Download</button>
                                <button type="button" class="btn btn-default btn-sm" id="vf-rebuild-privkey-cp">Copy</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    `;
    authWrap.append(authHtml);

    // Auth state logic
    var state = { mode: null, keyId: null, isWin: false };
    var $pwdCard = $("#vf-rebuild-auth-pwd");
    var $sshCard = $("#vf-rebuild-auth-ssh");
    var $pwdBtn = $("#vf-rebuild-btn-pwd");
    var $sshBtn = $("#vf-rebuild-btn-ssh");
    var $paste = $("#vf-rebuild-ssh-paste");
    var $contBtn = $("#vf-rebuild-ssh-continue");
    var $genBtn = $("#vf-rebuild-ssh-generate");
    var $keysDiv = $("#vf-rebuild-ssh-keys");

    function syncAuth() {
        if (state.mode === 'pwd') {
            $contBtn.prop('disabled', true);
            $genBtn.prop('disabled', true);
            return;
        }
        var has = state.keyId || $paste.val().trim().length > 0;
        if (state.mode === 'gen') {
            $contBtn.prop('disabled', true);
            $genBtn.prop('disabled', false);
        } else {
            $contBtn.prop('disabled', !has);
            $genBtn.prop('disabled', !!has);
        }
    }

    function setPwd(active) {
        $("#vf-rebuild-auth-error").removeClass("vf-visible");
        if (active) {
            state.mode = 'pwd';
            state.keyId = null;
            $paste.val('').prop('readonly', false);
            $("#vf-rebuild-privkey-panel").hide();
            $pwdCard.addClass('vf-selected');
            $pwdBtn.text('Password Selected');
            $sshCard.removeClass('vf-selected').addClass('vf-ssh-collapsed');
            $sshBtn.text('Continue with SSH Key');
            $("#vf-rebuild-ssh").val('');
            syncAuth();
        } else {
            $pwdCard.removeClass('vf-selected');
            $pwdBtn.text('Continue with Password');
            state.mode = null;
        }
    }

    function setSsh(active) {
        $("#vf-rebuild-auth-error").removeClass("vf-visible");
        if (active) {
            if (state.mode === 'pwd') setPwd(false);
            state.mode = 'ssh';
            $sshCard.addClass('vf-selected').removeClass('vf-ssh-collapsed');
            $sshBtn.text('SSH Key Selected');
        } else {
            $sshCard.removeClass('vf-selected').addClass('vf-ssh-collapsed');
            $sshBtn.text('Continue with SSH Key');
            state.mode = null;
        }
        syncAuth();
    }

    function setKey(kid) {
        setSsh(true);
        state.keyId = kid;
        $keysDiv.find('.vf-key-option').each(function() {
            $(this).attr('aria-pressed', $(this).attr('data-kid') === String(kid) ? 'true' : 'false');
        });
        if (kid) {
            $paste.val('').prop('readonly', true);
            $("#vf-rebuild-ssh").val(kid);
        } else {
            $paste.prop('readonly', false);
            $("#vf-rebuild-ssh").val($paste.val().trim());
        }
        $("#vf-rebuild-privkey-panel").hide();
        syncAuth();
    }

    $pwdBtn.on('click', function() { setPwd(state.mode !== 'pwd'); });
    $sshBtn.on('click', function() { setSsh(state.mode !== 'ssh'); });

    // Render user's saved keys
    var newKeyBtn = $('<button type="button" class="vf-key-option" data-kid="" aria-pressed="false">Paste a new key</button>');
    newKeyBtn.on('click', function() { setKey(''); });
    $keysDiv.append(newKeyBtn);

    $.each(sshKeys || [], function(i, k) {
        var btn = $('<button type="button" class="vf-key-option" aria-pressed="false"></button>');
        btn.attr('data-kid', k.id).text(k.name);
        btn.on('click', function() { setKey(k.id); });
        $keysDiv.append(btn);
    });

    $paste.on('input', function() {
        if ($(this).prop('readonly')) return;
        setSsh(true);
        if (state.keyId) {
            state.keyId = null;
            $keysDiv.find('.vf-key-option').attr('aria-pressed', 'false');
        }
        $("#vf-rebuild-ssh").val($(this).val().trim());
        $("#vf-rebuild-privkey-panel").hide();
        syncAuth();
    });

    $contBtn.on('click', function() {
        setSsh(true);
        $("#vf-rebuild-ssh").val(state.keyId ? state.keyId : $paste.val().trim());
        syncAuth();
    });

    $genBtn.on('click', async function() {
        setSsh(true);
        state.mode = 'gen';
        $contBtn.prop('disabled', true);
        $genBtn.prop('disabled', true).text('Generating...');
        try {
            state.keyId = null;
            $keysDiv.find('.vf-key-option').attr('aria-pressed', 'false');
            $paste.prop('readonly', false);
            $("#vf-rebuild-ssh-gen-err").hide();
            $("#vf-rebuild-privkey-panel").hide();
            
            var keys = await vfGenerateSSHKey();
            $paste.val(keys.publicKey);
            $("#vf-rebuild-ssh").val(keys.publicKey);
            $("#vf-rebuild-privkey-area").val(keys.privateKey);
            $("#vf-rebuild-privkey-panel").show();
        } catch (e) {
            $("#vf-rebuild-ssh-gen-err").show();
            state.mode = 'ssh';
        } finally {
            $genBtn.prop('disabled', false).text('Generate New SSH Key');
            syncAuth();
        }
    });

    $("#vf-rebuild-privkey-dl").on('click', function() {
        var bl = new Blob([$("#vf-rebuild-privkey-area").val()], {type: 'text/plain'});
        var a = document.createElement('a');
        a.href = URL.createObjectURL(bl);
        a.download = 'id_ed25519';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    });

    $("#vf-rebuild-privkey-cp").on('click', function() {
        var btn = $(this);
        navigator.clipboard.writeText($("#vf-rebuild-privkey-area").val()).then(function() {
            btn.text('Copied!');
            setTimeout(function() { btn.text('Copy'); }, 2000);
        });
    });


    $.each(data.categories, function (ci, category) {
        var brandColor = vfGetBrandColor(category.name);
        var isWin = (category.name || '').toLowerCase().indexOf('windows') !== -1;

        // Build the family pill button
        var pill = $('<button type="button" class="vf-rebuild-family-card" role="button" aria-pressed="false"></button>');
        pill.attr("data-family-index", ci);

        // OS icon
        var iconSpan = $('<span class="vf-rebuild-os-icon"></span>');
        if (category.icon && baseUrl) {
            var catImg = $('<img class="vf-rebuild-os-icon-img" alt="">');
            catImg.attr("src", baseUrl + "/img/logo/" + encodeURIComponent(category.icon));
            catImg.on("error", function () {
                $(this).parent().css("background", brandColor);
                $(this).replaceWith($('<span style="color:#fff;font-weight:700;font-size:13px;"></span>').text((category.name || "?")[0].toUpperCase()));
            });
            iconSpan.append(catImg);
        } else {
            iconSpan.css("background", brandColor);
            iconSpan.append($('<span style="color:#fff;font-weight:700;font-size:13px;"></span>').text((category.name || "?")[0].toUpperCase()));
        }
        pill.append(iconSpan);
        pill.append(document.createTextNode(category.name));

        // Click handler: show this family's version cards
        pill.on("click", function () {
            // Deselect all pills
            familyRow.find(".vf-rebuild-family-card").attr("aria-pressed", "false");
            // Select this one
            $(this).attr("aria-pressed", "true");

            // Clear hidden input + error state
            $(hiddenInput).val("");
            $("#vf-rebuild-os-error").removeClass("vf-visible");
            $("#vf-rebuild-auth-error").removeClass("vf-visible");

            // Update state.isWin and Auth panel visibility
            state.isWin = isWin;
            if (isWin) {
                authWrap.hide();
                $("#vf-rebuild-ssh").val(''); // Windows doesn't use SSH key
            }

            // Render version cards
            versionsList.empty();

            // Choose layout: pill-style for <= 6 versions, card-style otherwise
            var tplCount = category.templates.length;
            if (tplCount <= 6) {
                versionsList.addClass("vf-grid").removeClass("vf-grid-4");
            } else {
                versionsList.removeClass("vf-grid").addClass("vf-grid-4");
            }

            $.each(category.templates, function (ti, tpl) {
                var label = tpl.version || tpl.name;
                if (tpl.variant) label += " " + tpl.variant;

                var card = $('<button type="button" class="vf-rebuild-version-card" role="button" aria-pressed="false"></button>');
                card.attr("data-tpl-id", tpl.id);

                // Radio indicator
                card.append('<span class="vf-rebuild-radio"></span>');

                // Version text
                var textSpan = $('<span class="vf-rebuild-version-text"></span>').text(label);
                if (tpl.eol) {
                    textSpan.append('<span class="vf-rebuild-eol">EOL</span>');
                }
                card.append(textSpan);

                card.on("click", function () {
                    versionsList.find(".vf-rebuild-version-card").attr("aria-pressed", "false");
                    $(this).attr("aria-pressed", "true");
                    $(hiddenInput).val(tpl.id);
                    // Mark header active
                    rowbar.addClass("vf-has-version");
                    // Hide error
                    $("#vf-rebuild-os-error").removeClass("vf-visible");
                    
                    if (!isWin) {
                        authWrap.show();
                    }
                });

                versionsList.append(card);
            });

            // Show versions panel with animation
            versionsWrap.removeClass("vf-visible");
            // Force reflow for re-triggering animation
            versionsWrap[0].offsetHeight;
            versionsWrap.addClass("vf-visible");

            // Reset header accent
            rowbar.removeClass("vf-has-version");
        });

        familyRow.append(pill);
    });

    $container.append(familyRow);
    $container.append(versionsWrap);
    $container.show();
}

function vfLoadOsTemplates(serviceId, systemUrl) {
    var reqTpl = $.ajax({ type: "GET", dataType: "json", url: vfUrl(systemUrl, serviceId, "osTemplates") });
    var reqKeys = $.ajax({ type: "GET", dataType: "json", url: vfUrl(systemUrl, serviceId, "sshKeys") });

    $.when(reqTpl, reqKeys).done(function (resTpl, resKeys) {
        $("#vf-rebuild-wizard-loader").hide();
        var tplData = (resTpl[0] && resTpl[0].success) ? resTpl[0].data : null;
        var keysData = (resKeys[0] && resKeys[0].success) ? resKeys[0].data : [];
        
        if (tplData) {
            vfRenderRebuildWizard("#vf-rebuild-wizard", tplData, keysData, "#vf-rebuild-os");
        } else {
            $("#vf-rebuild-wizard").append($('<p class="text-muted"></p>').text("No templates available")).show();
        }
    }).fail(function () {
        $("#vf-rebuild-wizard-loader").hide();
        $("#vf-rebuild-wizard").append($('<p class="text-danger"></p>').text("Error loading templates")).show();
    });
}

function vfRebuildServer(serviceId, systemUrl) {
    var osId = $("#vf-rebuild-os").val();
    var sshKey = $("#vf-rebuild-ssh").val();
    var alertDiv = $("#vf-rebuild-alert");
    
    // Auth validation: If authWrap is visible, an auth mode must be selected
    var isAuthRequired = $("#vf-rebuild-auth-wrap").is(":visible");
    var hasAuthMode = $("#vf-rebuild-auth-pwd").hasClass("vf-selected") || $("#vf-rebuild-auth-ssh").hasClass("vf-selected");

    if (!osId) {
        $("#vf-rebuild-os-error").addClass("vf-visible");
        vfShowAlert(alertDiv, "danger","Please select an operating system.");
        return;
    }
    
    if (isAuthRequired && !hasAuthMode) {
        $("#vf-rebuild-auth-error").addClass("vf-visible");
        vfShowAlert(alertDiv, "danger","Please select an authentication method.");
        return;
    }

    vfConfirm({
        title: "Rebuild Server",
        message: "Are you sure you want to rebuild this server? ALL DATA WILL BE ERASED. This action cannot be undone.",
        danger: true,
        ok: "Yes, Rebuild",
        cancel: "Cancel",
        onOk: function() {
            $("#vf-rebuild-button").prop("disabled", true);
            $("#vf-rebuild-spinner").show();
            alertDiv.hide();
            vfShowProgress("Rebuilding server...");

            $.ajax({
                type: "POST",
                dataType: "json",
                url: vfUrl(systemUrl, serviceId, "rebuild"),
                data: { osId: osId, sshKey: sshKey }
            }).done(function (response) {
                if (response.success) {
                    vfShowAlert(alertDiv, "success", response.data.message || "Server rebuild initiated. You will receive an email when the process is complete.");
                } else {
                    vfShowAlert(alertDiv, "danger", "Rebuild failed. Please try again.");
                }
                alertDiv.show();
            }).fail(function () {
                vfShowAlert(alertDiv, "danger", "An error occurred. Please try again.");
            }).always(function () {
                vfHideProgress();
                $("#vf-rebuild-spinner").hide();
                setTimeout(function () {
                    $("#vf-rebuild-button").prop("disabled", false);
                }, 30000);
            });
        } // end onOk
    }); // end vfConfirm
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

// Open the noVNC viewer in a popup window. The popup is the response to a
// POST submit to client.php?action=vncViewer — a same-origin, session-
// authenticated route that:
//   - requires POST + same-origin (anti-CSRF; rejects cross-origin opens)
//   - validates WHMCS session + service ownership server-side
//   - rotates the wss token on every open (POST /vnc to VirtFusion)
//   - returns the noVNC HTML shell with credentials embedded
// We use a hidden form submit (rather than window.open(url)) because:
//   1. POST keeps the request out of GET-with-side-effects territory
//   2. requireSameOrigin validates Origin/Referer, which only proper form
//      POSTs reliably carry
// The wss token never appears in any URL the customer can copy or share.
function vfOpenVnc(serviceId, systemUrl) {
    var btn = $("#vf-vnc-button");
    var spinner = $("#vf-vnc-spinner");
    var alertDiv = $("#vf-vnc-alert");

    btn.prop("disabled", true);
    spinner.show();
    alertDiv.hide();

    var popupName = "vfvnc_" + serviceId;

    // Open the popup window/tab in click context (browsers block popups opened
    // from later async callbacks). The form submit below targets this window.
    var vncWindow = window.open("about:blank", popupName);
    if (!vncWindow) {
        vfShowAlert(alertDiv, "danger", "Popup blocked. Please allow popups for this site and try again.");
        spinner.hide();
        btn.prop("disabled", false);
        return;
    }

    // Build the hidden POST form — target=popupName routes the response into
    // our popup window. Form is removed immediately after submit; the popup
    // navigates to the rendered noVNC viewer and we don't need the form again.
    var form = document.createElement("form");
    form.method = "POST";
    form.action = vfUrl(systemUrl, serviceId, "vncViewer");
    form.target = popupName;
    form.style.display = "none";
    document.body.appendChild(form);
    form.submit();
    form.remove();

    try { vncWindow.focus(); } catch (e) { /* may throw if popup closed */ }
    spinner.hide();
    btn.prop("disabled", false);
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
        }
    }).fail(function () {
        // Self-service not available — keep basic billing visible
        $("#vf-selfservice-content").hide();
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
    vfConfirm({
        title: "Reset Root Password",
        message: "Are you sure you want to reset the server root password? This will change the password immediately.",
        danger: true,
        ok: "Yes, Reset",
        cancel: "Cancel",
        onOk: function() {
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
                            vfShowAlert(alertDiv, "success", "New password copied to clipboard.");
                        }).catch(function () {
                            vfShowAlert(alertDiv, "warning", "Password reset successful. Unable to copy to clipboard automatically.");
                        });
                    } else {
                        vfShowAlert(alertDiv, "success", "Password reset initiated. Check your email for the new credentials.");
                    }
                } else {
                    vfShowAlert(alertDiv, "danger", "Password reset failed. Please try again.");
                }
            }).fail(function () {
                vfShowAlert(alertDiv, "danger", "An error occurred. Please try again.");
            }).always(function () {
                spinner.hide();
                btn.prop("disabled", false);
            });
        } // end onOk
    }); // end vfConfirm
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
                $("#vf-tab-settings-nav").show();
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

    var H = 260;
    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.parentElement.getBoundingClientRect();
    var W = rect.width;

    canvas.width  = W * dpr;
    canvas.height = H * dpr;
    canvas.style.height = H + "px";
    canvas.style.width  = "100%";

    var ctx = canvas.getContext("2d");
    ctx.scale(dpr, dpr);

    // ── Background ──────────────────────────────────────────────────────────
    ctx.fillStyle = "#fafbfc";
    ctx.fillRect(0, 0, W, H);

    if (!entries || entries.length === 0) {
        ctx.fillStyle = "#9ca3af";
        ctx.font = "13px -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
        ctx.textAlign = "center";
        ctx.fillText("No traffic data available", W / 2, H / 2);
        return;
    }

    var maxVal = 0;
    entries.forEach(function (e) {
        var total = Math.max((e.inbound || 0), (e.outbound || 0));
        if (total > maxVal) maxVal = total;
    });
    if (maxVal === 0) maxVal = 1;

    var pad = { top: 16, right: 16, bottom: 64, left: 56 };
    var chartW = W - pad.left - pad.right;
    var chartH = H - pad.top - pad.bottom;
    var baseY  = pad.top + chartH;

    // ── Gridlines ────────────────────────────────────────────────────────────
    var gridCount = 4;
    for (var i = 0; i <= gridCount; i++) {
        var gy = pad.top + chartH - (chartH * i / gridCount);
        ctx.beginPath();
        ctx.strokeStyle = i === 0 ? "#d1d5db" : "#f0f1f3";
        ctx.lineWidth = 1;
        ctx.setLineDash(i === 0 ? [] : [4, 4]);
        ctx.moveTo(pad.left, gy);
        ctx.lineTo(W - pad.right, gy);
        ctx.stroke();
        ctx.setLineDash([]);

        var labelVal = maxVal * i / gridCount;
        ctx.fillStyle = "#9ca3af";
        ctx.font = "10px -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
        ctx.textAlign = "right";
        ctx.fillText(
            labelVal >= 1024 ? (labelVal / 1024).toFixed(1) + " TB" : labelVal.toFixed(labelVal >= 10 ? 0 : 1) + " GB",
            pad.left - 8, gy + 3.5
        );
    }

    // ── Bars ─────────────────────────────────────────────────────────────────
    var groupW = chartW / entries.length;
    var barW   = Math.min(Math.max(6, groupW * 0.28), 28);
    var gap    = 3;

    // Gradient helpers
    function mkGrad(x, y1, y2, c1, c2) {
        var g = ctx.createLinearGradient(x, y1, x, y2);
        g.addColorStop(0, c1);
        g.addColorStop(1, c2);
        return g;
    }

    // Rounded rect helper
    function roundedRect(x, y, w, h, r) {
        if (h <= 0) return;
        r = Math.min(r, h / 2, w / 2);
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + r);
        ctx.lineTo(x + w, y + h);
        ctx.lineTo(x, y + h);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.closePath();
        ctx.fill();
    }

    entries.forEach(function (e, idx) {
        var inVal  = e.inbound  || 0;
        var outVal = e.outbound || 0;
        var inH    = Math.max(inVal  > 0 ? 2 : 0, (inVal  / maxVal) * chartH);
        var outH   = Math.max(outVal > 0 ? 2 : 0, (outVal / maxVal) * chartH);

        var groupX = pad.left + idx * groupW;
        var centerX = groupX + groupW / 2;
        var inX  = centerX - barW - gap / 2;
        var outX = centerX + gap / 2;

        // Inbound — Blue
        ctx.fillStyle = mkGrad(inX, baseY - inH, baseY, "#3b82f6", "#60a5fa");
        roundedRect(inX, baseY - inH, barW, inH, 4);

        // Outbound — Violet
        ctx.fillStyle = mkGrad(outX, baseY - outH, baseY, "#8b5cf6", "#a78bfa");
        roundedRect(outX, baseY - outH, barW, outH, 4);

        // Month label
        ctx.fillStyle = "#6b7280";
        ctx.font = "10px -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
        ctx.textAlign = "center";
        ctx.fillText(e.label || (idx + 1), centerX, baseY + 16);
    });

    // ── Legend ───────────────────────────────────────────────────────────────
    var items = [
        { color: "#3b82f6", label: "Inbound" },
        { color: "#8b5cf6", label: "Outbound" }
    ];
    ctx.font = "11px -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
    var sw = 8, stGap = 5, itemGap = 20;
    var totalW = 0;
    items.forEach(function (it, i) {
        if (i > 0) totalW += itemGap;
        totalW += sw + stGap + ctx.measureText(it.label).width;
    });
    var lx = (W - totalW) / 2;
    var ly = baseY + 40;
    items.forEach(function (it) {
        // swatch pill
        ctx.fillStyle = it.color;
        ctx.beginPath();
        ctx.roundRect ? ctx.roundRect(lx, ly - sw + 1, sw, sw, 2) : ctx.rect(lx, ly - sw + 1, sw, sw);
        ctx.fill();
        // label
        ctx.fillStyle = "#6b7280";
        ctx.textAlign = "left";
        ctx.fillText(it.label, lx + sw + stGap, ly);
        lx += sw + stGap + ctx.measureText(it.label).width + itemGap;
    });
}

// Format a GB value with sensible precision and a TB cutoff at 1024 GB.
function _vfFormatGB(gb) {
    if (!isFinite(gb) || gb < 0) gb = 0;
    if (gb >= 1024) return (gb / 1024).toFixed(2) + " TB";
    if (gb >= 100) return gb.toFixed(0) + " GB";
    if (gb >= 10) return gb.toFixed(1) + " GB";
    return gb.toFixed(2) + " GB";
}

// Build a short month label (e.g. "Apr") + 2-digit year suffix when the
// chart spans more than one year so the customer can tell "Mar 25" from
// "Mar 26". Input is the start string from VF — "YYYY-MM-DD HH:MM:SS".
function _vfMonthLabel(startStr, includeYear) {
    if (!startStr) return "";
    var months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    var parts = String(startStr).split(/[-\s:]/);
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    if (!(m >= 1 && m <= 12)) return "";
    var label = months[m - 1];
    if (includeYear && !isNaN(y)) label += " " + String(y).slice(2);
    return label;
}

function vfLoadTrafficStats(serviceId, systemUrl) {
    $.ajax({
        type: "GET",
        dataType: "json",
        url: vfUrl(systemUrl, serviceId, "trafficStats")
    }).done(function (response) {
        if (!response || !response.success) return;

        // PHP wraps the API JSON: response.data is the wrapper, response.data.data
        // is VirtFusion's "data" envelope. Defensive fallbacks cover both shapes
        // in case getTrafficStats() ever changes how it surfaces the payload.
        var apiRoot = (response.data && response.data.data) ? response.data.data : (response.data || {});
        var monthly = Array.isArray(apiRoot.monthly) ? apiRoot.monthly : [];
        if (monthly.length === 0) return;

        // VF returns months in DESCENDING order (current is monthly[0]). For the
        // chart we want chronological (oldest → newest), capped at the most
        // recent 12 entries so the bars stay readable on smaller screens.
        var sliced = monthly.slice(0, 12);
        var crossesYear = false;
        for (var i = 1; i < sliced.length; i++) {
            if (String(sliced[i].start).slice(0, 4) !== String(sliced[0].start).slice(0, 4)) {
                crossesYear = true;
                break;
            }
        }
        var byOldest = sliced.slice().reverse();
        var entries = byOldest.map(function (m) {
            return {
                label: _vfMonthLabel(m.start, crossesYear),
                inbound: (m.rx || 0) / 1073741824,
                outbound: (m.tx || 0) / 1073741824,
            };
        });

        // Current period summary tile uses the first entry (descending order).
        var current = monthly[0];
        var usedGB = (current.total || 0) / 1073741824;
        var limitGB = current.limit || 0;
        var remainingGB = limitGB > 0 ? Math.max(0, limitGB - usedGB) : 0;

        $("#vf-traffic-used").text(_vfFormatGB(usedGB));
        $("#vf-traffic-limit").text(limitGB > 0 ? _vfFormatGB(limitGB) : "Unmetered");
        $("#vf-traffic-remaining").text(limitGB > 0 ? _vfFormatGB(remainingGB) : "-");

        // Show the parent panel (hidden by default in the template) before
        // sizing the canvas — getBoundingClientRect on a display:none parent
        // returns 0 and the chart would render zero-width.
        $("#vf-traffic-chart-section").show();
        vfDrawTrafficChart("vf-traffic-chart", entries);

        // Debounced resize redraw. .off() guards against multiple loads
        // stacking handlers (defensive — vfLoadTrafficStats is only called
        // once per page today, but cheap to be correct).
        var resizeTimer;
        $(window).off("resize.vfTraffic").on("resize.vfTraffic", function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                vfDrawTrafficChart("vf-traffic-chart", entries);
            }, 200);
        });

        // Redraw when the Network tab becomes visible, because the canvas width
        // evaluates to 0 if drawn while its parent tab is hidden.
        $('a[data-toggle="tab"]').off("shown.bs.tab.vfTraffic").on("shown.bs.tab.vfTraffic", function (e) {
            if ($(e.target).attr("href") === "#vf-pane-network") {
                vfDrawTrafficChart("vf-traffic-chart", entries);
            }
        });
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
    // Preserve case as the user typed it — VirtFusion's "name" is a display
    // label, not a DNS hostname, so casing is meaningful (a customer typing
    // "VPS-01" doesn't want it silently lower-cased to "vps-01").
    var name = $("#vf-rename-input").val().trim();
    var alertDiv = $("#vf-rename-alert");
    var input = $("#vf-rename-input");
    var btn = $("#vf-rename-save");
    var randomiseBtn = $("#vf-randomise-btn");
    alertDiv.hide();

    // Loose validation — VF accepts virtually any printable string for the
    // display name. We only enforce non-empty + length cap + reject control
    // characters (matches what VF itself rejects).
    if (!name) {
        vfShowAlert(alertDiv, "danger", "Name cannot be empty.");
        return;
    }
    if (name.length > 63) {
        vfShowAlert(alertDiv, "danger", "Name too long (63 character maximum).");
        return;
    }
    if (/[\x00-\x1F\x7F]/.test(name)) {
        vfShowAlert(alertDiv, "danger", "Name contains invalid control characters.");
        return;
    }

    // Disable the entire rename row until the request settles so the
    // customer can't double-submit or edit mid-flight.
    input.prop("disabled", true);
    btn.prop("disabled", true);
    randomiseBtn.prop("disabled", true);

    $.ajax({
        type: "POST",
        dataType: "json",
        url: vfUrl(systemUrl, serviceId, "rename"),
        data: { name: name }
    }).done(function (response) {
        if (response.success) {
            vfShowAlert(alertDiv, "success", "Server renamed successfully.");
        } else {
            vfShowAlert(alertDiv, "danger", (response && response.errors) || "Rename failed. Please try again.");
        }
        alertDiv.show();
    }).fail(function () {
        vfShowAlert(alertDiv, "danger", "An error occurred. Please try again.");
    }).always(function () {
        input.prop("disabled", false);
        btn.prop("disabled", false);
        randomiseBtn.prop("disabled", false);
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

// Render the IPv4 / IPv6 cell in Server Overview as a stack of compact
// rows: each row holds a single address (or v6 subnet) with a copy button.
// Falls back to "-" when the list is empty so the cell never renders empty.
// Marks each address span with .vf-ip so vfApplyIpMask() can mask it.
function vfRenderIpCells(selector, list) {
    var cell = $(selector);
    if (cell.length === 0) return;
    cell.empty();
    if (!Array.isArray(list) || list.length === 0) {
        cell.text("-");
        return;
    }
    list.forEach(function (addr) {
        var row = $('<div class="vf-ip-cell-row"></div>');
        row.append($('<span class="vf-ip vf-ip-address"></span>').text(addr));
        row.append(vfCopyButton(addr));
        cell.append(row);
    });
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
            $("#vf-tab-network").parent().hide();
            $("#vf-pane-network").removeClass("show active").hide();
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
    // rDNS rows are added to the DOM after the initial vfApplyIpMask() pass
    // in vfServerData ran — re-apply now so the screenshot mask covers them.
    vfApplyIpMask();
}

/** Standard per-IP row with inline PTR editor. Used for v4 addresses + discrete v6 hosts. */
function vfRenderIpRow(serviceId, systemUrl, row) {
    var wrap = $('<div class="vf-rdns-row"></div>');
    // .vf-ip class makes the address subject to vfApplyIpMask() (screenshot mode).
    var ipLabel = $('<div class="vf-rdns-ip vf-ip"></div>').text(row.ip);
    var badge = vfRdnsBadge(row.status);

    // .vf-sensitive lets the screenshot mask blur the PTR hostname value via
    // CSS text-security when "Mask Sensitive" is toggled on.
    var input = $('<input type="text" class="form-control form-control-sm vf-rdns-input vf-sensitive" maxlength="253" placeholder="host.example.com (blank to delete)">');
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
 * Subnet-only row: shows "2001:db8::/64" with a collapsible "Add host PTR" form.
 *
 * Why collapsed by default: most customers won't set custom v6 PTRs, so burying
 * the form until explicitly requested keeps the panel uncluttered for the common
 * case. Adding a host PTR is a power-user operation (needs a pre-existing AAAA
 * record) so surfacing it as a secondary action is UX-appropriate.
 */
function vfRenderSubnetRow(serviceId, systemUrl, row) {
    var wrap = $('<div class="vf-rdns-row vf-rdns-subnet-row"></div>');
    // .vf-ip class makes the subnet address subject to vfApplyIpMask().
    var label = $('<div class="vf-rdns-ip vf-ip"></div>').text(row.subnet + "/" + row.cidr);
    var badge = vfRdnsBadge(row.status);

    var toggleBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary">+ Add host PTR</button>');
    var form = $('<div class="vf-rdns-subnet-form" style="display:none;"></div>');

    // Both inputs hold sensitive customer-facing strings (a host IPv6 + a PTR
    // hostname). vf-sensitive plus the body's vf-mask-active class hides
    // their values via text-security in the screenshot mode.
    var ipInput = $('<input type="text" class="form-control form-control-sm vf-rdns-input vf-sensitive" placeholder="Host IPv6 address inside this subnet (e.g. 2001:db8::10)">');
    var ptrInput = $('<input type="text" class="form-control form-control-sm vf-rdns-input vf-sensitive" maxlength="253" placeholder="Hostname for PTR (e.g. mail.example.com)">');
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

// =============================================================
// In-page Section Navigation
// =============================================================
//
// Renders a "Jump to:" strip at the top of the product details page that
// links to each visible panel. Panel discovery is data-attribute-driven —
// any element carrying [data-vf-nav-label="..."] becomes a nav target. That
// keeps the JS oblivious to which sections happen to exist for a given
// install (Reverse DNS depends on PowerDNS being enabled at the template
// level, Self-Service depends on configoption4, etc.).
//
// Several panels (Resources, VNC, Self-Service) are rendered as display:none
// and revealed by their own data-load callbacks. The MutationObserver picks
// those reveals up automatically; the staggered setTimeout fallbacks cover
// browsers/situations where the observer misses the initial paint.

function _vfPanelIsVisible(el) {
    if (!el) return false;
    if (el.style && el.style.display === "none") return false;
    return el.offsetParent !== null || el.offsetHeight > 0;
}

function vfBuildSectionNav() {
    // Map every known section to whether its panel is currently visible.
    var panels = document.querySelectorAll("[data-vf-nav-label]");
    var visibleIds = {};
    panels.forEach(function (p) {
        if (_vfPanelIsVisible(p) && p.id) visibleIds[p.id] = true;
    });

    // (Optional) inline horizontal strip — kept as a fallback if the host
    // theme strips the WHMCS sidebar. If #vf-section-nav exists in the DOM
    // we populate it; otherwise we silently skip and leave the sidebar
    // version (rendered server-side via the ClientAreaPrimarySidebar hook)
    // as the only nav.
    var nav = document.getElementById("vf-section-nav");
    if (nav) {
        var list = nav.querySelector("[data-vf-nav-list]");
        if (list) {
            while (list.firstChild) list.removeChild(list.firstChild);
            var visibleCount = 0;
            panels.forEach(function (p) {
                if (!visibleIds[p.id]) return;
                var a = document.createElement("a");
                a.className = "vf-nav-link";
                a.href = "#" + p.id;
                a.setAttribute("data-vf-target", p.id);
                a.textContent = p.getAttribute("data-vf-nav-label") || p.id;
                list.appendChild(a);
                visibleCount++;
            });
            nav.style.display = visibleCount > 1 ? "" : "none";
        }
    }

    // Sidebar items are rendered statically by the PHP hook with every
    // possible section. Toggle their visibility per panel state so customers
    // don't see "Live Stats" or "Reverse DNS" jump-links for panels that
    // aren't actually rendered on this page.
    //
    // WHMCS 9's Twenty-One theme renders sidebar children as bare <a> elements
    // inside a flat .list-group div — there's no per-item <li> wrapper. Older
    // themes may use <li><a/></li>. Try <li> first (preserves layout if the
    // theme uses one), fall back to the link element itself.
    document.querySelectorAll("[data-vf-target]").forEach(function (el) {
        // Skip the inline-strip links — those are rebuilt above.
        if (el.closest && el.closest("#vf-section-nav")) return;
        var target = el.getAttribute("data-vf-target");
        var visible = !!visibleIds[target];
        var li = el.closest && el.closest("li");
        var hideTarget = li || el;
        hideTarget.style.display = visible ? "" : "none";
    });
}

document.addEventListener("click", function (e) {
    // Catch both inline and sidebar nav links — both carry data-vf-target.
    var link = e.target && e.target.closest && e.target.closest("[data-vf-target]");
    if (!link) return;
    var targetId = link.getAttribute("data-vf-target");
    var target = document.getElementById(targetId);
    if (!target) return;
    e.preventDefault();
    var top = target.getBoundingClientRect().top + window.pageYOffset - 16;
    window.scrollTo({ top: top, behavior: "smooth" });
    if (history && history.replaceState) {
        history.replaceState(null, "", "#" + targetId);
    }
});

// Tab State Persistence
$(document).ready(function() {
    var activeTab = sessionStorage.getItem('vfActiveTab');
    if (activeTab && $('#' + activeTab).length) {
        $('#' + activeTab).tab('show');
    }
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        sessionStorage.setItem('vfActiveTab', $(e.target).attr('id'));
    });
});

(function _vfInitSectionNav() {
    function init() {
        vfBuildSectionNav();
        [400, 1200, 2500].forEach(function (ms) { setTimeout(vfBuildSectionNav, ms); });
        try {
            var obs = new MutationObserver(vfBuildSectionNav);
            document.querySelectorAll("[data-vf-nav-label]").forEach(function (p) {
                obs.observe(p, { attributes: true, attributeFilter: ["style", "class"] });
            });
        } catch (e) { /* MutationObserver missing — staggered timeouts cover us */ }
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();

// =============================================================
// Live Stats + Filesystem rendering
// =============================================================

// Format a byte count for human display (KB / MB / GB / TB).
function _vfFormatBytes(bytes) {
    if (!isFinite(bytes) || bytes < 0) bytes = 0;
    var units = ["B", "KB", "MB", "GB", "TB", "PB"];
    var u = 0;
    var n = bytes;
    while (n >= 1024 && u < units.length - 1) { n /= 1024; u++; }
    return (n >= 100 ? n.toFixed(0) : n >= 10 ? n.toFixed(1) : n.toFixed(2)) + " " + units[u];
}

function vfRenderLiveStats(live, totalMemMB, serviceId) {
    if (!live || (live.cpu === null && live.memoryActualKB === null && live.memoryRssKB === null && live.diskRdBytes === null)) {
        // No remoteState payload — keep the panel hidden. Section nav will
        // skip it because it's display:none.
        return;
    }
    $("#vf-livestats-container").show();
    $("#vf-livestats-offline").hide();

    // CPU — VirtFusion returns a percentage. Clamp to [0, 100] defensively.
    var cpu = live.cpu;
    if (cpu === null) {
        $("#vf-live-cpu-pct").text("-");
        $("#vf-live-cpu-bar").css("width", "0%");
    } else {
        var cpuPct = Math.max(0, Math.min(100, cpu));
        $("#vf-live-cpu-pct").text(cpuPct.toFixed(1) + "%");
        var cpuBar = $("#vf-live-cpu-bar").css("width", cpuPct + "%");
        cpuBar.removeClass("bg-warning bg-danger");
        if (cpuPct > 90) cpuBar.addClass("bg-danger");
        else if (cpuPct > 70) cpuBar.addClass("bg-warning");
    }

    // Memory — libvirt returns kilobytes.
    // Priority:
    //   1. actual + unused   (Linux guest-agent: used = actual - unused)
    //   2. actual + available (some drivers report available instead of unused)
    //   3. actual + usable   (VIR_DOMAIN_MEMORY_STAT_USABLE — some KVM configs without agent)
    //   4. rss + totalKB    (host-side RSS — Linux without guest-agent)
    //   5. Last known state — if API returns all-null (intermittent guest-agent), don't flicker
    //   6. totalKB only as a last resort for first-load on Windows VMs (no usage data available)
    var actual    = live.memoryActualKB;
    var unused    = live.memoryUnusedKB;
    var available = live.memoryAvailableKB;
    var usable    = live.memoryUsableKB;
    var rss       = live.memoryRssKB;
    var memTotal  = live.memoryMemTotalKB; // Windows agent_meminfo
    var memFree   = live.memoryMemFreeKB;  // Windows agent_meminfo
    var totalKB   = (totalMemMB || 0) * 1024;

    // Persist last rendered memory state to sessionStorage so it survives full
    // page navigations within the same browser session. Keyed per service so
    // multi-server accounts don't bleed state between servers.
    var _memKey = "vfMem_" + (serviceId || "x");
    function _loadMem() {
        try { return JSON.parse(sessionStorage.getItem(_memKey)) || null; }
        catch(e) { return null; }
    }
    function _saveMem(state) {
        window._vfLastMemState = state;
        try { sessionStorage.setItem(_memKey, JSON.stringify(state)); }
        catch(e) {}
    }
    // Always read sessionStorage — don't trust window scope alone as WHMCS
    // can re-init the panel mid-session without a full navigation event.
    var _memState = window._vfLastMemState || _loadMem();

    function setMemBar(usedKB, denomKB, labelOverride) {
        var pct = denomKB > 0 ? Math.min(100, (usedKB / denomKB) * 100) : 0;
        var label = labelOverride || (_vfFormatBytes(usedKB * 1024) + " / " + _vfFormatBytes(denomKB * 1024));
        $("#vf-live-mem-text").text(label);
        $("#vf-live-mem-pct").text(pct > 0 ? pct.toFixed(0) + "%" : "");
        var memBar = $("#vf-live-mem-bar").css("width", pct + "%");
        // Always clear allocation class when rendering real data
        memBar.removeClass("bg-warning bg-danger vf-mem-allocated");
        if (pct > 90) memBar.addClass("bg-danger");
        else if (pct > 75) memBar.addClass("bg-warning");
    }

    function setAllocBar() {
        var allocLabel = _vfFormatBytes(totalKB * 1024) + " allocated";
        $("#vf-live-mem-text").text(allocLabel);
        $("#vf-live-mem-pct").text("");
        $("#vf-live-mem-bar").css("width", "100%")
            .removeClass("bg-warning bg-danger")
            .addClass("vf-mem-allocated");
    }

    var hasRealData = false;
    if (actual !== null && unused !== null) {
        hasRealData = true;
        setMemBar(Math.max(0, actual - unused), actual);
        _saveMem({ type: "usage", used: Math.max(0, actual - unused), denom: actual });
    } else if (actual !== null && available !== null) {
        hasRealData = true;
        setMemBar(Math.max(0, actual - available), actual);
        _saveMem({ type: "usage", used: Math.max(0, actual - available), denom: actual });
    } else if (actual !== null && usable !== null) {
        hasRealData = true;
        setMemBar(Math.max(0, actual - usable), actual);
        _saveMem({ type: "usage", used: Math.max(0, actual - usable), denom: actual });
    } else if (memTotal !== null && memFree !== null) {
        hasRealData = true;
        setMemBar(Math.max(0, memTotal - memFree), memTotal);
        _saveMem({ type: "usage", used: Math.max(0, memTotal - memFree), denom: memTotal });
    } else if (rss !== null && totalKB > 0) {
        hasRealData = true;
        setMemBar(Math.max(0, rss), totalKB);
        _saveMem({ type: "usage", used: Math.max(0, rss), denom: totalKB });
    }

    if (!hasRealData) {
        // Guest-agent returned nothing this tick — use last known state to prevent flicker
        if (_memState) {
            window._vfLastMemState = _memState; // sync window cache from sessionStorage read
            if (_memState.type === "usage") {
                setMemBar(_memState.used, _memState.denom);
            } else {
                setAllocBar();
            }
        } else if (totalKB > 0) {
            // First load with no data ever (Windows VM with no guest agent)
            _saveMem({ type: "alloc" });
            setAllocBar();
        } else {
            $("#vf-live-mem-text").text("-");
            $("#vf-live-mem-pct").text("");
            $("#vf-live-mem-bar").css("width", "0%").removeClass("vf-mem-allocated");
        }
    }

    // Disk I/O — cumulative bytes since boot.
    $("#vf-live-disk-rd").text(live.diskRdBytes === null ? "-" : _vfFormatBytes(live.diskRdBytes));
    $("#vf-live-disk-wr").text(live.diskWrBytes === null ? "-" : _vfFormatBytes(live.diskWrBytes));

    var now = new Date();
    $("#vf-live-updated").text("Updated " + now.toLocaleTimeString());
}

function vfRenderFilesystems(filesystems) {
    var container = $("#vf-fs-container");
    if (container.length === 0) return;
    container.empty();
    if (!Array.isArray(filesystems) || filesystems.length === 0) {
        $("#vf-fs-section").hide();
        return;
    }
    $("#vf-fs-section").show();
    filesystems.forEach(function (fs) {
        var pct = fs.totalBytes > 0 ? Math.min(100, (fs.usedBytes / fs.totalBytes) * 100) : 0;
        var barColor = pct > 90 ? "bg-danger" : (pct > 75 ? "bg-warning" : "");
        var row = $('<div class="vf-fs-row mb-3"></div>');
        var head = $('<div class="d-flex justify-content-between vf-small mb-1"></div>');
        head.append($('<span class="vf-bold"></span>').text(fs.mountpoint).attr("title", fs.name + " (" + fs.type + ")"));
        head.append($('<span class="text-muted"></span>').text(
            _vfFormatBytes(fs.usedBytes) + " / " + _vfFormatBytes(fs.totalBytes) +
            " (" + pct.toFixed(0) + "%)"
        ));
        row.append(head);
        var bar = $('<div class="progress" style="height:8px;"></div>');
        bar.append($('<div class="progress-bar"></div>').addClass(barColor).css("width", pct + "%"));
        row.append(bar);
        container.append(row);
    });
}

// -------------------------------------------------------------
// Live Stats auto-refresh
// -------------------------------------------------------------
//
// Polls the serverData endpoint every 30 seconds while the Live Stats
// panel is visible AND the page has focus. Pausing on visibilitychange
// avoids hammering the hypervisor when the customer alt-tabs away. The
// underlying serverData call is the same one vfServerData uses, so cache
// hits in client.php (when added) would benefit both paths.

(function _vfLiveStatsRefresh() {
    var REFRESH_MS = 30000;
    var timer = null;
    var serviceId = null, systemUrl = null;

    function tick() {
        if (!serviceId || document.hidden) return;
        // Guard: only refresh while the livestats container is actually visible
        // (i.e. the Resources tab is active and the server is online).
        var container = document.getElementById("vf-livestats-container");
        if (!container || container.offsetParent === null) return;
        $.ajax({
            type: "GET",
            dataType: "json",
            url: vfUrl(systemUrl, serviceId, "serverData")
        }).done(function (response) {
            if (response && response.success && response.data) {
                vfRenderLiveStats(response.data.live, response.data.memoryRaw, serviceId);
                vfRenderFilesystems(response.data.live ? response.data.live.filesystems : []);
            }
        });
    }

    // The first vfServerData call (from the inline <script> in overview.tpl)
    // captures the args the panel needs for refresh. We piggyback by walking
    // the DOM for the script's args isn't reliable across themes — instead,
    // expose an init hook the inline script can call.
    window.vfStartLiveStatsRefresh = function (sid, url) {
        serviceId = sid;
        systemUrl = url;
        if (timer) clearInterval(timer);
        timer = setInterval(tick, REFRESH_MS);
    };

    document.addEventListener("visibilitychange", function () {
        // No need to do anything special; tick() short-circuits when hidden.
        if (!document.hidden && serviceId) tick();
    });
})();
