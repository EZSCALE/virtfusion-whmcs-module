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
