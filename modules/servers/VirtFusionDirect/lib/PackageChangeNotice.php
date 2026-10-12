<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Customer notice on the WHMCS upgrade/downgrade pages of VirtFusionDirect services.
 *
 * Package changes queue memory/CPU/disk changes that apply at the next power
 * change; with the `restartOnPackageChange` provider setting the module restarts
 * a running server itself. The notice is a Bootstrap alert at the top of the
 * page plus a dismissible in-page modal shown once per service per browser.
 * All text is JSON-encoded with JSON_HEX_* flags and inserted with textContent.
 */
class PackageChangeNotice
{
    public const RESTART_TEXT = 'Your server will restart automatically to apply this plan change once it is processed (usually under a minute of downtime). Please save your work.';

    public const NO_RESTART_TEXT = 'Memory, CPU and disk changes from this plan change apply the next time the server restarts. Restart it yourself once the change is processed.';

    public const KEEP_DISK_TEXT = 'Want to be able to downgrade later? Before upgrading, set "Disk on upgrade" to "Keep my current disk size" under Upgrade/Downgrade Options for this service.';

    /**
     * WHMCS 9 plan-change templates: product upgrade `upgrade` -> `upgrade-configure`
     * -> cart `viewcart` -> `checkout`; configurable-option upgrades use `upgradesummary`.
     */
    public const TEMPLATES = ['upgrade', 'upgrade-configure', 'upgradesummary', 'viewcart', 'checkout'];

    /** Cart templates: only a service upgrade in the cart selects the service. */
    private const CART_TEMPLATES = ['viewcart', 'checkout'];

    public static function isUpgradeTemplate($template): bool
    {
        return is_string($template) && in_array($template, self::TEMPLATES, true);
    }

    /**
     * Service product upgrades in the cart, read defensively.
     *
     * Hook variables (`upgrades`: objects or arrays with type "service", service,
     * newProduct) win; otherwise the session cart's `upgrades` list, accepting
     * entity_id/serviceid/service_id and target_entity_id/productid/newproductid keys.
     *
     * @param  array  $vars  Hook variables.
     * @param  mixed  $cart  $_SESSION['cart'] or null.
     * @return array<int, array{serviceId: int, productId: int}>
     */
    public static function cartUpgrades(array $vars, $cart): array
    {
        $items = $vars['upgrades'] ?? null;
        if (! is_iterable($items) || is_string($items)) {
            $items = is_array($cart) ? ($cart['upgrades'] ?? null) : null;
        }
        if (! is_iterable($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) && ! is_object($item)) {
                continue;
            }
            $type = self::field($item, ['type', 'upgrade_type', 'entity_type']);
            if ($type !== 'service') {
                continue;
            }
            $service = self::field($item, ['service']);
            $product = self::field($item, ['newProduct', 'new_product']);
            $serviceId = self::field($service, ['id']) ?? self::field($item, ['entity_id', 'serviceid', 'service_id']);
            $productId = self::field($product, ['id']) ?? self::field($item, ['target_entity_id', 'productid', 'newproductid', 'product_id']);
            if (is_scalar($serviceId) && is_scalar($productId) && preg_match('/^[1-9][0-9]*$/D', (string) $serviceId) && preg_match('/^[1-9][0-9]*$/D', (string) $productId)) {
                $out[] = ['serviceId' => (int) $serviceId, 'productId' => (int) $productId];
            }
        }

        return $out;
    }

    /** First present key of an array or object (null when absent). */
    private static function field($source, array $keys)
    {
        foreach ($keys as $key) {
            if (is_array($source) && isset($source[$key])) {
                return $source[$key];
            }
            if (is_object($source) && isset($source->{$key})) {
                return $source->{$key};
            }
        }

        return null;
    }

    /**
     * Resolve the VirtFusionDirect service a plan-change page is about, owned by the logged-in client.
     *
     * upgrade: `id`; upgrade-configure: `$serviceToBeUpgraded->service->id` / `->id`
     * or the `serviceid` field; upgradesummary: `id`; viewcart/checkout: the
     * cart's service upgrades only.
     *
     * @param  array  $vars  ClientAreaFooterOutput variables.
     * @return object|null tblhosting row joined with its product, or null.
     */
    public static function service(array $vars, int $clientId): ?object
    {
        if ($clientId <= 0) {
            return null;
        }
        if (in_array($vars['templatefile'] ?? '', self::CART_TEMPLATES, true)) {
            $session = isset($_SESSION['cart']) ? $_SESSION['cart'] : null;
            $candidates = array_column(self::cartUpgrades($vars, $session), 'serviceId');
        } else {
            $upgrade = $vars['serviceToBeUpgraded'] ?? null;
            $candidates = [
                self::field(self::field($upgrade, ['service']), ['id']),
                self::field($upgrade, ['id']),
                $vars['serviceid'] ?? null,
                $vars['id'] ?? null,
                $_REQUEST['serviceid'] ?? null,
                $_REQUEST['id'] ?? null,
            ];
        }
        foreach ($candidates as $id) {
            if (! is_scalar($id) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
                continue;
            }
            $row = DB::table('tblhosting AS h')->join('tblproducts AS p', 'h.packageid', '=', 'p.id')
                ->where('h.id', (int) $id)->where('h.userid', $clientId)->where('p.servertype', 'VirtFusionDirect')
                ->first(['h.id', 'h.server', 'h.packageid']);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether a product has the keep-disk configurable option attached.
     */
    public static function keepDiskOffered(int $productId, string $optionName): bool
    {
        $names = DB::table('tblproductconfiglinks AS l')->join('tblproductconfigoptions AS o', 'o.gid', '=', 'l.gid')
            ->where('l.pid', $productId)->pluck('o.optionname');
        foreach ($names as $name) {
            if (ModuleFunctions::optionNameMatches((string) $name, $optionName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Markup + script for the notice. Safe to append to the footer output.
     */
    public static function html(int $serviceId, bool $keepDiskOffered, bool $restart): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $text = json_encode($restart ? self::RESTART_TEXT : self::NO_RESTART_TEXT, $flags);
        $keepDisk = json_encode($keepDiskOffered ? self::KEEP_DISK_TEXT : null, $flags);
        $key = json_encode('vfdPlanChangeNotice:' . $serviceId, $flags);

        return <<<HTML
<script>
(function () {
    var cfg = {text: {$text}, keepDisk: {$keepDisk}, key: {$key}};
    function lines(container) {
        var p = document.createElement("p");
        p.className = "mb-1";
        var strong = document.createElement("strong");
        strong.textContent = "Plan change: ";
        p.appendChild(strong);
        p.appendChild(document.createTextNode(cfg.text));
        container.appendChild(p);
        if (cfg.keepDisk) {
            var k = document.createElement("p");
            k.className = "mb-0";
            k.textContent = cfg.keepDisk;
            container.appendChild(k);
        }
    }
    function show() {
        var alert = document.createElement("div");
        alert.className = "alert alert-warning vf-plan-change-notice";
        alert.setAttribute("role", "alert");
        lines(alert);
        var host = document.querySelector(".main-content") || document.querySelector("#main-body .container") || document.body;
        host.insertBefore(alert, host.firstChild);

        var seen = false;
        try { seen = window.localStorage.getItem(cfg.key) === "1"; } catch (e) { seen = false; }
        if (seen) return;
        var backdrop = document.createElement("div");
        backdrop.setAttribute("style", "position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10050;display:flex;align-items:center;justify-content:center;padding:16px;");
        var dialog = document.createElement("div");
        dialog.setAttribute("role", "dialog");
        dialog.setAttribute("aria-modal", "true");
        dialog.setAttribute("style", "background:#fff;color:#222;max-width:520px;width:100%;border-radius:6px;padding:20px;box-shadow:0 10px 30px rgba(0,0,0,.3);");
        var title = document.createElement("h4");
        title.textContent = "Before you change your plan";
        dialog.appendChild(title);
        lines(dialog);
        var ok = document.createElement("button");
        ok.type = "button";
        ok.className = "btn btn-primary mt-3";
        ok.textContent = "I understand";
        function close() {
            try { window.localStorage.setItem(cfg.key, "1"); } catch (e) { /* storage unavailable */ }
            backdrop.remove();
        }
        ok.addEventListener("click", close);
        backdrop.addEventListener("click", function (e) { if (e.target === backdrop) close(); });
        document.addEventListener("keydown", function (e) { if (e.key === "Escape" && backdrop.parentNode) close(); });
        dialog.appendChild(ok);
        backdrop.appendChild(dialog);
        document.body.appendChild(backdrop);
        ok.focus();
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", show); else show();
})();
</script>
HTML;
    }
}
