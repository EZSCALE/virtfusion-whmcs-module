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

    public const KEEP_DISK_TEXT = 'Choose "Keep my current disk size" if you might downgrade later — disks can grow but never shrink.';

    /** WHMCS template names used by the product upgrade/downgrade flow (classic and cart-based). */
    public const TEMPLATES = ['upgrade', 'upgradesummary', 'upgrade-configure', 'upgrade-summary'];

    public static function isUpgradeTemplate($template): bool
    {
        return is_string($template) && in_array($template, self::TEMPLATES, true);
    }

    /**
     * Resolve the VirtFusionDirect service an upgrade page is about, owned by the logged-in client.
     *
     * @param  array  $vars  ClientAreaFooterOutput variables.
     * @return object|null tblhosting row joined with its product, or null.
     */
    public static function service(array $vars, int $clientId): ?object
    {
        $id = $vars['id'] ?? $vars['serviceid'] ?? $_REQUEST['id'] ?? null;
        if (! is_scalar($id) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id) || $clientId <= 0) {
            return null;
        }
        $row = DB::table('tblhosting AS h')->join('tblproducts AS p', 'h.packageid', '=', 'p.id')
            ->where('h.id', (int) $id)->where('h.userid', $clientId)->where('p.servertype', 'VirtFusionDirect')
            ->first(['h.id', 'h.server', 'h.packageid']);

        return $row ?: null;
    }

    /**
     * Whether a product has the keep-disk configurable option attached.
     */
    public static function keepDiskOffered(int $productId, string $optionName): bool
    {
        return DB::table('tblproductconfiglinks AS l')->join('tblproductconfigoptions AS o', 'o.gid', '=', 'l.gid')
            ->where('l.pid', $productId)
            ->where(function ($q) use ($optionName) {
                $q->where('o.optionname', $optionName)->orWhere('o.optionname', 'like', $optionName . '|%')->orWhere('o.optionname', 'like', '%|' . $optionName);
            })->exists();
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
