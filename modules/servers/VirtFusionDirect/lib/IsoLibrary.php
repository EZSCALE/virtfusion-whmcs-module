<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Module-owned private ISO entries with a per-panel, per-user entry limit.
 *
 * Pending imports consume quota under an account row lock. The limit counts
 * entries, not physical storage. Native download isolation must be verified
 * separately; syntax checks here cannot prevent DNS rebinding or private egress.
 */
class IsoLibrary
{
    const TABLE = 'mod_virtfusion_direct_isos';

    const QUOTAS = 'mod_virtfusion_direct_iso_accounts';

    private ServiceTools $tools;

    /**
     * Use the verified service gateway; no ISO URL is downloaded by WHMCS.
     */
    public function __construct(ServiceTools $tools)
    {
        $this->tools = $tools;
    }

    /**
     * Create additive ISO ownership and per-account quota-lock tables.
     */
    public static function schema(): void
    {
        if (! DB::schema()->hasTable(self::TABLE)) {
            DB::schema()->create(self::TABLE, function ($t) {
                $t->engine = 'InnoDB';
                $t->string('id', 32)->primary();
                $t->unsignedBigInteger('panel_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('iso_id')->nullable();
                $t->string('state', 20);
                $t->string('name', 150);
                $t->string('operation_id', 32);
                $t->timestamps();
                $t->index(['panel_id', 'user_id']);
            });
        }
        if (! DB::schema()->hasTable(self::QUOTAS)) {
            DB::schema()->create(self::QUOTAS, function ($t) {
                $t->engine = 'InnoDB';
                $t->string('scope', 70)->primary();
            });
        }
    }

    /**
     * Validate restricted HTTPS .iso syntax, not download-network safety.
     *
     * Native private-network and DNS-rebinding defenses are a separate mandatory
     * provider prerequisite. This function alone must never enable imports.
     *
     * @param  mixed  $url  Untrusted URL input.
     *
     * @throws \InvalidArgumentException Credentials, IP literal, query or invalid syntax.
     */
    public static function validateUrl($url): string
    {
        if (! is_string($url) || strlen($url) > 2000 || ! preg_match('~^https://[a-zA-Z0-9.-]+(?:/[_a-zA-Z0-9./-]+)\.iso$~D', $url)) {
            throw new \InvalidArgumentException('Use a public HTTPS .iso URL without credentials, port, query or redirect.');
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.') || str_contains($host, '..')) {
            throw new \InvalidArgumentException('A public domain is required.');
        }

        return $url; // Native hypervisor network isolation is an additional mandatory prerequisite.
    }

    /**
     * Check enabled global availability or enabled assignment to the native user.
     *
     * Native user-assignment pivots serialize enabled as 1, while catalogue
     * entries use booleans. Accept only explicit true/1 representations for the
     * pivot; arbitrary truthy strings, floats and non-binary integers fail closed.
     */
    public static function eligible(array $iso, int $user): bool
    {
        if (($iso['enabled'] ?? null) !== true) {
            return false;
        }
        if (($iso['global'] ?? false) === true) {
            return true;
        }
        foreach ($iso['users'] ?? [] as $assigned) {
            if (is_array($assigned) && ServiceTools::matchesId($assigned['id'] ?? null, $user)
                && in_array($assigned['enabled'] ?? null, [true, 1, '1'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find an enabled ISO assigned to the service owner, independent of API privilege.
     *
     * Admin-token mount success is not proof of customer access.
     *
     * @throws \RuntimeException Missing entry or unauthorized assignment.
     */
    public static function assigned(ServiceTools $tools, array $ctx, int $id): array
    {
        // Do not infer access from admin-token mount success: the API bypasses assignment for admins.
        $rows = $tools->catalogue($ctx, '/media/iso');
        $iso = ServiceTools::select($rows, $id, ['id']);
        if (! self::eligible($iso, (int) $ctx['user']['id'])) {
            throw new \RuntimeException('ISO is not assigned to the service owner.', 403);
        }

        return $iso;
    }

    /**
     * Require a module-owned active binding and exclusive non-global assignment.
     */
    private function owned(array $ctx, int $isoId): array
    {
        self::schema();
        $binding = DB::table(self::TABLE)->where('panel_id', $ctx['panelId'])->where('user_id', $ctx['user']['id'])
            ->where('iso_id', $isoId)->where('state', 'active')->first();
        if (! $binding) {
            throw new \RuntimeException('Private entry is not owned by this customer.', 403);
        }
        $iso = self::assigned($this->tools, $ctx, $isoId);
        if (($iso['global'] ?? true) !== false || count($iso['users'] ?? []) !== 1) {
            throw new \RuntimeException('Private ISO assignments changed; provider reconciliation required.', 409);
        }

        return $iso;
    }

    /**
     * Observe a partial import without repeating creation or assignment.
     *
     * A persisted native ID proves a fresh 201 creation was observed before the
     * interruption. Do not discover/adopt entries by name after a lost response.
     * The unique module filename, requested name and exclusive enabled assignment
     * must all still match. Activation and reservation release commit together;
     * missing, changed or unassigned entries continue consuming account quota.
     *
     * @param  array  $ctx  Currently authorized panel/service/VM and native owner.
     * @param  object  $operation  Record obtained through Operation::get($id, $ctx).
     */
    public function reconcileCreation(array $ctx, object $operation): void
    {
        self::schema();
        $binding = DB::table(self::TABLE)->where('panel_id', $ctx['panelId'])->where('user_id', $ctx['user']['id'])
            ->where('operation_id', $operation->id)->where('state', 'pending')->first();
        if (! $binding || ! $binding->iso_id || $operation->action !== 'isoLibraryCreate') {
            return;
        }
        $id = ServiceTools::positiveId($binding->iso_id);
        $iso = self::assigned($this->tools, $ctx, $id);
        $targets = json_decode($operation->targets, true);
        if (($iso['global'] ?? true) !== false || count($iso['users'] ?? []) !== 1
            || ($iso['filename'] ?? null) !== 'vfd-' . $binding->id
            || ($iso['name'] ?? null) !== $binding->name || ($targets['name'] ?? null) !== $binding->name) {
            return;
        }
        DB::connection()->transaction(function () use ($binding, $operation, $id) {
            $current = DB::table(Operation::TABLE)->where('id', $operation->id)->lockForUpdate()->first();
            if (! $current || ! in_array($current->state, ['saved', 'queued', 'running', 'unknown'], true)) {
                return; // An administrator may have resolved this record during the native read.
            }
            $updated = DB::table(self::TABLE)->where('id', $binding->id)->where('panel_id', $binding->panel_id)
                ->where('user_id', $binding->user_id)->where('operation_id', $operation->id)
                ->where('iso_id', $id)->where('state', 'pending')->update(['state' => 'active', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($updated === 1) {
                Operation::finish($operation->id, 'completed', ['verified' => true, 'isoId' => $id]);
            }
        });
    }

    /**
     * Validate a private entry name and reject control characters.
     *
     * @param  mixed  $name  Untrusted display name.
     */
    private static function name($name): string
    {
        if (! is_string($name) || strlen(trim($name)) < 3 || strlen($name) > 150 || preg_match('/[\x00-\x1f]/', $name)) {
            throw new \InvalidArgumentException('ISO name must be 3–150 characters.');
        }

        return trim($name);
    }

    /**
     * List assigned media or manage module-owned private ISO entries.
     *
     * Creation reserves the account quota before native writes; partial creation
     * remains pending. Rename/delete recheck exclusive assignment. Delete never
     * forces mounted media removal or deletes hypervisor cache files.
     */
    public function execute(array $ctx, string $action, array $input, array $settings, bool $admin): array
    {
        self::schema();
        if ($action === 'isoLibraryList') {
            $rows = $this->tools->catalogue($ctx, '/media/iso');
            $owned = DB::table(self::TABLE)->where('panel_id', $ctx['panelId'])->where('user_id', $ctx['user']['id'])->where('state', 'active')->pluck('iso_id')->all();
            $out = [];
            foreach ($rows as $iso) {
                if (self::eligible($iso, (int) $ctx['user']['id'])) {
                    $out[] = ['id' => $iso['id'], 'name' => $iso['name'], 'global' => $iso['global'] ?? false,
                        'owned' => in_array((int) $iso['id'], array_map('intval', $owned), true) && ($iso['global'] ?? true) === false];
                }
            }

            return ['data' => $out, 'limit' => $settings['isoLimit']];
        }
        if ($action === 'isoLibraryCreate') {
            if (! $settings['urlIsolationVerified']) {
                throw new \RuntimeException('Native download isolation is unverified.', 403);
            }
            $name = self::name($input['name'] ?? null);
            $url = self::validateUrl($input['url'] ?? null);
            $op = Operation::begin($ctx, $action, ['name' => $name, 'urlHash' => hash('sha256', $url)], Access::actor($admin), (string) ($input['requestId'] ?? ''));
            $localId = bin2hex(random_bytes(16));

            try {
                $scope = $ctx['panelId'] . ':' . $ctx['user']['id'];
                DB::table(self::QUOTAS)->insertOrIgnore(['scope' => $scope]);
                DB::connection()->transaction(function () use ($ctx, $settings, $op, $name, $localId, $scope) {
                    DB::table(self::QUOTAS)->where('scope', $scope)->lockForUpdate()->first();
                    $count = DB::table(self::TABLE)->where('panel_id', $ctx['panelId'])->where('user_id', $ctx['user']['id'])
                        ->where('state', '!=', 'removed')->count();
                    if ($count >= $settings['isoLimit']) {
                        throw new \RuntimeException('Private ISO entry limit reached.', 409);
                    }
                    DB::table(self::TABLE)->insert([
                        'id' => $localId, 'panel_id' => $ctx['panelId'], 'user_id' => $ctx['user']['id'], 'state' => 'pending',
                        'name' => $name, 'operation_id' => $op->id, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                });
            } catch (\Throwable $e) {
                Operation::finish($op->id, 'failed');

                throw $e;
            }

            try {
                $before = $this->tools->catalogue($ctx, '/media/iso');
                $existingIds = array_map(fn ($iso) => (int) ($iso['id'] ?? 0), $before);
                $ctx = $this->tools->revalidate($ctx, $admin);
                [$code, $response] = $this->tools->request($ctx, 'post', '/media/iso', [
                    'name' => $name, 'url' => $url, 'filename' => 'vfd-' . $localId,
                    'arch' => 0, 'enabled' => true, 'global' => false, 'download' => false,
                ]);
                $state = Operation::resultState($code, $response);
                if (! in_array($code, [200, 201], true)) {
                    if ($state === 'failed') {
                        DB::table(self::TABLE)->where('id', $localId)->update(['state' => 'removed']);
                    }
                    Operation::finish($op->id, $state);

                    return ['operationId' => $op->id, 'state' => $state];
                }
                $id = ServiceTools::positiveId($response['data']['id'] ?? null);
                // Never reassign an existing entry because an admin-token POST
                // returned its ID. Only a fresh 201 is creation evidence.
                if ($code !== 201 || in_array($id, $existingIds, true)) {
                    throw new \RuntimeException('Private ISO creation is not proven.');
                }
                DB::table(self::TABLE)->where('id', $localId)->update(['iso_id' => $id]);
                $created = ServiceTools::select($this->tools->catalogue($ctx, '/media/iso'), $id, ['id']);
                if (($created['global'] ?? true) !== false || ! empty($created['users'])) {
                    throw new \RuntimeException('Private ISO is already assigned or global.');
                }
                $ctx = $this->tools->revalidate($ctx, $admin);
                [$code] = $this->tools->request($ctx, 'put', '/media/iso/' . $id . '/users', ['users' => [['id' => (int) $ctx['user']['id'], 'enabled' => true]]]);
                if (! in_array($code, [200, 204], true)) {
                    throw new \RuntimeException('Private assignment incomplete.');
                }
                $iso = self::assigned($this->tools, $ctx, $id);
                if (($iso['global'] ?? true) !== false || count($iso['users'] ?? []) !== 1) {
                    throw new \RuntimeException('Private assignment verification failed.');
                }
                DB::table(self::TABLE)->where('id', $localId)->update(['state' => 'active']);
                Operation::finish($op->id, 'completed', ['isoId' => $id]);

                return ['operationId' => $op->id, 'state' => 'completed', 'isoId' => $id];
            } catch (\Throwable $e) {
                Operation::finish($op->id, 'unknown');

                throw new \RuntimeException('ISO import unresolved; entry remains reserved.', 502);
            }
        }
        $id = ServiceTools::positiveId($input['isoId'] ?? null);
        $this->owned($ctx, $id);
        if ($action === 'isoLibraryRename') {
            return $this->tools->mutate($ctx, $action, 'put', '/media/iso/' . $id, ['name' => self::name($input['name'] ?? null)], $input, $admin);
        }
        ServiceTools::confirm($input, 'deleteConfirmed');
        $result = $this->tools->mutate($ctx, $action, 'delete', '/media/iso/' . $id, ['confirm' => true, 'force' => false], $input, $admin);
        if (in_array($result['http'], [200, 204], true)) {
            DB::table(self::TABLE)->where('panel_id', $ctx['panelId'])->where('user_id', $ctx['user']['id'])->where('iso_id', $id)->update(['state' => 'removed']);
            Operation::finish($result['operationId'], 'completed');
            $result['state'] = 'completed';
        }

        return $result;
    }
}
