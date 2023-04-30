<?php

require dirname(__DIR__, 3) . '/init.php';

use WHMCS\Module\Server\VirtFusionDirect\Database;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\ServerResource;

$vf = new Module();

$vf->adminOnly();

switch ($vf->validateAction(true)) {

    /**
     *
     * Get server information.
     *
     */
    case 'serverData':

        if ($vf->validateServiceID(true)) {

            /** No need to validate ownership **/

            $whmcsService = Database::getWhmcsService((int)$_GET['serviceID']);

            if (!$whmcsService) {
                $vf->output(['success' => false, 'errors' => 'Service not found.'], true, true, 200);
            }

            if ($whmcsService->domainstatus == 'Pending' || $whmcsService->domainstatus == 'Terminated' || $whmcsService->domainstatus == 'Cancelled' || $whmcsService->domainstatus == 'Fraud') {
                $vf->output(['success' => false, 'errors' => 'Server is not Active, Suspended or Completed. Not fetching remote data.'], true, true, 200);
            }

            $data = $vf->fetchServerData((int)$_GET['serviceID']);

            if (!$data) {
                $vf->output(['success' => false, 'errors' => 'No data returned from VirtFusion.'], true, true, 200);

            }

            (new Module())->updateWhmcsServiceParamsOnServerObject((int)$_GET['serviceID'], $data);
            $vf->output(['success' => true, 'data' => (new ServerResource())->process($data)], true, true, 200);

        }
        break;

    /**
     *
     * Impersonate server owner.
     *
     */
    case 'impersonateServerOwner':

        if ($vf->validateServiceID(true)) {

            $service = Database::getSystemService((int)$_GET['serviceID']);

            if (!$service) {
                $vf->output(['success' => false, 'errors' => 'Service not found'], true, true, 200);
            }

            $whmcsService = Database::getWhmcsService((int)$_GET['serviceID']);

            $cp = $vf->getCP($whmcsService->server);
            $request = $vf->initCurl($cp['token']);

            $data = $request->get($cp['url'] . '/users/' . $whmcsService->userid . '/byExtRelation');

            if ($request->getRequestInfo('http_code') === 200) {
                $vf->output(['success' => true, 'url' => $cp['base_url'], 'user' => json_decode($data, true)['data']], true, true, 200);
            }

            $vf->output(['success' => false, 'errors' => 'Received HTTP code ' . $request->getRequestInfo('http_code')], true, true, 200);

        }
        break;

    default:
        /** No valid action was specified **/

        $vf->output(['success' => false, 'errors' => 'invalid action'], true, true, 200);
}

