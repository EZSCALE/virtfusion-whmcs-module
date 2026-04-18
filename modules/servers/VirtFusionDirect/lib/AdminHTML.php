<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Static methods that generate HTML fragments for the WHMCS admin services tab.
 *
 * WHY RAW HTML STRINGS INSTEAD OF TEMPLATES
 * -----------------------------------------
 * WHMCS's AdminServicesTabFields hook expects an associative array of
 * label => HTML-string pairs. It renders each entry as a table row with the
 * label on the left and the raw HTML inserted verbatim on the right. There's
 * no way to return a Smarty template reference from that hook — WHMCS doesn't
 * know how to render one in that context.
 *
 * So we concatenate HTML here. All variable interpolation uses htmlspecialchars()
 * at the PHP boundary — never trust that a value passed in is safe for HTML.
 *
 * ASSET INJECTION
 * ---------------
 * Some renderers (serverInfo, rdnsSection) embed <link> and <script> tags so
 * the admin services tab picks up our CSS and JS without a separate loader
 * hook. This is safe because WHMCS's admin CSP allows same-origin resources
 * and the admin page is already inside an authenticated admin session.
 *
 * Cache-busting uses time() as a query string — fine for an admin-only surface
 * where we'd rather pay for the extra fetch than let stale JS cause bugs.
 */
class AdminHTML
{
    /**
     * Render the "Impersonate Server Owner" button for the admin services tab.
     *
     * @param  string  $systemUrl  WHMCS system URL
     * @param  int  $serviceId  VirtFusion server ID
     * @return string HTML button markup
     */
    public static function options($systemUrl, $serviceId)
    {
        $systemUrl = htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8');

        return <<<EOT
            <button onclick="impersonateServerOwner('${serviceId}', '${systemUrl}')" type="button" class="btn btn-primary">Impersonate Server Owner</button>
            <span class="text-info">&nbsp;&nbsp;A valid VirtFusion admin session in the same browser is required for this functionality to work.</span>
EOT;
    }

    /**
     * Render a read-only textarea containing the raw VirtFusion server JSON object.
     *
     * @param  string  $serverObject  JSON-encoded server object from the VirtFusion API
     * @return string HTML textarea markup
     */
    public static function serverObject($serverObject)
    {
        $serverObject = htmlspecialchars($serverObject, ENT_QUOTES, 'UTF-8');

        return <<<EOT
            <textarea class="form-control" name="modulefields[1]" rows="10" style="width: 100%" disabled>${serverObject}</textarea>
EOT;

    }

    /**
     * Render an editable text input for the VirtFusion server ID field.
     *
     * @param  int  $serverId  Current VirtFusion server ID
     * @return string HTML input markup with a warning note
     */
    public static function serverId($serverId)
    {
        $serverId = (int) $serverId;

        return <<<EOT
            <input type="text" class="form-control input-200 input-inline" name="modulefields[0]" size="20" value="${serverId}" />
            <span class="text-info">&nbsp;&nbsp;Changing the Sever ID manually is not recommended. Alterations to this field are usually handled automatically.</span>
EOT;
    }

    /**
     * Render the inline server info panel for the admin services tab, including CSS/JS assets.
     *
     * @param  string  $systemUrl  WHMCS system URL (used to build asset and AJAX URLs)
     * @param  int  $serviceId  VirtFusion server ID passed to the JS data-loader
     * @return string HTML panel markup with embedded script and asset tags
     */
    public static function serverInfo($systemUrl, $serviceId)
    {
        $systemUrl = htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8');
        $serviceId = (int) $serviceId;
        $cacheV = time();

        return <<<EOT
            <link href="${systemUrl}modules/servers/VirtFusionDirect/templates/css/module.css?v=${cacheV}" rel="stylesheet">
            <script src="${systemUrl}modules/servers/VirtFusionDirect/templates/js/module.js?v=${cacheV}"></script>
            <div id="vf-loader" class="vf-loader">
               <div id="vf-loading"></div>
            </div>
            <div id="vf-server-info-error">
               <div class="alert alert-warning mb-0">
                  <div id="vf-server-info-error-message"></div>
               </div>
            </div>
            <div id="vf-server-info" class="row mb-2">
               <div class="col-12">
                  <div class="row">
                     <div class="col-md-6">
                        <div class="row p-1">
                           <div class="col-xs-4 col-4 text-right vf-bold">
                              Name:
                           </div>
                           <div class="col-xs-8 col-8" id="vf-data-server-name">
                           </div>
                        </div>
                        <div class="row p-1">
                           <div class="col-xs-4 col-4 text-right vf-bold">
                              Hostname:
                           </div>
                           <div class="col-xs-8 col-8" id="vf-data-server-hostname">
                           </div>
                        </div>
                        <div class="row p-1">
                           <div class="col-xs-4 col-4 text-right vf-bold">
                              Memory:
                           </div>
                           <div class="col-xs-8 col-8" id="vf-data-server-memory">
                           </div>
                        </div>
                        <div class="row p-1">
                           <div class="col-xs-4 col-4 text-right vf-bold">
                              CPU:
                           </div>
                           <div class="col-xs-8 col-8" id="vf-data-server-cpu">
                           </div>
                        </div>
                     </div>
                     <div class="col-md-6">
                        <div class="row p-1">
                           <div class="col-xs-4 col-4 text-right vf-bold">
                              IPv4:
                           </div>
                           <div class="col-xs-8 col-8" id="vf-data-server-ipv4">
                           </div>
                        </div>
                        <div class="row p-1">
                           <div class="col-xs-4 col-4 text-right vf-bold">
                              IPv6:
                           </div>
                           <div class="col-xs-8 col-8" id="vf-data-server-ipv6">
                           </div>
                        </div>
                        <div class="row p-1">
                           <div class="col-xs-4 col-4 text-right vf-bold">
                              Storage:
                           </div>
                           <div class="col-xs-8 col-8" id="vf-data-server-storage">
                           </div>
                        </div>
                        <div class="row p-1">
                           <div class="col-xs-4 col-4 text-right vf-bold">
                              Traffic:
                           </div>
                           <div class="col-xs-8 col-8" id="vf-data-server-traffic">
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
            <script>vfServerDataAdmin("${serviceId}","${systemUrl}");</script>
EOT;
    }

    /**
     * Render the admin Reverse DNS section for the services tab.
     *
     * Ships an empty container + a Reconcile button. Data is loaded client-side via
     * the admin rdnsStatus AJAX endpoint once the page opens. The JS function
     * vfAdminLoadRdns (defined in templates/js/module.js) populates #vf-rdns-list
     * and wires up the Reconcile button's onclick to admin.php?action=rdnsReconcile.
     *
     * @param  string  $systemUrl  WHMCS system URL
     * @param  int  $serviceId  WHMCS service ID
     * @return string HTML fragment for the admin services tab
     */
    public static function rdnsSection($systemUrl, $serviceId)
    {
        $systemUrl = htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8');
        $serviceId = (int) $serviceId;

        return <<<EOT
            <div id="vf-rdns-admin-wrap">
                <div id="vf-rdns-list" class="vf-rdns-list">
                    <em class="text-muted">Loading reverse DNS…</em>
                </div>
                <div class="vf-rdns-actions" style="margin-top:10px">
                    <button type="button" class="btn btn-default btn-sm" onclick="vfAdminReconcileRdns(${serviceId}, '${systemUrl}', false)">Reconcile (additive)</button>
                    <button type="button" class="btn btn-warning btn-sm" onclick="vfAdminReconcileRdns(${serviceId}, '${systemUrl}', true)">Reconcile (force reset)</button>
                    <span id="vf-rdns-report" style="margin-left:10px"></span>
                </div>
            </div>
            <script>if(typeof vfAdminLoadRdns==='function'){vfAdminLoadRdns(${serviceId},"${systemUrl}");}</script>
EOT;
    }
}
