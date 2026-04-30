<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Extends Module to handle the WHMCS service lifecycle for VirtFusion servers.
 *
 * WHY A SEPARATE CLASS FROM MODULE
 * --------------------------------
 * The WHMCS module interface (VirtFusionDirect.php) expects top-level functions
 * like VirtFusionDirect_CreateAccount(). Those functions delegate into methods
 * on this class so:
 *   1. The top-level functions stay one-liners that are easy to audit.
 *   2. All lifecycle logic lives in an object we can instantiate and unit-exercise
 *      without going through WHMCS's dispatch machinery.
 *   3. The shared behaviour with Module (API calls, auth, validation) comes for
 *      free via inheritance — no copy-pasted curl setup or error handling.
 *
 * ERROR MESSAGE CONVENTION
 * ------------------------
 * Every public method either returns the literal string 'success' or an error
 * string that WHMCS will render to the admin in the service activity log. Do NOT
 * return arrays, objects, or booleans — WHMCS treats anything other than
 * 'success' as an error and displays it verbatim.
 *
 * EXCEPTION HANDLING
 * ------------------
 * Every public method is wrapped in try/catch. Uncaught exceptions bubbling up
 * to WHMCS appear as stack traces in the admin UI and leak implementation detail,
 * so we catch and convert to a human error string. Log::insert() captures the
 * original exception message for diagnostics in the module log.
 *
 * PowerDNS INTEGRATION
 * --------------------
 * createAccount(), terminateAccount(), and (via parent Module) renameServer()
 * call into PowerDns\PtrManager to sync rDNS. Those calls are wrapped in their
 * OWN try/catch so DNS failures never bubble up to WHMCS — provisioning must
 * succeed even if PowerDNS is temporarily unreachable. See cleanupPowerDnsForService()
 * for the termination-time cleanup helper.
 */
class ModuleFunctions extends Module
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Provision a new VirtFusion server for a WHMCS service.
     *
     * Ensures a matching VirtFusion user exists (creating one if needed), then creates
     * the server and triggers the OS build via ConfigureService::initServerBuild().
     *
     * @param  array  $params  WHMCS service parameters
     * @return string 'success' or an error message
     */
    public function createAccount($params)
    {
        try {

            /**
             * If the service exists in the custom table, cancel the create account action.
             */
            if (Database::checkSystemService($params['serviceid'])) {
                return 'Service already exists. You must run a termination first.';
            }

            /**
             * If no VirtFusionDirect control server exists, cancel the create account action.
             */
            $server = $params['serverid'] ?: false;
            $cp = $this->getCP($server, ! $server);

            if (! $cp) {
                return 'No Control server found. Please ensure a VirtFusion server is configured in WHMCS.';
            }

            Log::insert(__FUNCTION__, $params, []);

            /**
             * Does a user account in VirtFusion match this account (byExtRelationId) in WHMCS.
             */
            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/users/' . (int) $params['userid'] . '/byExtRelation');

            Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

            switch ($request->getRequestInfo('http_code')) {
                case 200:
                    /**
                     * A user with relation ID exists in VirtFusion. We can provision under that account.
                     */
                    break;

                case 404:
                    /**
                     * A user doesn't exist in VirtFusion. We should attempt to create one.
                     */
                    $user = Database::getUser($params['userid']);

                    if (! $user) {
                        return 'WHMCS user not found for ID ' . (int) $params['userid'];
                    }

                    $request = $this->initCurl($cp['token']);

                    $userData = [
                        'name' => $user->firstname . ' ' . $user->lastname,
                        'email' => $user->email,
                        'extRelationId' => $user->id,
                    ];

                    // Enable self-service billing if configured
                    $selfServiceMode = (int) ($params['configoption4'] ?? 0);
                    if ($selfServiceMode > 0) {
                        $userData['selfService'] = $selfServiceMode;
                        $userData['selfServiceHourlyCredit'] = in_array($selfServiceMode, [1, 3]);
                    }

                    $request->addOption(CURLOPT_POSTFIELDS, json_encode($userData));

                    $data = $request->post($cp['url'] . '/users');

                    Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                    if ($request->getRequestInfo('http_code') !== 201) {
                        return 'Unable to create user in VirtFusion. API returned HTTP ' . $request->getRequestInfo('http_code');
                    }
                    break;
                default:
                    return 'Error processing user account. VirtFusion API returned HTTP ' . $request->getRequestInfo('http_code');
            }

            $data = json_decode($data);

            /**
             * A user is available. We can now attempt to create a server.
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
                $configOptionCustomNaming = require ROOTDIR . '/modules/servers/VirtFusionDirect/config/ConfigOptionMapping.php';
            }

            $options = [
                'packageId' => (int) $params['configoption2'],
                'userId' => $data->data->id,
                'hypervisorId' => (int) $params['configoption1'],
                'ipv4' => (int) $params['configoption3'],
            ];

            if (array_key_exists('configoptions', $params)) {
                foreach ($configOptionDefaultNaming as $key => $option) {
                    $currentOption = array_key_exists($key, $configOptionCustomNaming) ? $configOptionCustomNaming[$key] : $option;
                    if (array_key_exists($currentOption, $params['configoptions'])) {
                        $value = $params['configoptions'][$currentOption];
                        // If the option key is "Memory" and the value is less than 1024, convert to MB
                        // VirtFusion expects memory in MB.
                        if ($key === 'memory' && is_numeric($value) && $value < 1024) {
                            $options[$key] = (int) ($value * 1024);
                        } else {
                            $options[$key] = is_numeric($value) ? (int) $value : $value;
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

                // Initialize reverse DNS for the newly-assigned IPs.
                //
                // Ordering: after Database::systemOnServerCreate() AND
                // updateWhmcsServiceParamsOnServerObject() so mod_virtfusion_direct
                // has the stored server_object (admin reconcile later reads it) and
                // tblhosting has the primary IP (for cross-check on client edits).
                //
                // But BEFORE ConfigureService::initServerBuild() so rDNS is in place
                // when the VPS first boots — mail servers and other services that
                // check FCrDNS during early-boot see correct PTRs.
                //
                // Non-blocking: rDNS failures are logged but never fail provisioning.
                // A broken PowerDNS or missing zone must not prevent a customer
                // from getting the VPS they paid for.
                try {
                    if (PowerDns\Config::isEnabled()) {
                        // syncServer with $oldHostname=null means "create mode" — see
                        // PtrManager::syncServer() docblock for the semantics.
                        $hostname = PowerDns\PtrManager::extractHostname($data);
                        if ($hostname !== null) {
                            (new PowerDns\PtrManager)->syncServer($data, null, $hostname);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::insert('PowerDns:createAccount', ['serviceid' => $params['serviceid']], $e->getMessage());
                }

                // If the server is created successfully, we can initialize the server build.
                $cs = new ConfigureService;
                $vfUserId = isset($data->data->owner->id) ? (int) $data->data->owner->id : null;
                $cs->initServerBuild($data->data->id, $params, $vfUserId);

                return 'success';
            } else {
                if (isset($data->errors) && is_array($data->errors) && isset($data->errors[0])) {
                    return $data->errors[0];
                }
                if (isset($data->msg)) {
                    return $data->msg;
                }

                return 'Server creation failed. VirtFusion API returned HTTP ' . $request->getRequestInfo('http_code');
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Change the VirtFusion package assigned to a server and apply resource modifications.
     *
     * Updates the package via the API, then individually adjusts memory, CPU, and bandwidth
     * if those configurable options are present.
     *
     * @param  array  $params  WHMCS service parameters
     * @return string 'success' or an error message
     */
    public function changePackage($params)
    {
        try {
            $service = Database::getSystemService($params['serviceid']);

            if ($service) {
                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                $request = $this->initCurl($cp['token']);
                $data = $request->put($cp['url'] . '/servers/' . (int) $service->server_id . '/package/' . (int) $params['configoption2']);
                $data = json_decode($data);

                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                switch ($request->getRequestInfo('http_code')) {

                    case 204:
                        break;
                    case 404:
                        return 'The server or package was not found in VirtFusion (HTTP 404).';
                    case 423:
                        if (isset($data->msg)) {
                            return $data->msg;
                        }

                        return 'The server is currently locked. Please try again later.';
                    default:
                        return 'Update package request failed. VirtFusion API returned HTTP ' . $request->getRequestInfo('http_code');
                }

                // Apply individual resource modifications from configurable options
                if (isset($params['configoptions']) && is_array($params['configoptions'])) {
                    $configOptionDefaultNaming = [
                        'memory' => 'Memory',
                        'cpuCores' => 'CPU Cores',
                        'traffic' => 'Bandwidth',
                    ];

                    $configOptionCustomNaming = [];
                    if (file_exists(ROOTDIR . '/modules/servers/VirtFusionDirect/config/ConfigOptionMapping.php')) {
                        $configOptionCustomNaming = require ROOTDIR . '/modules/servers/VirtFusionDirect/config/ConfigOptionMapping.php';
                    }

                    foreach ($configOptionDefaultNaming as $resource => $optionName) {
                        $currentOption = array_key_exists($resource, $configOptionCustomNaming) ? $configOptionCustomNaming[$resource] : $optionName;
                        if (isset($params['configoptions'][$currentOption]) && is_numeric($params['configoptions'][$currentOption])) {
                            $value = (int) $params['configoptions'][$currentOption];
                            if ($resource === 'memory' && $value < 1024) {
                                $value = $value * 1024;
                            }
                            $this->modifyResource($params['serviceid'], $resource, $value);
                        }
                    }
                }

                return 'success';
            }

            return 'Service not found in module database.';
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Delete a VirtFusion server, applying the default 5-minute grace period before destruction.
     *
     * On success, removes the service record from the module database and clears WHMCS service fields.
     * If VirtFusion reports the server is already gone (404 + "server not found"), treats it as success.
     *
     * @param  array  $params  WHMCS service parameters
     * @return string 'success' or an error message
     */
    public function terminateAccount($params)
    {
        try {
            $service = Database::getSystemService($params['serviceid']);

            if ($service) {

                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                $request = $this->initCurl($cp['token']);
                $data = $request->delete($cp['url'] . '/servers/' . (int) $service->server_id);
                $data = json_decode($data);

                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                switch ($request->getRequestInfo('http_code')) {

                    case 204:
                        $this->cleanupPowerDnsForService($service);
                        Database::deleteSystemService($params['serviceid']);
                        $this->updateWhmcsServiceParamsOnDestroy($params['serviceid']);

                        return 'success';

                    case 404:
                        if (isset($data->msg)) {
                            if ($data->msg == 'server not found') {
                                $this->cleanupPowerDnsForService($service);
                                Database::deleteSystemService($params['serviceid']);

                                return 'success';
                            } else {
                                return 'VirtFusion returned 404: ' . $data->msg;
                            }
                        } else {
                            return 'VirtFusion returned 404 without details. The API may be unavailable.';
                        }

                    default:
                        return 'Termination request failed. VirtFusion API returned HTTP ' . $request->getRequestInfo('http_code');
                }
            }

            return 'Service not found in module database. Has termination already been run?';
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Delete any PTR records owned by this service before the local record is erased.
     * The stored server_object is the last source of the IP list; once deleted from
     * the module table we'd have no way to find them again. Non-fatal — DNS failures
     * never block termination.
     *
     * @param  object|null  $service  Row from mod_virtfusion_direct (has server_object JSON)
     */
    protected function cleanupPowerDnsForService($service): void
    {
        try {
            if (! PowerDns\Config::isEnabled()) {
                return;
            }
            if (! $service || empty($service->server_object)) {
                return;
            }
            $decoded = json_decode($service->server_object, true);
            if (! is_array($decoded)) {
                return;
            }
            (new PowerDns\PtrManager)->deleteForServer($decoded);
        } catch (\Throwable $e) {
            Log::insert('PowerDns:terminate', ['service' => $service->service_id ?? null], $e->getMessage());
        }
    }

    /**
     * Suspend a VirtFusion server, queuing the action if another operation is in progress.
     *
     * Returns 'success' whether the server is suspended immediately or queued for suspension.
     *
     * @param  array  $params  WHMCS service parameters
     * @return string 'success' or an error message
     */
    public function suspendAccount($params)
    {
        try {
            $service = Database::getSystemService($params['serviceid']);

            if ($service) {

                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                $request = $this->initCurl($cp['token']);
                $data = $request->post($cp['url'] . '/servers/' . (int) $service->server_id . '/suspend');
                $data = json_decode($data);

                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                switch ($request->getRequestInfo('http_code')) {

                    case 204:
                        return 'success';

                    case 404:
                        if (isset($data->msg)) {
                            if ($data->msg == 'server not found') {
                                Database::deleteSystemService($params['serviceid']);

                                return 'success';
                            } else {
                                return 'VirtFusion returned 404: ' . $data->msg;
                            }
                        } else {
                            return 'VirtFusion returned 404 without details. The API may be unavailable.';
                        }
                    case 423:
                        if (isset($data->msg)) {
                            return $data->msg;
                        }

                        return 'The server is currently locked. Please try again later.';

                    default:
                        return 'Suspend request failed. VirtFusion API returned HTTP ' . $request->getRequestInfo('http_code');
                }
            }

            return 'Service not found in module database.';
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Refresh the cached server object by fetching fresh data from the VirtFusion API.
     *
     * Updates both the module database record and the WHMCS service fields (IP, username, etc.).
     *
     * @param  array  $params  WHMCS service parameters
     * @return string 'success' or an error message
     */
    public function updateServerObject($params)
    {
        try {
            $service = Database::getSystemService($params['serviceid']);

            if ($service) {

                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                $request = $this->initCurl($cp['token']);
                $data = $request->get($cp['url'] . '/servers/' . (int) $service->server_id);
                $data = json_decode($data);

                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                switch ($request->getRequestInfo('http_code')) {

                    case 200:
                        Database::updateSystemServiceServerObject($params['serviceid'], $data);

                        $this->updateWhmcsServiceParamsOnServerObject($params['serviceid'], $data);

                        return 'success';
                    default:
                        return 'Request failed. VirtFusion API returned HTTP ' . $request->getRequestInfo('http_code');
                }
            }

            return 'Service not found in module database.';
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Unsuspend a VirtFusion server, queuing the action if another operation is in progress.
     *
     * Returns 'success' whether the server is unsuspended immediately or queued for unsuspension.
     *
     * @param  array  $params  WHMCS service parameters
     * @return string 'success' or an error message
     */
    public function unsuspendAccount($params)
    {
        try {
            $service = Database::getSystemService($params['serviceid']);

            if ($service) {
                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                $request = $this->initCurl($cp['token']);
                $data = $request->post($cp['url'] . '/servers/' . (int) $service->server_id . '/unsuspend');
                $data = json_decode($data);

                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                switch ($request->getRequestInfo('http_code')) {

                    case 204:
                        return 'success';

                    case 404:
                        if (isset($data->msg)) {
                            if ($data->msg == 'server not found') {
                                Database::deleteSystemService($params['serviceid']);

                                return 'success';
                            } else {
                                return 'VirtFusion returned 404: ' . $data->msg;
                            }
                        } else {
                            return 'VirtFusion returned 404 without details. The API may be unavailable.';
                        }
                    case 423:
                        if (isset($data->msg)) {
                            return $data->msg;
                        }

                        return 'The server is currently locked. Please try again later.';

                    default:
                        return 'Unsuspend request failed. VirtFusion API returned HTTP ' . $request->getRequestInfo('http_code');
                }
            }

            return 'Service not found in module database.';
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Generate the admin Services tab custom fields for a VirtFusion service.
     *
     * Returns fields for Server ID (editable), Server Info, Server Object (JSON viewer),
     * and Options (action buttons), omitting Options for terminated services.
     *
     * @param  array  $params  WHMCS service parameters
     * @return array Associative array of field label => HTML content
     */
    public function adminServicesTabFields($params)
    {
        try {
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
                if (PowerDns\Config::isEnabled()) {
                    $fields['Reverse DNS'] = AdminHTML::rdnsSection($systemUrl, $params['serviceid']);
                }
            }

            return $fields;
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());

            return [];
        }
    }

    /**
     * Save the admin Services tab custom fields for a VirtFusion service.
     *
     * Deletes the module database record if the Server ID field is cleared,
     * or updates it with the new integer server ID if a value is provided.
     *
     * @param  array  $params  WHMCS service parameters
     * @return void
     */
    public function adminServicesTabFieldsSave($params)
    {
        try {
            if (! isset($_POST['modulefields'][0]) || $_POST['modulefields'][0] === '') {
                Database::deleteSystemService($params['serviceid']);
            } else {
                $serverId = (int) $_POST['modulefields'][0];
                if ($serverId > 0) {
                    Database::updateSystemServiceServerId($params['serviceid'], $serverId);
                }
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, $params, $e->getMessage());
        }
    }

    /**
     * Perform a dry-run server creation to validate the current product configuration.
     *
     * Used by the WHMCS "Test Connection" button to confirm that the package, hypervisor,
     * and IP settings are accepted by the VirtFusion API without creating a server.
     *
     * @param  array  $params  WHMCS service parameters
     * @return string 'success' or an error message
     */
    public function validateServerConfig($params)
    {
        try {
            $server = $params['serverid'] ?: false;
            $cp = $this->getCP($server, ! $server);

            if (! $cp) {
                return 'No Control server found.';
            }

            $options = [
                'packageId' => (int) $params['configoption2'],
                'hypervisorId' => (int) $params['configoption1'],
                'ipv4' => (int) $params['configoption3'],
            ];

            // We need a userId for dry run - use the service owner
            if (isset($params['userid'])) {
                $request = $this->initCurl($cp['token']);
                $data = $request->get($cp['url'] . '/users/' . (int) $params['userid'] . '/byExtRelation');
                if ($request->getRequestInfo('http_code') == 200) {
                    $userData = json_decode($data);
                    $options['userId'] = $userData->data->id;
                }
            }

            $result = $this->validateServerCreation($options, $params['serverid']);

            if ($result['valid']) {
                return 'success';
            }

            return 'Validation failed: ' . implode(', ', $result['errors']);
        } catch (\Exception $e) {
            return 'Validation error: ' . $e->getMessage();
        }
    }

    /**
     * Render the client area overview tab for a VirtFusion service.
     *
     * Returns the template name and variables (system URL, service status, hostname,
     * self-service mode) needed by the Smarty overview template. Falls back to an
     * error template on any exception.
     *
     * @param  array  $params  WHMCS service parameters
     * @return array Template name and variables for WHMCS to render
     */
    public function clientArea($params)
    {
        $serverHostname = null;
        if (array_key_exists('serverhostname', $params)) {
            $serverHostname = $params['serverhostname'];
        }

        try {
            $nextDueDays = '';
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $params['serviceid'])->first();
            if ($service && !empty($service->nextduedate) && $service->nextduedate !== '0000-00-00') {
                $due = new \DateTime($service->nextduedate);
                $due->setTime(0, 0, 0);
                $now = new \DateTime();
                $now->setTime(0, 0, 0);
                $diff = $now->diff($due);
                
                if ($diff->invert && $diff->days > 0) {
                    $nextDueDays = " <span class=\"text-danger\">({$diff->days} days overdue)</span>";
                } elseif ($diff->days == 0) {
                    $nextDueDays = " <span class=\"text-warning\">(due today)</span>";
                } else {
                    $nextDueDays = " <span class=\"text-muted\">({$diff->days} days remains)</span>";
                }
            }

            return [
                'tabOverviewReplacementTemplate' => 'overview',
                'templateVariables' => [
                    'systemURL' => Database::getSystemUrl(),
                    'serviceStatus' => $params['status'],
                    'serverHostname' => $serverHostname,
                    'selfServiceMode' => (int) ($params['configoption4'] ?? 0),
                    'rdnsEnabled' => PowerDns\Config::isEnabled(),
                    'nextduedateremaining' => $nextDueDays,
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
