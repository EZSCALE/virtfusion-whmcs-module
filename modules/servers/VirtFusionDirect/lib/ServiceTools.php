<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/** Explicit actions only. Never accept a caller-supplied API path, panel or owner. */
class ServiceTools extends Module
{
    const ACTIONS = [
        'interfaceChoices' => ['firewall', 'get', '/interfaces'],
        'powerStatus' => ['power', 'get', '?remoteState=true'],
        'ptrList' => ['rdns', 'get', null],
        'ptrSet' => ['rdns', 'post', null],
        'powerBoot' => ['power', 'post', '/power/boot'],
        'powerShutdown' => ['power', 'post', '/power/shutdown'],
        'powerRestart' => ['power', 'post', '/power/restart'],
        'powerPoweroff' => ['power', 'post', '/power/poweroff'],
        'interfaces' => ['network', 'get', '/interfaces'],
        'disks' => ['disks', 'get', '/disks'],
        'rescueStatus' => ['rescue', 'get', '/rescue'],
        'rescueStart' => ['rescue', 'post', '/rescue'],
        'rescueEnd' => ['rescue', 'delete', '/rescue'],
        'elevationStatus' => ['elevation', 'get', '/elevate'],
        'elevationStart' => ['elevation', 'post', '/elevate'],
        'elevationCommit' => ['elevation', 'post', '/elevate/commit'],
        'elevationDiscard' => ['elevation', 'delete', '/elevate'],
        'isoStatus' => ['iso', 'get', '/media/iso'],
        'isoMount' => ['iso', 'post', '/media/iso'],
        'isoEject' => ['iso', 'delete', '/media/iso'],
        'backupList' => ['backups', 'get', null],
        'backupJobs' => ['backups', 'get', null],
        'backupJob' => ['backups', 'get', null],
        'backupCreate' => ['backups', 'post', '/backupManager/backup'],
        'backupRestoreContinue' => ['backups', 'post', null],
        'backupRestore' => ['backups', 'post', '/backupManager/restore'],
        'firewallList' => ['firewall', 'get', null],
        'firewallAdd' => ['firewall', 'post', null],
        'firewallEnableRule' => ['firewall', 'put', null],
        'firewallDelete' => ['firewall', 'delete', null],
        'firewallPreset' => ['firewall', 'post', null],
        'firewallApply' => ['firewall', 'post', null],
        'isoLibraryList' => ['isoLibrary', 'get', null],
        'isoLibraryCreate' => ['isoLibrary', 'post', null],
        'isoLibraryRename' => ['isoLibrary', 'put', null],
        'isoLibraryDelete' => ['isoLibrary', 'delete', null],
        'migrationHybridStatus' => ['adminTools', 'get', '/migrate/hybrid'],
        'migrationLiveStatus' => ['adminTools', 'get', '/migrate/live'],
        'migrationDestinations' => ['adminTools', 'get', '/migrate/hybrid/destinations'],
        'assets' => ['adminTools', 'get', '/hypervisorAssets'],
        'assetsAvailable' => ['adminTools', 'get', '/hypervisorAssets/available'],
        'diskGrow' => ['adminTools', 'put', null],
        'diskDelete' => ['adminTools', 'delete', null],
        'diskCancelPending' => ['adminTools', 'delete', null],
        'diskSettings' => ['adminTools', 'put', null],
        'diskAdd' => ['adminTools', 'post', '/disks'],
        'assetAttach' => ['adminTools', 'post', null],
        'assetDetach' => ['adminTools', 'delete', null],
        'assetOrder' => ['adminTools', 'put', '/hypervisorAssets/order'],
        'migrationHybridStart' => ['adminTools', 'post', null],
        'migrationHybridRetry' => ['adminTools', 'post', null],
        'migrationHybridCancel' => ['adminTools', 'post', null],
        'migrationHybridComplete' => ['adminTools', 'post', null],
        'migrationHybridRevert' => ['adminTools', 'post', null],
        'migrationLiveStart' => ['adminTools', 'post', null],
        'migrationLiveCancel' => ['adminTools', 'post', null],
        'migrationLiveAbort' => ['adminTools', 'post', null],
        'migrationLiveForceCancel' => ['adminTools', 'post', null],
        'migrationLiveForceDataSuccess' => ['adminTools', 'post', null],
        'migrationLiveResume' => ['adminTools', 'post', null],
        'interfaceAdd' => ['adminTools', 'post', null],
        'interfaceSettings' => ['adminTools', 'post', null],
        'interfaceIpv4Add' => ['adminTools', 'post', null],
        'interfaceIpv4Remove' => ['adminTools', 'post', null],
        'interfaceIpv4Order' => ['adminTools', 'post', null],
        'interfaceIpv6Add' => ['adminTools', 'post', null],
        'interfaceIpv6Remove' => ['adminTools', 'post', null],
        'vlanAdd' => ['adminTools', 'post', null],
        'vlanSettings' => ['adminTools', 'post', null],
        'vlanDelete' => ['adminTools', 'post', null],
        'macSet' => ['adminTools', 'post', null],
        'interfaceDetails' => ['adminTools', 'get', null],
        'vlanList' => ['adminTools', 'get', null],
        'diskDetails' => ['adminTools', 'get', null],
        'assetDetails' => ['adminTools', 'get', null],
        'hypervisorStorage' => ['adminTools', 'get', null],
        'hypervisorNetworks' => ['adminTools', 'get', null],
        'hypervisorMetrics' => ['adminTools', 'get', null],
        'hypervisorBindings' => ['adminTools', 'get', null],
        'firewallCatalogue' => ['adminTools', 'get', null],
        'templateCatalogue' => ['adminTools', 'get', null],
        'templateDetails' => ['adminTools', 'get', null],
        'isoAdminCatalogue' => ['adminTools', 'get', null],
        'migrationDestinationDetails' => ['adminTools', 'get', null],
        'firewallDelegate' => ['adminTools', 'post', null],
        'firewallLock' => ['adminTools', 'post', null],
        'isoAdminCreate' => ['adminTools', 'post', null],
        'isoAdminUpdate' => ['adminTools', 'post', null],
        'isoAdminDelete' => ['adminTools', 'post', null],
        'isoAdminAssign' => ['adminTools', 'post', null],
        'operationReconcile' => ['adminTools', 'post', null],
        'operation' => ['operation', 'get', null],
    ];

    public static function minimumVersion(string $feature): string
    {
        return $feature === 'power' ? '1.7.3' : '7.1.0';
    }

    public static function positiveId($id): int
    {
        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)
            || filter_var($id, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('A positive integer ID is required.');
        }

        return (int) $id;
    }

    public static function confirm(array $input, string $key): void
    {
        if (! in_array($input[$key] ?? null, [true, '1', 1], true)) {
            throw new \InvalidArgumentException('Explicit ' . $key . ' confirmation is required.');
        }
    }

    protected function context(int $serviceId, ?int $member, bool $admin): array
    {
        $service = Database::getWhmcsService($serviceId);
        if (! $service) {
            throw new \RuntimeException('Service not found.', 404);
        }
        $product = DB::table('tblproducts')->where('id', (int) $service->packageid)->first();
        if (! $product || $product->servertype !== 'VirtFusionDirect') {
            throw new \RuntimeException('Wrong service module.', 403);
        }
        if (! $admin && (int) $service->userid !== Access::client()) {
            throw new \RuntimeException('Service ownership mismatch.', 403);
        }
        $cp = $this->getCP((int) $service->server);
        if (! $cp) {
            throw new \RuntimeException('Panel unavailable.', 503);
        }
        $packBinding = ResourcePack::binding($serviceId);
        if (($product->configoption9 ?? '') === 'Resource Pack' || $packBinding) {
            if (! $member || ! in_array($packBinding->state ?? '', ['active', 'suspended'], true)) {
                throw new \RuntimeException('An active pack member must be selected.', 403);
            }
            $pack = $this->resourcePacks()->fetchPack($serviceId, $cp);
            if (! $pack || ! in_array($member, ResourcePack::memberIds($pack), true)) {
                throw new \RuntimeException('VM is not a current pack member.', 403);
            }
            $vm = $member;
        } else {
            if ($member !== null) {
                throw new \RuntimeException('VM selection is only valid for resource packs.', 403);
            }
            $binding = Database::getSystemService($serviceId);
            $vm = self::positiveId($binding->server_id ?? null);
        }
        $owned = $this->verifyVmOwner($cp, $vm, (int) $service->userid);

        return [
            'serviceId' => $serviceId, 'serverId' => $vm, 'panelId' => (int) $cp['panel_id'],
            'cp' => $cp, 'service' => $service, 'product' => $product,
            'server' => $owned['server'], 'user' => $owned['user'], 'memberVm' => $member, 'packState' => $packBinding->state ?? null,
        ];
    }

    protected function resourcePacks(): ResourcePack
    {
        return new ResourcePack;
    }

    protected function settings(int $panel): array
    {
        return ProviderSettings::get($panel);
    }

    protected function version(array $cp): ?string
    {
        return PanelVersion::detect($this, $cp);
    }

    public function request(array $ctx, string $method, string $path, ?array $body = null): array
    {
        $request = $this->initCurl($ctx['cp']['token']);
        if ($body !== null) {
            $request->addOption(CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        // Do not log bodies: rescue credentials and native download URLs are deliberately ephemeral.
        $raw = $request->{$method}($ctx['cp']['url'] . $path);
        $code = (int) $request->getRequestInfo('http_code');
        Log::insert('ServiceTools:' . $method, ['service' => $ctx['serviceId'], 'vm' => $ctx['serverId']], ['http' => $code]);

        return [$code, json_decode((string) $raw, true)];
    }

    public function catalogue(array $ctx, string $path): array
    {
        $out = [];
        for ($page = 1; $page <= 100; $page++) {
            [$code, $body] = $this->request($ctx, 'get', $path . '?results=200&page=' . $page);
            if ($code !== 200 || ! is_array($body['data'] ?? null)) {
                throw new \RuntimeException('Catalogue unavailable.', 502);
            }
            $out = array_merge($out, $body['data']);
            if ($page >= (int) ($body['last_page'] ?? 1)) {
                return $out;
            }
        }

        throw new \RuntimeException('Catalogue incomplete.', 502);
    }

    public function read(array $ctx, string $path)
    {
        [$code, $data] = $this->request($ctx, 'get', $path);
        if ($code !== 200 || ! is_array($data)) {
            throw new \RuntimeException('Unable to read service state.', 502);
        }

        return array_key_exists('data', $data) ? $data['data'] : $data;
    }

    public static function ownedRows(array $rows, int $vm): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || (int) ($row['serverId'] ?? 0) !== $vm) {
                throw new \RuntimeException('Returned job or backup ownership mismatch.', 403);
            }
            $out[] = $row;
        }

        return $out;
    }

    public static function select(array $rows, $id, array $fields = ['id', 'drive', 'tag']): array
    {
        if (! is_string($id) && ! is_int($id)) {
            throw new \InvalidArgumentException('Invalid target.');
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($fields as $field) {
                if (isset($row[$field]) && (string) $row[$field] === (string) $id) {
                    return $row;
                }
            }
        }

        throw new \RuntimeException('Target does not belong to this service.', 403);
    }

    public static function protect(array $server): void
    {
        // Native VF still enforces other locks and prerequisites on the actual mutation.
        if (! empty($server['suspended']) || ! empty($server['settings']['doNotDestroy'])
            || ! empty($server['settings']['dnd']) || ! empty($server['doNotDestroy'])
            || ! empty($server['dnd']) || ! empty($server['prohibit']['destroy'])) {
            throw new \RuntimeException('Service is protected or suspended.', 409);
        }
    }

    public function capabilities(int $serviceId, bool $admin = false): array
    {
        $service = Database::getWhmcsService($serviceId);
        if (! $service || (! $admin && (! $this->validateUserOwnsService($serviceId)))) {
            throw new \RuntimeException('Service ownership mismatch.', 403);
        }
        $cp = $this->getCP((int) $service->server);
        if (! $cp) {
            throw new \RuntimeException('Panel unavailable.', 503);
        }
        $settings = $this->settings((int) $cp['panel_id']);

        try {
            $version = $this->version($cp);
        } catch (\RuntimeException $e) {
            $version = null;
        }
        $enabled = [];
        foreach (['power', 'rdns', 'network', 'disks', 'backups', 'rescue', 'elevation', 'iso', 'firewall', 'isoLibrary', 'adminTools'] as $feature) {
            $enabled[$feature] = ($settings[$feature] || ($admin && $settings['adminTools'])) && PanelVersion::supports($version, self::minimumVersion($feature)) && ($feature !== 'adminTools' || $admin);
        }
        $enabled['customUrls'] = $enabled['iso'] && $settings['urlIsolationVerified'];

        return ['version' => $version, 'features' => $enabled, 'isoLimit' => $settings['isoLimit']];
    }

    public function execute(int $serviceId, string $action, array $input, bool $admin = false): array
    {
        if (! isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException('Unsupported service action.');
        }
        [$feature, $method, $suffix] = self::ACTIONS[$action];
        if ($feature === 'adminTools' && ! $admin) {
            throw new \RuntimeException('Administrator action.', 403);
        }
        if ($admin) {
            Access::admin($method === 'get' ? 'View Clients Products/Services' : 'Perform Module Command Operations');
        }
        $ctx = $this->context($serviceId, isset($input['memberVm']) && $input['memberVm'] !== '' ? self::positiveId($input['memberVm']) : null, $admin);
        $settings = $this->settings($ctx['panelId']);
        if ($feature !== 'operation' && (! (($settings[$feature] ?? false) || ($admin && $settings['adminTools'])) || ! PanelVersion::supports($this->version($ctx['cp']), self::minimumVersion($feature)))) {
            throw new \RuntimeException('This feature is disabled or requires VirtFusion 7.1.', 403);
        }
        $base = '/servers/' . $ctx['serverId'];
        if ($action === 'operationReconcile') {
            Access::recovery($settings);
            self::confirm($input, 'reconciliationConfirmed');
            if ((string) ($input['targetConfirmed'] ?? '') !== (string) $ctx['serverId']) {
                throw new \InvalidArgumentException('Confirm the exact VM ID.');
            }
            $op = Operation::get((string) ($input['operationId'] ?? ''), $ctx);
            if (! in_array($input['resolution'] ?? '', ['completed', 'failed'], true)) {
                throw new \InvalidArgumentException('Invalid reconciliation outcome.');
            }
            $observed = $this->read($ctx, '/servers/' . $ctx['serverId']);
            Log::insert('OperationReconcile', ['actor' => Access::actor(true), 'operation' => $op->id, 'vm' => $ctx['serverId']], ['resolution' => $input['resolution'], 'observed' => Log::redact($observed)]);
            Operation::finish($op->id, $input['resolution'], ['reconciledBy' => Access::actor(true)]);

            return ['operationId' => $op->id, 'state' => $input['resolution']];
        }
        if ($action === 'operation') {
            return $this->poll($ctx, (string) ($input['operationId'] ?? ''));
        }
        if ($method !== 'get') {
            Access::csrf();
            if (($ctx['packState'] ?? 'active') !== 'active' || $ctx['service']->domainstatus !== 'Active') {
                throw new \RuntimeException('An active service is required.', 409);
            }
            self::protect($ctx['server']);
        } elseif (! in_array($ctx['service']->domainstatus, ['Active', 'Suspended', 'Completed'], true)) {
            throw new \RuntimeException('Service is unavailable.', 409);
        }
        if (in_array($action, ['ptrList', 'ptrSet'], true)) {
            return (new PtrTools($this))->execute($ctx, $action, $input, $settings, $admin);
        }
        if (str_starts_with($action, 'firewall')) {
            return (new FirewallTools($this))->execute($ctx, $action, $input, $settings, $admin);
        }
        if (str_starts_with($action, 'isoLibrary')) {
            return (new IsoLibrary($this))->execute($ctx, $action, $input, $settings, $admin);
        }
        if (str_starts_with($action, 'backup')) {
            return (new BackupTools($this))->execute($ctx, $action, $input, $admin);
        }
        if ($method === 'get' && $suffix === null) {
            return (new AdminTools($this))->execute($ctx, $action, $input, $settings);
        }
        if ($method === 'get') {
            return ['data' => $this->presentation($action, $this->read($ctx, $base . $suffix), $admin)];
        }
        $body = null;
        $path = $base . $suffix;
        switch ($action) {
            case 'powerBoot':
            case 'powerShutdown':
            case 'powerRestart':
            case 'powerPoweroff':
                if ($action !== 'powerBoot') {
                    self::confirm($input, 'powerConfirmed');
                }
                break;
            case 'rescueStart':
            case 'rescueEnd':
                self::confirm($input, 'restartConfirmed');
                if ($action === 'rescueStart') {
                    $state = $this->read($ctx, $base . '/rescue');
                    $image = self::select($state['images'] ?? [], $input['imageId'] ?? 0, ['id']);
                    $body = ['imageId' => (int) $image['id'], 'sendMail' => true];
                }
                break;
            case 'elevationStart':
                $state = $this->read($ctx, $base . '/elevate');
                if (! empty($state['active'])) {
                    throw new \RuntimeException('An elevation is already active.', 409);
                }
                $disks = $input['disks'] ?? [];
                if (! is_array($disks) || ! $disks || count($disks) !== count(array_unique($disks))) {
                    throw new \InvalidArgumentException('Select distinct eligible disks.');
                }
                $selected = [];
                foreach ($disks as $disk) {
                    $row = self::select($state['eligible'] ?? [], $disk);
                    $selected[] = (string) $row['drive'];
                }
                $quiesce = ! in_array($input['quiesce'] ?? true, [false, '0', 0], true);
                if (! $quiesce) {
                    self::confirm($input, 'crashConsistentConfirmed');
                }
                $body = ['disks' => $selected, 'quiesce' => $quiesce];
                break;
            case 'elevationCommit':
                self::confirm($input, 'commitConfirmed');
                $state = $this->read($ctx, $base . '/elevate');
                $disk = self::select($state['drives'] ?? [], $input['drive'] ?? '');
                if (! empty($disk['committed'])) {
                    throw new \RuntimeException('This drive is already committed.', 409);
                }
                $body = ['drive' => $disk['drive']];
                break;
            case 'elevationDiscard':
                self::confirm($input, 'discardConfirmed');
                $state = $this->read($ctx, $base . '/elevate');
                if (empty($state['active'])) {
                    throw new \RuntimeException('No active elevation.', 409);
                }
                break;
            case 'isoMount':
                if (isset($input['url']) && $input['url'] !== '') {
                    if (! $settings['urlIsolationVerified']) {
                        throw new \RuntimeException('Native download isolation has not been verified.', 403);
                    }
                    $body = ['url' => IsoLibrary::validateUrl($input['url'])];
                } else {
                    $iso = IsoLibrary::assigned($this, $ctx, self::positiveId($input['isoId'] ?? null));
                    $body = ['isoId' => (int) $iso['id']];
                }
                break;
            case 'isoEject':
                break;
            default:
                return (new AdminTools($this))->execute($ctx, $action, $input, $settings);
        }

        return $this->mutate($ctx, $action, $method, $path, $body, $input, $admin);
    }

    public function mutate(array $ctx, string $action, string $method, string $path, ?array $body, array $input, bool $admin): array
    {
        $targets = $body ?? [];
        if (isset($targets['url'])) {
            $targets['urlHash'] = hash('sha256', $targets['url']);
        }
        $op = Operation::begin($ctx, $action, ['resource' => $path, 'fields' => $targets], Access::actor($admin), (string) ($input['requestId'] ?? ''));

        try {
            $fresh = $this->context($ctx['serviceId'], $ctx['memberVm'] ?? null, $admin);
            if ($fresh['panelId'] !== $ctx['panelId'] || $fresh['serverId'] !== $ctx['serverId'] || (int) $fresh['user']['id'] !== (int) $ctx['user']['id']) {
                throw new \RuntimeException('Service changed before submission.', 409);
            }
            self::protect($fresh['server']);
            if ($fresh['service']->domainstatus !== 'Active') {
                throw new \RuntimeException('Service is no longer active.', 409);
            }
            if (str_starts_with($path, '/connectivity/') && isset($body['address'])) {
                $assigned = PowerDns\IpUtil::extractIps((object) ['data' => json_decode(json_encode($fresh['server']))]);
                if (! in_array($body['address'], $assigned, true)) {
                    throw new \RuntimeException('Address assignment changed before submission.', 403);
                }
            }
            if ($action === 'isoMount' && isset($body['isoId'])) {
                IsoLibrary::assigned($this, $fresh, (int) $body['isoId']);
            }
            if (! $admin && in_array($action, ['isoLibraryRename', 'isoLibraryDelete'], true)) {
                $isoId = (int) basename($path);
                $iso = IsoLibrary::assigned($this, $fresh, $isoId);
                if (($iso['global'] ?? true) !== false || count($iso['users'] ?? []) !== 1) {
                    throw new \RuntimeException('Private assignment changed before submission.', 403);
                }
            }
            [$code, $response] = $this->request($ctx, $method, $path, $body);
            $state = Operation::resultState($code, $response);
            $job = $response['data']['jobId'] ?? null;
            if (in_array($action, ['backupCreate', 'backupRestore'], true) && $code >= 200 && $code < 300 && ! $job) {
                $state = 'unknown';
            }
            if ($job && $code >= 200 && $code < 300) {
                self::ownedRows([$response['data']], $ctx['serverId']);
            }
            Operation::finish($op->id, $state, ['http' => $code], is_numeric($job) ? (int) $job : null);

            return ['operationId' => $op->id, 'state' => $state, 'http' => $code,
                'created' => ($response['created'] ?? false) === true,
                'targetRuleId' => ($response['created'] ?? false) === true ? ($response['data']['id'] ?? null) : null,
                'applied' => $response['applied'] ?? null,
                'addedRules' => $action === 'firewallPreset' ? ($response['added'] ?? []) : [],
                'message' => $state === 'unknown' ? 'Outcome unknown. Reconcile before submitting again.' : ($state === 'failed' ? 'VirtFusion rejected the operation. Check service protection and prerequisites.' : 'Request accepted; verify applied state.')];
        } catch (\Throwable $e) {
            Operation::finish($op->id, 'unknown');

            throw new \RuntimeException('Outcome unknown; reconcile before retrying.', 502);
        }
    }

    protected function poll(array $ctx, string $id): array
    {
        $op = Operation::get($id, $ctx);
        if ($op->job_id) {
            $job = $this->read($ctx, '/backupManager/jobs/' . (int) $op->job_id);
            self::ownedRows([$job], $ctx['serverId']);
            $state = $job['status'] ?? 'unknown';
            if ($state === 'completed' && (int) ($job['exitCode'] ?? -1) !== 0) {
                $state = 'failed';
            }
            if (in_array($state, ['queued', 'running', 'completed', 'failed'], true)) {
                if ($op->action === 'backupSafeguard' && $state === 'completed') {
                    $state = 'saved'; // Keep the VM reservation until the pinned restore is confirmed.
                }
                Operation::finish($id, $state, ['progress' => $job['progress'] ?? null], (int) $op->job_id);
            }
        }
        if (! $op->job_id && in_array($op->state, ['saved', 'queued', 'running', 'unknown'], true)) {
            $targets = json_decode($op->targets, true);
            $completed = false;
            $base = '/servers/' . $ctx['serverId'];
            if (in_array($op->action, ['rescueStart', 'rescueEnd'], true)) {
                $status = $this->read($ctx, $base . '/rescue');
                $completed = isset($status['active']) && $status['active'] === ($op->action === 'rescueStart');
            } elseif ($op->action === 'elevationStart') {
                $status = $this->read($ctx, $base . '/elevate');
                $actual = array_column($status['drives'] ?? [], 'drive');
                $wanted = $targets['fields']['disks'] ?? [];
                $completed = ! empty($status['active']) && $wanted && ! array_diff($wanted, $actual);
            } elseif ($op->action === 'elevationCommit') {
                $status = $this->read($ctx, $base . '/elevate');
                foreach ($status['drives'] ?? [] as $drive) {
                    if ((string) $drive['drive'] === (string) ($targets['fields']['drive'] ?? '') && ! empty($drive['committed'])) {
                        $completed = true;
                    }
                }
            } elseif ($op->action === 'elevationDiscard') {
                $status = $this->read($ctx, $base . '/elevate');
                $completed = isset($status['active']) && $status['active'] === false;
            }
            if ($completed) {
                Operation::finish($id, 'completed', ['verified' => true]);
            }
        }
        $op = Operation::get($id, $ctx);

        return ['operationId' => $id, 'state' => $op->state, 'action' => $op->action, 'targets' => json_decode($op->targets, true)];
    }

    public function presentation(string $action, $data, bool $admin)
    {
        if ($action === 'powerStatus') {
            return ['state' => $data['remoteState']['state'] ?? null];
        }
        // Credentials appear only on authenticated rescue reads and are never cached/logged.
        if ($action === 'rescueStatus') {
            return array_intersect_key($data, array_flip(['active', 'image', 'ssh', 'images']));
        }
        if ($action === 'elevationStatus') {
            return array_intersect_key($data, array_flip(['active', 'since', 'drives', 'eligible']));
        }
        if ($admin) {
            return Log::redact($data);
        }
        if ($action === 'disks') {
            return array_map(function ($disk) {
                return array_intersect_key($disk, array_flip(['id', 'drive', 'primary', 'sizeGb', 'format', 'bus', 'cache', 'io', 'qos', 'status', 'enabled', 'pending']));
            }, $data);
        }
        if (in_array($action, ['interfaces', 'interfaceChoices'], true)) {
            return array_map(function ($nic) {
                $view = array_intersect_key($nic, array_flip(['id', 'tag', 'type', 'mac']));
                foreach (['ipv4' => ['id', 'address', 'ip', 'cidr', 'gateway', 'ptr'], 'ipv6' => ['id', 'address', 'subnet', 'cidr', 'ptr'], 'vlans' => ['id', 'vlanId', 'nativeMode', 'trunk']] as $key => $fields) {
                    $view[$key] = array_map(fn ($row) => array_intersect_key($row, array_flip($fields)), $nic[$key] ?? []);
                }

                return $view;
            }, $data);
        }

        return Log::redact($data);
    }
}
