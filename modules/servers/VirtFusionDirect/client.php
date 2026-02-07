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
        }

        $data = $vf->resetUserPassword($serviceID, $client);

        if ($data) {
            $vf->output(['success' => true, 'data' => $data->data], true, true, 200);
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
        }

        $data = $vf->fetchServerData($serviceID);

        if ($data) {
            (new Module())->updateWhmcsServiceParamsOnServerObject($serviceID, $data);
            $vf->output(['success' => true, 'data' => (new ServerResource())->process($data)], true, true, 200);
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
        }

        $token = $vf->fetchLoginTokens($serviceID);

        if ($token) {
            $vf->output(['success' => true, 'token_url' => $token], true, true, 200);
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
        }

        $powerAction = isset($_GET['powerAction']) ? preg_replace('/[^a-zA-Z]/', '', $_GET['powerAction']) : '';
        $allowedActions = ['boot', 'shutdown', 'restart', 'poweroff'];

        if (!in_array($powerAction, $allowedActions, true)) {
            $vf->output(['success' => false, 'errors' => 'Invalid power action'], true, true, 400);
        }

        $result = $vf->serverPowerAction($serviceID, $powerAction);

        if ($result) {
            $vf->output(['success' => true, 'data' => ['action' => $powerAction, 'message' => 'Power action queued successfully']], true, true, 200);
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
        }

        $osId = isset($_GET['osId']) ? (int) $_GET['osId'] : 0;
        $hostname = isset($_GET['hostname']) ? preg_replace('/[^a-zA-Z0-9.\-]/', '', $_GET['hostname']) : null;

        if ($osId <= 0) {
            $vf->output(['success' => false, 'errors' => 'Invalid operating system ID'], true, true, 400);
        }

        $result = $vf->rebuildServer($serviceID, $osId, $hostname);

        if ($result) {
            $vf->output(['success' => true, 'data' => ['message' => 'Server rebuild initiated successfully']], true, true, 200);
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
        }

        $newName = isset($_GET['name']) ? trim($_GET['name']) : '';
        $newName = htmlspecialchars($newName, ENT_QUOTES, 'UTF-8');

        if (empty($newName) || strlen($newName) > 255) {
            $vf->output(['success' => false, 'errors' => 'Invalid server name'], true, true, 400);
        }

        $result = $vf->renameServer($serviceID, $newName);

        if ($result) {
            $vf->output(['success' => true, 'data' => ['message' => 'Server renamed successfully']], true, true, 200);
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
        }

        $templates = $vf->fetchOsTemplates($serviceID);

        if ($templates !== false) {
            $vf->output(['success' => true, 'data' => $templates], true, true, 200);
        }

        $vf->output(['success' => false, 'errors' => 'Unable to fetch OS templates'], true, true, 500);
        break;

    // =================================================================
    // IP Address Management
    // =================================================================

    /**
     * Remove an IPv4 address.
     */
    case 'removeIPv4':

        $serviceID = $vf->validateServiceID(true);

        if (!$vf->validateUserOwnsService($serviceID)) {
            $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 403);
        }

        $ipAddress = isset($_GET['ip']) ? trim($_GET['ip']) : '';
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $vf->output(['success' => false, 'errors' => 'Invalid IPv4 address'], true, true, 400);
        }

        $result = $vf->removeIPv4($serviceID, $ipAddress);

        if ($result) {
            $vf->output(['success' => true, 'data' => ['message' => 'IPv4 address removed successfully']], true, true, 200);
        }

        $vf->output(['success' => false, 'errors' => 'Failed to remove IPv4 address'], true, true, 500);
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
        }

        $result = $vf->getVncConsole($serviceID);

        if ($result !== false) {
            $vf->output(['success' => true, 'data' => $result], true, true, 200);
        }

        $vf->output(['success' => false, 'errors' => 'VNC console unavailable. The server may be powered off or VNC is not supported.'], true, true, 500);
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
        }

        $result = $vf->getSelfServiceUsage($serviceID);

        if ($result !== false) {
            $vf->output(['success' => true, 'data' => $result], true, true, 200);
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
        }

        $result = $vf->getSelfServiceReport($serviceID);

        if ($result !== false) {
            $vf->output(['success' => true, 'data' => $result], true, true, 200);
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
        }

        $tokens = isset($_GET['tokens']) ? (float) $_GET['tokens'] : 0;
        if ($tokens <= 0) {
            $vf->output(['success' => false, 'errors' => 'Invalid credit amount. Must be a positive number.'], true, true, 400);
        }

        $result = $vf->addSelfServiceCredit($serviceID, $tokens);

        if ($result !== false) {
            $vf->output(['success' => true, 'data' => $result], true, true, 200);
        }

        $vf->output(['success' => false, 'errors' => 'Failed to add credit'], true, true, 500);
        break;

    default:
        $vf->output(['success' => false, 'errors' => 'invalid action'], true, true, 400);
}
