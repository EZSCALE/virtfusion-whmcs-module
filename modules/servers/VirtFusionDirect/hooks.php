<?php

/**
 * WHMCS hooks for the VirtFusion module.
 *
 * HOW HOOKS WORK IN WHMCS
 * -----------------------
 * add_hook('EventName', $priority, $callback) registers $callback to fire on
 * the named event. WHMCS discovers hook files by walking modules/servers/*
 * /hooks.php and modules/addons/* /hooks.php on every page load, then invokes
 * every registered hook for the current event.
 *
 * Hooks run IN-REQUEST — there's no queue or background worker. Anything
 * expensive in a hook (like an external API call) blocks the user's page
 * load. For that reason we only do:
 *   - Fast in-process work (building DOM snippets, validating session state)
 *   - Scheduled work on DailyCronJob where "in-request" means the cron worker,
 *     not a user session
 *
 * HOOKS REGISTERED HERE
 * ---------------------

 *   DailyCronJob                — PowerDNS reconciliation across all services
 *   AfterCronJob                — Every-2-hour stock recalculation safety net
 *   AfterModuleCreate           — Stock refresh + order auto-accept after a VPS provisions
 *   AfterModuleTerminate        — Stock refresh after a VPS is destroyed
 *   ClientAreaPageCart          — Lazy per-product stock refresh during the order flow
 *   ShoppingCartValidateCheckout — blocks checkout until OS is selected
 *   ClientAreaFooterOutput      — injects the OS/SSH-key gallery on order form
 *
 * FAILURE SEMANTICS
 * -----------------
 * Every hook wraps its body in try/catch and silently absorbs any exception.
 * A hook that throws would potentially break the entire WHMCS request for
 * all users, not just this module — so we log and swallow, preferring
 * degraded functionality over site-wide breakage.
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VirtFusionDirect\Cache;
use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Database;
use WHMCS\Module\Server\VirtFusionDirect\Log;
use WHMCS\Module\Server\VirtFusionDirect\Module;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config as PowerDnsConfig;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\PtrManager;
use WHMCS\Module\Server\VirtFusionDirect\StockControl;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Daily PowerDNS reconciliation.
 *
 * Walks every managed service and creates any missing PTRs (never overwrites existing
 * values — cron is additive-only). Requires the VirtFusion DNS addon to be activated
 * and enabled; otherwise short-circuits immediately.
 *
 * All error handling lives inside reconcileAll(); this wrapper just logs any escape
 * without disturbing the rest of the daily cron run.
 */
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        if (PowerDnsConfig::isEnabled()) {
            (new PtrManager)->reconcileAll();
        }
    } catch (Throwable $e) {
        Log::insert('PowerDns:DailyCronJob', [], $e->getMessage());
    }
});

/**
 * Every-~2-hour stock recalculation safety net.
 *
 * Events (AfterModuleCreate/Terminate) cover every capacity change driven
 * through WHMCS. But an operator can also create/destroy VMs directly in the
 * VirtFusion panel — no WHMCS hook fires for that, so stock qty would drift
 * until the next cart-page visit or the next event-driven refresh. This hook
 * closes that blind spot.
 *
 * AfterCronJob fires on every main WHMCS cron invocation (typically every
 * 5 minutes). Cache::get on the rate-limit key means the hook is effectively
 * free on the 99% of invocations where no recalc is due — one cache read,
 * return. The actual recalc only runs when the key has expired.
 *
 * Interval: 2 hours. Tunable via the STOCK_CRON_INTERVAL_SECONDS constant
 * below. Short enough that out-of-band VirtFusion panel changes surface the
 * same business day; long enough that the storefront isn't writing
 * tblproducts.qty every five minutes.
 *
 * FAIL-SAFE: StockControl::recalculateAll() returns a map of productId =>
 * qty|null, where null means the orchestrator left qty UNTOUCHED (transient
 * API failure, missing CP, etc.). Our catch here only fires on truly unexpected
 * errors that escape the orchestrator itself.
 */
const STOCK_CRON_INTERVAL_SECONDS = 2 * 3600;   // 2 hours

add_hook('AfterCronJob', 5, function ($vars) {
    try {
        $rateKey = 'stockrefresh:cron';
        if (Cache::get($rateKey) !== null) {
            return;
        }
        Cache::set($rateKey, 1, STOCK_CRON_INTERVAL_SECONDS);

        (new StockControl)->recalculateAll();
    } catch (Throwable $e) {
        Log::insert('StockControl:AfterCronJob', [], $e->getMessage());
    }
});

/**
 * Post-provision: auto-accept the originating order and refresh stock.
 *
 * Fires after every successful VirtFusion CreateAccount. Two responsibilities,
 * independent try/catch blocks so a failure in one doesn't short-circuit the other:
 *
 *   1. AUTO-ACCEPT — if the service's parent order is still 'Pending' (admin
 *      hasn't manually accepted yet), call WHMCS's AcceptOrder API with
 *      autosetup=false (we already provisioned, don't re-trigger CreateAccount).
 *      This closes the loop for installs that rely on pending-order workflows
 *      for non-VF products but want VF provisions to auto-advance.
 *
 *   2. STOCK REFRESH — a new VM just consumed memory/cpu/disk/IPv4 on the
 *      target hypervisor group. Bust the grpres:{id} cache and recalculate
 *      every stock-controlled product. A shared 30 s rate-limit key prevents
 *      a burst of 10 parallel provisions from triggering 10 full recalcs.
 *
 * Filtering by moduletype='VirtFusionDirect' keeps this hook harmless for
 * unrelated products that happen to share the WHMCS install.
 */
add_hook('AfterModuleCreate', 1, function ($vars) {
    if (($vars['params']['moduletype'] ?? '') !== 'VirtFusionDirect') {
        return;
    }

    // Part 1: auto-accept the originating order if still Pending.
    try {
        $serviceId = (int) ($vars['params']['serviceid'] ?? 0);
        if ($serviceId > 0) {
            $hosting = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            $orderId = $hosting ? (int) ($hosting->orderid ?? 0) : 0;
            if ($orderId > 0) {
                $order = Capsule::table('tblorders')->where('id', $orderId)->first();
                if ($order && strcasecmp((string) $order->status, 'Pending') === 0) {
                    // WHMCS 9 regression guard: WHMCS 9's batch order-acceptance
                    // loop terminates once the order leaves Pending status.
                    // Calling AcceptOrder after the first sibling completes
                    // therefore short-circuits provisioning of the rest of the
                    // order's services — they end up Active in tblhosting with
                    // no mod_virtfusion_direct row and no server in VirtFusion.
                    // Defer the AcceptOrder until every VF service in this
                    // order has provisioned; the hook fires once per service,
                    // so the last one to complete will see no unprovisioned
                    // siblings and trigger the accept. WHMCS 8 wasn't affected
                    // (its loop ignored order status mid-batch), but deferring
                    // there is harmless — same end state, just later timing.
                    $unprovisionedSiblings = Capsule::table('tblhosting AS h')
                        ->join('tblproducts AS p', 'h.packageid', '=', 'p.id')
                        ->leftJoin('mod_virtfusion_direct AS m', 'h.id', '=', 'm.service_id')
                        ->where('h.orderid', $orderId)
                        ->where('h.id', '!=', $serviceId)
                        ->where('p.servertype', 'VirtFusionDirect')
                        ->where('h.domainstatus', 'Pending')
                        ->whereNull('m.server_id')
                        ->count();

                    if ($unprovisionedSiblings > 0) {
                        Log::insert(
                            'AutoAcceptOrder:deferred',
                            ['orderid' => $orderId, 'serviceid' => $serviceId, 'unprovisioned_siblings' => $unprovisionedSiblings],
                            'Order has more VirtFusionDirect services awaiting provisioning; AcceptOrder will fire after the last one',
                        );
                    } else {
                        $resp = localAPI('AcceptOrder', [
                            'orderid' => $orderId,
                            'autosetup' => false,   // already provisioned; don't re-run CreateAccount
                            'sendemail' => true,
                        ]);
                        Log::insert(
                            'AutoAcceptOrder',
                            ['orderid' => $orderId, 'serviceid' => $serviceId],
                            $resp,
                        );
                    }
                }
            }
        }
    } catch (Throwable $e) {
        Log::insert('AutoAcceptOrder:fail', ['serviceID' => $vars['params']['serviceid'] ?? null], $e->getMessage());
    }

    // Part 2: refresh stock (capacity just decreased).
    try {
        if (Cache::get('stockrefresh:event') === null) {
            Cache::set('stockrefresh:event', 1, 30);

            $groupId = (int) ($vars['params']['configoption1'] ?? 0);
            if ($groupId > 0) {
                Cache::forget('grpres:' . $groupId);
            }

            (new StockControl)->recalculateAll();
        }
    } catch (Throwable $e) {
        Log::insert('StockControl:AfterModuleCreate', ['serviceID' => $vars['params']['serviceid'] ?? null], $e->getMessage());
    }
});

/**
 * Post-termination stock refresh.
 *
 * A destroyed VM just freed memory/cpu/disk/IPv4 on the target hypervisor group.
 * Refresh so the storefront reflects the restored capacity immediately. Shares
 * the 30 s rate-limit key with AfterModuleCreate — a provision-then-terminate in
 * quick succession only triggers one full recalc.
 */
add_hook('AfterModuleTerminate', 1, function ($vars) {
    if (($vars['params']['moduletype'] ?? '') !== 'VirtFusionDirect') {
        return;
    }

    try {
        if (Cache::get('stockrefresh:event') !== null) {
            return;
        }
        Cache::set('stockrefresh:event', 1, 30);

        $groupId = (int) ($vars['params']['configoption1'] ?? 0);
        if ($groupId > 0) {
            Cache::forget('grpres:' . $groupId);
        }

        (new StockControl)->recalculateAll();
    } catch (Throwable $e) {
        Log::insert('StockControl:AfterModuleTerminate', ['serviceID' => $vars['params']['serviceid'] ?? null], $e->getMessage());
    }
});

/**
 * Lazy stock refresh on order-flow cart pages.
 *
 * Keeps "hot" products fresh between daily cron runs without a polling loop: when a
 * customer lands on a cart page for a specific product, we opportunistically recalculate
 * that product's qty. If the upstream grpres:{id} cache is warm (populated in the last
 * 120 s by an earlier view or the daily cron), recalculateForProduct does no HTTP calls
 * and just re-writes the same qty — effectively free.
 *
 * WHY ClientAreaPageCart (not ClientAreaPageProductDetails)
 * ---------------------------------------------------------
 * ClientAreaPageProductDetails fires on the My Services → product-details view for an
 * EXISTING service, which is the wrong place — the stock number only matters during
 * pre-order. ClientAreaPageCart fires on every cart/order page (product browse, config,
 * checkout) and WHMCS consults tblproducts.qty on each of those, so this is where a
 * fresh number pays off.
 *
 * RATE LIMIT
 * ----------
 * 60 s per product (stockrefresh:{pid}). Short enough that a busy product refreshes
 * near-continuously across viewers; long enough that two customers arriving within the
 * same second don't trigger two identical DB UPDATEs. The pid check below filters this
 * hook to only fire when a specific product is known — generic cart pages (templatefile=
 * "cart.tpl") pass no pid and are no-ops.
 */
add_hook('ClientAreaPageCart', 1, function ($vars) {
    try {
        $productId = (int) ($vars['pid'] ?? $vars['productid'] ?? ($vars['productinfo']['pid'] ?? 0));
        if ($productId <= 0) {
            return null;
        }

        $rateKey = 'stockrefresh:' . $productId;
        if (Cache::get($rateKey) !== null) {
            return null;
        }
        Cache::set($rateKey, 1, 60);

        (new StockControl)->recalculateForProduct($productId);
    } catch (Throwable $e) {
        Log::insert('StockControl:ClientAreaPageCart', ['pid' => $vars['pid'] ?? null], $e->getMessage());
    }

    return null;
});

/**
 * Shopping Cart Validation Hook
 *
 * Validates that an operating system has been selected before checkout
 * for all VirtFusion products in the cart.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    $errors = [];

    try {
        if (! isset($_SESSION['cart']['products']) || ! is_array($_SESSION['cart']['products'])) {
            return $errors;
        }

        foreach ($_SESSION['cart']['products'] as $key => $product) {
            $pid = $product['pid'] ?? null;
            if (! $pid) {
                continue;
            }

            $dbProduct = Capsule::table('tblproducts')
                ->where('id', $pid)
                ->where('servertype', 'VirtFusionDirect')
                ->first();

            if (! $dbProduct) {
                continue;
            }

            // Check if Initial Operating System custom field has a value
            if (isset($product['customfields']) && is_array($product['customfields'])) {
                $osSelected = false;
                $customFields = Capsule::table('tblcustomfields')
                    ->where('relid', $pid)
                    ->where('type', 'product')
                    ->get();

                foreach ($customFields as $field) {
                    if (strtolower(str_replace(' ', '', $field->fieldname)) === 'initialoperatingsystem') {
                        $fieldValue = $product['customfields'][$field->id] ?? '';
                        if (! empty($fieldValue) && is_numeric($fieldValue)) {
                            $osSelected = true;
                        }
                        break;
                    }
                }

                if (! $osSelected) {
                    $errors[] = 'Please select an Operating System for your VPS order.';
                }
            }
        }
    } catch (Exception $e) {
        // Don't block checkout on internal errors
    }

    return $errors;
});


/**
 * Client Area Footer Output Hook - Shopping Cart OS & SSH Key Wizard
 *
 * Replaces hidden text custom fields for OS templates and SSH keys with a
 * polished two-step wizard (Family pills -> Version cards) and an
 * authentication method panel (Password vs SSH Key).
 *
 * All UI logic lives in external files:
 *   - templates/css/cart-wizard.css  (scoped styles)
 *   - templates/js/cart-wizard.js    (reads window.vfCartConfig)
 *   - templates/js/keygen.js         (Ed25519 key generation)
 */
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (! isset($vars['productinfo']['module']) || $vars['productinfo']['module'] !== 'VirtFusionDirect') {
        return null;
    }

    try {
        $cs = new ConfigureService;

        $templates_data = $cs->fetchTemplates(
            $cs->fetchPackageByDbId($vars['productinfo']['pid']) ?? $cs->fetchPackageId($vars['productinfo']['name']),
        );

        if (empty($templates_data)) {
            return null;
        }

        $vfServer = Capsule::table('tblservers')
            ->where('type', 'VirtFusionDirect')
            ->where('disabled', 0)
            ->first();
        $baseUrl = $vfServer ? rtrim('https://' . $vfServer->hostname, '/') : '';

        $osGroups = [];
        foreach (($templates_data['data'] ?? []) as $osCategory) {
            if (! is_array($osCategory)) {
                continue;
            }
            $catName = trim($osCategory['name'] ?? 'Other');
            $catIcon = $osCategory['icon'] ?? null;
            $templates = [];
            foreach (($osCategory['templates'] ?? []) as $tpl) {
                if (! is_array($tpl) || ! isset($tpl['id'])) {
                    continue;
                }
                $label = trim(($tpl['name'] ?? '') . ' ' . ($tpl['version'] ?? '') . ' ' . ($tpl['variant'] ?? ''));
                $templates[] = [
                    'id' => $tpl['id'],
                    'name' => htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                ];
            }
            if (empty($templates)) {
                continue;
            }
            if (count($templates) <= 1) {
                $found = false;
                foreach ($osGroups as &$g) {
                    if ($g['label'] === 'Other') {
                        $g['templates'] = array_merge($g['templates'], $templates);
                        $found = true;
                        break;
                    }
                }
                unset($g);
                if (! $found) {
                    $osGroups[] = ['label' => 'Other', 'icon' => null, 'templates' => $templates];
                }
            } else {
                $osGroups[] = [
                    'label' => htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'),
                    'icon' => $catIcon,
                    'templates' => $templates,
                ];
            }
        }
        usort($osGroups, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        $sshKeysOptions = [];
        if (isset($vars['loggedinuser']) && $vars['loggedinuser']) {
            $sshKeysData = $cs->getUserSshKeys($vars['loggedinuser']);
            if ($sshKeysData && isset($sshKeysData['data'])) {
                $sshKeysOptions = array_values(array_filter(array_map(function ($k) {
                    if ($k['enabled'] === false) {
                        return null;
                    }

                    return ['id' => $k['id'], 'name' => htmlspecialchars($k['name'], ENT_QUOTES, 'UTF-8')];
                }, $sshKeysData['data'])));
            }
        }

        $osFieldId = null;
        $sshFieldId = null;
        foreach ($vars['customfields'] ?? [] as $cf) {
            if ($cf['textid'] === 'initialoperatingsystem') {
                $osFieldId = $cf['id'];
            }
            if ($cf['textid'] === 'initialsshkey') {
                $sshFieldId = $cf['id'];
            }
        }
        if ($osFieldId === null) {
            return null;
        }

        $systemUrl = rtrim(Database::getSystemUrl(), '/') . '/';
        $jsonFlags = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $v = '20260429';

        $configJson = json_encode([
            'osGroups' => $osGroups,
            'sshKeys' => $sshKeysOptions,
            'baseUrl' => $baseUrl,
            'osFieldId' => (int) $osFieldId,
            'sshFieldId' => $sshFieldId !== null ? (int) $sshFieldId : null,
        ], $jsonFlags);

        $cssUrl = htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8') . 'modules/servers/VirtFusionDirect/templates/css/cart-wizard.css?v=' . $v;
        $keygenUrl = htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8') . 'modules/servers/VirtFusionDirect/templates/js/keygen.js?v=' . $v;
        $wizardUrl = htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8') . 'modules/servers/VirtFusionDirect/templates/js/cart-wizard.js?v=' . $v;

        return <<<HTML
    <link rel="stylesheet" href="{$cssUrl}">
    <script src="{$keygenUrl}"></script>
    <script>window.vfCartConfig = {$configJson};</script>
    <script src="{$wizardUrl}"></script>
HTML;
    } catch (\Throwable $e) {
        Log::insert('ClientAreaFooterOutput:CartUI', [], $e->getMessage());

        return null;
    }
});

/**
 * Inject a "On This Page" jump-link group into the client area sidebar
 * when the customer is viewing a VirtFusionDirect product details page.
 *
 * Replaces the previous inline horizontal nav strip — sidebar placement
 * keeps the links visible while scrolling the long product details page.
 *
 * Static rendering: every known section anchor is added regardless of
 * whether its panel is visible. JS (vfBuildSectionNav in module.js) walks
 * the rendered links post-load and hides the parent <li> for any target
 * panel that isn't visible (Resources/VNC/Self-Service when their data
 * hasn't loaded; rDNS when PowerDNS isn't enabled at the template level).
 *
 * Filtered to productdetails for VF services so we don't pollute the
 * sidebar on unrelated pages or non-VF service detail pages.
 */
