<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Static methods that generate HTML fragments for the WHMCS admin services tab.
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
}
