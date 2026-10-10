<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Owned backup inventories and the optional safeguard-then-restore workflow.
 *
 * Global native endpoints must return the same serverId for every row. Restore
 * selections stay pinned while the safeguard runs; no implicit latest-point
 * selection or automatic retry is permitted.
 */
class BackupTools
{
    private ServiceTools $tools;

    /**
     * Use the verified service gateway for native reads and mutations.
     */
    public function __construct(ServiceTools $tools)
    {
        $this->tools = $tools;
    }

    /**
     * Page through a filtered global inventory and verify every returned owner.
     *
     * @param  string  $kind  Internal inventory name: backups or jobs.
     * @return array List of records whose serverId matches the verified VM.
     *
     * @throws \RuntimeException Foreign rows or incomplete/unavailable inventory.
     */
    public function rows(array $ctx, string $kind): array
    {
        $rows = [];
        for ($page = 1; $page <= 100; $page++) {
            [$code, $body] = $this->tools->request($ctx, 'get', '/backupManager/' . $kind . '?serverId=' . $ctx['serverId'] . '&results=200&page=' . $page);
            if ($code !== 200 || ! is_array($body['data'] ?? null)) {
                throw new \RuntimeException('Backup inventory unavailable.', 502);
            }
            $rows = array_merge($rows, ServiceTools::ownedRows($body['data'], $ctx['serverId']));
            if ($page >= (int) ($body['last_page'] ?? 1)) {
                return $rows;
            }
        }

        throw new \RuntimeException('Backup inventory is incomplete.', 502);
    }

    /**
     * Pin distinct disks to exact available chain IDs and timestamps.
     *
     * @param  array  $available  Owned recovery-point inventory.
     * @param  array  $selections  List of disk, chainId and timestamp choices.
     * @return array{disks: array, diskChains: array, diskUntils: array}
     *
     * @throws \RuntimeException A chosen recovery point is no longer available.
     */
    public static function restoreTargets(array $available, array $selections): array
    {
        if (! $selections) {
            throw new \InvalidArgumentException('Choose at least one recovery point.');
        }
        $body = ['disks' => [], 'diskChains' => [], 'diskUntils' => []];
        foreach ($selections as $choice) {
            if (! is_array($choice)) {
                throw new \InvalidArgumentException('Invalid recovery point.');
            }
            $disk = ServiceTools::positiveId($choice['disk'] ?? null);
            $chain = ServiceTools::positiveId($choice['chainId'] ?? null);
            $timestamp = ServiceTools::positiveId($choice['timestamp'] ?? null);
            if (in_array((string) $disk, $body['disks'], true)) {
                throw new \InvalidArgumentException('Duplicate restore disk.');
            }
            $found = false;
            foreach ($available as $point) {
                if ((int) ($point['disk'] ?? 0) === $disk && (int) ($point['chainId'] ?? 0) === $chain
                    && (int) ($point['timestamp'] ?? 0) === $timestamp) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                throw new \RuntimeException('Recovery point no longer available for this VM.', 409);
            }
            $body['disks'][] = (string) $disk;
            $body['diskChains'][(string) $disk] = $chain;
            $body['diskUntils'][(string) $disk] = $timestamp;
        }

        return $body;
    }

    /**
     * Reject backup/restore mutations while a native job is pending or running.
     */
    private function idle(array $ctx): void
    {
        foreach ($this->rows($ctx, 'jobs') as $job) {
            if (in_array($job['status'] ?? '', ['queued', 'running', 'pending'], true)) {
                throw new \RuntimeException('A backup or restore is already queued or running.', 409);
            }
        }
    }

    /**
     * List owned recovery data or submit a validated backup/restore operation.
     *
     * A safeguard reserves the VM and records restore selections before creating
     * the backup. Completion does not automatically restore: the user confirms
     * continuation separately.
     */
    public function execute(array $ctx, string $action, array $input, bool $admin): array
    {
        if ($action === 'backupList') {
            return ['data' => array_map(function ($row) {
                return array_intersect_key($row, array_flip(['disk', 'chainId', 'timestamp', 'date', 'backupType', 'size', 'virtualSize', 'encrypted']));
            }, $this->rows($ctx, 'backups'))];
        }
        if ($action === 'backupJobs') {
            return ['data' => array_map([self::class, 'presentJob'], $this->rows($ctx, 'jobs'))];
        }
        if ($action === 'backupJob') {
            $id = ServiceTools::positiveId($input['jobId'] ?? null);

            try {
                ServiceTools::select($this->rows($ctx, 'jobs'), $id, ['jobId']);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Job not found.', 404);
            }
            $job = $this->tools->read($ctx, '/backupManager/jobs/' . $id);
            ServiceTools::ownedJob($job, $ctx['serverId'], $id);

            return ['data' => self::presentJob($job)];
        }
        $this->idle($ctx);
        if ($action === 'backupCreate') {
            return $this->tools->mutate($ctx, $action, 'post', '/servers/' . $ctx['serverId'] . '/backupManager/backup', null, $input, $admin);
        }
        ServiceTools::confirm($input, 'overwriteConfirmed');
        if ($action === 'backupRestoreContinue') {
            return $this->continueRestore($ctx, $input, $admin);
        }
        $body = self::restoreTargets($this->rows($ctx, 'backups'), $input['selections'] ?? []);
        if (! empty($input['safeguard'])) {
            $op = Operation::begin($ctx, 'backupSafeguard', $body, Access::actor($admin), (string) ($input['requestId'] ?? ''));

            try {
                $ctx = $this->tools->revalidate($ctx, $admin);
                [$code, $response] = $this->tools->request($ctx, 'post', '/servers/' . $ctx['serverId'] . '/backupManager/backup');
                $state = Operation::resultState($code, $response);
                $job = $response['data']['jobId'] ?? null;
                if ($code >= 200 && $code < 300) {
                    ServiceTools::ownedRows([$response['data'] ?? []], $ctx['serverId']);
                    if (! $job) {
                        $state = 'unknown';
                    }
                }
                Operation::finish($op->id, $state, ['phase' => 'safeguard'], $job ? (int) $job : null);

                return ['operationId' => $op->id, 'state' => $state, 'message' => 'Wait for the safeguard job to complete, then confirm the pinned restore.'];
            } catch (\Throwable $e) {
                Operation::finish($op->id, 'unknown');

                throw new \RuntimeException('Safeguard outcome unknown. Restore was not submitted.', 502);
            }
        }

        return $this->tools->mutate($ctx, 'backupRestore', 'post', '/servers/' . $ctx['serverId'] . '/backupManager/restore', $body, $input, $admin);
    }

    /**
     * Continue pinned restore targets after a successful owned safeguard job.
     *
     * An atomic phase transition prevents duplicate continuation. Ownership is
     * rechecked after inventory reads; ambiguous submission keeps the reservation.
     */
    private function continueRestore(array $ctx, array $input, bool $admin): array
    {
        $op = Operation::get((string) ($input['operationId'] ?? ''), $ctx);
        if ($op->action !== 'backupSafeguard' || ! $op->job_id || ! in_array($op->state, ['queued', 'running', 'saved'], true)) {
            throw new \RuntimeException('No pending safeguard restore.', 409);
        }
        $job = $this->tools->read($ctx, '/backupManager/jobs/' . (int) $op->job_id);
        ServiceTools::ownedJob($job, $ctx['serverId'], (int) $op->job_id);
        if (($job['status'] ?? '') !== 'completed' || (int) ($job['exitCode'] ?? -1) !== 0) {
            throw new \RuntimeException('Safeguard has not completed successfully.', 409);
        }
        $body = json_decode($op->targets, true);
        $selections = [];
        foreach ($body['disks'] as $disk) {
            $selections[] = ['disk' => $disk, 'chainId' => $body['diskChains'][$disk], 'timestamp' => $body['diskUntils'][$disk]];
        }
        $body = self::restoreTargets($this->rows($ctx, 'backups'), $selections);
        // Atomic phase transition prevents simultaneous Continue requests from restoring twice.
        $changed = DB::table(Operation::TABLE)->where('id', $op->id)->where('action', 'backupSafeguard')
            ->whereIn('state', ['queued', 'running', 'saved'])->update(['action' => 'backupRestore', 'state' => 'unknown', 'job_id' => null]);
        if ($changed !== 1) {
            throw new \RuntimeException('Restore already submitted.', 409);
        }

        try {
            $ctx = $this->tools->revalidate($ctx, $admin);
            [$code, $response] = $this->tools->request($ctx, 'post', '/servers/' . $ctx['serverId'] . '/backupManager/restore', $body);
            $state = Operation::resultState($code, $response);
            $job = $response['data']['jobId'] ?? null;
            if ($code >= 200 && $code < 300) {
                ServiceTools::ownedRows([$response['data'] ?? []], $ctx['serverId']);
                if (! $job) {
                    $state = 'unknown';
                }
            }
            Operation::finish($op->id, $state, ['phase' => 'restore'], $job ? (int) $job : null);

            return ['operationId' => $op->id, 'state' => $state];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Restore outcome unknown; reconcile before retrying.', 502);
        }
    }

    /**
     * Expose only customer progress fields; omit backend paths and internal errors.
     */
    public static function presentJob(array $job): array
    {
        return array_intersect_key($job, array_flip(['jobId', 'type', 'status', 'progress', 'started', 'finished', 'duration']));
    }
}
