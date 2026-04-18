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

            $serviceID = $vf->validateServiceID(true);
            $client = $vf->validateUserOwnsService($serviceID);

            if (! $client) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

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

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

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

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $osId = isset($_POST['osId']) ? (int) $_POST['osId'] : 0;
            $hostname = isset($_POST['hostname']) ? preg_replace('/[^a-zA-Z0-9.\-]/', '', $_POST['hostname']) : null;

            if ($osId <= 0) {
                $vf->output(['success' => false, 'errors' => 'Invalid operating system ID'], true, true, 400);
                break;
            }

            $result = $vf->rebuildServer($serviceID, $osId, $hostname);

            if ($result) {
                $vf->output(['success' => true, 'data' => ['message' => 'Server rebuild initiated successfully']], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Server rebuild failed. The server may be locked or unavailable.'], true, true, 500);
            break;

            /**
             * Rename server.
             */
        case 'rename':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $newName = isset($_POST['name']) ? trim($_POST['name']) : '';

            if (empty($newName) || strlen($newName) > 63 || ! preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $newName)) {
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

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

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

            $result = $vf->getVncConsole($serviceID);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'VNC console unavailable. The server may be powered off or VNC is not supported.'], true, true, 500);
            break;

            /**
             * Toggle VNC on/off.
             */
        case 'toggleVnc':

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

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

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

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
