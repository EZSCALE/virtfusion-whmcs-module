/* Service tools: no tokens in URLs, browser storage or cached responses. */
(function () {
    "use strict";
    if (window.vfdToolsLoaded) return;
    window.vfdToolsLoaded = true;

    function element(tag, text, parent) {
        var el = document.createElement(tag);
        if (text !== undefined) el.textContent = text;
        if (parent) parent.appendChild(el);
        return el;
    }
    function id() {
        return Array.from(crypto.getRandomValues(new Uint8Array(16)), function (b) { return b.toString(16).padStart(2, "0"); }).join("");
    }
    function token() {
        var input = document.querySelector("input[name='token']");
        return window.csrfToken || (input && input.value) || "";
    }
    function button(parent, label, fn) {
        var b = element("button", label, parent);
        b.type = "button";
        b.className = "btn btn-default btn-secondary mr-2 mb-2";
        b.addEventListener("click", fn);
        return b;
    }
    function input(parent, label, type) {
        var l = element("label", label, parent);
        l.style.display = "block";
        var i = element("input", undefined, l);
        i.type = type || "text";
        i.className = "form-control";
        return i;
    }
    function select(parent, label, options, multiple) {
        var l = element("label", label, parent);
        l.style.display = "block";
        var s = element("select", undefined, l);
        s.className = "form-control";
        s.multiple = !!multiple;
        options.forEach(function (o) {
            var opt = element("option", o.label, s);
            opt.value = String(o.value);
        });
        return s;
    }
    async function mount(root) {
        var admin = root.dataset.admin === "1", pack = root.dataset.pack === "1";
        var member = null, pending = null, ended = false, checking = false, sessionTimer = null;
        var endpoint = new URL("modules/servers/VirtFusionDirect/" + (admin ? "admin" : "client") + ".php", root.dataset.base || window.location.origin);
        if (endpoint.origin !== window.location.origin) return;
        var output = element("pre", "", root);
        output.style.whiteSpace = "pre-wrap";
        var controls = element("div", undefined, root);
        var operations = element("div", undefined, root);
        async function call(action, tool, payload, write, signal) {
            if (ended) throw new Error("Service session ended. Reload to authenticate again.");
            var url = new URL(endpoint);
            url.searchParams.set("action", action);
            url.searchParams.set("serviceID", root.dataset.service);
            if (tool) url.searchParams.set("tool", tool);
            var options = {method: write ? "POST" : "GET", credentials: "same-origin", cache: "no-store", redirect: "error", referrerPolicy: "no-referrer", headers: {Accept: "application/json"}};
            if (signal) options.signal = signal;
            payload = Object.assign({}, payload || {});
            if (member) payload.memberVm = member;
            if (write) {
                if (!token()) throw new Error("Reload this page to obtain a valid security token.");
                options.body = new URLSearchParams({token: token(), payload: JSON.stringify(payload)});
            } else {
                Object.keys(payload).forEach(function (key) { url.searchParams.set(key, payload[key]); });
            }
            var response = await fetch(url, options);
            var body = await response.json();
            if (ended) throw new Error("Service session ended. Reload to authenticate again.");
            if (!response.ok || !body.success) {
                if (body.data && body.data.operationId) pending = body.data.operationId;
                throw new Error(body.errors || (body.data && body.data.message) || "Request failed. Check the operation before retrying.");
            }
            return body.data;
        }
        async function run(tool, payload, write) {
            try {
                var result = await call("serviceTools", tool, Object.assign(write ? {requestId: id()} : {}, payload || {}), write);
                if (result.operationId) {
                    pending = result.operationId;
                    operations.replaceChildren();
                    element("p", "Operation " + pending + ": " + result.state + ". Check applied state before another change.", operations);
                    button(operations, "Check operation", async function () { await run("operation", {operationId: pending}, false); });
                    if (tool === "backupRestore" && payload.safeguard) {
                        button(operations, "Continue pinned restore", async function () {
                            if (window.confirm("Overwrite the selected disks with the pinned recovery points after the safeguard backup?")) await run("backupRestoreContinue", {operationId: pending, overwriteConfirmed: true}, true);
                        });
                    }
                }
                output.textContent = JSON.stringify(result, null, 2);
                return result;
            } catch (e) { output.textContent = e.message; return null; }
        }
        window.addEventListener("pagehide", function () { endSession(); output.textContent = ""; });
        document.addEventListener("visibilitychange", function () { if (!document.hidden) checkSession(); });
        window.addEventListener("focus", checkSession);
        try {
            if (pack) {
                var packResult;
                try { packResult = await call("packData"); }
                catch (e) {
                    if (!admin) throw e;
                    packResult = {pack: {}, members: []};
                    output.textContent = e.message + " Use Pack reconciliation below.";
                }
                element("pre", JSON.stringify(packResult.pack, null, 2), controls);
                var choice = select(controls, "Member VM", packResult.members.map(function (vm) { return {value: vm, label: "VM " + vm}; }));
                member = choice.value || null;
                choice.addEventListener("change", function () { member = choice.value; pending = null; operations.replaceChildren(); output.textContent = ""; });
            }
            var caps = await call("capabilities");
            element("p", caps.version ? "VirtFusion " + caps.version : "Panel version is unavailable; new capabilities remain disabled.", controls);
            var features = caps.features;
            function panel(title, note) {
                var d = element("details", undefined, controls);
                element("summary", title, d);
                if (note) element("p", note, d);
                return d;
            }
            if (features.power) {
                var power = panel("Power", "Shutdown, restart and force-off interrupt workloads. Force-off can lose unsaved changes.");
                button(power, "Power status", function () { run("powerStatus"); });
                ["Boot", "Shutdown", "Restart", "Poweroff"].forEach(function (action) {
                    button(power, action, function () { if (action === "Boot" || confirm("Apply " + action.toLowerCase() + " to this VM?")) run("power" + action, {powerConfirmed: true}, true); });
                });
            }
            if (features.rdns) {
                var ptr = panel("Reverse DNS", "Only currently assigned addresses can be changed.");
                button(ptr, "Assigned addresses and PTR records", function () { run("ptrList"); });
                var addr = input(ptr, "Assigned IP address");
                var host = input(ptr, "PTR hostname (blank clears it)");
                button(ptr, "Save PTR", function () { run("ptrSet", {address: addr.value, hostname: host.value}, true); });
            }
            if (features.network) button(controls, "Network details", function () { run("interfaces"); });
            if (features.disks) button(controls, "Disk details", function () { run("disks"); });
            if (features.rescue) {
                var rescue = panel("Rescue", "Starting or ending rescue restarts the VM. VirtFusion emails the owner. Credentials appear privately in the status below.");
                button(rescue, "Rescue status", function () { run("rescueStatus"); });
                var image = input(rescue, "Image ID from rescue status", "number");
                button(rescue, "Start rescue", function () { if (confirm("Restart into rescue?")) run("rescueStart", {imageId: image.value, restartConfirmed: true}, true); });
                button(rescue, "End rescue", function () { if (confirm("Restart back into the VM's own disks?")) run("rescueEnd", {restartConfirmed: true}, true); });
            }
            if (features.elevation) {
                var elevate = panel("Elevation", "Commit saves an individual drive permanently. Discard restores all remaining drives; already committed drives stay committed. Multi-disk changes are not atomic.");
                button(elevate, "Elevation status", function () { run("elevationStatus"); });
                var drives = input(elevate, "Eligible drive letters, separated by commas");
                var crash = input(elevate, "Allow a crash-consistent attempt if freezing is unavailable", "checkbox");
                button(elevate, "Start elevation", function () {
                    if (crash.checked && !confirm("Proceed without freezing applications? This may produce a crash-consistent snapshot.")) return;
                    run("elevationStart", {disks: drives.value.split(",").map(function (x) { return x.trim(); }), quiesce: !crash.checked, crashConsistentConfirmed: crash.checked}, true);
                });
                var drive = input(elevate, "Uncommitted drive letter");
                button(elevate, "Commit drive", function () { if (confirm("Permanently commit drive " + drive.value + "?")) run("elevationCommit", {drive: drive.value, commitConfirmed: true}, true); });
                button(elevate, "Discard remaining elevation", function () { if (confirm("Discard ALL remaining uncommitted changes? Already committed drives will remain changed.")) run("elevationDiscard", {discardConfirmed: true}, true); });
            }
            if (features.iso || features.isoLibrary) {
                var iso = panel("ISO media", "Private ISO limits count entries, including unresolved imports. They are not storage quotas.");
                if (features.iso) button(iso, "Mounted ISO", function () { run("isoStatus"); });
                if (features.isoLibrary) button(iso, "Available ISOs", function () { run("isoLibraryList"); });
                var isoId = input(iso, "Assigned ISO ID", "number");
                if (features.iso) {
                    button(iso, "Mount ISO", function () { run("isoMount", {isoId: isoId.value}, true); });
                    button(iso, "Eject ISO", function () { run("isoEject", {}, true); });
                }
                if (features.customUrls) {
                    var isoUrl = input(iso, "Public HTTPS .iso URL");
                    if (features.iso) button(iso, "Mount URL", function () { run("isoMount", {url: isoUrl.value}, true); });
                    if (features.isoLibrary) {
                        var isoName = input(iso, "Private ISO name");
                        button(iso, "Add private entry", function () { run("isoLibraryCreate", {name: isoName.value, url: isoUrl.value}, true); });
                    }
                }
                if (features.isoLibrary) {
                    var rename = input(iso, "New name for owned private entry");
                    button(iso, "Rename entry", function () { run("isoLibraryRename", {isoId: isoId.value, name: rename.value}, true); });
                    button(iso, "Remove entry", function () { if (confirm("Remove your private ISO entry? Mounted media cannot be force-deleted.")) run("isoLibraryDelete", {isoId: isoId.value, deleteConfirmed: true}, true); });
                }
            }
            if (features.backups) {
                var backups = panel("Backups", "Restore overwrites selected disks. Recovery points are rechecked before restore. A safeguard backup must finish before you continue.");
                var points = select(backups, "Recovery points (select one per disk)", [], true);
                button(backups, "Load recovery points", async function () {
                    var result = await run("backupList");
                    if (!result) return;
                    points.replaceChildren();
                    result.data.forEach(function (p) {
                        var o = element("option", "Disk " + p.disk + " · chain " + p.chainId + " · " + p.date, points);
                        o.value = JSON.stringify({disk: p.disk, chainId: p.chainId, timestamp: p.timestamp});
                    });
                });
                button(backups, "Jobs", function () { run("backupJobs"); });
                button(backups, "Create backup", function () { run("backupCreate", {}, true); });
                var safeguard = input(backups, "Create a safeguard backup before restoring", "checkbox");
                button(backups, "Restore selected disks", function () {
                    var choices = Array.from(points.selectedOptions, function (o) { return JSON.parse(o.value); });
                    if (choices.length && confirm("Overwrite these disks with the selected recovery points?")) run("backupRestore", {selections: choices, safeguard: safeguard.checked, overwriteConfirmed: true}, true);
                });
            }
            if (features.firewall) {
                var firewall = panel("Firewall", "Provider policies are locked. Exceptions to protected directions and address families require provider approval.");
                var interfaceRef = input(firewall, "Interface tag (from interface details)");
                button(firewall, "Interface details", function () { run("interfaceChoices"); });
                button(firewall, "Rules and approved presets", function () { run("firewallList", {interfaceRef: interfaceRef.value}); });
                var protocol = select(firewall, "Protocol", ["tcp", "udp", "icmp", "sctp", "all"].map(function (x) { return {value: x, label: x}; }));
                var direction = select(firewall, "Direction", [{value: "in", label: "Inbound"}, {value: "out", label: "Outbound"}]);
                var policy = select(firewall, "Action", [{value: "allow", label: "Allow"}, {value: "deny", label: "Deny"}]);
                var family = select(firewall, "Address family", [{value: 4, label: "IPv4"}, {value: 6, label: "IPv6"}]);
                var port = input(firewall, "Destination port or range (optional)");
                var source = input(firewall, "Source IP/CIDR (optional)");
                button(firewall, "Add rule", function () { run("firewallAdd", {interfaceRef: interfaceRef.value, rule: {protocol: protocol.value, direction: direction.value, action: policy.value, ipType: Number(family.value), destinationPort: port.value, sourceAddress: source.value}}, true); });
                var ruleId = input(firewall, "Owned rule ID", "number");
                button(firewall, "Enable rule", function () { run("firewallEnableRule", {interfaceRef: interfaceRef.value, ruleId: ruleId.value, enabled: true}, true); });
                button(firewall, "Disable rule", function () { run("firewallEnableRule", {interfaceRef: interfaceRef.value, ruleId: ruleId.value, enabled: false}, true); });
                button(firewall, "Delete rule", function () { if (confirm("Delete this owned rule?")) run("firewallDelete", {interfaceRef: interfaceRef.value, ruleId: ruleId.value}, true); });
                var preset = input(firewall, "Approved preset ID", "number");
                button(firewall, "Attach preset", function () { run("firewallPreset", {interfaceRef: interfaceRef.value, rulesetId: preset.value}, true); });
                button(firewall, "Apply saved rules", function () { run("firewallApply", {interfaceRef: interfaceRef.value}, true); });
            }
            if (admin) {
                var provider = panel("Panel configuration", "New capabilities default off. Verify firewall enforcement and native download isolation before asserting the respective prerequisites. Changes are audited.");
                var settingsBox = element("textarea", undefined, provider);
                settingsBox.className = "form-control";
                settingsBox.rows = 14;
                var cancelDays = input(provider, "Cancel unpaid VirtFusion orders after (days, 0 = off)", "number");
                cancelDays.min = 0; cancelDays.max = 365; cancelDays.step = 1;
                cancelDays.addEventListener("change", function () {
                    if (!/^\d{1,3}$/.test(cancelDays.value) || Number(cancelDays.value) > 365) { output.textContent = "Unpaid order cancellation must be 0 to 365 days."; return; }
                    try { var current = JSON.parse(settingsBox.value); current.unpaidOrderCancelDays = Number(cancelDays.value); settingsBox.value = JSON.stringify(current, null, 2); }
                    catch (e) { output.textContent = "Load settings before changing this value."; }
                });
                button(provider, "Load settings", async function () {
                    try {
                        var url = new URL(endpoint); url.searchParams.set("action", "providerSettings"); url.searchParams.set("panelId", root.dataset.panel);
                        var response = await fetch(url, {credentials: "same-origin", cache: "no-store", redirect: "error"}); var body = await response.json();
                        if (!response.ok || !body.success) throw new Error(body.errors || "Unable to load settings.");
                        settingsBox.value = JSON.stringify(body.data.settings, null, 2);
                        cancelDays.value = body.data.settings.unpaidOrderCancelDays;
                        output.textContent = "Panel version: " + (body.data.version || "unknown");
                    } catch (e) { output.textContent = e.message; }
                });
                button(provider, "Save settings", async function () {
                    try { await call("providerSettingsSave", null, {panelId: root.dataset.panel, settings: JSON.parse(settingsBox.value)}, true); output.textContent = "Settings saved. Reload the page to refresh available tools."; }
                    catch (e) { output.textContent = e.message; }
                });
                if (pack) {
                    var bindingPanel = panel("Pack reconciliation", "Inspect the native panel before resolving a pending binding. No VF mutation is replayed. An allowed recovery role, exact pack ID and verified native state are required.");
                    button(bindingPanel, "Pack binding status", async function () { try { output.textContent = JSON.stringify(await call("packBindingStatus"), null, 2); } catch (e) { output.textContent = e.message; } });
                    var reconcileFields = element("textarea", JSON.stringify({packId: 0, state: "active", targetConfirmed: "0", reconciliationConfirmed: true, nativeStateVerified: true}, null, 2), bindingPanel);
                    reconcileFields.className = "form-control"; reconcileFields.rows = 6;
                    button(bindingPanel, "Reconcile inspected pack", async function () { if (!confirm("Record the verified native pack state and resolve its pending operation?")) return; try { output.textContent = JSON.stringify(await call("packBindingReconcile", null, JSON.parse(reconcileFields.value), true), null, 2); } catch (e) { output.textContent = e.message; } });
                }
                var migration = panel("Official pack migration", "Map the source settings explicitly. Review a dry run first; applying changes selected products and bindings while retaining all source rows and a rollback snapshot.");
                var migrationFields = element("textarea", JSON.stringify({sourceModule: "VirtFusionResourcePacks", products: [{productId: 0, panelId: Number(root.dataset.panel), profileId: 0, deletionDelay: 60, terminationBehavior: "Suspend Servers"}]}, null, 2), migration);
                migrationFields.className = "form-control"; migrationFields.rows = 8;
                var migrationDigest = null;
                button(migration, "Preview migration", async function () {
                    try { var result = await call("packMigrationPreview", null, JSON.parse(migrationFields.value), true); migrationDigest = result.digest; output.textContent = JSON.stringify(result, null, 2); }
                    catch (e) { migrationDigest = null; output.textContent = e.message; }
                });
                button(migration, "Apply reviewed migration", async function () {
                    if (!migrationDigest || !confirm("Apply exactly the reviewed product and binding changes?")) return;
                    try { var fields = JSON.parse(migrationFields.value); fields.digest = migrationDigest; fields.migrationConfirmed = true; output.textContent = JSON.stringify(await call("packMigrationApply", null, fields, true), null, 2); migrationDigest = null; }
                    catch (e) { output.textContent = e.message; }
                });
                if (features.adminTools) {
                    var tools = panel("Service administration", "Disk growth applies at the next power change. QoS can apply live. Recovery actions require the separate recovery role and exact target confirmation.");
                    var actions = ["migrationHybridStatus", "migrationLiveStatus", "migrationDestinations", "assets", "assetsAvailable", "disks", "interfaces", "diskAdd", "diskGrow", "diskSettings", "diskDelete", "diskCancelPending", "assetAttach", "assetDetach", "assetOrder", "interfaceAdd", "interfaceSettings", "interfaceIpv4Add", "interfaceIpv4Remove", "interfaceIpv4Order", "interfaceIpv6Add", "interfaceIpv6Remove", "vlanAdd", "vlanSettings", "vlanDelete", "macSet", "migrationHybridStart", "migrationHybridRetry", "migrationHybridCancel", "migrationHybridComplete", "migrationHybridRevert", "migrationLiveStart", "migrationLiveCancel", "migrationLiveAbort", "migrationLiveForceCancel", "migrationLiveForceDataSuccess", "migrationLiveResume", "operationReconcile", "interfaceDetails", "vlanList", "diskDetails", "assetDetails", "hypervisorStorage", "hypervisorNetworks", "hypervisorMetrics", "hypervisorBindings", "firewallCatalogue", "templateCatalogue", "templateDetails", "isoAdminCatalogue", "migrationDestinationDetails", "firewallDelegate", "firewallLock", "isoAdminCreate", "isoAdminUpdate", "isoAdminDelete", "isoAdminAssign"];
                    var toolChoice = select(tools, "Action", actions.map(function (a) { return {value: a, label: a}; }));
                    var fields = element("textarea", "{}", tools); fields.className = "form-control"; fields.rows = 5;
                    element("p", "Enter the action's validated fields and required confirmations as JSON.", tools);
                    button(tools, "Run service tool", function () {
                        try {
                            var read = ["migrationHybridStatus", "migrationLiveStatus", "migrationDestinations", "assets", "assetsAvailable", "disks", "interfaces", "interfaceDetails", "vlanList", "diskDetails", "assetDetails", "hypervisorStorage", "hypervisorNetworks", "hypervisorMetrics", "hypervisorBindings", "firewallCatalogue", "templateCatalogue", "templateDetails", "isoAdminCatalogue", "migrationDestinationDetails"].indexOf(toolChoice.value) >= 0;
                            if (read || confirm("Apply " + toolChoice.value + " to this service's selected VM?")) run(toolChoice.value, JSON.parse(fields.value), !read);
                        } catch (e) { output.textContent = e.message; }
                    });
                }
            }
            if (!Object.keys(features).some(function (key) { return features[key]; })) element("p", "Additional controls are disabled for this panel.", controls);
        } catch (e) { output.textContent = e.message; }
        function endSession() {
            ended = true;
            clearInterval(sessionTimer);
            output.textContent = "Service session ended. Reload to authenticate again.";
            controls.replaceChildren(); operations.replaceChildren(); pending = null;
        }
        async function checkSession() {
            if (ended || checking) return;
            checking = true;
            var controller = new AbortController();
            var timeout = setTimeout(function () { controller.abort(); }, 5000);
            try {
                // Pack pages without a selected VM contain no rescue credentials.
                // A selected VM additionally rechecks current native membership.
                var status = await call(pack && !member ? "packData" : "serviceTools", pack && !member ? null : "sessionStatus", {}, false, controller.signal);
                if (pack && !member ? !Array.isArray(status.members) : status.authenticated !== true) throw new Error("Invalid authorization response");
            } catch (e) { endSession(); }
            finally { clearTimeout(timeout); checking = false; }
        }
        // Clear credential displays after logout, permission/ownership changes,
        // failed authorization checks or navigation. Native credential revocation
        // remains the panel's responsibility; clearing the DOM is not revocation.
        if (!ended) sessionTimer = setInterval(checkSession, 30000);
    }
    function init() { document.querySelectorAll(".vfd-tools").forEach(function (root) { if (!root.dataset.loaded) { root.dataset.loaded = "1"; mount(root); } }); }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
})();
