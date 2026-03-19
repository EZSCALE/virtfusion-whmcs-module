<?php

require dirname(__DIR__, 3) . '/init.php';

use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\ServerResource;

$vf = new Module();

$vf->isAuthenticated();

$action = $vf->validateAction(true);

switch ($action) {

    /**
     * Reset Password.
     */
    case 'resetPassword':

        $serviceID = $vf->validateServiceID(true);
        $client = $vf->validateUserOwnsService($serviceID);

        if (!$client) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
            $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
            break;
        }

        $data = $vf->fetchServerData($serviceID);

        if ($data) {
            (new Module())->updateWhmcsServiceParamsOnServerObject($serviceID, $data);
            $vf->output(['success' => true, 'data' => (new ServerResource())->process($data)], true, true, 200);
            break;
        }

        $vf->output(['success' => false, 'errors' => 'Unable to retrieve server data'], true, true, 500);
        break;

    /**
     * Login as server owner.
     */
    case 'loginAsServerOwner':

        $serviceID = $vf->validateServiceID(true);

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
            $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
            break;
        }

        $powerAction = isset($_POST['powerAction']) ? preg_replace('/[^a-zA-Z]/', '', $_POST['powerAction']) : '';
        $allowedActions = ['boot', 'shutdown', 'restart', 'poweroff'];

        if (!in_array($powerAction, $allowedActions, true)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
            $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
            break;
        }

        $newName = isset($_POST['name']) ? trim($_POST['name']) : '';

        if (empty($newName) || strlen($newName) > 63 || !preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $newName)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

        if (!$vf->validateUserOwnsService($serviceID)) {
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

    default:
        $vf->output(['success' => false, 'errors' => 'invalid action'], true, true, 400);
}
