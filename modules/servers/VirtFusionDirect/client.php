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
 *   2. $vf->validateServiceID(true)        — strict positive integer + presence
 *   3. $vf->validateUserOwnsService($id)   — the session owns this service (403)
 *   4. Optional: requireServiceStatus      — filter by tblhosting.domainstatus
 *   5. Optional (mutations): requirePost   — HTTP method gate (405)
 *   6. Mutations: requireSameOrigin       — WHMCS CSRF token plus origin check
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
 * client, even on bugs in our own code. All uncaught exceptions are logged. A
 * RuntimeException carrying 401/403/404/405/409/429 (CSRF, auth, not found,
 * busy, throttled) keeps its status and message; everything else is a generic 500.
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VirtFusionDirect\Access;
use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Database;
use WHMCS\Module\Server\VirtFusionDirect\Log;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config as PowerDnsConfig;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\IpUtil;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\PtrManager;
use WHMCS\Module\Server\VirtFusionDirect\PtrTools;
use WHMCS\Module\Server\VirtFusionDirect\ServiceTools;
use WHMCS\Module\Server\VirtFusionDirect\SshKeyRejected;
use WHMCS\Module\Server\VirtFusionDirect\SshKeys;
use WHMCS\Module\Server\VirtFusionDirect\ToolsRouter;
use WHMCS\User\User;

$vf = new Module;

try {

    $vf->isAuthenticated();
    Access::client('manageproducts');

    $action = $vf->validateAction(true);

    $toolsAction = $vf->validateAction(true);
    if (in_array($toolsAction, ['serviceTools', 'capabilities', 'packData', 'providerSettings', 'providerSettingsSave', 'packMigrationPreview', 'packMigrationApply', 'packBindingStatus', 'packBindingReconcile'], true)) {
        ToolsRouter::run($vf, $toolsAction, false);
    }

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

            // The rotated panel password is returned to the caller, so this is
            // effectively panel SSO: sub-users need the productsso permission.
            try {
                Access::client('productsso');
            } catch (RuntimeException $e) {
                $vf->output(['success' => false, 'errors' => 'Single sign-on permission is required to reset the panel password.'], true, true, 403);
                break;
            }

            $serviceID = $vf->validateServiceID(true);
            $client = $vf->validateUserOwnsService($serviceID);

            if (! $client) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireProvisionedService($serviceID);
            $vf->requireServiceStatus($serviceID, ['Active']);
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

            // Cached 20 s per panel/VM (sanitised view only); the 2 s throttle applies on misses.
            $view = $vf->serverDataView($serviceID, fn () => $vf->requireRateLimit('serverData:' . $serviceID, 2));

            if ($view !== null) {
                $vf->output(['success' => true, 'data' => $view], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to retrieve server data'], true, true, 500);
            break;

            /**
             * Login as server owner.
             */
        case 'loginAsServerOwner':

            $vf->requirePost();
            $vf->requireSameOrigin();
            Access::client('productsso');

            $serviceID = $vf->validateServiceID(true);

            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireServiceStatus($serviceID, ['Active', 'Suspended']);

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
            $vf->requireServiceStatus($serviceID, ['Active']);
            $vf->requireNoUnresolvedOperation($serviceID);
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

            if ($vf->serverWasBusy()) {
                $vf->output(['success' => false, 'errors' => Module::SERVER_BUSY_MESSAGE], true, true, 409);
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
            $vf->requireServiceStatus($serviceID, ['Active']);
            $vf->requireNoUnresolvedOperation($serviceID);

            // Cheap input validation runs BEFORE the rate limit so a typo does
            // not lock the customer out of rebuild for the whole window.
            $osId = isset($_POST['osId']) ? (int) $_POST['osId'] : 0;
            $hostname = isset($_POST['hostname']) ? trim((string) $_POST['hostname']) : null;
            $sshKey = isset($_POST['sshKey']) ? trim((string) $_POST['sshKey']) : null;

            if ($osId <= 0) {
                $vf->output(['success' => false, 'errors' => 'Invalid operating system ID'], true, true, 400);
                break;
            }

            if ($hostname !== null && $hostname !== '' && ! Module::isValidHostname($hostname, 63)) {
                $vf->output(['success' => false, 'errors' => 'Invalid hostname. Use letters, digits and hyphens (max 63 characters).'], true, true, 400);
                break;
            }

            // A pasted key must be a valid public key; a numeric value is an
            // existing key ID that rebuildServer() checks against the owner's keys.
            if ($sshKey !== null && $sshKey !== '' && ! ctype_digit($sshKey)) {
                try {
                    $sshKey = SshKeys::publicKey($sshKey);
                } catch (SshKeyRejected $e) {
                    $vf->output(['success' => false, 'errors' => $e->getMessage()], true, true, 400);
                    break;
                }
            }

            $vf->requireRateLimit('rebuild:' . $serviceID, 60);

            $result = $vf->rebuildServer($serviceID, $osId, $hostname, $sshKey);

            if ($result) {
                $vf->output(['success' => true, 'data' => ['message' => 'Server rebuild initiated successfully']], true, true, 200);
                break;
            }

            if ($vf->serverWasBusy()) {
                $vf->output(['success' => false, 'errors' => Module::SERVER_BUSY_MESSAGE], true, true, 409);
                break;
            }

            $refusal = $vf->lastRefusal();
            if ($refusal !== null) {
                $vf->output(['success' => false, 'errors' => $refusal['message']], true, true, $refusal['status']);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Server rebuild failed. The server may be locked or unavailable.'], true, true, 500);
            break;

            /**
             * List the current customer's existing VirtFusion SSH keys (read-only)
             * so the rebuild panel can offer them. Keys are scoped to the
             * authenticated owner — the VF user is derived from $clientId, never
             * from request input — so there is no cross-customer exposure.
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
                $service = Database::getWhmcsService($serviceID);
                $cs = new ConfigureService((int) $service->server);
                $sshKeysData = $cs->getClientSshKeys($clientId);
                // id, name and fingerprint only — never the public key body.
                $keys = SshKeys::present($sshKeysData['data'] ?? []);
                $vf->output(['success' => true, 'data' => $keys], true, true, 200);
            } catch (Throwable $e) {
                Log::insert('sshKeys', [], $e->getMessage());
                $vf->output(['success' => true, 'data' => []], true, true, 200);
            }
            break;

            /**
             * Add an SSH key to the customer's VirtFusion account.
             *
             * Keys are account-level: they belong to the VF user resolved from
             * the authenticated client, never from request input. The refreshed
             * list (id, name, fingerprint) is returned so the SSH Keys panel and
             * the Rebuild dropdown can update without another request.
             */
        case 'sshKeyAdd':

            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            $clientId = $vf->validateUserOwnsService($serviceID);
            if (! $clientId) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireServiceStatus($serviceID, ['Active']);

            try {
                $keyName = SshKeys::name($_POST['name'] ?? '');
                $publicKey = SshKeys::publicKey($_POST['publicKey'] ?? '');
            } catch (SshKeyRejected $e) {
                $vf->output(['success' => false, 'errors' => $e->getMessage()], true, true, 400);
                break;
            }

            $vf->requireRateLimit('sshKeyAdd:' . $clientId, 5);

            try {
                $service = Database::getWhmcsService($serviceID);
                $cs = new ConfigureService((int) $service->server);
                $keys = $cs->addClientSshKey((int) $clientId, $keyName, $publicKey);
            } catch (Throwable $e) {
                $error = SshKeys::clientError($e);
                Log::insert('sshKeyAdd', ['clientId' => $clientId, 'serviceID' => $serviceID, 'status' => $error['status']], $e->getMessage());
                $vf->output(['success' => false, 'errors' => $error['message']], true, true, $error['status']);
                break;
            }

            if ($keys === null) {
                // The write completed; only the list re-read failed.
                $vf->output(['success' => true, 'data' => null, 'message' => 'Saved. Refresh the page to see the updated key list.'], true, true, 200);
                break;
            }

            $vf->output(['success' => true, 'data' => $keys], true, true, 200);
            break;

            /**
             * Delete an SSH key from the customer's VirtFusion account.
             *
             * The key ID must be in the key inventory of THIS client's VF user
             * (ConfigureService::deleteClientSshKey); a foreign or unknown ID is
             * a 404 and no DELETE is sent.
             */
        case 'sshKeyDelete':

            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            $clientId = $vf->validateUserOwnsService($serviceID);
            if (! $clientId) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
                break;
            }

            $vf->requireServiceStatus($serviceID, ['Active']);

            try {
                $keyId = ServiceTools::positiveId($_POST['keyId'] ?? null);
            } catch (InvalidArgumentException $e) {
                $vf->output(['success' => false, 'errors' => 'SSH key not found.'], true, true, 404);
                break;
            }

            // Per key, short window: several keys can be removed in a row.
            $vf->requireRateLimit('sshKeyDelete:' . $clientId . ':' . $keyId, 2);

            try {
                $service = Database::getWhmcsService($serviceID);
                $cs = new ConfigureService((int) $service->server);
                $keys = $cs->deleteClientSshKey((int) $clientId, $keyId);
            } catch (Throwable $e) {
                $error = SshKeys::clientError($e);
                Log::insert('sshKeyDelete', ['clientId' => $clientId, 'serviceID' => $serviceID, 'status' => $error['status']], $e->getMessage());
                $vf->output(['success' => false, 'errors' => $error['message']], true, true, $error['status']);
                break;
            }

            if ($keys === null) {
                // The write completed; only the list re-read failed.
                $vf->output(['success' => true, 'data' => null, 'message' => 'Saved. Refresh the page to see the updated key list.'], true, true, 200);
                break;
            }

            $vf->output(['success' => true, 'data' => $keys], true, true, 200);
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
            $vf->requireServiceStatus($serviceID, ['Active']);
            $vf->requireNoUnresolvedOperation($serviceID);

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
            $vf->requireServiceStatus($serviceID, ['Active']);
            $vf->requireNoUnresolvedOperation($serviceID);
            $vf->requireRateLimit('resetServerPassword:' . $serviceID, 30);

            // Which guest account to reset: root (Linux) or Administrator (Windows).
            // Default root; reject anything off the allow-list before it reaches the API.
            $resetUser = $_POST['user'] ?? 'root';
            if (! Module::isValidPasswordResetUser($resetUser)) {
                $vf->output(['success' => false, 'errors' => 'Invalid user'], true, true, 400);
                break;
            }

            $result = $vf->resetServerPassword($serviceID, $resetUser);

            if ($result !== false) {
                $vf->output(['success' => true, 'data' => $result], true, true, 200);
                break;
            }

            if ($vf->serverWasBusy()) {
                $vf->output(['success' => false, 'errors' => Module::SERVER_BUSY_MESSAGE], true, true, 409);
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

            $vf->requireServiceStatus($serviceID, ['Active']);
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
             * Each open requests credentials using POST /vnc with vnc:true.
             * WHMCS authorization is rechecked while the viewer is open and
             * credentials are cleared on pagehide. Native token expiry and
             * revocation require installed-panel validation; closing this
             * browser view does not prove a leaked native token was revoked.
             *
             * Method is POST (not GET) so we can require same-origin and
             * avoid the GET-with-side-effects anti-pattern. JS opens the
             * popup via a hidden form-submit (see vfOpenVnc in module.js).
             *
             * Output is text/html (NOT the JSON the other actions use),
             * directly delivered to the popup window.
             */
        case 'vncSessionStatus':
            $serviceID = $vf->validateServiceID(true);
            if (! $vf->validateUserOwnsService($serviceID)) {
                $vf->output(['success' => false, 'errors' => 'Console session ended.'], true, true, 403);
                break;
            }
            $vf->requireProvisionedService($serviceID);
            $vf->requireServiceStatus($serviceID, ['Active']);
            $vf->output(['success' => true, 'data' => ['authenticated' => true]], true, true, 200);
            break;

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

            $vf->requireServiceStatus($serviceID, ['Active']);

            // A failed credential request may be an authorization or transport
            // failure. Do not silently retrieve an older session as a fallback.
            $vncData = $vf->toggleVnc($serviceID, true);

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

            // Per-request nonce for the single inline bootstrap script below. Using
            // a nonce keeps the CSP strict (no 'unsafe-inline') while still allowing
            // the one inline script that delays loading the noVNC bundle.
            $nonce = base64_encode(random_bytes(16));
            $sessionCheckUrl = rtrim(Database::getSystemUrl(), '/') . '/modules/servers/VirtFusionDirect/client.php?action=vncSessionStatus&serviceID=' . $serviceID;

            header('Content-Type: text/html; charset=utf-8');
            // Don't let the page be embedded by other origins or cached
            // intermediaries — the rotated token must not stick around.
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
            // CSP — only the VirtFusion panel can serve the vnc.js bundle (allowed
            // via $baseUrl), only the wss endpoint on that host accepts our
            // WebSocket, and only our nonce'd inline bootstrap may run.
            header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "' " . $baseUrl . "; connect-src 'self' wss://" . $vfHost . ' ' . $baseUrl . "; img-src 'self' data: " . $baseUrl . "; style-src 'self' 'unsafe-inline'; frame-ancestors 'none';");
            ?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>VNC — <?= $esc($serverName) ?></title>
    <style>html,body{margin:0;padding:0;background:#000;height:100%;font-family:sans-serif;color:#aaa;}</style>
</head>
<body>
    <input type="hidden" id="con" value="wss://<?= $esc($vfHost . $wssPath) ?>">
    <input type="hidden" id="pass" value="<?= $esc($password) ?>">
    <input type="hidden" id="server-name" value="<?= $esc($serverName) ?>">
    <div id="noVNC_container" style="position:fixed;inset:0;"></div>
    <script nonce="<?= $esc($nonce) ?>">
        // The native console was requested (toggleVnc POST above), starting the VNC
        // process up on the hypervisor. Loading the noVNC bundle immediately can
        // race that startup — the wss proxy then drops the socket with a 1006.
        // Delaying the bundle load a couple of seconds lets the hypervisor bind
        // the port first. (Timing fix contributed by ThakorRohan / FlashRDP.)
        window.opener = null;
        var sessionCheckUrl = <?= json_encode($sessionCheckUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var checking = false;
        async function checkConsoleSession() {
            if (checking) return;
            checking = true;
            var controller = new AbortController();
            var timeout = setTimeout(function () { controller.abort(); }, 5000);
            try {
                var response = await fetch(sessionCheckUrl, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer', signal: controller.signal});
                var result = await response.json();
                if (!response.ok || !result.success) throw new Error('Console session ended');
                document.body.style.visibility = 'visible';
            } catch (e) {
                document.getElementById('con').value = '';
                document.getElementById('pass').value = '';
                window.location.replace('about:blank');
            } finally {
                clearTimeout(timeout);
                checking = false;
            }
        }
        setInterval(checkConsoleSession, 30000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                document.body.style.visibility = 'hidden';
                checkConsoleSession();
            }
        });

        window.addEventListener('pagehide', function () {
            document.getElementById('con').value = '';
            document.getElementById('pass').value = '';
        });
        setTimeout(function () {
            var s = document.createElement('script');
            s.src = <?= json_encode($vncJsSrc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            document.body.appendChild(s);
        }, 2500);
    </script>
</body>
</html><?php
            exit;
            break;

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
            $vf->requireServiceStatus($serviceID, ['Active']);
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

            $vf->requirePost();
            $vf->requireSameOrigin();
            // Ownership and CSRF cannot prove payment. Keep this legacy route
            // denied until a paid-transaction, idempotent issuance flow exists.
            $vf->output(['success' => false, 'errors' => 'Customer credit top-ups are unavailable.'], true, true, 403);
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

            if ($ptr !== '' && ! preg_match(PtrTools::HOSTNAME_PATTERN, $ptr)) {
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

} catch (Throwable $e) {
    // Guards such as Access::csrf() and Access::client() throw RuntimeException
    // with an HTTP code (401/403/404/405/409/429); honour it like ToolsRouter
    // does. Anything else stays a generic 500.
    $error = Module::clientError($e);
    Log::insert('client.php', ['status' => $error['status']], $e->getMessage());
    $vf->output(['success' => false, 'errors' => $error['message']], true, true, $error['status']);
}
