<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class AdminTools
{
    private ServiceTools $tools;

    public function __construct(ServiceTools $tools)
    {
        $this->tools = $tools;
    }

    public static function settings(array $input): array
    {
        $allowed = ['bus', 'cache', 'io', 'qos', 'enabled', 'apply'];
        if (array_diff(array_keys($input), $allowed)) {
            throw new \InvalidArgumentException('Unknown disk setting.');
        }
        foreach (['bus' => ['default', 'virtio', 'virtio-scsi', 'sata', 'virtio-discard', 'virtio-scsi-single'],
            'cache' => ['default', 'none', 'writethrough', 'writeback', 'directsync', 'unsafe'],
            'io' => ['default', 'threads', 'native', 'io_uring', 'none']] as $key => $values) {
            if (isset($input[$key]) && ! in_array($input[$key], $values, true)) {
                throw new \InvalidArgumentException('Invalid disk setting.');
            }
        }
        foreach (['enabled', 'apply'] as $key) {
            if (isset($input[$key]) && ! is_bool($input[$key])) {
                throw new \InvalidArgumentException('Expected a boolean.');
            }
        }
        if (isset($input['qos'])) {
            if (! is_array($input['qos']) || array_diff(array_keys($input['qos']), ['readIops', 'writeIops', 'readBytes', 'writeBytes'])) {
                throw new \InvalidArgumentException('Invalid QoS.');
            }
            foreach ($input['qos'] as $v) {
                if ($v !== null && (! is_int($v) || $v < 0 || $v > 99999999999999)) {
                    throw new \InvalidArgumentException('Invalid QoS limit.');
                }
            }
        }
        if (! empty($input['apply']) && ! isset($input['qos'])) {
            throw new \InvalidArgumentException('Live apply is for QoS, not growth.');
        }

        return $input;
    }

    public function execute(array $ctx, string $action, array $input, array $settings): array
    {
        $read = ServiceTools::ACTIONS[$action][1] === 'get';
        Access::admin($read ? 'View Clients Products/Services' : 'Perform Module Command Operations');
        if ($read) {
            return $this->inspect($ctx, $action, $input);
        }
        if (str_starts_with($action, 'isoAdmin')) {
            return $this->iso($ctx, $action, $input, $settings);
        }
        $base = '/servers/' . $ctx['serverId'];
        $method = ServiceTools::ACTIONS[$action][1];
        $body = null;
        $path = '';
        if (str_starts_with($action, 'disk') && $action !== 'diskAdd') {
            $disk = ServiceTools::select($this->tools->read($ctx, $base . '/disks'), $input['diskRef'] ?? '');
            $ref = (string) $disk['id'];
            $path = $base . '/disks/' . $ref;
            if ($action === 'diskGrow') {
                $size = ServiceTools::positiveId($input['sizeGb'] ?? null);
                if ($size <= (int) $disk['sizeGb'] || ! empty($disk['pending'])) {
                    throw new \RuntimeException('Growth must increase size and have no pending action.', 409);
                }
                $body = ['sizeGb' => $size];
            } elseif ($action === 'diskDelete') {
                ServiceTools::confirm($input, 'deleteConfirmed');
                if (! empty($disk['primary'])) {
                    throw new \RuntimeException('Cannot delete primary disk.', 409);
                }
                $body = ['confirm' => true];
            } elseif ($action === 'diskCancelPending') {
                ServiceTools::confirm($input, 'cancelConfirmed');
                if (empty($disk['pending'])) {
                    throw new \RuntimeException('No pending disk action.', 409);
                }
                $path .= '/pending';
            } else {
                $body = self::settings($input['settings'] ?? []);
            }
        } elseif ($action === 'diskAdd') {
            $body = ['sizeGb' => ServiceTools::positiveId($input['sizeGb'] ?? null)];
            if (isset($input['storagePointId']) && (int) $input['storagePointId'] > 0) {
                $hv = ServiceTools::positiveId($ctx['server']['hypervisor']['id'] ?? null);
                $storage = ServiceTools::select($this->tools->read($ctx, '/compute/hypervisors/' . $hv . '/storage'), ServiceTools::positiveId($input['storagePointId']), ['id']);
                $body['storagePointId'] = (int) $storage['id'];
            }
            $path = $base . '/disks';
        } elseif (str_starts_with($action, 'asset')) {
            $attached = $this->tools->read($ctx, $base . '/hypervisorAssets');
            if ($action === 'assetOrder') {
                $order = $input['order'] ?? [];
                if (! is_array($order)) {
                    throw new \InvalidArgumentException('Invalid asset order.');
                }
                $order = array_map([ServiceTools::class, 'positiveId'], $order);
                $ids = array_map(fn ($asset) => (int) $asset['id'], $attached);
                $sorted = $order;
                sort($sorted);
                sort($ids);
                if ($sorted !== $ids) {
                    throw new \InvalidArgumentException('Order must include each attached asset exactly once.');
                }
                $path = $base . '/hypervisorAssets/order';
                $body = ['order' => $order];
            } else {
                $id = ServiceTools::positiveId($input['assetId'] ?? null);
                $inventory = $action === 'assetAttach' ? $this->tools->read($ctx, $base . '/hypervisorAssets/available') : $attached;
                ServiceTools::select($inventory, $id, ['id']);
                $path = $base . '/hypervisorAssets/' . $id;
            }
        } elseif (str_starts_with($action, 'migration')) {
            return $this->migration($ctx, $action, $input, $settings);
        } elseif (str_starts_with($action, 'interface') || str_starts_with($action, 'vlan') || $action === 'macSet') {
            return (new NetworkTools($this->tools))->execute($ctx, $action, $input);
        } else {
            throw new \InvalidArgumentException('Unsupported admin action.');
        }

        return $this->tools->mutate($ctx, $action, $method, $path, $body, $input, true);
    }

    private function inspect(array $ctx, string $action, array $input): array
    {
        $base = '/servers/' . $ctx['serverId'];
        if (in_array($action, ['interfaceDetails', 'vlanList'], true)) {
            $nic = ServiceTools::select($this->tools->read($ctx, $base . '/interfaces'), $input['interfaceRef'] ?? '', ['tag']);
            $tag = (string) $nic['tag'];
            if (! preg_match('/^[0-9]{10}$/D', $tag)) {
                throw new \RuntimeException('Invalid interface tag.', 403);
            }
            $path = $base . '/interfaces/' . $tag . ($action === 'vlanList' ? '/vlans' : '');
        } elseif ($action === 'diskDetails') {
            $disk = ServiceTools::select($this->tools->read($ctx, $base . '/disks'), $input['diskRef'] ?? '');
            $path = $base . '/disks/' . (int) $disk['id'];
        } elseif ($action === 'assetDetails') {
            $asset = ServiceTools::select($this->tools->read($ctx, $base . '/hypervisorAssets'), ServiceTools::positiveId($input['assetId'] ?? null), ['id']);
            $path = $base . '/hypervisorAssets/' . (int) $asset['id'];
        } elseif (str_starts_with($action, 'hypervisor')) {
            $hv = ServiceTools::positiveId($ctx['server']['hypervisor']['id'] ?? null);
            $suffix = ['hypervisorStorage' => 'storage', 'hypervisorNetworks' => 'networks', 'hypervisorMetrics' => 'metrics', 'hypervisorBindings' => 'ipblocks'][$action];
            $path = '/compute/hypervisors/' . $hv . '/' . $suffix;
        } elseif ($action === 'migrationDestinationDetails') {
            $id = ServiceTools::positiveId($input['destinationId'] ?? null);
            ServiceTools::select($this->tools->read($ctx, $base . '/migrate/hybrid/destinations'), $id, ['id']);
            $path = $base . '/migrate/hybrid/destinations/' . $id;
        } elseif ($action === 'templateDetails') {
            $path = '/media/templates/' . ServiceTools::positiveId($input['templateId'] ?? null);
        } else {
            $path = ['firewallCatalogue' => '/firewall/rulesets', 'templateCatalogue' => '/media/templates', 'isoAdminCatalogue' => '/media/iso'][$action] ?? null;
            if (! $path) {
                throw new \InvalidArgumentException('Unknown inspection tool.');
            }
        }

        return ['data' => Log::redact($this->tools->read($ctx, $path))];
    }

    private function iso(array $ctx, string $action, array $input, array $settings): array
    {
        $fields = $input['fields'] ?? [];
        if (! is_array($fields) || array_diff(array_keys($fields), ['name', 'description', 'url', 'filename', 'arch', 'enabled', 'global', 'download'])) {
            throw new \InvalidArgumentException('Invalid ISO fields.');
        }
        foreach (['enabled', 'global', 'download'] as $key) {
            if (isset($fields[$key]) && ! is_bool($fields[$key])) {
                throw new \InvalidArgumentException('Expected boolean ISO flags.');
            }
        }
        if (isset($fields['url'])) {
            if (! $settings['urlIsolationVerified']) {
                throw new \RuntimeException('Native download isolation is unverified.', 403);
            }
            $fields['url'] = IsoLibrary::validateUrl($fields['url']);
        }
        if (isset($fields['name']) && (! is_string($fields['name']) || strlen($fields['name']) < 3 || strlen($fields['name']) > 150)) {
            throw new \InvalidArgumentException('Invalid ISO name.');
        }
        if (isset($fields['filename']) && (! is_string($fields['filename']) || ! preg_match('/^[A-Za-z0-9_-]{1,300}$/D', $fields['filename']))) {
            throw new \InvalidArgumentException('Invalid ISO filename.');
        }
        if (isset($fields['arch']) && ! in_array($fields['arch'], [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Invalid ISO architecture.');
        }
        if (isset($fields['description']) && (! is_string($fields['description']) || strlen($fields['description']) > 500)) {
            throw new \InvalidArgumentException('Invalid ISO description.');
        }
        $path = '/media/iso';
        $method = 'post';
        if ($action !== 'isoAdminCreate') {
            $iso = ServiceTools::select($this->tools->catalogue($ctx, $path), ServiceTools::positiveId($input['isoId'] ?? null), ['id']);
            $path .= '/' . (int) $iso['id'];
            $method = 'put';
        }
        if ($action === 'isoAdminDelete') {
            ServiceTools::confirm($input, 'deleteConfirmed');
            $fields = ['confirm' => true, 'force' => false];
            $method = 'delete';
        } elseif ($action === 'isoAdminAssign') {
            $users = $input['users'] ?? [];
            if (! is_array($users)) {
                throw new \InvalidArgumentException('Invalid user assignments.');
            }
            $users = array_map([ServiceTools::class, 'positiveId'], $users);
            foreach ($users as $id) {
                $user = $this->tools->read($ctx, '/users/' . $id);
                if ((int) ($user['id'] ?? 0) !== $id) {
                    throw new \RuntimeException('User not found.', 404);
                }
            }
            $fields = ['users' => $users];
            $path .= '/users';
        }

        return $this->tools->mutate($ctx, $action, $method, $path, $fields, $input, true);
    }

    private function migration(array $ctx, string $action, array $input, array $settings): array
    {
        $kind = str_contains($action, 'Hybrid') ? 'hybrid' : 'live';
        $verb = lcfirst(substr($action, strlen('migration' . ucfirst($kind))));
        $base = '/servers/' . $ctx['serverId'] . '/migrate/' . $kind;
        $state = $this->tools->read($ctx, $base);
        $body = [];
        $unsafe = in_array($verb, ['forceCancel', 'forceDataSuccess'], true);
        foreach (['allowUnsafe', 'disableFilterCheck', 'ignoreHv', 'ignoreDst', 'forceOffline', 'autoPostCopy'] as $flag) {
            if (! empty($input[$flag])) {
                $unsafe = true;
            }
        }
        if ($unsafe) {
            Access::recovery($settings);
            if ((string) ($input['targetConfirmed'] ?? '') !== (string) $ctx['serverId']) {
                throw new \InvalidArgumentException('Confirm the exact VM ID for recovery.');
            }
        }
        if ($verb === 'start') {
            if ($state !== null && $state !== [] && $state !== false) {
                throw new \RuntimeException('A migration is already present.', 409);
            }
            $destination = ServiceTools::positiveId($input['destinationId'] ?? null);
            ServiceTools::select($this->tools->read($ctx, '/servers/' . $ctx['serverId'] . '/migrate/hybrid/destinations'), $destination, ['id']);
            $availability = $this->tools->read($ctx, '/servers/' . $ctx['serverId'] . '/migrate/hybrid/destinations/' . $destination);
            $body['destinationId'] = $destination;
            if ($kind === 'hybrid') {
                $disks = $input['disks'] ?? [];
                if (! is_array($disks) || ! $disks) {
                    throw new \InvalidArgumentException('Destination disk mapping is required.');
                }
                $selected = [];
                foreach ($disks as $selection) {
                    $disk = ServiceTools::select($availability['disks'] ?? [], $selection['drive'] ?? '', ['drive']);
                    $storage = ServiceTools::select($disk['storageOptions'] ?? [], $selection['storageId'] ?? '', ['id']);
                    $selected[] = ['drive' => $disk['drive'], 'storageId' => (int) $storage['id']];
                }
                $drives = array_column($selected, 'drive');
                $expected = array_column($availability['disks'] ?? [], 'drive');
                sort($drives);
                sort($expected);
                if ($drives !== $expected) {
                    throw new \InvalidArgumentException('Map every disk exactly once.');
                }
                $body['disks'] = $selected;
                $body['sendMail'] = true;
                if (isset($input['speedLimit'])) {
                    $speed = filter_var($input['speedLimit'], FILTER_VALIDATE_INT);
                    if ($speed === false || $speed < 0 || $speed > 625000) {
                        throw new \InvalidArgumentException('Invalid transfer speed.');
                    }
                    $body['speedLimit'] = $speed;
                }
            } else {
                foreach (['xferSpeed' => [10, 8796093022206], 'maxDowntime' => [5, 9000], 'abortAfter' => [60, 86400]] as $key => $range) {
                    if (isset($input[$key])) {
                        $v = filter_var($input[$key], FILTER_VALIDATE_INT);
                        if ($v === false || $v < $range[0] || $v > $range[1]) {
                            throw new \InvalidArgumentException('Invalid migration tuning.');
                        }
                        $body[$key] = $v;
                    }
                }
                foreach (['forceOffline', 'allowUnsafe', 'disableFilterCheck', 'autoPostCopy'] as $flag) {
                    if (isset($input[$flag])) {
                        if (! is_bool($input[$flag])) {
                            throw new \InvalidArgumentException('Expected boolean recovery flags.');
                        }
                        $body[$flag] = $input[$flag];
                    }
                }
            }
            $path = $base;
        } else {
            if ($state === null || $state === [] || $state === false) {
                throw new \RuntimeException('No active migration.', 409);
            }
            ServiceTools::confirm($input, 'migrationConfirmed');
            $path = $base . '/' . $verb;
            if ($kind === 'hybrid' && $verb === 'complete') {
                $body = ['ignoreHv' => false, 'ignoreDst' => false, 'mailChanges' => true];
                foreach (['ignoreHv', 'ignoreDst'] as $flag) {
                    if (isset($input[$flag])) {
                        if (! is_bool($input[$flag])) {
                            throw new \InvalidArgumentException('Expected boolean recovery flags.');
                        }
                        $body[$flag] = $input[$flag];
                    }
                }
            }
        }

        // Native VF validates the current sub-action state atomically. No fallback/bypass on conflict.
        return $this->tools->mutate($ctx, $action, 'post', $path, $body ?: null, $input, true);
    }
}
