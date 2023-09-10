<?php

require dirname(__DIR__, 3) . '/init.php';

use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\ServerResource;

$vf = new Module();

$vf->isAuthenticated();

switch ($vf->validateAction(true)) {

    /**
     *
     * Reset Password.
     *
     */
    case 'resetPassword':

        if ($vf->validateServiceID(true)) {

            $client = $vf->validateUserOwnsService((int)$_GET['serviceID']);

            if (!$client) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 200);
            }

            $data = $vf->resetUserPassword((int)$_GET['serviceID'], $client);

            if ($data) {
                $vf->output(['success' => true, 'data' => $data->data], true, true, 200);
            }

            $vf->output(['success' => false, 'errors' => 'error'], true, true, 200);

        }
        break;

    /**
     *
     * Get server information.
     *
     */
    case 'serverData':

        if ($vf->validateServiceID(true)) {

            if (!$vf->validateUserOwnsService((int)$_GET['serviceID'])) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 200);
            }

            $data = $vf->fetchServerData((int)$_GET['serviceID']);

            if ($data) {

                (new Module())->updateWhmcsServiceParamsOnServerObject((int)$_GET['serviceID'], $data);

                $vf->output(['success' => true, 'data' => (new ServerResource())->process($data)], true, true, 200);

            }

            $vf->output(['success' => false, 'errors' => 'error'], true, true, 200);

        }
        break;

    /**
     *
     * Login as server owner.
     *
     */
    case 'loginAsServerOwner':

        if ($vf->validateServiceID(true)) {
            /**
             * A client can't log in as any user. Ownership should be validated.
             */

            if (!$vf->validateUserOwnsService((int)$_GET['serviceID'])) {
                $vf->output(['success' => false, 'errors' => 'service <> owner mismatch'], true, true, 200);
            }

            $token = $vf->fetchLoginTokens((int)$_GET['serviceID']);

            if ($token) {

                /**
                 * A valid token/url was received.
                 */
                $vf->output(['success' => true, 'token_url' => $token], true, true, 200);
            }

            /**
             * Failed to get the token from the control panel or the service ID doesn't exist.
             */
            $vf->output(['success' => false, 'errors' => 'token request error'], true, true, 200);

        }
        break;

    default:
        /**
         *
         * No valid action was specified.
         *
         */
        $vf->output(['success' => false, 'errors' => 'invalid action'], true, true, 200);
}


