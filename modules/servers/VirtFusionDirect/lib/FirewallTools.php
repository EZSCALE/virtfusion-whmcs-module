<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Customer rule delegation and protected provider-policy enforcement.
 *
 * Ownership requires a stored matching fingerprint and creation/delegation
 * evidence. Existing native rules default to locked. Provider edits remain
 * possible outside module locks, so submission rechecks the current policy.
 */
class FirewallTools
{
    const TABLE = 'mod_virtfusion_direct_rules';

    private ServiceTools $tools;

    /**
     * Use the verified service gateway for policy reads and tracked mutations.
     */
    public function __construct(ServiceTools $tools)
    {
        $this->tools = $tools;
    }

    /**
     * Create additive rule-delegation storage without adopting existing rules.
     */
    public static function schema(): void
    {
        if (! DB::schema()->hasTable(self::TABLE)) {
            DB::schema()->create(self::TABLE, function ($t) {
                $t->engine = 'InnoDB';
                $t->string('scope', 120)->primary();
                $t->unsignedBigInteger('panel_id');
                $t->unsignedBigInteger('service_id');
                $t->string('interface_ref', 30);
                $t->unsignedBigInteger('rule_id');
                $t->string('fingerprint', 64);
                $t->string('origin', 20);
                $t->unsignedBigInteger('actor_id');
                $t->timestamps();
            });
        }
    }

    /**
     * Hash stable policy fields so native edits revoke stale delegation.
     *
     * VirtFusion expands IPv6 addresses in responses. Use its padded form for
     * both submitted and returned addresses, retaining the CIDR and every
     * address bit. Existing fingerprints of native expanded rules stay valid.
     *
     * @param  array  $rule  Native rule or validated candidate policy fields.
     */
    public static function fingerprint(array $rule): string
    {
        $fields = ['protocol', 'direction', 'action', 'ipType', 'priority', 'sourcePort', 'destinationPort', 'sourceAddress', 'destinationAddress'];
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $rule[$field] ?? null;
            if (in_array($field, ['sourceAddress', 'destinationAddress'], true) && is_string($values[$field])) {
                $parts = explode('/', $values[$field]);
                $packed = @inet_pton($parts[0]);
                if ($packed !== false && strlen($packed) === 16 && count($parts) <= 2) {
                    $values[$field] = implode(':', str_split(bin2hex($packed), 4));
                    if (isset($parts[1])) {
                        $values[$field] .= '/' . $parts[1];
                    }
                }
            }
        }

        return hash('sha256', json_encode($values));
    }

    /**
     * Reject customer allows in a locked deny direction and IP family.
     *
     * The overlap check is deliberately conservative and handles both directions.
     * It does not claim to validate installed hypervisor priority translation.
     */
    public static function protectedPolicy(array $candidate, array $locked): void
    {
        if (($candidate['action'] ?? '') !== 'allow') {
            return;
        }
        foreach ($locked as $rule) {
            if (($rule['action'] ?? '') === 'deny'
                && ($rule['enabled'] ?? true) && (($candidate['direction'] ?? '') === 'both' || ($rule['direction'] ?? '') === 'both' || ($candidate['direction'] ?? '') === ($rule['direction'] ?? ''))
                && (int) ($candidate['ipType'] ?? 0) === (int) ($rule['ipType'] ?? 0)) {
                throw new \RuntimeException('Provider policy reserves this direction and IP family. Request provider approval for an exception.', 403);
            }
        }
    }

    /**
     * Validate customer rule fields and fix priority to 500 with apply enabled.
     *
     * Unknown flags, replacement rulesets and caller priorities are rejected.
     *
     * @throws \InvalidArgumentException Invalid ports, address family or policy fields.
     */
    public static function rule(array $input): array
    {
        $fields = ['protocol', 'direction', 'action', 'ipType', 'sourcePort', 'destinationPort', 'sourceAddress', 'destinationAddress'];
        if (array_diff(array_keys($input), $fields)) {
            throw new \InvalidArgumentException('Unsupported firewall field.');
        }
        $rule = array_intersect_key($input, array_flip($fields));
        foreach (['protocol' => ['all', 'tcp', 'udp', 'icmp', 'sctp'], 'direction' => ['in', 'out'], 'action' => ['allow', 'deny']] as $field => $values) {
            if (! in_array($rule[$field] ?? null, $values, true)) {
                throw new \InvalidArgumentException('Invalid firewall ' . $field . '.');
            }
        }
        if (! in_array($rule['ipType'] ?? null, [4, 6, '4', '6'], true)) {
            throw new \InvalidArgumentException('Choose IPv4 or IPv6.');
        }
        $rule['ipType'] = (int) $rule['ipType'];
        foreach (['sourcePort', 'destinationPort'] as $field) {
            if (isset($rule[$field]) && $rule[$field] !== '') {
                if (! is_string($rule[$field]) || ! preg_match('/^([0-9]{1,5})(?:-([0-9]{1,5}))?$/D', $rule[$field], $m)
                    || (int) $m[1] < 1 || (int) $m[1] > 65535 || (isset($m[2]) && ((int) $m[2] < (int) $m[1] || (int) $m[2] > 65535))) {
                    throw new \InvalidArgumentException('Invalid port range.');
                }
            } else {
                $rule[$field] = null;
            }
        }
        foreach (['sourceAddress', 'destinationAddress'] as $field) {
            if (isset($rule[$field]) && $rule[$field] !== '') {
                if (! is_string($rule[$field])) {
                    throw new \InvalidArgumentException('Invalid address.');
                }
                $parts = explode('/', $rule[$field]);
                $flag = $rule['ipType'] === 4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
                if (count($parts) > 2 || ! filter_var($parts[0], FILTER_VALIDATE_IP, $flag)
                    || (isset($parts[1]) && (! ctype_digit($parts[1]) || (int) $parts[1] > ($rule['ipType'] === 4 ? 32 : 128)))) {
                    throw new \InvalidArgumentException('Invalid IP or CIDR.');
                }
            } else {
                $rule[$field] = null;
            }
        }
        $rule['priority'] = 500;
        $rule['apply'] = true;

        return $rule;
    }

    /**
     * Require a stored creation/delegation record and an unchanged fingerprint.
     */
    private function owned(array $ctx, string $interface, array $rule): bool
    {
        $row = DB::table(self::TABLE)->where('panel_id', $ctx['panelId'])->where('service_id', $ctx['serviceId'])
            ->where('interface_ref', $interface)->where('rule_id', $rule['id'])->first();

        return $row && in_array($row->origin, ['created', 'delegated'], true) && hash_equals($row->fingerprint, self::fingerprint($rule));
    }

    /**
     * Recheck customer policy after reserving the VM and before submitting a write.
     *
     * Native/provider edits can occur outside the module reservation. Never trust
     * the earlier rule fingerprint, enforcement state or approved preset list.
     *
     * @throws \RuntimeException When delegation or protected policy has changed.
     */
    public function revalidate(array $ctx, string $action, array $input, array $body, array $settings): void
    {
        if (! $settings['firewall'] || ! $settings['firewallVerified']) {
            throw new \RuntimeException('Customer firewall changes are disabled.', 403);
        }
        $nic = ServiceTools::select($this->tools->read($ctx, '/servers/' . $ctx['serverId'] . '/interfaces'), $input['interfaceRef'] ?? '', ['tag']);
        $ref = (string) $nic['tag'];
        if (! preg_match('/^[0-9]{10}$/D', $ref)) {
            throw new \RuntimeException('Invalid interface tag.', 403);
        }
        $base = '/servers/' . $ctx['serverId'] . '/firewall/' . $ref;
        // The legacy firewall-info route only accepts primary/secondary, not tags.
        if (($nic['firewallEnabled'] ?? null) !== true) {
            throw new \RuntimeException('Provider firewall enforcement changed.', 403);
        }
        $rules = $this->tools->read($ctx, $base . '/rules');
        $locked = array_values(array_filter($rules, fn ($rule) => ! $this->owned($ctx, $ref, $rule)));
        if ($action === 'firewallAdd') {
            self::protectedPolicy($body, $locked);
        } elseif ($action === 'firewallPreset') {
            $id = ServiceTools::positiveId($input['rulesetId'] ?? null);
            if (! in_array($id, $settings['approvedPresets'], true)) {
                throw new \RuntimeException('Preset approval changed.', 403);
            }
            $preset = $this->tools->read($ctx, '/firewall/rulesets/' . $id);
            if (! is_array($preset['rules'] ?? null)) {
                throw new \RuntimeException('Preset policy unavailable.', 409);
            }
            foreach ($preset['rules'] as $rule) {
                self::protectedPolicy($rule, $locked);
            }
        } elseif (in_array($action, ['firewallDelete', 'firewallEnableRule'], true)) {
            $rule = ServiceTools::select($rules, ServiceTools::positiveId($input['ruleId'] ?? null), ['id']);
            if (! $this->owned($ctx, $ref, $rule)) {
                throw new \RuntimeException('Rule delegation changed.', 403);
            }
            if (! empty($body['enabled'])) {
                self::protectedPolicy($rule, $locked);
            }
        }
    }

    /**
     * Manage only a validated service interface and its rule inventory.
     *
     * Customers cannot disable enforcement or claim duplicates returned as existing
     * rules. Admin delegation is explicit and audited; provider policy stays locked
     * unless that delegation is recorded.
     */
    public function execute(array $ctx, string $action, array $input, array $settings, bool $admin): array
    {
        self::schema();
        $nics = $this->tools->read($ctx, '/servers/' . $ctx['serverId'] . '/interfaces');
        $nic = ServiceTools::select($nics, $input['interfaceRef'] ?? '', ['tag']);
        $ref = (string) $nic['tag'];
        if (! preg_match('/^[0-9]{10}$/D', $ref)) {
            throw new \RuntimeException('Invalid interface tag.', 403);
        }
        $base = '/servers/' . $ctx['serverId'] . '/firewall/' . $ref;
        $rules = $this->tools->read($ctx, $base . '/rules');
        $locked = array_values(array_filter($rules, fn ($r) => ! $this->owned($ctx, $ref, $r)));
        if (in_array($action, ['firewallDelegate', 'firewallLock'], true)) {
            Access::admin('Perform Module Command Operations');
            ServiceTools::confirm($input, 'delegationConfirmed');
            $id = ServiceTools::positiveId($input['ruleId'] ?? null);
            $rule = ServiceTools::select($rules, $id, ['id']);
            $op = Operation::begin($ctx, $action, ['interfaceRef' => $ref, 'ruleId' => $id, 'fingerprint' => self::fingerprint($rule)], Access::actor(true), (string) ($input['requestId'] ?? ''));
            DB::table(self::TABLE)->updateOrInsert(['scope' => $ctx['panelId'] . ':' . $ctx['serviceId'] . ':' . $ref . ':' . $id], [
                'panel_id' => $ctx['panelId'], 'service_id' => $ctx['serviceId'], 'interface_ref' => $ref, 'rule_id' => $id,
                'fingerprint' => self::fingerprint($rule), 'origin' => $action === 'firewallDelegate' ? 'delegated' : 'locked',
                'actor_id' => Access::actor(true)['id'], 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Operation::finish($op->id, 'completed', ['delegated' => $action === 'firewallDelegate']);
            Log::insert($action, ['actor' => Access::actor(true), 'service' => $ctx['serviceId'], 'rule' => $id], ['interface' => $ref]);

            return ['operationId' => $op->id, 'state' => 'completed'];
        }
        if ($action === 'firewallList') {
            $view = [];
            foreach ($rules as $rule) {
                $owned = $this->owned($ctx, $ref, $rule);
                if ($owned || $admin || $settings['showProviderRules']) {
                    $rule['locked'] = ! $owned;
                    $view[] = $rule;
                }
            }

            return ['data' => $view, 'policyNotice' => 'Provider rules are enforced and locked. Changes cannot override them.', 'presets' => $settings['approvedPresets']];
        }
        if (! $admin && ! $settings['firewallVerified']) {
            throw new \RuntimeException('Hypervisor firewall priority and alternate customer controls have not been verified.', 403);
        }
        if (! $admin) {
            if (($nic['firewallEnabled'] ?? null) !== true) {
                throw new \RuntimeException('Provider firewall enforcement must be enabled before customer changes.', 403);
            }
        }
        $body = [];
        $method = ServiceTools::ACTIONS[$action][1];
        $path = $base . '/rules';
        if ($action === 'firewallAdd') {
            $body = self::rule($input['rule'] ?? []);
            if (! $admin) {
                self::protectedPolicy($body, $locked);
            }
        } elseif ($action === 'firewallPreset') {
            $id = ServiceTools::positiveId($input['rulesetId'] ?? null);
            if (! $admin && ! in_array($id, $settings['approvedPresets'], true)) {
                throw new \RuntimeException('Preset is not approved.', 403);
            }
            $preset = $this->tools->read($ctx, '/firewall/rulesets/' . $id);
            if (! is_array($preset['rules'] ?? null)) {
                throw new \RuntimeException('Preset policy unavailable.', 409);
            }
            if (! $admin) {
                foreach ($preset['rules'] as $rule) {
                    self::protectedPolicy($rule, $locked);
                }
            }
            $body = ['rulesetId' => $id, 'apply' => true];
            $path = $base . '/rulesets';
        } elseif ($action === 'firewallApply') {
            $path = $base . '/apply';
        } else {
            $id = ServiceTools::positiveId($input['ruleId'] ?? null);
            $rule = ServiceTools::select($rules, $id, ['id']);
            if (! $admin && ! $this->owned($ctx, $ref, $rule)) {
                throw new \RuntimeException('Provider rule is locked.', 403);
            }
            if ($action === 'firewallEnableRule') {
                if (! in_array($input['enabled'] ?? null, [true, false, 0, 1, '0', '1'], true)) {
                    throw new \InvalidArgumentException('Enabled must be boolean.');
                }
                $enabled = in_array($input['enabled'], [true, 1, '1'], true);
                if (! $admin && $enabled) {
                    self::protectedPolicy($rule, $locked);
                }
                $body = ['enabled' => $enabled, 'apply' => true];
            } else {
                $body = ['apply' => true];
            }
            $path .= '/' . $id;
        }
        $result = $this->tools->mutate($ctx, $action, $method, $path, $body, $input, $admin);
        // Only a single new matching rule can be claimed; pre-existing IDs never qualify.
        if (! $admin && $action === 'firewallAdd' && $result['http'] === 201 && $result['created'] && $result['targetRuleId']) {
            $after = $this->tools->read($ctx, $base . '/rules');
            $beforeIds = array_map(fn ($r) => (int) $r['id'], $rules);
            $new = array_values(array_filter($after, fn ($r) => (int) $r['id'] === (int) $result['targetRuleId'] && ! in_array((int) $r['id'], $beforeIds, true) && self::fingerprint($r) === self::fingerprint($body)));
            if (count($new) === 1) {
                $rule = $new[0];
                DB::table(self::TABLE)->insertOrIgnore([
                    'scope' => $ctx['panelId'] . ':' . $ctx['serviceId'] . ':' . $ref . ':' . $rule['id'],
                    'panel_id' => $ctx['panelId'], 'service_id' => $ctx['serviceId'], 'interface_ref' => $ref,
                    'rule_id' => $rule['id'], 'fingerprint' => self::fingerprint($rule), 'origin' => 'created',
                    'actor_id' => Access::actor($admin)['id'], 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        if (! $admin && $action === 'firewallPreset' && $result['http'] === 200) {
            $beforeIds = array_map(fn ($rule) => (int) $rule['id'], $rules);
            $after = $this->tools->read($ctx, $base . '/rules');
            foreach ($result['addedRules'] as $added) {
                $id = ServiceTools::positiveId($added['id'] ?? null);
                if (in_array($id, $beforeIds, true)) {
                    continue;
                }
                $rule = ServiceTools::select($after, $id, ['id']);
                if (self::fingerprint($rule) !== self::fingerprint($added)) {
                    continue;
                }
                DB::table(self::TABLE)->insertOrIgnore([
                    'scope' => $ctx['panelId'] . ':' . $ctx['serviceId'] . ':' . $ref . ':' . $id,
                    'panel_id' => $ctx['panelId'], 'service_id' => $ctx['serviceId'], 'interface_ref' => $ref,
                    'rule_id' => $id, 'fingerprint' => self::fingerprint($rule), 'origin' => 'created',
                    'actor_id' => Access::actor(false)['id'], 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return $result;
    }
}
