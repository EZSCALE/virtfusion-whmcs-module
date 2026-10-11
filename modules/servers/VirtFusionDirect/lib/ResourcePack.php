<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Provisioning and lifecycle for resource packs within VirtFusionDirect.
 *
 * Assigned pack IDs are distinct from profile IDs and VM bindings. Pending
 * bindings and operation checkpoints prevent duplicate creation. Default
 * termination disables allocation and retains/suspends servers; unresolved
 * dependencies remain visible for recovery instead of deleting audit data.
 */
class ResourcePack extends Module
{
    const TABLE = 'mod_virtfusion_direct_packs';

    private ?string $operationId = null;

    /** Whether a native mutation may have reached VirtFusion in this lifecycle run. */
    private bool $sentWrite = false;

    /**
     * Detect explicit pack service mode without changing legacy blank Server mode.
     *
     * @param  array  $params  WHMCS module parameters.
     * @return bool
     */
    public static function isProduct($params)
    {
        return ($params['configoption9'] ?? '') === 'Resource Pack';
    }

    /**
     * Read a retained assigned-pack binding without creating or adopting one.
     *
     * @param  int|string  $serviceId  WHMCS hosting service ID.
     * @return object|null
     */
    public static function binding($serviceId)
    {
        if (DB::schema()->hasTable(self::TABLE)) {
            $row = DB::table(self::TABLE)->where('service_id', (int) $serviceId)->first();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Route explicit pack products or services with an existing retained pack binding.
     *
     * @param  array  $params  WHMCS module parameters.
     * @return bool
     */
    public static function handles($params)
    {
        return self::isProduct($params) || self::binding($params['serviceid'] ?? 0) !== null;
    }

    /**
     * Create/extend additive pack storage while retaining disabled/terminated bindings.
     */
    public static function schema(): void
    {
        if (! DB::schema()->hasTable(self::TABLE)) {
            DB::schema()->create(self::TABLE, function ($table) {
                $table->engine = 'InnoDB';
                $table->unsignedBigInteger('service_id')->primary();
                $table->unsignedBigInteger('pack_id')->nullable();
                $table->string('state', 30);
                $table->timestamps();
            });
        }
        foreach (['panel_id', 'user_id', 'profile_id'] as $column) {
            if (! DB::schema()->hasColumn(self::TABLE, $column)) {
                DB::schema()->table(self::TABLE, function ($table) use ($column) {
                    $table->unsignedBigInteger($column)->nullable();
                });
            }
        }
    }

    /**
     * Reserve the provisioning service and audit lifecycle intent before native writes.
     */
    private function beginOperation(array $params, array $cp, string $action): void
    {
        $actor = Access::lifecycleActor();
        $ctx = ['panelId' => $cp['panel_id'], 'serviceId' => (int) $params['serviceid'], 'serverId' => 0];
        $op = Operation::begin($ctx, 'pack:' . $action, ['profileId' => $params['configoption10'] ?? null,
            'terminationBehavior' => $params['configoption12'] ?? 'Suspend Servers'], $actor, bin2hex(random_bytes(16)));
        $this->operationId = $op->id;
    }

    /**
     * Update a retained local binding after a verified lifecycle checkpoint.
     *
     * @return void
     */
    protected function store($serviceId, $packId, $state)
    {
        self::schema();
        DB::table(self::TABLE)->where('service_id', (int) $serviceId)->update([
            'pack_id' => $packId, 'state' => $state, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Read the service binding; overridable for isolated lifecycle tests.
     *
     * @return object|null
     */
    protected function findBinding($serviceId)
    {
        return self::binding($serviceId);
    }

    /**
     * Read the WHMCS client record used for native account provisioning.
     *
     * @return object|null
     */
    protected function findUser($userId)
    {
        return Database::getUser($userId);
    }

    /**
     * Detect a conflicting per-VM binding before provisioning a resource pack.
     *
     * @return bool
     */
    protected function hasServer($serviceId)
    {
        return (bool) Database::getSystemService($serviceId);
    }

    /**
     * Send one native lifecycle request and record redacted status metadata.
     *
     * @param  array  $cp  Server-resolved panel connection.
     * @param  string  $method  Internal HTTP verb.
     * @param  string  $path  Internal validated native path, never caller input.
     * @param  array|null  $body  Native payload.
     * @return array{0: int, 1: array|null} HTTP code and decoded response.
     */
    protected function request(array $cp, $method, $path, $body = null)
    {
        if ($method !== 'get') {
            $this->sentWrite = true;
        }
        $request = $this->initCurl($cp['token']);
        if ($body !== null) {
            $request->addOption(CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = $request->{$method}($cp['url'] . $path);
        $code = (int) $request->getRequestInfo('http_code');
        if ($method !== 'get' && $this->operationId) {
            Operation::finish($this->operationId, 'saved', ['checkpoint' => $path, 'http' => $code, 'outcome' => Operation::resultState($code, json_decode((string) $raw, true))]);
        }
        Log::insert('ResourcePack:' . $method . ':' . $path, ['http_code' => $code], $raw);

        return [$code, json_decode((string) $raw, true)];
    }

    /**
     * Send a lifecycle mutation, recording that it may have reached VirtFusion.
     *
     * request() marks the write before the native call; marking again after it
     * returns keeps the flag correct for transports that override request().
     *
     * @return array{0: int, 1: array|null} HTTP code and decoded response.
     */
    private function send(array $cp, string $method, string $path, $body = null): array
    {
        $result = $this->request($cp, $method, $path, $body);
        $this->sentWrite = true;

        return $result;
    }

    /**
     * Accept only documented HTTP outcomes; dependency failures remain unresolved.
     *
     * @param  array  $result  HTTP code and decoded response tuple.
     * @return void
     *
     * @throws \RuntimeException Unexpected native response.
     */
    protected function requireSuccess(array $result, array $codes = [200, 201, 204])
    {
        if (! in_array($result[0], $codes, true)) {
            throw new \RuntimeException(implode(', ', self::apiErrors($result[1], 'VirtFusion returned HTTP ' . $result[0])));
        }
    }

    /**
     * Resolve the WHMCS control server from trusted provisioning parameters.
     *
     * @param  array  $params  WHMCS module parameters.
     * @return array Panel connection.
     *
     * @throws \RuntimeException No configured panel.
     */
    protected function connection($params)
    {
        $cp = $this->getCP($params['serverid'] ?? false, empty($params['serverid']));
        if (! $cp) {
            throw new \RuntimeException('No VirtFusion control server configured.');
        }

        return $cp;
    }

    /**
     * Turn the pack's self-service mode back on for an existing VirtFusion user whose mode is off.
     *
     * Mirrors ModuleFunctions::reEnableSelfService(): a revoke on an earlier termination leaves
     * the user at 0, which would make a new pack unusable. A different non-zero mode is never
     * overwritten (logged instead). Best effort: never fails provisioning.
     *
     * @param  array  $params  WHMCS module parameters.
     * @param  array  $cp  Panel connection.
     * @param  mixed  $user  The verified byExtRelation user data.
     */
    protected function reEnableSelfService($params, array $cp, $user): void
    {
        $mode = AccountType::billingType($params);
        if ($mode <= 0) {
            return;
        }
        $current = (int) (is_array($user) ? ($user['selfService'] ?? 0) : 0);
        if ($current === $mode) {
            return;
        }
        if ($current !== 0) {
            Log::insert('createAccount:selfServiceConflict', ['client' => (int) $params['userid'], 'current' => $current, 'product' => $mode], 'self-service mode left unchanged');

            return;
        }

        try {
            $result = $this->request($cp, 'put', '/users/' . $this->extRelationRef($params['userid']) . '/byExtRelation' . $this->extRelationQuery(), [
                'selfService' => $mode, 'selfServiceHourlyCredit' => in_array($mode, [1, 3], true),
            ]);
            if (! in_array((int) $result[0], [200, 201, 204], true)) {
                Log::insert('createAccount:selfServiceFailed', ['client' => (int) $params['userid'], 'http_code' => (int) $result[0]], $result[1]);
            }
        } catch (\Throwable $e) {
            Log::insert('createAccount:selfServiceFailed', ['client' => (int) ($params['userid'] ?? 0)], $e->getMessage());
        }
    }

    /**
     * Reserve a pending binding before creating a native assigned resource pack.
     *
     * Retries must reconcile pending checkpoints instead of creating a second pack.
     *
     * @param  array  $params  WHMCS module parameters.
     * @return string WHMCS success sentinel or sanitized failure description.
     */
    public function createAccount($params)
    {
        try {
            if ($this->findBinding($params['serviceid']) || $this->hasServer($params['serviceid'])) {
                return 'Service already has a binding; reconcile it before provisioning.';
            }
            $packId = filter_var($params['configoption10'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! $packId) {
                return 'A positive Resource Pack ID is required.';
            }
            $cp = $this->connection($params);
            self::schema();
            DB::table(self::TABLE)->insert([
                'service_id' => (int) $params['serviceid'], 'pack_id' => null, 'state' => 'pending',
                'panel_id' => $cp['panel_id'], 'profile_id' => $packId,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->beginOperation($params, $cp, 'create');
            $ref = $this->extRelationRef($params['userid']);
            $query = $this->extRelationQuery();
            $userResult = $this->request($cp, 'get', '/users/' . $ref . '/byExtRelation' . $query);
            if ($userResult[0] === 404) {
                $user = $this->findUser($params['userid']);
                if (! $user) {
                    return 'WHMCS client not found.';
                }
                $this->requireSuccess($this->request($cp, 'post', '/users', [
                    'name' => $user->firstname . ' ' . $user->lastname,
                    'email' => $user->email,
                    'selfService' => AccountType::billingType($params),
                ] + $this->userRelationFields($user->id)), [201]);
                $userResult = $this->request($cp, 'get', '/users/' . $ref . '/byExtRelation' . $query);
                $this->requireSuccess($userResult, [200]);
                $existingUser = false;
            } else {
                $this->requireSuccess($userResult, [200]);
                $existingUser = true;
            }

            // A pending binding prevents a timeout/retry from buying a second pack.
            // Keep it for manual reconciliation if the remote result is ambiguous.
            $this->verifyUserRelation($userResult[1]['data'] ?? [], (int) $params['userid'], true);
            $vfUser = ServiceTools::positiveId($userResult[1]['data']['id'] ?? null);
            if ($existingUser) {
                $this->reEnableSelfService($params, $cp, $userResult[1]['data'] ?? []);
            }
            DB::table(self::TABLE)->where('service_id', (int) $params['serviceid'])->update(['user_id' => $vfUser]);
            $before = $this->request($cp, 'get', '/selfService/report/byUserExtRelationId/' . $ref . $query);
            $this->requireSuccess($before, [200]);
            if (! is_array($before[1]['data'] ?? null)) {
                throw new \RuntimeException('Pack inventory unavailable before creation.');
            }
            $result = $this->request($cp, 'post', '/selfService/resourcePack/byUserExtRelationId/' . $ref . $query, [
                'packId' => $packId, 'enabled' => true,
            ]);
            $this->requireSuccess($result, [200, 201]);
            $created = $result[1]['data'] ?? null;
            $createdId = ServiceTools::positiveId(is_array($created) ? ($created['id'] ?? null) : $created);
            // Persist acceptance before further reads, but never activate an
            // existing, foreign or wrong-profile pack based on a POST response.
            $this->store($params['serviceid'], $createdId, 'pending');
            if (self::containsPack($before[1]['data'], $createdId)) {
                throw new \RuntimeException('Pack creation returned an existing assignment; reconcile before retrying.');
            }
            $verified = $this->fetchPack($params['serviceid'], $cp);
            if (! $verified || ServiceTools::positiveId($verified['pid'] ?? null) !== $packId || self::memberIds($verified)) {
                throw new \RuntimeException('New pack ownership, profile or empty membership could not be verified.');
            }
            $this->store($params['serviceid'], $createdId, 'active');
            // Profile sync is deliberately after binding: a failed sync must not
            // lose the created pack or trigger another POST on the next attempt.
            $sync = $this->request($cp, 'put', '/selfService/access/byUserExtRelationId/' . $ref . $query, ['syncToProfiles' => true]);
            $this->requireSuccess($sync);

            if ($this->operationId) {
                Operation::finish($this->operationId, 'completed');
            }

            return 'success';
        } catch (\Throwable $e) {
            if ($this->operationId) {
                Operation::finish($this->operationId, 'unknown');
            }
            Log::insert(__FUNCTION__, ['serviceid' => $params['serviceid'] ?? null], $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Choose explicit legacy deletion behavior or the retain/suspend default.
     *
     * @param  int  $packId  Assigned native pack ID, never the profile ID.
     * @param  array  $params  WHMCS settings including deletion delay and termination behavior.
     * @return array{0: string, 1: string} HTTP verb and validated native path.
     */
    public static function terminationPath($packId, $params)
    {
        if (($params['configoption12'] ?? 'Suspend Servers') !== 'Delete Servers') {
            return ['post', '/selfService/resourcePackServers/' . (int) $packId . '/suspend'];
        }
        $delay = filter_var($params['configoption11'] ?? 60, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($delay === false) {
            throw new \RuntimeException('Deletion Delay must be a non-negative number of minutes.');
        }

        return ['delete', '/selfService/resourcePackServers/' . (int) $packId . '?delay=' . $delay];
    }

    /**
     * Run suspend, unsuspend or terminate against a verified retained binding.
     *
     * Disable allocation before suspending/deleting members. Unknown native outcomes
     * retain the pending state for reconciliation; no automatic replay is performed.
     *
     * @param  string  $action  Internal lifecycle verb.
     * @param  array  $params  WHMCS module parameters.
     * @return string WHMCS success sentinel or failure description.
     */
    public function lifecycle($action, $params)
    {
        $binding = null;
        $reservedBinding = false;
        $this->sentWrite = false;
        $this->operationId = null;

        try {
            if (! in_array($action, ['terminate', 'suspend', 'unsuspend'], true)) {
                return 'Resource pack plans must be changed in VirtFusion; automatic package changes are unsupported.';
            }
            $binding = $this->findBinding($params['serviceid']);
            if (! $binding || empty($binding->pack_id)) {
                return 'No pack is bound; reconcile any pending provisioning before running lifecycle actions.';
            }
            if (($binding->state ?? '') === 'terminated') {
                return $action === 'terminate' ? 'success' : 'This pack has been terminated.';
            }
            if (($action === 'suspend' && $binding->state === 'suspended') || ($action === 'unsuspend' && $binding->state === 'active')) {
                return 'success';
            }
            $cp = $this->connection($params);
            if ((int) ($binding->panel_id ?? 0) !== (int) $cp['panel_id'] || empty($binding->user_id)) {
                return 'Pack binding requires explicit migration or reconciliation.';
            }
            if (! in_array($binding->state, ['active', 'suspended'], true)) {
                return 'Pack operation unresolved; reconcile the binding before retrying.';
            }
            $pack = $this->fetchPack($params['serviceid'], $cp);
            if (! $pack) {
                throw new \RuntimeException('Pack inventory unavailable.', 403);
            }
            foreach (self::memberIds($pack) as $member) {
                $this->verifyVmOwner($cp, $member, (int) $params['userid']);
            }
            $id = (int) $binding->pack_id;
            if ($action === 'terminate') {
                // Validate configuration before any reservation or native write.
                try {
                    [$termMethod, $termPath] = self::terminationPath($id, $params);
                } catch (\RuntimeException $e) {
                    return $e->getMessage();
                }
            }

            // Reserve the service scope before the binding CAS so a held lock can
            // never strand the binding in "<action>_pending".
            try {
                $this->beginOperation($params, $cp, $action);
            } catch (\RuntimeException $e) {
                Log::insert('ResourcePack:' . $action, ['serviceid' => $params['serviceid']], $e->getMessage());

                return 'Another operation is unresolved for this service; reconcile it before retrying.';
            }
            $reserved = DB::table(self::TABLE)->where('service_id', (int) $params['serviceid'])->where('state', $binding->state)->update(['state' => $action . '_pending']);
            if ($reserved !== 1) {
                Operation::finish($this->operationId, 'failed', ['reason' => 'binding changed']);

                return 'Another pack operation is unresolved.';
            }
            $reservedBinding = true;

            if ($action === 'terminate') {
                // Disable first so the account cannot allocate more servers while
                // suspension/deletion and pack removal are being processed.
                $this->requireSuccess($this->send($cp, 'put', '/selfService/resourcePack/' . $id, ['enabled' => false]));
                $this->requireSuccess($this->send($cp, $termMethod, $termPath));
                if (($params['configoption12'] ?? '') === 'Delete Servers') {
                    $this->requireSuccess($this->send($cp, 'delete', '/selfService/resourcePack/' . $id . '?disable=true'), [204]);
                }
                // Preserve audit/binding data. 424 and generic 404 are NOT success.
                $this->store($params['serviceid'], $id, 'terminated');
            } elseif ($action === 'suspend' || $action === 'unsuspend') {
                if ($action === 'suspend') {
                    $this->requireSuccess($this->send($cp, 'put', '/selfService/resourcePack/' . $id, ['enabled' => false]));
                }
                $this->requireSuccess($this->send($cp, 'post', '/selfService/resourcePackServers/' . $id . '/' . $action));
                if ($action === 'unsuspend') {
                    $this->requireSuccess($this->send($cp, 'put', '/selfService/resourcePack/' . $id, ['enabled' => true]));
                }
                $this->store($params['serviceid'], $id, $action === 'suspend' ? 'suspended' : 'active');
            } else {
                return 'Resource pack plans must be changed in VirtFusion; automatic package changes are unsupported.';
            }

            if ($this->operationId) {
                Operation::finish($this->operationId, 'completed');
            }

            return 'success';
        } catch (\Throwable $e) {
            if (! $this->sentWrite) {
                // Nothing reached VirtFusion: undo our own reservation only.
                if ($binding && $reservedBinding) {
                    DB::table(self::TABLE)->where('service_id', (int) $params['serviceid'])->where('state', $action . '_pending')->update(['state' => $binding->state]);
                }
                if ($this->operationId) {
                    Operation::finish($this->operationId, 'failed', ['reason' => 'rejected before submission']);
                }
            } elseif ($this->operationId) {
                Operation::finish($this->operationId, 'unknown');
            }
            Log::insert('ResourcePack:' . $action, ['serviceid' => $params['serviceid'] ?? null], $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Verify the binding user and native account report before returning pack data.
     *
     * @param  int|string  $serviceId  WHMCS hosting service ID.
     * @param  array  $cp  Server-resolved panel connection.
     * @return array|null Native assigned pack; null for absent/terminated bindings.
     *
     * @throws \RuntimeException Pack/user/panel ownership cannot be proven.
     */
    public function fetchPack($serviceId, array $cp)
    {
        $binding = $this->findBinding($serviceId);
        if (! $binding || empty($binding->pack_id) || ($binding->state ?? '') === 'terminated') {
            return null;
        }
        $service = Database::getWhmcsService((int) $serviceId);
        if (! $service || (int) ($binding->panel_id ?? 0) !== (int) $cp['panel_id'] || empty($binding->user_id)) {
            throw new \RuntimeException('Pack binding requires reconciliation.', 403);
        }
        $user = $this->request($cp, 'get', '/users/' . $this->extRelationRef($service->userid) . '/byExtRelation' . $this->extRelationQuery());
        $this->requireSuccess($user, [200]);
        $this->verifyUserRelation($user[1]['data'] ?? [], (int) $service->userid, true);
        if ((int) ($user[1]['data']['id'] ?? 0) !== (int) $binding->user_id) {
            throw new \RuntimeException('Pack user mismatch.', 403);
        }
        $usage = $this->request($cp, 'get', '/selfService/report/byUserExtRelationId/' . $this->extRelationRef($service->userid) . $this->extRelationQuery());
        $this->requireSuccess($usage, [200]);
        if (! self::containsPack($usage[1]['data'] ?? [], (int) $binding->pack_id)) {
            throw new \RuntimeException('Pack is not assigned to the service owner.', 403);
        }
        $result = $this->request($cp, 'get', '/selfService/resourcePack/' . (int) $binding->pack_id . '?withServers=true');
        $this->requireSuccess($result, [200]);

        $pack = $result[1]['data'] ?? null;
        if (! is_array($pack) || ! ServiceTools::matchesId($pack['id'] ?? null, (int) $binding->pack_id)) {
            throw new \RuntimeException('Pack inventory mismatch.', 403);
        }

        return $pack;
    }

    /**
     * Recover a pending pack binding without replaying any native mutation.
     *
     * Requires recovery-role access, exact pack confirmation, native user/profile
     * verification and an explicit native-state verification acknowledgment.
     * The HTTP boundary must enforce POST/CSRF before calling.
     */
    public function reconcile(int $serviceId, array $input): array
    {
        Access::admin('Perform Module Command Operations');
        ServiceTools::confirm($input, 'reconciliationConfirmed');
        $service = Database::getWhmcsService($serviceId);
        $binding = self::binding($serviceId);
        if (! $service || ! $binding) {
            throw new \RuntimeException('Pack binding not found.', 404);
        }
        $cp = $this->getCP((int) $service->server);
        if (! $cp || (int) ($binding->panel_id ?? 0) !== (int) $cp['panel_id']) {
            throw new \RuntimeException('Pack panel mismatch.', 403);
        }
        Access::recovery(ProviderSettings::get((int) $cp['panel_id']));
        $id = ServiceTools::positiveId($input['packId'] ?? ($binding->pack_id ?? null));
        if ((string) ($input['targetConfirmed'] ?? '') !== (string) $id) {
            throw new \InvalidArgumentException('Confirm the exact assigned pack ID.');
        }
        $state = $input['state'] ?? '';
        if (! in_array($state, ['active', 'suspended', 'terminated'], true)) {
            throw new \InvalidArgumentException('Invalid reconciled pack state.');
        }
        $ref = $this->extRelationRef($service->userid);
        $user = $this->request($cp, 'get', '/users/' . $ref . '/byExtRelation' . $this->extRelationQuery());
        $this->requireSuccess($user, [200]);
        $userData = $user[1]['data'] ?? [];
        $this->verifyUserRelation($userData, (int) $service->userid, true);
        $report = $this->request($cp, 'get', '/selfService/report/byUserExtRelationId/' . $ref . $this->extRelationQuery());
        $this->requireSuccess($report, [200]);
        if (! self::containsPack($report[1]['data'] ?? [], $id)) {
            throw new \RuntimeException('Pack is not assigned to the verified owner.', 403);
        }
        $pack = $this->request($cp, 'get', '/selfService/resourcePack/' . $id . '?withServers=true');
        $this->requireSuccess($pack, [200]);
        $data = $pack[1]['data'] ?? [];
        if ((int) ($data['id'] ?? 0) !== $id || (int) ($data['pid'] ?? 0) !== (int) $binding->profile_id) {
            throw new \RuntimeException('Assigned pack or profile mismatch.', 403);
        }
        foreach (self::memberIds($data) as $vm) {
            $this->verifyVmOwner($cp, $vm, (int) $service->userid);
        }
        $other = DB::table(self::TABLE)->where('panel_id', $binding->panel_id)->where('pack_id', $id)->where('service_id', '!=', $serviceId)->exists();
        if ($other) {
            throw new \RuntimeException('Assigned pack already has another binding.', 409);
        }
        ServiceTools::confirm($input, 'nativeStateVerified');
        DB::table(self::TABLE)->where('service_id', $serviceId)->update([
            'pack_id' => $id, 'user_id' => (int) $userData['id'], 'state' => $state, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Operation::schema();
        foreach (DB::table(Operation::TABLE)->where('panel_id', $binding->panel_id)->where('service_id', $serviceId)->where('vm_id', 0)
            ->whereIn('state', ['saved', 'queued', 'running', 'unknown'])->get() as $operation) {
            Operation::finish($operation->id, 'completed', ['reconciledBy' => Access::actor(true), 'packId' => $id, 'state' => $state]);
        }
        Log::insert('PackReconcile', ['actor' => Access::actor(true), 'before' => (array) $binding], ['packId' => $id, 'state' => $state]);

        return ['packId' => $id, 'state' => $state];
    }

    /**
     * Find a pack ID only in report entries explicitly typed as pack.
     */
    public static function containsPack(array $data, int $id): bool
    {
        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') === 'pack' && ServiceTools::matchesId($item['id'] ?? null, $id)) {
                return true;
            }
            if (self::containsPack($item, $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate the complete member inventory and deduplicate canonical VM IDs.
     *
     * Membership does not prove ownership: verify each native VM before access.
     *
     * Missing or malformed members must not become an empty/partial inventory:
     * native bulk lifecycle routes act on every actual member, including any
     * member silently omitted by a permissive parser.
     *
     * @return int[]
     *
     * @throws \RuntimeException Membership is absent or malformed.
     */
    public static function memberIds(array $pack): array
    {
        if (! isset($pack['servers']) || ! is_array($pack['servers']) || array_values($pack['servers']) !== $pack['servers']) {
            throw new \RuntimeException('Pack member inventory unavailable.', 403);
        }
        $ids = [];
        foreach ($pack['servers'] as $server) {
            try {
                $ids[] = ServiceTools::positiveId(is_array($server) ? ($server['id'] ?? null) : $server);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException('Pack member identity is malformed.', 403);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Render the pack interface from a verified binding, failing closed on errors.
     *
     * @param  array  $params  WHMCS module parameters.
     * @return array WHMCS template selection and variables.
     */
    public function clientArea($params)
    {
        try {
            $pack = $this->fetchPack($params['serviceid'], $this->connection($params));

            return [
                'tabOverviewReplacementTemplate' => 'resource-pack',
                'templateVariables' => ['pack' => $pack, 'systemURL' => Database::getSystemUrl(), 'serviceStatus' => $params['status'], 'serviceid' => $params['serviceid']],
            ];
        } catch (\Throwable $e) {
            Log::insert('ResourcePack:clientArea', ['serviceid' => $params['serviceid'] ?? null], $e->getMessage());

            return ['tabOverviewReplacementTemplate' => 'error', 'templateVariables' => []];
        }
    }
}
