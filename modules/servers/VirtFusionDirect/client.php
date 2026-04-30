<?php

require dirname(__DIR__, 3) . '/init.php';

/**
 * Client-facing AJAX API endpoint.
 *
 * ROUTING MODEL
 * -------------
 * Every request carries ?action=X&serviceID=Y. We dispatch on $action via the
 * switch below. Because PHP's switch() is O(N) over case labels that's still
 * fine at ~20 actions; if this grows large enough that dispatch cost matters
 * we'd want a lookup table, but we're nowhere near that.
 *
 * WHMCS requires every action URL to re-authenticate on each request (no
 * cross-request sticky state beyond the session cookie). That's why the
 * isAuthenticated() call is the first thing inside the try block — nothing
 * downstream may assume a session exists.
 *
 * AUTH LAYERS (ORDER MATTERS)
 * ---------------------------
 * Each case composes the defenses it needs:
 *
 *   1. $vf->isAuthenticated()              — client session (401 otherwise)
 *   2. $vf->validateServiceID(true)        — numeric coercion + presence
 *   3. $vf->validateUserOwnsService($id)   — the session owns this service (403)
 *   4. Optional: requireServiceStatus      — filter by tblhosting.domainstatus
 *   5. Optional (mutations): requirePost   — HTTP method gate (405)
 *   6. Optional (mutations): requireSameOrigin — CSRF origin gate (403)
 *
 * The helpers are "fail loudly" — they exit on failure rather than returning.
 * So everything AFTER a guard in a case branch knows the guard passed.
 *
 * EVERY $vf->output() FOLLOWED BY break
 * -------------------------------------
 * output() emits a JSON response and exits by default, so in theory `break`
 * is redundant. In practice we always break explicitly for two reasons:
 *   1. If someone later passes exit=false to output() the switch would fall
 *      through to the default case and emit a second response body.
 *   2. Code readers shouldn't have to remember that one function exits.
 *
 * RESPONSE SHAPE
 * --------------
 * Success: { success: true, data: { ... } }
 * Error:   { success: false, errors: "human-readable message" }
 * Status codes match HTTP semantics (200/400/401/403/404/405/429/500/502).
 *
 * CATCH-ALL
 * ---------
 * The outer try/catch guarantees we never expose a raw PHP stack trace to the
 * client, even on bugs in our own code. All uncaught exceptions are logged and
 * the user sees a generic 500.
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VirtFusionDirect\Log;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config as PowerDnsConfig;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\IpUtil;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\PtrManager;
use WHMCS\Module\Server\VirtFusionDirect\ServerResource;

$vf = new Module;

try {

    $vf->isAuthenticated();

    $action = $vf->validateAction(true);

    switch ($action) {

        /**
         * Reset Password.
         */
        case 'resetPassword':

            // Destructive: rotates the customer's VirtFusion login password.
            // Gated by POST + same-origin (anti-CSRF) and a 30 s rate limit
            // so a runaway / malicious script can't lock out the customer
            // by spamming password resets.
            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);
            $client = $vf->validateUserOwnsService($serviceID);

            if (! $client) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);
            $vf->requireRateLimit('resetPassword:' . $serviceID, 30);

            $data = $vf->resetUserPassword($serviceID, $client);

            if ($data) {
                $vf->output(['success' => true, 'data' => $data->data], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Password reset failed'], true, true, 500);
            break;

            /**
             * Get server information.
             */
        case 'serverData':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $data = $vf->fetchServerData($serviceID);

            if ($data) {
                $vf->updateWhmcsServiceParamsOnServerObject($serviceID, $data);
                $vf->output(['success' => true, 'data' => (new ServerResource)->process($data)], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to retrieve server data'], true, true, 500);
            break;

            /**
             * Login as server owner.
             */
        case 'loginAsServerOwner':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $token = $vf->fetchLoginTokens($serviceID);

            if ($token) {
                $vf->output(['success' => true, 'token_url' => $token], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to generate login token'], true, true, 500);
            break;

            /**
             * Power management actions: boot, shutdown, restart, poweroff
             */
        case 'powerAction':

            // Destructive: poweroff/restart can interrupt running workloads.
            // Anti-CSRF + 10 s rate limit (short — power actions can legitimately
            // cycle quickly when an admin is testing).
            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);
            $vf->requireRateLimit('power:' . $serviceID, 10);

            $powerAction = isset($_POST['powerAction']) ? preg_replace('/[^a-zA-Z]/', '', $_POST['powerAction']) : '';
            $allowedActions = ['boot', 'shutdown', 'restart', 'poweroff'];

            if (! in_array($powerAction, $allowedActions, true)) {
                $vf->output(['success' => false, 'errors' => 'Invalid power action'], true, true, 400);
                break;
            }

            $result = $vf->serverPowerAction($serviceID, $powerAction);

            if ($result) {
                $vf->output(['success' => true, 'data' => ['action' => $powerAction, 'message' => 'Power action queued successfully']], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Power action failed. The server may be locked or unavailable.'], true, true, 500);
            break;

            /**
             * Rebuild/reinstall server with new OS.
             */
        case 'rebuild':

            // Most-destructive client action — wipes the server. Strict
            // anti-CSRF (a malicious page tricking the customer into
            // rebuilding their own server destroys data) + 60 s rate limit
            // (no legitimate flow needs more than one rebuild per minute).
            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);
            $vf->requireRateLimit('rebuild:' . $serviceID, 60);

            $osId = isset($_POST['osId']) ? (int) $_POST['osId'] : 0;
            $hostname = isset($_POST['hostname']) ? preg_replace('/[^a-zA-Z0-9.\-]/', '', $_POST['hostname']) : null;
            $sshKey = isset($_POST['sshKey']) ? trim((string) $_POST['sshKey']) : null;

            if ($osId <= 0) {
                $vf->output(['success' => false, 'errors' => 'Invalid operating system ID'], true, true, 400);
                break;
            }

            $result = $vf->rebuildServer($serviceID, $osId, $hostname, $sshKey);

            if ($result) {
                $vf->output(['success' => true, 'data' => ['message' => 'Server rebuild initiated successfully']], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Server rebuild failed. The server may be locked or unavailable.'], true, true, 500);
            break;

            /**
             * Get SSH keys for the current user (for rebuild auth panel).
             */
        case 'sshKeys':

            $serviceID = $vf->validateServiceID(true);

            $clientId = $vf->validateUserOwnsService($serviceID);
            if (! $clientId) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            try {
                $cs = new \WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
                $user = \WHMCS\User\User::find($clientId);
                $sshKeysData = $cs->getUserSshKeys($user);
                $keys = [];
                if ($sshKeysData && isset($sshKeysData['data'])) {
                    $keys = array_values(array_filter(array_map(function ($k) {
                        if (empty($k['id']) || empty($k['name'])) return null;
                        return ['id' => $k['id'], 'name' => $k['name']];
                    }, $sshKeysData['data'])));
                }
                $vf->output(['success' => true, 'data' => $keys], true, true, 200);
            } catch (\Throwable $e) {
                Log::insert('sshKeys', [], $e->getMessage());
                $vf->output(['success' => true, 'data' => []], true, true, 200);
            }
            break;

            /**
             * Rename server.
             */
        case 'rename':

            // Mutation: anti-CSRF. No rate limit — name changes are cheap.
            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $newName = isset($_POST['name']) ? trim($_POST['name']) : '';

            // VF "name" is a display label, not a DNS hostname — preserve
            // case + accept any printable string up to 63 chars. The only
            // hard rejects are empty, oversized, and control characters.
            if ($newName === '' || strlen($newName) > 63 || preg_match('/[\x00-\x1F\x7F]/', $newName)) {
                $vf->output(['success' => false, 'errors' => 'Invalid server name'], true, true, 400);
                break;
            }

            $result = $vf->renameServer($serviceID, $newName);

            if ($result) {
                $vf->output(['success' => true, 'data' => ['message' => 'Server renamed successfully']], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Server rename failed'], true, true, 500);
            break;

            /**
             * Get available OS templates for rebuild.
             */
        case 'osTemplates':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $templates = $vf->fetchOsTemplates($serviceID);

            if ($templates !== false) {
                $vf->output(['success' => true, 'data' => $templates], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to fetch OS templates'], true, true, 500);
            break;

            // =================================================================
            // Server Password Reset
            // =================================================================

            /**
             * Reset server root password.
             */
        case 'resetServerPassword':

            // Destructive: rotates the VPS root password. Anti-CSRF + 30 s
            // rate limit so a hostile script can't lock out the customer.
            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);
            $vf->requireRateLimit('resetServerPassword:' . $serviceID, 30);

            $result = $vf->resetServerPassword($serviceID);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Password reset failed'], true, true, 500);
            break;

            // =================================================================
            // Backup Listing
            // =================================================================

            /**
             * Get server backups.
             */
        case 'backups':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $result = $vf->getServerBackups($serviceID);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to retrieve backups'], true, true, 500);
            break;

            // =================================================================
            // Traffic Statistics
            // =================================================================

            /**
             * Get traffic statistics for a server.
             */
        case 'trafficStats':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $result = $vf->getTrafficStats($serviceID);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to retrieve traffic statistics'], true, true, 500);
            break;

            // =================================================================
            // VNC Console
            // =================================================================

            /**
             * Get VNC console URL.
             */
        case 'vnc':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $result = $vf->getVncConsole($serviceID);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'VNC console unavailable. The server may be powered off or VNC is not supported.'], true, true, 500);
            break;

            /**
             * Render the noVNC viewer HTML page.
             *
             * SECURITY MODEL
             * --------------
             * This is the popup target instead of a blob URL — it keeps the
             * wss token out of any URL the customer can copy/share. The page
             * is gated by the same client.php protections every other action
             * uses:
             *   - WHMCS session required (isAuthenticated)
             *   - validateUserOwnsService prevents cross-customer access
             *     (any other customer hitting this URL with their session
             *     gets a 403)
             *   - requireProvisionedService blocks orphan services
             *
             * Each request rotates the wss token by POSTing to VirtFusion's
             * /vnc endpoint with vnc:true — older tokens VirtFusion was
             * tracking are superseded, so a leaked token from a previous
             * popup open is no longer usable after the next click.
             *
             * Method is POST (not GET) so we can require same-origin and
             * avoid the GET-with-side-effects anti-pattern. JS opens the
             * popup via a hidden form-submit (see vfOpenVnc in module.js).
             *
             * Output is text/html (NOT the JSON the other actions use),
             * directly delivered to the popup window.
             */
        case 'vncViewer':

            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);
            // 5 s rate limit — protects against runaway-script token rotation
            // bursts. A legitimate user clicking Open Console twice in a row
            // (e.g. popup got closed) waits at most 5 s.
            $vf->requireRateLimit('vncViewer:' . $serviceID, 5);

            // We MUST use toggleVnc (POST) to guarantee the VNC process is awake.
            // VirtFusion automatically kills idle VNC sessions on the hypervisor, so 
            // getVncConsole (GET) often returns a valid token for a dead port (Error 1006).
            $vncData = $vf->toggleVnc($serviceID, true);

            // If the POST fails (e.g., API glitch), fall back to GET.
            if ($vncData === false) {
                $vncData = $vf->getVncConsole($serviceID);
            }

            if ($vncData === false) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html><head><title>VNC Console</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;color:#aaa;background:#111;">Unable to obtain VNC credentials. The server may be powered off.</body></html>';
                exit;
            }

            // Drill the response shape the same way module.js used to —
            // wrapper.data.vnc holds the credentials; wrapper.baseUrl is
            // added by Module::toggleVnc / Module::getVncConsole.
            $apiRoot = isset($vncData['data']) ? $vncData['data'] : $vncData;
            $vnc = $apiRoot['vnc'] ?? [];
            $baseUrl = $vncData['baseUrl'] ?? '';
            $wssPath = $vnc['wss']['url'] ?? '';
            $password = $vnc['password'] ?? '';

            if ($baseUrl === '' || $wssPath === '') {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html><head><title>VNC Console</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;color:#aaa;background:#111;">VNC credentials missing from the API response.</body></html>';
                exit;
            }

            // Look up the server name for the popup title (best-effort —
            // doesn't gate rendering).
            $serverName = '';

            try {
                $hosting = Capsule::table('tblhosting')->where('id', $serviceID)->first(['domain']);
                $serverName = $hosting && $hosting->domain ? (string) $hosting->domain : '';
            } catch (Throwable $e) { /* non-fatal */
            }

            $vfHost = preg_replace('~^https?://~', '', rtrim($baseUrl, '/'));
            $vncJsSrc = $baseUrl . '/vnc/vnc.js';

            $esc = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

            header('Content-Type: text/html; charset=utf-8');
            // Don't let the page be embedded by other origins or cached
            // intermediaries — the rotated token must not stick around.
            header('X-Frame-Options: DENY');
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
            // CSP — only the VirtFusion panel can serve scripts (vnc.js bundle)
            // and only the wss endpoint on that host accepts our WebSocket.
            // Self and unsafe-inline are needed for the inline script that injects the noVNC bundle.
            header("Content-Security-Policy: default-src 'none'; script-src 'self' 'unsafe-inline' " . $baseUrl . '; connect-src wss://' . $vfHost . ' ' . $baseUrl . "; img-src 'self' data: " . $baseUrl . "; style-src 'self' 'unsafe-inline'; frame-ancestors 'none';");
            ?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>VNC — <?= $esc($serverName) ?></title>
    <style>html,body{margin:0;padding:0;background:#000;height:100%;font-family:sans-serif;color:#aaa;}</style>
</head>
<body>
    <input type="hidden" id="con" value="">
    <input type="hidden" id="pass" value="">
    <input type="hidden" id="server-name" value="">
    <div id="noVNC_container" style="position:fixed;inset:0;"></div>

    <script>
        const VIRTFUSION_HOST = <?= json_encode($vfHost, JSON_UNESCAPED_SLASHES) ?>;
        const VNC_PATH        = <?= json_encode($wssPath, JSON_UNESCAPED_SLASHES) ?>;
        const VNC_PASSWORD    = <?= json_encode($password, JSON_UNESCAPED_SLASHES) ?>;
        const SERVER_NAME     = <?= json_encode($serverName, JSON_UNESCAPED_SLASHES) ?>;
        const BUNDLE_VERSION  = "24"; // Use official version string to prevent chunk loading errors

        document.getElementById("con").value         = `wss://${VIRTFUSION_HOST}${VNC_PATH}`;
        document.getElementById("pass").value        = VNC_PASSWORD;
        document.getElementById("server-name").value = SERVER_NAME;

        // VNC processes are started dynamically on the hypervisor when we rotate the token.
        // We must delay the websocket connection slightly to give the hypervisor time to 
        // bind the port, otherwise the websocket proxy immediately drops us with a 1006.
        setTimeout(() => {
            const s = document.createElement("script");
            s.src = `https://${VIRTFUSION_HOST}/vnc/vnc.js?v=${BUNDLE_VERSION}`;
            document.body.appendChild(s);
        }, 2500);
    </script>
</body>
</html><?php
            exit;

            /**
             * Toggle VNC on/off.
             *
             * Dead path as of 1.5.0 (UI no longer exposes a toggle — see
             * VNC notes in CLAUDE.md). Kept for backwards-compat in case any
             * out-of-tree caller invokes it; gated as if it were live so
             * leaving it here doesn't widen the attack surface.
             */
        case 'toggleVnc':

            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);
            $vf->requireRateLimit('toggleVnc:' . $serviceID, 5);

            $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
            $result = $vf->toggleVnc($serviceID, $enabled);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Failed to toggle VNC'], true, true, 500);
            break;

            // =================================================================
            // Self Service — Credit & Usage
            // =================================================================

            /**
             * Get self-service usage data.
             */
        case 'selfServiceUsage':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $result = $vf->getSelfServiceUsage($serviceID);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to retrieve self-service usage data'], true, true, 500);
            break;

            /**
             * Get self-service billing report.
             */
        case 'selfServiceReport':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            $result = $vf->getSelfServiceReport($serviceID);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to retrieve self-service report'], true, true, 500);
            break;

            /**
             * Add self-service credit.
             */
        case 'selfServiceAddCredit':

            // Money-affecting mutation: anti-CSRF + 5 s rate limit so a
            // hostile script can't accidentally trigger duplicate charges
            // by spamming credit-adds. The actual amount is also validated
            // and money-bound on the WHMCS side, but defence-in-depth.
            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);
            $vf->requireRateLimit('selfServiceAddCredit:' . $serviceID, 5);

            $tokens = isset($_POST['tokens']) ? (float) $_POST['tokens'] : 0;
            if ($tokens <= 0) {
                $vf->output(['success' => false, 'errors' => 'Invalid credit amount. Must be a positive number.'], true, true, 400);
                break;
            }

            $result = $vf->addSelfServiceCredit($serviceID, $tokens);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Failed to add credit'], true, true, 500);
            break;

            // =================================================================
            // Reverse DNS (PowerDNS)
            // =================================================================

            /**
             * List PTR state for every IP assigned to the service's server.
             *
             * Always fetches fresh server data from VirtFusion (not cached server_object)
             * so the displayed IPs match current reality — if an IP was reassigned out
             * of this server since last sync, it won't appear here.
             */
        case 'rdnsList':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            // Reads are permitted for Active + Suspended (a suspended user can still see their rDNS);
            // Terminated/Pending/Cancelled/Fraud return a clear 400 upfront.
            $vf->requireServiceStatus($serviceID, ['Active', 'Suspended']);

            if (! PowerDnsConfig::isEnabled()) {
                $vf->output(['success' => true, 'data' => ['enabled' => false, 'ips' => []]], true, true, 200);
                break;
            }

            $serverData = $vf->fetchServerData($serviceID);
            if (! $serverData) {
                $vf->output(['success' => false, 'errors' => 'Unable to retrieve server data'], true, true, 502);
                break;
            }

            $ptrs = (new PtrManager)->listPtrs($serverData);
            $vf->output(['success' => true, 'data' => ['enabled' => true, 'ips' => $ptrs]], true, true, 200);
            break;

            /**
             * Update (or delete) the PTR for a single IP assigned to the user's server.
             *
             * Validation order: ownership -> IP format -> PTR regex -> IP belongs to this server
             * -> rate-limit/forward-DNS checks inside PtrManager. Sending an empty `ptr` deletes.
             */
        case 'rdnsUpdate':

            // Mutation: enforce POST, same-origin, active service status in that order.
            // requirePost/requireSameOrigin exit on failure (405/403 respectively), so nothing below runs.
            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            $clientId = $vf->validateUserOwnsService($serviceID);
            if (! $clientId) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);

            // Writes require an Active service — Suspended/Terminated/etc. cannot mutate rDNS.
            $vf->requireServiceStatus($serviceID, ['Active']);

            if (! PowerDnsConfig::isEnabled()) {
                $vf->output(['success' => false, 'errors' => 'Reverse DNS is not enabled on this installation'], true, true, 400);
                break;
            }

            $ip = isset($_POST['ip']) ? trim((string) $_POST['ip']) : '';
            $ptr = isset($_POST['ptr']) ? trim((string) $_POST['ptr']) : '';

            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $vf->output(['success' => false, 'errors' => 'Invalid IP address'], true, true, 400);
                break;
            }

            if ($ptr !== '' && ! preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}\.?$/', $ptr)) {
                $vf->output(['success' => false, 'errors' => 'Invalid hostname for PTR record'], true, true, 400);
                break;
            }
            if (strlen($ptr) > 253) {
                $vf->output(['success' => false, 'errors' => 'Hostname too long'], true, true, 400);
                break;
            }

            // Cross-check: the submitted IP must be currently assigned to this user's server.
            // Fetch fresh from VirtFusion (not the stored object) to prevent stale-ownership writes
            // after an IP reassignment.
            $serverData = $vf->fetchServerData($serviceID);
            if (! $serverData) {
                $vf->output(['success' => false, 'errors' => 'Unable to verify IP ownership'], true, true, 502);
                break;
            }
            $extracted = IpUtil::extractIps($serverData);
            $targetBin = @inet_pton($ip);
            $owns = false;

            // Stage 1: exact-IP match. Covers every v4 case and any v6 host address
            // VirtFusion exposes directly (per-host records or /128 subnet entries).
            foreach ($extracted['addresses'] as $a) {
                if (@inet_pton($a) === $targetBin) {
                    $owns = true;
                    break;
                }
            }

            // Stage 2: v6 subnet containment. If the exact match failed and this is
            // a v6 address, check whether it falls inside any of the server's
            // allocated v6 subnets. This is the path for "my VirtFusion VPS has a
            // /64 routed to it and I want a PTR for mail.example.com on one of the
            // host addresses inside that /64" — we don't know which host addresses
            // are actually in use, but we can prove this one lies within a range
            // the customer is authorised for.
            if (! $owns && IpUtil::isIpv6($ip)) {
                foreach ($extracted['subnets'] as $s) {
                    if (IpUtil::ipv6InSubnet($ip, $s['subnet'], (int) $s['cidr'])) {
                        $owns = true;
                        break;
                    }
                }
            }

            if (! $owns) {
                Log::insert('rdnsUpdate:ownership', ['serviceID' => $serviceID, 'ip' => $ip], 'IP not assigned to this service');
                $vf->output(['success' => false, 'errors' => 'This IP is not assigned to your server'], true, true, 403);
                break;
            }

            $result = (new PtrManager)->setPtr($ip, $ptr);

            if ($result['ok']) {
                // Audit trail for successful edits — surfaces in Utilities → Logs → Module Log,
                // searchable by clientId / serviceId / ip for "who changed this PTR".
                Log::insert(
                    'rdnsUpdate:ok',
                    ['clientId' => $clientId, 'serviceID' => $serviceID, 'ip' => $ip, 'reason' => $result['reason']],
                    ['ptr' => $ptr === '' ? '(deleted)' : $ptr],
                );
                $vf->output(['success' => true, 'data' => ['reason' => $result['reason']]], true, true, 200);
                break;
            }

            // Map internal reasons to client-facing messages/status codes.
            switch ($result['reason']) {
                case 'forward-missing':
                    $vf->output(['success' => false, 'errors' => 'Forward DNS for "' . $ptr . '" does not resolve to ' . $ip . '. Configure the A/AAAA record with your DNS provider first, then try again.'], true, true, 400);
                    break;
                case 'rate-limited':
                    $vf->output(['success' => false, 'errors' => 'Too many updates for this IP. Try again in a few seconds.'], true, true, 429);
                    break;
                case 'no-zone':
                    $vf->output(['success' => false, 'errors' => 'This IP has no reverse DNS zone configured on the nameserver.'], true, true, 400);
                    break;
                case 'disabled':
                    $vf->output(['success' => false, 'errors' => 'Reverse DNS is not enabled'], true, true, 400);
                    break;
                default:
                    $vf->output(['success' => false, 'errors' => 'Reverse DNS update failed (' . $result['reason'] . ')'], true, true, 500);
            }
            break;

        default:
            $vf->output(['success' => false, 'errors' => 'invalid action'], true, true, 400);
    }

} catch (Exception $e) {
    Log::insert('client.php', [], $e->getMessage());
    $vf->output(['success' => false, 'errors' => 'An unexpected error occurred'], true, true, 500);
}
