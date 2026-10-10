<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/** Explicit, dry-run-first import. Source bindings are never changed/deleted. */
class PackMigration
{
    public static function preview(array $input): array
    {
        Access::admin('Configure Products/Services');
        $source = $input['sourceModule'] ?? '';
        if (! is_string($source) || ! preg_match('/^VirtFusion[A-Za-z]+$/D', $source) || $source === 'VirtFusionDirect') {
            throw new \InvalidArgumentException('Specify the official resource-pack module directory name.');
        }
        $products = $input['products'] ?? [];
        if (! is_array($products) || ! $products) {
            throw new \InvalidArgumentException('Explicit product mappings are required.');
        }
        $changes = [];
        foreach ($products as $mapping) {
            $id = ServiceTools::positiveId($mapping['productId'] ?? null);
            $profile = ServiceTools::positiveId($mapping['profileId'] ?? null);
            $delay = filter_var($mapping['deletionDelay'] ?? null, FILTER_VALIDATE_INT);
            $behavior = $mapping['terminationBehavior'] ?? null;
            if ($delay === false || $delay < 0 || ! in_array($behavior, ['Suspend Servers', 'Delete Servers'], true)) {
                throw new \InvalidArgumentException('Map the original deletion delay and termination behavior explicitly.');
            }
            $product = DB::table('tblproducts')->where('id', $id)->where('servertype', $source)->first();
            if (! $product) {
                throw new \RuntimeException('Source product not found.', 404);
            }
            $services = DB::table('tblhosting')->where('packageid', $id)->get();
            $bindings = [];
            foreach ($services as $service) {
                $row = DB::schema()->hasTable('mod_virtfusion_res_pks') ? DB::table('mod_virtfusion_res_pks')->where('service_id', $service->id)->first() : null;
                if (! $row || empty($row->pack_id)) {
                    throw new \RuntimeException('Service lacks a source binding; reconcile before migrating.', 409);
                }
                $panel = ServiceTools::positiveId($mapping['panelId'] ?? null);
                $cp = (new Module)->getCP($panel);
                $oldCp = DB::table('tblservers')->where('id', (int) $service->server)->first();
                if (! $cp || ! $oldCp || strcasecmp((string) $oldCp->hostname, (string) parse_url($cp['base_url'], PHP_URL_HOST)) !== 0) {
                    throw new \RuntimeException('Migration must use an explicitly configured Direct panel on the same VF host.', 409);
                }
                if (ResourcePack::binding((int) $service->id) || Database::getSystemService((int) $service->id)) {
                    throw new \RuntimeException('Destination service is already bound.', 409);
                }
                $module = new Module;
                $request = $module->initCurl($cp['token']);
                $raw = $request->get($cp['url'] . '/users/' . $module->extRelationRef($service->userid) . '/byExtRelation' . $module->extRelationQuery());
                $userId = json_decode((string) $raw, true)['data']['id'] ?? null;
                if ((int) $request->getRequestInfo('http_code') !== 200 || ! $userId) {
                    throw new \RuntimeException('Destination user verification failed.', 403);
                }
                $request = $module->initCurl($cp['token']);
                $raw = $request->get($cp['url'] . '/selfService/report/byUserExtRelationId/' . $module->extRelationRef($service->userid) . $module->extRelationQuery());
                $usage = json_decode((string) $raw, true)['data'] ?? [];
                if ((int) $request->getRequestInfo('http_code') !== 200 || ! ResourcePack::containsPack($usage, (int) $row->pack_id)) {
                    throw new \RuntimeException('Source pack is not assigned to the verified user.', 403);
                }
                $bindings[] = ['serviceId' => (int) $service->id, 'packId' => (int) $row->pack_id, 'panelId' => $panel,
                    'userId' => (int) $userId, 'profileId' => $profile, 'status' => $service->domainstatus, 'oldPanel' => (int) $service->server];
            }
            $snapshot = array_intersect_key((array) $product, array_flip(array_merge(['id', 'servertype'], array_map(fn ($n) => 'configoption' . $n, range(1, 12)))));
            $changes[] = ['productId' => $id, 'sourceSettings' => $snapshot, 'targetSettings' => [
                'servertype' => 'VirtFusionDirect', 'configoption1' => '1', 'configoption2' => '1', 'configoption3' => '1',
                'configoption4' => '2', 'configoption5' => '0', 'configoption6' => '100', 'configoption7' => '10', 'configoption8' => '0',
                'configoption9' => 'Resource Pack', 'configoption10' => (string) $profile, 'configoption11' => (string) $delay, 'configoption12' => $behavior,
            ], 'bindings' => $bindings];
        }
        $digest = hash('sha256', json_encode($changes));
        $_SESSION['vfd_pack_migration'] = ['digest' => $digest, 'created' => time(), 'actor' => Access::actor(true)['id']];

        return ['dryRun' => true, 'digest' => $digest, 'changes' => $changes];
    }

    public static function apply(array $input): array
    {
        Access::admin('Configure Products/Services');
        Access::admin('Perform Module Command Operations');
        ServiceTools::confirm($input, 'migrationConfirmed');
        $saved = $_SESSION['vfd_pack_migration'] ?? [];
        if (! isset($saved['digest']) || time() - ($saved['created'] ?? 0) > 900 || ($saved['actor'] ?? 0) !== Access::actor(true)['id']
            || ! hash_equals($saved['digest'], (string) ($input['digest'] ?? ''))) {
            throw new \RuntimeException('Run and review a fresh migration dry run first.', 409);
        }
        $preview = self::preview($input);
        if (! hash_equals($saved['digest'], $preview['digest'])) {
            throw new \RuntimeException('Source changed; review the new dry run.', 409);
        }
        ResourcePack::schema();
        if (! DB::schema()->hasTable('mod_virtfusion_direct_migrations')) {
            DB::schema()->create('mod_virtfusion_direct_migrations', function ($t) {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->string('digest', 64);
                $t->unsignedBigInteger('actor_id');
                $t->text('snapshot');
                $t->timestamps();
            });
        }
        DB::connection()->transaction(function () use ($preview) {
            DB::table('mod_virtfusion_direct_migrations')->insert(['digest' => $preview['digest'], 'actor_id' => Access::actor(true)['id'], 'snapshot' => json_encode($preview), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            foreach ($preview['changes'] as $change) {
                $current = DB::table('tblproducts')->where('id', $change['productId'])->lockForUpdate()->first();
                foreach ($change['sourceSettings'] as $key => $value) {
                    if ((string) ($current->{$key} ?? '') !== (string) $value) {
                        throw new \RuntimeException('Product settings changed.', 409);
                    }
                }
                foreach ($change['bindings'] as $binding) {
                    $currentService = DB::table('tblhosting')->where('id', $binding['serviceId'])->lockForUpdate()->first();
                    if ((int) $currentService->server !== $binding['oldPanel'] || $currentService->domainstatus !== $binding['status']) {
                        throw new \RuntimeException('Service changed; repeat dry run.', 409);
                    }
                    DB::table(ResourcePack::TABLE)->insert([
                        'service_id' => $binding['serviceId'], 'pack_id' => $binding['packId'], 'panel_id' => $binding['panelId'],
                        'user_id' => $binding['userId'], 'profile_id' => $binding['profileId'],
                        'state' => $binding['status'] === 'Active' ? 'active' : ($binding['status'] === 'Suspended' ? 'suspended' : (in_array($binding['status'], ['Terminated', 'Cancelled', 'Fraud'], true) ? 'terminated' : 'inactive')),
                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    DB::table('tblhosting')->where('id', $binding['serviceId'])->update(['server' => $binding['panelId']]);
                }
                DB::table('tblproducts')->where('id', $change['productId'])->update($change['targetSettings']);
            }
        });
        Log::insert('PackMigration', ['actor' => Access::actor(true)], $preview);
        unset($_SESSION['vfd_pack_migration']);

        return ['applied' => true, 'digest' => $preview['digest']];
    }
}
