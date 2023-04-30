<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class ModuleFunctions extends Module
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     *
     * CREATE SERVER
     *
     * Before creating a server, we check to see if a user exists in VirtFusion that matches
     * the WHMCS user. If it matches, We move on to create the server, if not, we attempt to
     * create a user to assign to the new server.
     *
     */
    public function createAccount($params)
    {
        try {

            /**
             *
             * If the service exists in the custom table, Cancel the create account action.
             *
             */
            if (Database::checkSystemService($params['serviceid'])) {
                return 'Service already exists. You must run a termination first.';
            }


            /**
             *
             * If no VirtFusionDirect control server exists, cancel the create account action.
             *
             */

            $server = $params['serverid'] ?: false;
            $cp = $this->getCP($server, $server ? false : true);

            if (!$cp) {
                return 'No Control server found.';
            }

            Log::insert(__FUNCTION__, $params, []);

            /**
             *
             * Does a user account in VirtFusion match this account (byExtRelationId) in WHMCS.
             *
             */
            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/users/' . $params['userid'] . '/byExtRelation');

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            switch ($request->getRequestInfo('http_code')) {
                case 200:

                    /**
                     *
                     * A user with relation ID exists in VirtFusion. We can provision under that account.
                     *
                     */
                    break;

                case 404:

                    /**
                     *
                     * A user doesn't exist in VirtFusion. We should attempt to create one.
                     *
                     */
                    $user = Database::getUser($params['userid']);

                    $request = $this->initCurl($cp['token']);

                    $request->addOption(CURLOPT_POSTFIELDS, json_encode(
                        [
                            "name" => $user->firstname . ' ' . $user->lastname,
                            "email" => $user->email,
                            "extRelationId" => $user->id
                        ]
                    ));

                    $data = $request->post($cp['url'] . '/users');

                    Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                    if ($request->getRequestInfo('http_code') !== 201) {
                        return 'Unable to create user.';
                    }
                    break;
                default:
                    return 'Error processing user account.';
                    break;
            }

            $data = json_decode($data);

            /**
             *
             * A user is available. We can now attempt to create a server.
             *
             */

            $configOptionDefaultNaming = [
                'ipv4' => 'IPv4',
                'packageId' => 'Package',
                'hypervisorId' => 'Location',
                'storage' => 'Storage',
                'memory' => 'Memory',
                'traffic' => 'Bandwidth',
                'networkSpeedInbound' => 'Inbound Network Speed',
                'networkSpeedOutbound' => 'Outbound Network Speed',
                'cpuCores' => 'CPU Cores',
                'networkProfile' => 'Network Type',
                'storageProfile' => 'Storage Type',
            ];

            $configOptionCustomNaming = [];

            if (file_exists(ROOTDIR . '/modules/servers/VirtFusionDirect/config/ConfigOptionMapping.php')) {
                $configOptionCustomNaming = require_once ROOTDIR . '/modules/servers/VirtFusionDirect/config/ConfigOptionMapping.php';
            }

            $options = [
                "packageId" => $params['configoption2'],
                "userId" => $data->data->id,
                "hypervisorId" => $params['configoption1'],
                "ipv4" => $params['configoption3'],
            ];

            if (array_key_exists('configoptions', $params)) {
                foreach ($configOptionDefaultNaming as $key => $option) {
                    $currentOption = array_key_exists($key, $configOptionCustomNaming) ? $configOptionCustomNaming[$key] : $option;
                    if (array_key_exists($currentOption, $params['configoptions'])) {
                        // If the option key is "Memory" and the value is less than 1024, we need to convert it to MB
                        // VirtFusion expects memory in MB.
                        if ($currentOption === 'Memory' && $params['configoptions'][$currentOption] < 1024) {
                            $options[$key] = $params['configoptions'][$currentOption] * 1024;
                        } else {
                            $options[$key] = $params['configoptions'][$currentOption];
                        }
                    }
                }
            }

            $request = $this->initCurl($cp['token']);
            $request->addOption(CURLOPT_POSTFIELDS, json_encode($options));

            $data = $request->post($cp['url'] . '/servers');

            $data = json_decode($data);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            if ($request->getRequestInfo('http_code') === 201) {

                Database::systemOnServerCreate($params['serviceid'], $data);
                $this->updateWhmcsServiceParamsOnServerObject($params['serviceid'], $data);

                /**
                 *
                 * Server was created successfully.
                 *
                 */
                return 'success';
            } else {
                if ($data->errors[0]) {
                    return $data->errors[0];
                }
                return 'Unknown error.';
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());
            return $e->getMessage();
        }
    }

    /**
     *
     * TERMINATE SERVER
     *
     * When requesting to terminate a server in VirtFusion, we leave it set to
     * the default 5-minute delay allowing to un-terminate in VirtFusion if the
     * request was done in error.
     *
     */
    public function terminateAccount($params)
    {
        $service = Database::getSystemService($params['serviceid']);

        if ($service) {

            $whmcsService = Database::getWhmcsService($params['serviceid']);

            $cp = $this->getCP($whmcsService->server);

            $request = $this->initCurl($cp['token']);
            $data = $request->delete($cp['url'] . '/servers/' . $service->server_id);
            $data = json_decode($data);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            switch ($request->getRequestInfo('http_code')) {

                case 204:
                    Database::deleteSystemService($params['serviceid']);
                    $this->updateWhmcsServiceParamsOnDestroy($params['serviceid']);
                    return 'success';
                    break;

                case 404:
                    if (property_exists($data, 'msg')) {
                        if ($data->msg == 'server not found') {
                            Database::deleteSystemService($params['serviceid']);
                            return 'success';
                        } else {
                            return '404 was returned from the web service with the msg property but doesn\'t contain appropriate data to process a termination.';
                        }
                    } else {
                        return '404 was returned from the web service without the msg property. The service may be currently unavailable.';
                    }
                    break;

                default:
                    return 'Termination request failed. The web service reported HTTP code ' . $request->getRequestInfo('http_code');
                    break;
            }
        }
        return 'Service not found. Termination routine has already been run?';
    }

    /**
     *
     * SUSPEND SERVER
     *
     * When requesting to suspend a server in VirtFusion it may be delayed if another action
     * is being processed. This function will return success if the server is either suspended
     * now or has been queued for suspension.
     *
     */
    public function suspendAccount($params)
    {
        $service = Database::getSystemService($params['serviceid']);

        if ($service) {

            $whmcsService = Database::getWhmcsService($params['serviceid']);

            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->post($cp['url'] . '/servers/' . $service->server_id . '/suspend');
            $data = json_decode($data);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            switch ($request->getRequestInfo('http_code')) {

                case 204:
                    return 'success';
                    break;

                case 404:
                    if (property_exists($data, 'msg')) {
                        if ($data->msg == 'server not found') {
                            Database::deleteSystemService($params['serviceid']);
                            return 'success';
                        } else {

                            return '404 was returned from the web service with the msg property but doesn\'t contain appropriate data to process a suspension.';
                        }
                    } else {
                        return '404 was returned from the web service without the msg property. The service may be currently unavailable.';
                    }
                    break;
                case 423:
                    if (property_exists($data, 'msg')) {
                        return $data->msg;
                    }

                default:
                    return 'Suspend request failed. The web service reported HTTP code ' . $request->getRequestInfo('http_code');
                    break;
            }
        }
        return 'Service not found.';
    }

    function updateServerObject($params)
    {
        $service = Database::getSystemService($params['serviceid']);

        if ($service) {

            $whmcsService = Database::getWhmcsService($params['serviceid']);

            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/servers/' . $service->server_id);
            $data = json_decode($data);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            switch ($request->getRequestInfo('http_code')) {

                case 200:
                    Database::updateSystemServiceServerObject($params['serviceid'], $data);

                    $this->updateWhmcsServiceParamsOnServerObject($params['serviceid'], $data);

                    return 'success';
                    break;
                default:
                    return 'Request failed. The web service reported HTTP code ' . $request->getRequestInfo('http_code');
                    break;
            }
        }
        return 'Service not found.';
    }


    public function unsuspendAccount($params)
    {
        $service = Database::getSystemService($params['serviceid']);

        if ($service) {
            $whmcsService = Database::getWhmcsService($params['serviceid']);

            $cp = $this->getCP($whmcsService->server);
            $request = $this->initCurl($cp['token']);
            $data = $request->post($cp['url'] . '/servers/' . $service->server_id . '/unsuspend');
            $data = json_decode($data);

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            switch ($request->getRequestInfo('http_code')) {

                case 204:
                    return 'success';
                    break;

                case 404:
                    if (property_exists($data, 'msg')) {
                        if ($data->msg == 'server not found') {
                            Database::deleteSystemService($params['serviceid']);
                            return 'success';
                        } else {
                            return '404 was returned from the web service with the msg property but doesn\'t contain appropriate data to process an unsuspension.';
                        }
                    } else {
                        return '404 was returned from the web service without the msg property. The service may be currently unavailable.';
                    }
                    break;
                case 423:
                    if (property_exists($data, 'msg')) {
                        return $data->msg;
                    }

                default:
                    return 'Unsuspend request failed. The web service reported HTTP code ' . $request->getRequestInfo('http_code');
                    break;
            }
        }
        return 'Service not found';
    }

    public function adminServicesTabFields($params)
    {
        $serverId = '';
        $serverObject = '';

        $service = Database::getSystemService($params['serviceid']);
        $systemUrl = Database::getSystemUrl();

        if ($service) {
            $serverId = $service->server_id;
            $serverObject = $service->server_object;
        }
        $fields = [
            'Server ID' => AdminHTML::serverId($serverId),
            'Server Info' => AdminHTML::serverInfo($systemUrl, $params['serviceid']),
            'Server Object' => AdminHTML::serverObject($serverObject),
        ];

        if ($params['status'] != 'Terminated') {
            $fields['Options'] = AdminHTML::options($systemUrl, $params['serviceid']);
        }

        return $fields;
    }

    public function adminServicesTabFieldsSave($params)
    {

        if ($_POST['modulefields'][0] == '') {
            Database::deleteSystemService($params['serviceid']);
        } else {

            Database::updateSystemServiceServerId($params['serviceid'], $_POST['modulefields'][0]);
        }
    }

    public function clientArea($params)
    {
        $serverHostname = null;
        if (array_key_exists('serverhostname', $params)) {
            $serverHostname = $params['serverhostname'];
        }

        try {
            return [
                'tabOverviewReplacementTemplate' => 'overview',
                'templateVariables' => [
                    'systemURL' => Database::getSystemUrl(),
                    'serviceStatus' => $params['status'],
                    'serverHostname' => $serverHostname,
                ],
            ];
        } catch (\Throwable $e) {

            Log::insert(__FUNCTION__, $params, $e->getMessage());

            return [
                'tabOverviewReplacementTemplate' => 'error',
                'templateVariables' => [],
            ];
        }
    }
}
