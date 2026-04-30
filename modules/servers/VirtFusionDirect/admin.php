<?php

require dirname(__DIR__, 3) . '/init.php';

/**
 * Admin-facing AJAX API endpoint.
 *
 * MIRRORS client.php STRUCTURE
 * ----------------------------
 * Same switch-on-$action dispatch pattern, same JSON response shape, same
 * "output + break" convention. The only substantive difference is the auth
 * gate at the top: $vf->adminOnly() instead of $vf->isAuthenticated().
 *
 * WHY SEPARATE FROM client.php
 * ----------------------------
 * A single file with a per-action admin/client switch would risk one bug
 * (e.g. forgetting to call adminOnly on a new admin-only action) giving a
 * client authenticated but without admin privileges access to admin data.
 * Having two physical entry points means the admin auth gate is enforced
 * at file scope — any action routed here already went through adminOnly().
 *
 * ADMIN-LEVEL AUTH ONLY — NO SERVICE OWNERSHIP CHECK
 * --------------------------------------------------
 * An admin is allowed to view/operate on any service, so we don't call
 * validateUserOwnsService() here. If you add an action that needs finer-
 * grained auth (e.g. restrict to the admin role that owns the product
 * group), compose the additional check inside the case branch.
 *
 * SAME-ORIGIN / POST GATES STILL APPLY TO MUTATIONS
 * -------------------------------------------------
 * Admins are still subject to requirePost + requireSameOrigin on writes —
 * admin sessions are just as CSRF-vulnerable as client sessions. See the
 * rdnsReconcile case for the pattern.
 */

use WHMCS\Module\Server\VirtFusionDirect\Database;
use WHMCS\Module\Server\VirtFusionDirect\Log;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config as PowerDnsConfig;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\PtrManager;
use WHMCS\Module\Server\VirtFusionDirect\ServerResource;
use WHMCS\Module\Server\VirtFusionDirect\StockControl;

$vf = new Module;

try {

    $vf->adminOnly();

    switch ($vf->validateAction(true)) {

        /**
         * Get server information.
         */
        case 'serverData':

            $serviceID = $vf->validateServiceID(true);

            $whmcsService = Database::getWhmcsService($serviceID);

            if (! $whmcsService) {
                $vf->output(['success' => false, 'errors' => 'Service not found.'], true, true, 404);
                break;
            }

            if (in_array($whmcsService->domainstatus, ['Pending', 'Terminated', 'Cancelled', 'Fraud'], true)) {
                $vf->output(['success' => false, 'errors' => 'Server is not Active, Suspended or Completed. Not fetching remote data.'], true, true, 400);
                break;
            }

            $data = $vf->fetchServerData($serviceID);

            if (! $data) {
                $vf->output(['success' => false, 'errors' => 'No data returned from VirtFusion.'], true, true, 502);
                break;
            }

            $vf->updateWhmcsServiceParamsOnServerObject($serviceID, $data);
            $vf->output(['success' => true, 'data' => (new ServerResource)->process($data)], true, true, 200);
            break;

            /**
             * Impersonate server owner.
             */
        case 'impersonateServerOwner':

            $serviceID = $vf->validateServiceID(true);

            $service = Database::getSystemService($serviceID);
            if (! $service) {
                $vf->output(['success' => false, 'errors' => 'Service not found'], true, true, 404);
                break;
            }

            $whmcsService = Database::getWhmcsService($serviceID);
            if (! $whmcsService) {
                $vf->output(['success' => false, 'errors' => 'WHMCS service not found'], true, true, 404);
                break;
            }

            $cp = $vf->getCP($whmcsService->server);
            if (! $cp) {
                $vf->output(['success' => false, 'errors' => 'Control server not found'], true, true, 500);
                break;
            }

            $request = $vf->initCurl($cp['token']);
            $data = $request->get($cp['url'] . '/users/' . (int) $whmcsService->userid . '/byExtRelation');

            if ($request->getRequestInfo('http_code') === 200) {
                $vf->output(['success' => true, 'url' => $cp['base_url'], 'user' => json_decode($data, true)['data']], true, true, 200);
                break;
            }

            $vf->output(['success' => false, 'errors' => 'Unable to fetch user data'], true, true, 502);
            break;

            // =================================================================
            // Reverse DNS (PowerDNS)
            // =================================================================

            /**
             * Admin-side PTR status for a service. Same shape as client-side rdnsList but
             * accessible without being the service owner (admin-only guard at top).
             */
        case 'rdnsStatus':

            $serviceID = $vf->validateServiceID(true);

            if (! PowerDnsConfig::isEnabled()) {
                $vf->output(['success' => true, 'data' => ['enabled' => false, 'ips' => []]], true, true, 200);
                break;
            }

            $serverData = $vf->fetchServerData($serviceID);
            if (! $serverData) {
                $vf->output(['success' => false, 'errors' => 'Unable to retrieve server data'], true, true, 502);
                break;
            }

            $ptrs = (new PtrManager)->listPtrs($serverData);
            $vf->output(['success' => true, 'data' => ['enabled' => true, 'ips' => $ptrs]], true, true, 200);
            break;

            /**
             * Trigger PTR reconciliation for a single service. Additive-only by default
             * (missing PTRs are created with the current hostname); pass force=1 to also
             * reset PTRs that differ from the server hostname.
             */
        case 'rdnsReconcile':

            // Mutating action — enforce POST + same-origin even though the session is admin-authenticated.
            $vf->requirePost();
            $vf->requireSameOrigin();

            $serviceID = $vf->validateServiceID(true);

            if (! PowerDnsConfig::isEnabled()) {
                $vf->output(['success' => false, 'errors' => 'Reverse DNS is not enabled'], true, true, 400);
                break;
            }

            $force = ! empty($_POST['force']);
            $summary = (new PtrManager)->reconcile($serviceID, $force);
            Log::insert(
                'rdnsReconcile:ok',
                ['serviceID' => $serviceID, 'force' => $force],
                $summary,
            );
            $vf->output(['success' => true, 'data' => $summary], true, true, 200);
            break;

            // =================================================================
            // Stock Control
            // =================================================================

            /**
             * Force a full stock-quantity recalculation across every VirtFusionDirect
             * product that has WHMCS stock control enabled. Same logic as the 2-hour
             * AfterCronJob safety-net hook and the post-provision / post-termination
             * event hooks in hooks.php, but on-demand. Cache TTLs still govern freshness
             * of the underlying VirtFusion API reads — run a separate cache bust first
             * if the admin needs to bypass the 120 s grpres:{id} TTL.
             *
             * Usable by admins via POST; returns a JSON map of productId => qty (or null
             * where the product was skipped / left untouched by the orchestrator).
             */
        case 'stockRecalculate':

            $vf->requirePost();
            $vf->requireSameOrigin();

            $results = (new StockControl)->recalculateAll();

            // Log a compact summary instead of the full map — the admin client still
            // gets the detailed per-product map in the JSON response, but the module
            // log stays readable even on stores with hundreds of VirtFusion products.
            $summary = ['total' => count($results), 'updated' => 0, 'zeroed' => 0, 'skipped' => 0];
            foreach ($results as $qty) {
                if ($qty === null) {
                    $summary['skipped']++;
                } elseif ((int) $qty === 0) {
                    $summary['zeroed']++;
                } else {
                    $summary['updated']++;
                }
            }
            Log::insert('stockRecalculate:ok', [], $summary);

            $vf->output(['success' => true, 'data' => $results], true, true, 200);
            break;

        default:
            $vf->output(['success' => false, 'errors' => 'invalid action'], true, true, 400);
    }

} catch (Exception $e) {
    Log::insert('admin.php', [], $e->getMessage());
    $vf->output(['success' => false, 'errors' => 'An unexpected error occurred'], true, true, 500);
}
