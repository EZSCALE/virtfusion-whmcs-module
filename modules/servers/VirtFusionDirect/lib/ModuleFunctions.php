<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

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
            if (ResourcePack::handles($params)) {
                return (new ResourcePack)->createAccount($params);
            }
        } catch (\Throwable $e) {
            Log::insert('PackDispatch', ['service' => $params['serviceid'] ?? 0], $e->getMessage());

            return 'Unable to resolve service mode.';
        }

        try {

            /**
             * A bound row means a server already exists for this service. A row with a
             * NULL server_id is a pending reservation from an earlier attempt and is
             * reconciled below instead of refused.
             */
            $row = Database::getSystemService((int) $params['serviceid']);
            if ($row && ! empty($row->server_id)) {
                // An operator bound the server by hand: the unresolved create is now resolved.
                $this->closeCreateOps((int) $params['serviceid'], 'completed', ['bound' => (int) $row->server_id]);

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

            Log::insert(__FUNCTION__, Log::params($params), []);

            /**
             * Does a user account in VirtFusion match this account (byExtRelationId) in WHMCS.
             */
            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/users/' . $this->extRelationRef($params['userid']) . '/byExtRelation' . $this->extRelationQuery());

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
                    ];

                    $userData += $this->userRelationFields($user->id);

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
            $vfUserId = (int) ($data->data->id ?? 0);
            if ($vfUserId <= 0) {
                return 'Unable to resolve the VirtFusion user for this client.';
            }

            $ctx = ['panelId' => (int) $cp['panel_id'], 'serviceId' => (int) $params['serviceid'], 'serverId' => 0];

            // Resolve an earlier ambiguous attempt before anything can POST /servers again.
            if ($row) {
                $reconciled = $this->reconcilePendingCreate($params, $cp, $ctx, $vfUserId);
                if ($reconciled !== null) {
                    return $reconciled;
                }
            }

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

            $configOptionCustomNaming = $this->configOptionNaming();

            $options = [
                'packageId' => (int) $params['configoption2'],
                'userId' => $vfUserId,
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

            $known = $this->listUserServerIds($cp, $vfUserId);
            if ($known === null) {
                return 'Unable to list existing VirtFusion servers; creation was not attempted.';
            }

            try {
                $op = Operation::begin($ctx, 'server:create', ['packageId' => (int) $params['configoption2'],
                    'vfUserId' => $vfUserId, 'knownServerIds' => $known], Access::lifecycleActor(), bin2hex(random_bytes(16)));
            } catch (\RuntimeException $e) {
                Log::insert(__FUNCTION__ . ':begin', ['serviceid' => $ctx['serviceId']], $e->getMessage());

                return 'Another operation is unresolved for this service; reconcile it before retrying.';
            }

            return $this->postServerCreate($params, $cp, $op->id, $options);
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Send the one POST /servers this reservation allows and classify its outcome.
     *
     * The pending row is recorded before the request. A 4xx releases it; any
     * ambiguous outcome keeps it so the next Create reconciles instead of
     * creating a second server.
     *
     * @param  array  $params  WHMCS service parameters
     * @param  array  $cp  Control panel connection from getCP()
     * @param  string  $opId  Operation reserved by Operation::begin()
     * @param  array  $options  POST /servers body
     * @return string 'success' or an error message
     */
    private function postServerCreate(array $params, array $cp, string $opId, array $options): string
    {
        $serviceId = (int) $params['serviceid'];
        $sent = false;
        $finished = false;

        try {
            try {
                Database::insertPendingSystemService($serviceId);
            } catch (\Throwable $e) {
                Log::insert('createAccount:pending', ['serviceid' => $serviceId], $e->getMessage());
                Operation::finish($opId, 'failed', ['reason' => 'pending row not recorded']);
                $finished = true;

                return 'Unable to record pending provisioning; creation was not attempted.';
            }

            $request = $this->initCurl($cp['token']);
            $request->addOption(CURLOPT_POSTFIELDS, json_encode($options));

            $sent = true;
            $raw = $request->post($cp['url'] . '/servers');
            $code = (int) $request->getRequestInfo('http_code');
            $data = json_decode((string) $raw);

            Log::insert('createAccount', $request->getRequestInfo(), $data);

            if ($code === 201) {
                $error = $this->bindCreatedServer($serviceId, $opId, $data, ['server' => (int) ($data->data->id ?? 0)]);
                $finished = true;

                return $error ?? $this->afterServerCreate($params, $cp, $data);
            }

            if (Operation::resultState($code, json_decode((string) $raw, true)) === 'failed') {
                Operation::finish($opId, 'failed', ['http' => $code]);
                $finished = true;
                Database::deleteSystemService($serviceId);

                if (isset($data->errors) && is_array($data->errors) && isset($data->errors[0])) {
                    return $data->errors[0];
                }
                if (isset($data->msg)) {
                    return $data->msg;
                }

                return 'Server creation failed. VirtFusion API returned HTTP ' . $code;
            }

            Operation::finish($opId, 'unknown', ['http' => $code]);
            $finished = true;

            return 'Server creation outcome unknown (HTTP ' . $code . '). A server may exist; run Create again to reconcile — it will not create a duplicate.';
        } catch (\Throwable $e) {
            Log::insert('createAccount', Log::params($params), $e->getMessage());
            if (! $finished) {
                try {
                    if ($sent) {
                        Operation::finish($opId, 'unknown', ['reason' => 'exception after request']);
                    } else {
                        Operation::finish($opId, 'failed', ['reason' => 'request not sent']);
                        Database::deleteSystemService($serviceId);
                    }
                } catch (\Throwable $inner) {
                    Log::insert('createAccount:finish', ['serviceid' => $serviceId], $inner->getMessage());
                }
            }

            return $sent && ! $finished
                ? 'Server creation outcome unknown. A server may exist; run Create again to reconcile — it will not create a duplicate.'
                : $e->getMessage();
        }
    }

    /**
     * Resolve a pending row left by an earlier create whose outcome was not known.
     *
     * Compares the VirtFusion user's current server list against the list captured
     * before the earlier POST. Exactly one new, unbound, owned server is adopted;
     * none means nothing was created and a fresh create may proceed; anything else
     * is refused for an operator to resolve.
     *
     * @param  array  $params  WHMCS service parameters
     * @param  array  $cp  Control panel connection from getCP()
     * @param  array  $ctx  Service-scope operation context
     * @param  int  $vfUserId  VirtFusion user that owns the service
     * @return string|null Final result, or null to continue with a fresh create
     */
    private function reconcilePendingCreate(array $params, array $cp, array $ctx, int $vfUserId): ?string
    {
        $op = $this->unresolvedCreateOp($ctx['serviceId'], $ctx['panelId']);
        if (! $op) {
            return null; // Legacy or stale pending row: reuse it for a fresh create.
        }
        $busy = 'Server creation is already in progress for this service; retry in a few minutes.';
        if ($op->state === 'saved' && strtotime((string) $op->created_at) > time() - 600) {
            return $busy;
        }
        // Claim the operation so concurrent retries cannot both adopt or both recreate.
        // A claim abandoned by a crashed request becomes claimable again after 600s.
        $claimed = DB::table(Operation::TABLE)->where('id', $op->id)->where(function ($q) {
            $q->whereIn('state', ['unknown', 'saved', 'queued'])
                ->orWhere(function ($q) {
                    $q->where('state', 'running')->where('updated_at', '<', date('Y-m-d H:i:s', time() - 600));
                });
        })->update(['state' => 'running', 'updated_at' => date('Y-m-d H:i:s')]);
        if ($claimed !== 1) {
            return $busy;
        }

        try {
            $result = $this->reconcileClaimed($params, $cp, $ctx, $vfUserId, $op);
        } catch (\Throwable $e) {
            $this->releaseCreateClaim($op->id);

            throw $e;
        }
        if ($result !== null && $result !== 'success') {
            $this->releaseCreateClaim($op->id);
        }

        return $result;
    }

    /**
     * Reconcile a claimed create operation; see reconcilePendingCreate().
     *
     * @param  object  $op  Claimed operation row
     * @return string|null Final result, or null to continue with a fresh create
     */
    private function reconcileClaimed(array $params, array $cp, array $ctx, int $vfUserId, $op): ?string
    {
        $targets = json_decode((string) $op->targets, true);
        if ((int) ($targets['vfUserId'] ?? 0) !== $vfUserId) {
            return 'Server creation is unresolved for a different VirtFusion user; bind the correct server ID in the admin Services tab, then retry.';
        }

        $known = $targets['knownServerIds'] ?? null;
        $now = $this->listUserServerIds($cp, $vfUserId);
        if (! is_array($known) || $now === null) {
            return 'Server creation is unresolved and the VirtFusion server list is unavailable; retry later.';
        }

        $bound = DB::table(Database::SYSTEM_TABLE)->whereNotNull('server_id')->pluck('server_id')
            ->map(static function ($id) {
                return (int) $id;
            })->all();
        $candidates = array_values(array_diff($now, array_map('intval', $known), $bound));

        if ($candidates === []) {
            Operation::finish($op->id, 'failed', ['reconciled' => 'no server created']);

            return null;
        }

        // Another unresolved create for the same client could own any new server.
        $others = DB::table(Operation::TABLE)->where('panel_id', $ctx['panelId'])->where('service_id', '!=', $ctx['serviceId'])
            ->where('vm_id', 0)->where('action', 'server:create')->whereIn('state', ['saved', 'queued', 'running', 'unknown'])
            ->pluck('service_id')->all();
        if ($others && DB::table('tblhosting')->whereIn('id', $others)->where('userid', (int) $params['userid'])->exists()) {
            return 'Server creation is unresolved for another service of this client too; bind the correct server ID in the admin Services tab, then retry.';
        }

        if (count($candidates) > 1) {
            return 'Server creation is unresolved: ' . count($candidates) . ' unbound servers exist for this VirtFusion user. Bind the correct server ID in the admin Services tab, then retry.';
        }

        $id = $candidates[0];
        $request = $this->initCurl($cp['token']);
        $raw = $request->get($cp['url'] . '/servers/' . $id);
        $code = (int) $request->getRequestInfo('http_code');
        $obj = json_decode((string) $raw);
        Log::insert('createAccount:reconcile', $request->getRequestInfo(), $obj);

        if ($code !== 200 || ! isset($obj->data) || ! is_object($obj->data)
            || ! ServiceTools::matchesId($obj->data->id ?? null, $id)
            || ! ServiceTools::matchesId($obj->data->owner->id ?? null, $vfUserId)) {
            return 'Server creation is unresolved: server #' . $id . ' could not be verified for this VirtFusion user. Bind the correct server ID in the admin Services tab, then retry.';
        }

        $error = $this->bindCreatedServer($ctx['serviceId'], $op->id, $obj, ['adopted' => $id]);

        return $error ?? $this->afterServerCreate($params, $cp, $obj);
    }

    /**
     * Return a claimed create operation to unknown, keeping its lock, after a refusal.
     */
    private function releaseCreateClaim(string $opId): void
    {
        try {
            DB::table(Operation::TABLE)->where('id', $opId)->where('state', 'running')
                ->update(['state' => 'unknown', 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            Log::insert('createAccount:release', ['operation' => $opId], $e->getMessage());
        }
    }

    /**
     * Close every unresolved server:create operation for a service.
     *
     * Used once the outcome is settled outside reconciliation: an operator bound
     * the server by hand, or the service was terminated. Releases the lock.
     */
    private function closeCreateOps(int $serviceId, string $state, array $result): void
    {
        try {
            Operation::schema();
            $ids = DB::table(Operation::TABLE)->where('service_id', $serviceId)->where('vm_id', 0)
                ->where('action', 'server:create')->whereIn('state', ['saved', 'queued', 'running', 'unknown'])->pluck('id')->all();
            foreach ($ids as $id) {
                Operation::finish((string) $id, $state, $result);
            }
        } catch (\Throwable $e) {
            Log::insert('createAccount:close', ['serviceid' => $serviceId], $e->getMessage());
        }
    }

    /**
     * Bind a created server to the pending row and close the create operation.
     *
     * If the binding cannot be confirmed the operation is left unknown, so the
     * next Create adopts the server instead of creating another.
     *
     * @param  object|null  $data  Server object ({data: {id, ...}})
     * @return string|null Error message, or null when bound
     */
    private function bindCreatedServer(int $serviceId, string $opId, $data, array $result): ?string
    {
        $id = (int) ($data->data->id ?? 0);
        if ($id > 0) {
            Database::systemOnServerCreate($serviceId, $data);
        }
        $row = Database::getSystemService($serviceId);
        if ($id <= 0 || ! $row || (int) $row->server_id !== $id) {
            Operation::finish($opId, 'unknown', $result + ['reason' => 'binding not recorded']);

            return 'Server creation outcome unknown: the server binding could not be recorded. Run Create again to reconcile — it will not create a duplicate.';
        }
        Operation::finish($opId, 'completed', $result);

        return null;
    }

    /**
     * Post-create steps shared by a fresh create and an adopted server.
     *
     * @param  array  $params  WHMCS service parameters
     * @param  array  $cp  Control panel connection from getCP()
     * @param  object  $data  Server object ({data: {id, owner, ...}})
     * @return string 'success'
     */
    private function afterServerCreate(array $params, array $cp, $data): string
    {
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
            if ($this->powerDnsEnabled()) {
                // syncServer with $oldHostname=null means "create mode" — see
                // PtrManager::syncServer() docblock for the semantics.
                $hostname = PowerDns\PtrManager::extractHostname($data);
                if ($hostname !== null) {
                    $this->ptrManager()->syncServer($data, null, $hostname);
                }
            }
        } catch (\Throwable $e) {
            Log::insert('PowerDns:createAccount', ['serviceid' => $params['serviceid']], $e->getMessage());
        }

        // If the server is created successfully, we can initialize the server build.
        $cs = $this->configureService((int) $cp['panel_id']);
        $vfUserId = isset($data->data->owner->id) ? (int) $data->data->owner->id : null;
        $cs->initServerBuild($data->data->id, $params, $vfUserId);

        return 'success';
    }

    /**
     * IDs of every server the VirtFusion user owns, or null when the list is unavailable.
     *
     * A partial list (more than one page) is treated as unavailable, because
     * reconciliation compares complete lists.
     *
     * @param  array  $cp  Control panel connection from getCP()
     * @return int[]|null
     */
    private function listUserServerIds(array $cp, int $vfUserId): ?array
    {
        try {
            $request = $this->initCurl($cp['token']);
            $raw = $request->get($cp['url'] . '/servers/user/' . $vfUserId . '?results=200');
            $code = (int) $request->getRequestInfo('http_code');
            Log::insert('createAccount:listServers', $request->getRequestInfo(), $code === 200 ? 'listed' : $raw);
            $body = json_decode((string) $raw, true);
            if ($code !== 200 || ! is_array($body) || ! is_array($body['data'] ?? null)
                || ! empty($body['next_page_url']) || (int) ($body['last_page'] ?? 1) > (int) ($body['current_page'] ?? 1)) {
                return null;
            }
            $ids = [];
            foreach ($body['data'] as $server) {
                // positiveId() throws on a malformed entry, which makes the whole list unavailable.
                $ids[] = ServiceTools::positiveId(is_array($server) ? ($server['id'] ?? null) : null);
            }

            return $ids;
        } catch (\Throwable $e) {
            Log::insert('createAccount:listServers', ['user' => $vfUserId], $e->getMessage());

            return null;
        }
    }

    /**
     * Newest unresolved server:create operation for a service, if any.
     *
     * @param  int|null  $panelId  Restrict to one panel; null matches any panel.
     * @return object|null
     */
    private function unresolvedCreateOp(int $serviceId, ?int $panelId = null)
    {
        Operation::schema();
        $query = DB::table(Operation::TABLE)->where('service_id', $serviceId)->where('vm_id', 0)
            ->where('action', 'server:create')->whereIn('state', ['saved', 'queued', 'running', 'unknown']);
        if ($panelId !== null) {
            $query->where('panel_id', $panelId);
        }

        return $query->orderByDesc('created_at')->first();
    }

    /**
     * Stop lifecycle actions on a row whose server is not yet bound.
     *
     * Terminate may clear a stale pending row when no create is unresolved;
     * every other case must be reconciled through Create first.
     *
     * @param  object|null  $service  Row from mod_virtfusion_direct
     * @return string|null Result to return, or null to proceed
     */
    private function pendingProvisioningGuard($service, int $serviceId, bool $terminate = false): ?string
    {
        if (! $service || ! empty($service->server_id)) {
            return null;
        }
        if ($terminate && ! $this->unresolvedCreateOp($serviceId)) {
            Database::deleteSystemService($serviceId);
            $this->closeCreateOps($serviceId, 'failed', ['reason' => 'terminated']);

            return 'success';
        }

        return 'Server provisioning is unresolved for this service; run Create to reconcile before this action.';
    }

    /**
     * Change the VirtFusion package assigned to a server and apply resource modifications.
     *
     * Reads the server's current resources and the requested target (package plus
     * configurable-option overrides) before touching anything; if either is unreadable the
     * change is refused. It then updates the package, individually adjusts memory, CPU and
     * bandwidth from configurable options, and re-reads the server. Any failed modification
     * or any requested reduction VirtFusion did not apply (it never shrinks disks) is logged
     * and returned as an error string, which WHMCS shows to the admin instead of 'success'.
     *
     * @param  array  $params  WHMCS service parameters
     * @return string 'success' or an error message
     */
    public function changePackage($params)
    {
        try {
            if (ResourcePack::handles($params)) {
                return (new ResourcePack)->lifecycle('change', $params);
            }
        } catch (\Throwable $e) {
            Log::insert('PackDispatch', ['service' => $params['serviceid'] ?? 0], $e->getMessage());

            return 'Unable to resolve service mode.';
        }

        try {
            $service = Database::getSystemService($params['serviceid']);
            $pending = $this->pendingProvisioningGuard($service, (int) $params['serviceid']);
            if ($pending !== null) {
                return $pending;
            }

            if ($service) {
                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                $before = $this->serverResources($cp, (int) $service->server_id);
                if ($before === null) {
                    return 'Unable to read current server resources; package change was not attempted.';
                }
                $requested = $this->requestedResources($params, $cp);
                if ($requested === null) {
                    return 'Package change could not be verified: VirtFusion package #' . (int) $params['configoption2'] . ' is unavailable.';
                }

                $request = $this->initCurl($cp['token']);
                $data = $request->put($cp['url'] . '/servers/' . (int) $service->server_id . '/package/' . (int) $params['configoption2']);
                $data = json_decode($data);

                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                switch ((int) $request->getRequestInfo('http_code')) {

                    case 200:
                        // VirtFusion v7 returns 200 (with an {info:[...]} body) on a package change;
                        // older builds returned 204. The info array reports what was/wasn't synced
                        // from the new package — e.g. "primary disk not updated. It either matches or
                        // is lower than the current value" (the panel never shrinks a disk). Log it so
                        // an operator can see when a requested change was skipped.
                        if (isset($data->info) && is_array($data->info)) {
                            Log::insert(__FUNCTION__ . ':info', [], $data->info);
                        }
                        break;
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
                $failed = [];
                if (isset($params['configoptions']) && is_array($params['configoptions'])) {
                    $configOptionDefaultNaming = [
                        'memory' => 'Memory',
                        'cpuCores' => 'CPU Cores',
                        'traffic' => 'Bandwidth',
                    ];

                    $configOptionCustomNaming = $this->configOptionNaming();

                    foreach ($configOptionDefaultNaming as $resource => $optionName) {
                        $currentOption = array_key_exists($resource, $configOptionCustomNaming) ? $configOptionCustomNaming[$resource] : $optionName;
                        if (isset($params['configoptions'][$currentOption]) && is_numeric($params['configoptions'][$currentOption])) {
                            $value = (int) $params['configoptions'][$currentOption];
                            if ($resource === 'memory' && $value < 1024) {
                                $value = $value * 1024;
                            }
                            if ($this->modifyResource($params['serviceid'], $resource, $value) === false) {
                                // Continue with the remaining resources, but report the failure.
                                Log::insert(__FUNCTION__ . ':modifyFailed', ['resource' => $resource, 'value' => $value], 'modifyResource returned false');
                                $failed[] = $resource;
                            }
                        }
                    }
                }

                // Verify every requested reduction actually landed.
                $after = $this->serverResources($cp, (int) $service->server_id);
                $unapplied = [];
                foreach ($requested as $resource => $target) {
                    if (! isset($before[$resource]) || $target >= $before[$resource]) {
                        continue;
                    }
                    if ($after === null || ! isset($after[$resource]) || $after[$resource] > $target) {
                        Log::insert(__FUNCTION__ . ':reductionNotApplied', [
                            'serviceid' => (int) $params['serviceid'],
                            'resource' => $resource,
                            'requested' => $target,
                            'before' => $before[$resource],
                            'after' => $after[$resource] ?? null,
                        ], 'Requested reduction not applied');
                        $unapplied[] = $resource === 'storage'
                            ? 'storage (VirtFusion cannot shrink disks; adjust manually or keep the larger plan)'
                            : $resource;
                    }
                }

                if ($failed || $unapplied) {
                    $parts = [];
                    if ($failed) {
                        $parts[] = 'failed to apply ' . implode(', ', $failed);
                    }
                    if ($unapplied) {
                        $parts[] = 'reduction not applied for ' . implode(', ', $unapplied);
                    }

                    return 'Package change incomplete: ' . implode('; ', $parts);
                }

                return 'success';
            }

            return 'Service not found in module database.';
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());

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
            if (ResourcePack::handles($params)) {
                return (new ResourcePack)->lifecycle('terminate', $params);
            }
        } catch (\Throwable $e) {
            Log::insert('PackDispatch', ['service' => $params['serviceid'] ?? 0], $e->getMessage());

            return 'Unable to resolve service mode.';
        }

        try {
            $service = Database::getSystemService($params['serviceid']);
            $pending = $this->pendingProvisioningGuard($service, (int) $params['serviceid'], true);
            if ($pending !== null) {
                return $pending;
            }

            if ($service) {

                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                try {
                    $check = $this->lifecycleVmCheck($cp, (int) $service->server_id, (int) $whmcsService->userid);
                } catch (\RuntimeException $e) {
                    Log::insert(__FUNCTION__ . ':ownership', ['serviceid' => $params['serviceid'], 'server' => (int) $service->server_id], $e->getMessage());

                    return $e->getMessage() . ' Refusing to terminate.';
                }
                $fresh = $check['state'] === 'owned' ? $check['server'] : null;

                $request = $this->initCurl($cp['token']);
                $data = $request->delete($cp['url'] . '/servers/' . (int) $service->server_id);
                $data = json_decode($data);

                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                switch ($request->getRequestInfo('http_code')) {

                    case 204:
                        $this->cleanupPowerDnsForService($service, $fresh);
                        Database::deleteSystemService($params['serviceid']);
                        $this->updateWhmcsServiceParamsOnDestroy($params['serviceid']);
                        $this->closeCreateOps((int) $params['serviceid'], 'failed', ['reason' => 'terminated']);
                        $this->revokeSelfServiceIfLast($cp, $whmcsService);

                        return 'success';

                    case 404:
                        if (isset($data->msg)) {
                            if ($data->msg == 'server not found') {
                                $this->cleanupPowerDnsForService($service, $fresh);
                                Database::deleteSystemService($params['serviceid']);
                                $this->closeCreateOps((int) $params['serviceid'], 'failed', ['reason' => 'terminated']);
                                $this->revokeSelfServiceIfLast($cp, $whmcsService);

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
            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Turn off hourly self-service for a client whose last VirtFusion service on this panel was terminated.
     *
     * Self-service is a per-user flag on the panel, so it stays on while the client still has
     * another Active VirtFusionDirect service on the same WHMCS server. Best effort: a failure is
     * logged and never changes the termination result.
     *
     * @param  array  $cp  Control-server connection from getCP()
     * @param  object  $whmcsService  The tblhosting row being terminated
     */
    protected function revokeSelfServiceIfLast(array $cp, object $whmcsService): void
    {
        try {
            $clientId = (int) $whmcsService->userid;
            $others = DB::table('tblhosting as h')
                ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
                ->where('h.userid', $clientId)
                ->where('h.server', (int) $whmcsService->server)
                ->where('h.id', '!=', (int) $whmcsService->id)
                ->where('p.servertype', 'VirtFusionDirect')
                ->where('h.domainstatus', 'Active')
                ->exists();
            if ($others) {
                return;
            }

            $request = $this->initCurl($cp['token']);
            $request->addOption(CURLOPT_POSTFIELDS, json_encode(['selfService' => 0, 'selfServiceHourlyCredit' => false]));
            $data = $request->put($cp['url'] . '/users/' . $this->extRelationRef($clientId) . '/byExtRelation' . $this->extRelationQuery());
            $code = (int) $request->getRequestInfo('http_code');

            if (in_array($code, [200, 201, 204], true)) {
                Log::insert('SelfServiceRevoke', ['client' => $clientId], 'revoked');
            } else {
                Log::insert('SelfServiceRevoke:failed', ['client' => $clientId, 'http_code' => $code], $data);
            }
        } catch (\Throwable $e) {
            Log::insert('SelfServiceRevoke:failed', ['client' => (int) ($whmcsService->userid ?? 0)], $e->getMessage());
        }
    }

    /**
     * Delete any PTR records owned by this service before the local record is erased.
     *
     * The IP list comes from the fresh server object fetched by lifecycleVmCheck()
     * just before termination. The stored server_object is used only when no fresh
     * data exists, which happens only when VirtFusion already reports the VM missing;
     * it is then the last record of the IPs, since the module row is about to be deleted.
     * Non-fatal — DNS failures never block termination.
     *
     * @param  object|null  $service  Row from mod_virtfusion_direct (has server_object JSON)
     * @param  array|null  $fresh  Fresh GET /servers/{id} response, or null when the VM is missing
     */
    protected function cleanupPowerDnsForService($service, ?array $fresh = null): void
    {
        try {
            if (! $this->powerDnsEnabled()) {
                return;
            }
            if ($fresh !== null) {
                $this->ptrManager()->deleteForServer($fresh);

                return;
            }
            Log::insert('PowerDns:terminate', ['service' => $service->service_id ?? null], 'fresh server data unavailable; using stored server_object');
            if (! $service || empty($service->server_object)) {
                return;
            }
            $decoded = json_decode($service->server_object, true);
            if (! is_array($decoded)) {
                return;
            }
            $this->ptrManager()->deleteForServer($decoded);
        } catch (\Throwable $e) {
            Log::insert('PowerDns:terminate', ['service' => $service->service_id ?? null], $e->getMessage());
        }
    }

    /** Server-build seam; tests substitute a stub. */
    protected function configureService(int $panelId): ConfigureService
    {
        return new ConfigureService($panelId);
    }

    /**
     * Current VirtFusion resource allocation for a server, or null when unreadable.
     *
     * @return array<string,int>|null Keys among memory, cpuCores, traffic, storage
     */
    private function serverResources(array $cp, int $serverId): ?array
    {
        try {
            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/servers/' . $serverId);
            if ((int) $request->getRequestInfo('http_code') !== 200) {
                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);

                return null;
            }
            $resources = json_decode((string) $data, true)['data']['settings']['resources'] ?? null;
            if (! is_array($resources)) {
                return null;
            }
            $out = [];
            foreach (['memory', 'cpuCores', 'traffic', 'storage'] as $key) {
                if (isset($resources[$key]) && is_numeric($resources[$key])) {
                    $out[$key] = (int) $resources[$key];
                }
            }

            return $out ?: null;
        } catch (\Throwable $e) {
            Log::insert(__FUNCTION__, ['serverId' => $serverId], $e->getMessage());

            return null;
        }
    }

    /**
     * Target resources for a package change: the package, overlaid by configurable options.
     *
     * @return array<string,int>|null Null when the package is unreadable and no option supplied a value
     */
    private function requestedResources(array $params, array $cp): ?array
    {
        $requested = [];

        try {
            $request = $this->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/packages/' . (int) ($params['configoption2'] ?? 0));
            if ((int) $request->getRequestInfo('http_code') === 200) {
                $decoded = json_decode((string) $data, true);
                $package = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;
                if (is_array($package)) {
                    foreach (['memory' => 'memory', 'cpuCores' => 'cpuCores', 'primaryStorage' => 'storage', 'traffic' => 'traffic'] as $field => $resource) {
                        if (isset($package[$field]) && is_numeric($package[$field])) {
                            $requested[$resource] = (int) $package[$field];
                        }
                    }
                }
            } else {
                Log::insert(__FUNCTION__, $request->getRequestInfo(), $data);
            }
        } catch (\Throwable $e) {
            Log::insert(__FUNCTION__, ['package' => (int) ($params['configoption2'] ?? 0)], $e->getMessage());
        }
        $packageRead = $requested !== [];

        $options = isset($params['configoptions']) && is_array($params['configoptions']) ? $params['configoptions'] : [];
        $custom = $this->configOptionNaming();
        $fromOptions = false;
        foreach (['memory' => 'Memory', 'cpuCores' => 'CPU Cores', 'traffic' => 'Bandwidth', 'storage' => 'Storage'] as $resource => $default) {
            $name = $custom[$resource] ?? $default;
            if (isset($options[$name]) && is_numeric($options[$name])) {
                $value = (int) $options[$name];
                if ($resource === 'memory' && $value < 1024) {
                    $value = $value * 1024;
                }
                $requested[$resource] = $value;
                $fromOptions = true;
            }
        }

        return ($packageRead || $fromOptions) ? $requested : null;
    }

    /**
     * Operator's custom configurable-option names, or [] when no mapping file exists.
     *
     * Falls back to the repository root when WHMCS's ROOTDIR is undefined (tests).
     */
    protected function configOptionNaming(): array
    {
        $file = (defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 4)) . '/modules/servers/VirtFusionDirect/config/ConfigOptionMapping.php';
        if (! file_exists($file)) {
            return [];
        }
        $mapping = require $file;

        return is_array($mapping) ? $mapping : [];
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
            if (ResourcePack::handles($params)) {
                return (new ResourcePack)->lifecycle('suspend', $params);
            }
        } catch (\Throwable $e) {
            Log::insert('PackDispatch', ['service' => $params['serviceid'] ?? 0], $e->getMessage());

            return 'Unable to resolve service mode.';
        }

        try {
            $service = Database::getSystemService($params['serviceid']);
            $pending = $this->pendingProvisioningGuard($service, (int) $params['serviceid']);
            if ($pending !== null) {
                return $pending;
            }

            if ($service) {

                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                try {
                    $this->lifecycleVmCheck($cp, (int) $service->server_id, (int) $whmcsService->userid);
                } catch (\RuntimeException $e) {
                    Log::insert(__FUNCTION__ . ':ownership', ['serviceid' => $params['serviceid'], 'server' => (int) $service->server_id], $e->getMessage());

                    return $e->getMessage() . ' Refusing to suspend.';
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
            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());

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
            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());

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
            if (ResourcePack::handles($params)) {
                return (new ResourcePack)->lifecycle('unsuspend', $params);
            }
        } catch (\Throwable $e) {
            Log::insert('PackDispatch', ['service' => $params['serviceid'] ?? 0], $e->getMessage());

            return 'Unable to resolve service mode.';
        }

        try {
            $service = Database::getSystemService($params['serviceid']);
            $pending = $this->pendingProvisioningGuard($service, (int) $params['serviceid']);
            if ($pending !== null) {
                return $pending;
            }

            if ($service) {
                $whmcsService = Database::getWhmcsService($params['serviceid']);
                if (! $whmcsService) {
                    return 'WHMCS service record not found.';
                }

                $cp = $this->getCP($whmcsService->server);
                if (! $cp) {
                    return 'No control server found.';
                }

                try {
                    $this->lifecycleVmCheck($cp, (int) $service->server_id, (int) $whmcsService->userid);
                } catch (\RuntimeException $e) {
                    Log::insert(__FUNCTION__ . ':ownership', ['serviceid' => $params['serviceid'], 'server' => (int) $service->server_id], $e->getMessage());

                    return $e->getMessage() . ' Refusing to unsuspend.';
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
            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());

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
            Access::admin('View Clients Products/Services');
            $systemUrl = Database::getSystemUrl();
            if (ResourcePack::handles($params)) {
                return ['Resource Pack' => htmlspecialchars(json_encode(Log::redact((array) ResourcePack::binding((int) $params['serviceid']))), ENT_QUOTES, 'UTF-8'),
                    'Unified Tools' => AdminHTML::toolsSection($systemUrl, $params['serviceid'], $params['serverid'] ?? 0, true)];
            }
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
                'Unified Tools' => AdminHTML::toolsSection($systemUrl, $params['serviceid'], $params['serverid'] ?? 0),
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
            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());

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
            Access::admin('Perform Module Command Operations');
            Access::csrf();
            if (ResourcePack::handles($params)) {
                return;
            }
            if (! isset($_POST['modulefields'][0]) || $_POST['modulefields'][0] === '') {
                return; // A blank form must never erase a binding.
            } else {
                $serverId = ServiceTools::positiveId($_POST['modulefields'][0]);
                $this->verifyVmOwner($this->getCP((int) $params['serverid']), $serverId, (int) $params['userid']);
                if ($serverId > 0) {
                    Database::updateSystemServiceServerId($params['serviceid'], $serverId);
                }
            }
        } catch (\Exception $e) {
            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());
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
                $data = $request->get($cp['url'] . '/users/' . $this->extRelationRef($params['userid']) . '/byExtRelation' . $this->extRelationQuery());
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
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');

        try {
            if (ResourcePack::handles($params)) {
                return (new ResourcePack)->clientArea($params);
            }
        } catch (\Throwable $e) {
            Log::insert('PackDispatch', ['service' => $params['serviceid'] ?? 0], $e->getMessage());

            return ['tabOverviewReplacementTemplate' => 'error', 'templateVariables' => []];
        }

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
                    'selfServiceMode' => (int) ($params['configoption4'] ?? 0),
                    'rdnsEnabled' => PowerDns\Config::isEnabled(),
                ],
            ];
        } catch (\Throwable $e) {

            Log::insert(__FUNCTION__, Log::params($params), $e->getMessage());

            return [
                'tabOverviewReplacementTemplate' => 'error',
                'templateVariables' => [],
            ];
        }
    }
}
