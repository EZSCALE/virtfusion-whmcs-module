<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use Illuminate\Database\QueryException;
use WHMCS\Database\Capsule as DB;

/**
 * Persistent audit records and unique service/VM mutation reservations.
 *
 * Unknown results retain reservations indefinitely for reconciliation. Terminal
 * results release locks while keeping audit rows. These locks serialize module
 * requests only; they cannot serialize changes made directly in VirtFusion.
 */
class Operation
{
    const TABLE = 'mod_virtfusion_direct_operations';

    const LOCKS = 'mod_virtfusion_direct_locks';

    /**
     * Create additive operation and reservation tables with unique scope keys.
     */
    public static function schema(): void
    {
        if (! DB::schema()->hasTable(self::TABLE)) {
            DB::schema()->create(self::TABLE, function ($t) {
                $t->engine = 'InnoDB';
                $t->string('id', 32)->primary();
                $t->string('request_key', 64)->unique();
                $t->unsignedBigInteger('panel_id');
                $t->unsignedBigInteger('service_id')->index();
                $t->unsignedBigInteger('vm_id');
                $t->string('actor_type', 10);
                $t->unsignedBigInteger('actor_id');
                $t->string('action', 60);
                $t->text('targets');
                $t->string('state', 20);
                $t->unsignedBigInteger('job_id')->nullable();
                $t->text('result')->nullable();
                $t->timestamps();
            });
        }
        if (! DB::schema()->hasTable(self::LOCKS)) {
            DB::schema()->create(self::LOCKS, function ($t) {
                $t->engine = 'InnoDB';
                $t->string('scope', 100)->primary();
                $t->string('operation_id', 32);
            });
        }
    }

    /**
     * Atomically reserve a panel VM (or provisioning service) and record intent.
     *
     * @param  array  $ctx  Requires panelId, serviceId and serverId; zero VM means service scope.
     * @param  array  $targets  Pinned targets; secret fields are redacted before persistence.
     * @param  array{type: string, id: int}  $actor  Previously authenticated actor.
     * @param  string  $nonce  Exactly 32 lowercase hexadecimal characters from the request.
     * @return object Persisted operation row.
     *
     * @throws \RuntimeException Reused request ID or an unresolved scope reservation.
     */
    public static function begin(array $ctx, string $action, array $targets, array $actor, string $nonce): object
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new \InvalidArgumentException('A unique request ID is required.');
        }
        self::schema();
        $key = hash('sha256', implode(':', [$ctx['panelId'], $ctx['serviceId'], $ctx['serverId'], $actor['type'], $actor['id'], $nonce]));
        $scope = $ctx['panelId'] . ':vm:' . $ctx['serverId'];
        if ($ctx['serverId'] === 0) {
            $scope = $ctx['panelId'] . ':service:' . $ctx['serviceId'];
        }

        try {
            return DB::connection()->transaction(function () use ($ctx, $action, $targets, $actor, $key, $scope) {
                $existing = DB::table(self::TABLE)->where('request_key', $key)->first();
                if ($existing) {
                    if ($existing->action !== $action || $existing->targets !== json_encode(Log::redact($targets))) {
                        throw new \RuntimeException('Request ID reused for different targets.', 409);
                    }

                    throw new \RuntimeException('Operation already submitted: ' . $existing->id . ' (' . $existing->state . ').', 409);
                }
                $id = bin2hex(random_bytes(16));
                // Unique scope arbitrates simultaneous requests even from different services in a pack.
                DB::table(self::LOCKS)->insert(['scope' => $scope, 'operation_id' => $id]);
                DB::table(self::TABLE)->insert([
                    'id' => $id, 'request_key' => $key, 'panel_id' => $ctx['panelId'],
                    'service_id' => $ctx['serviceId'], 'vm_id' => $ctx['serverId'],
                    'actor_type' => $actor['type'], 'actor_id' => $actor['id'],
                    'action' => $action, 'targets' => json_encode(Log::redact($targets)), 'state' => 'saved',
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);

                return DB::table(self::TABLE)->where('id', $id)->first();
            });
        } catch (QueryException $e) {
            throw new \RuntimeException('Another operation is unresolved; reconcile it before retrying.', 409);
        }
    }

    /**
     * Classify native acceptance without assuming hypervisor completion.
     *
     * Timeouts, transport errors, redirects and server errors remain unknown.
     * Only explicit applied=true is immediate completion.
     *
     * @param  mixed  $body  Decoded native response or null.
     */
    public static function resultState(int $http, $body): string
    {
        if (in_array($http, [0, 408, 499], true) || $http >= 500 || ($http >= 300 && $http < 400)) {
            return 'unknown';
        }
        if ($http < 200 || $http >= 300) {
            return 'failed';
        }
        if (is_array($body) && array_key_exists('applied', $body)) {
            return $body['applied'] === true ? 'completed' : 'saved';
        }
        if ($http === 202 || isset($body['data']['queueId']) || isset($body['data']['jobId'])) {
            return 'queued';
        }

        return 'saved'; // Acceptance is not evidence of hypervisor completion.
    }

    /**
     * Persist a known operation result and release only terminal reservations.
     *
     * @param  string  $id  ID obtained from begin() or ownership-checked get().
     * @param  array  $result  Safe result metadata, redacted before persistence.
     * @param  int|null  $job  Owned native job ID for subsequent progress polling.
     */
    public static function finish(string $id, string $state, array $result = [], ?int $job = null): void
    {
        if (! in_array($state, ['saved', 'queued', 'running', 'completed', 'failed', 'unknown'], true)) {
            throw new \InvalidArgumentException('Invalid operation state.');
        }
        DB::table(self::TABLE)->where('id', $id)->update([
            'state' => $state, 'result' => json_encode(Log::redact($result)), 'job_id' => $job,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if (in_array($state, ['completed', 'failed'], true)) {
            DB::table(self::LOCKS)->where('operation_id', $id)->delete();
        }
    }

    /**
     * Read an operation only within its authorized panel, service and VM.
     *
     * Callers must resolve/authenticate the context first.
     *
     * @throws \RuntimeException The record is absent or belongs to another context.
     */
    public static function get(string $id, array $ctx): object
    {
        self::schema();
        $row = DB::table(self::TABLE)->where('id', $id)->where('panel_id', $ctx['panelId'])
            ->where('service_id', $ctx['serviceId'])->where('vm_id', $ctx['serverId'])->first();
        if (! $row) {
            throw new \RuntimeException('Operation not found.', 404);
        }

        return $row;
    }
}
