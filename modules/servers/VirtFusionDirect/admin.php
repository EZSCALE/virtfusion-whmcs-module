<?php

require dirname(__DIR__, 3) . '/init.php';

use WHMCS\Module\Server\VirtFusionDirect\Database;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\ServerResource;

$vf = new Module();

$vf->adminOnly();

switch ($vf->validateAction(true)) {

    /**
     * Get server information.
     */
    case 'serverData':

        $serviceID = $vf->validateServiceID(true);

        $whmcsService = Database::getWhmcsService($serviceID);

        if (!$whmcsService) {
            $vf->output(['success' => false, 'errors' => 'Service not found.'], true, true, 404);
            break;
        }

        if (in_array($whmcsService->domainstatus, ['Pending', 'Terminated', 'Cancelled', 'Fraud'], true)) {
            $vf->output(['success' => false, 'errors' => 'Server is not Active, Suspended or Completed. Not fetching remote data.'], true, true, 400);
            break;
        }

        $data = $vf->fetchServerData($serviceID);

        if (!$data) {
            $vf->output(['success' => false, 'errors' => 'No data returned from VirtFusion.'], true, true, 502);
            break;
        }

        $vf->updateWhmcsServiceParamsOnServerObject($serviceID, $data);
        $vf->output(['success' => true, 'data' => (new ServerResource())->process($data)], true, true, 200);
        break;

    /**
     * Impersonate server owner.
     */
    case 'impersonateServerOwner':

        $serviceID = $vf->validateServiceID(true);

        $service = Database::getSystemService($serviceID);
        if (!$service) {
            $vf->output(['success' => false, 'errors' => 'Service not found'], true, true, 404);
            break;
        }

        $whmcsService = Database::getWhmcsService($serviceID);
        if (!$whmcsService) {
            $vf->output(['success' => false, 'errors' => 'WHMCS service not found'], true, true, 404);
            break;
        }

        $cp = $vf->getCP($whmcsService->server);
        if (!$cp) {
            $vf->output(['success' => false, 'errors' => 'Control server not found'], true, true, 500);
            break;
        }

        $request = $vf->initCurl($cp['token']);
        $data = $request->get($cp['url'] . '/users/' . (int) $whmcsService->userid . '/byExtRelation');

        if ($request->getRequestInfo('http_code') === 200) {
            $vf->output(['success' => true, 'url' => $cp['base_url'], 'user' => json_decode($data, true)['data']], true, true, 200);
            break;
        }

        $vf->output(['success' => false, 'errors' => 'Unable to fetch user data'], true, true, 502);
        break;

    default:
        $vf->output(['success' => false, 'errors' => 'invalid action'], true, true, 400);
}
