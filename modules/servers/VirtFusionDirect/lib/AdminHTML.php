<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class AdminHTML
{

    public static function options($systemUrl, $serviceId)
    {
        $systemUrl = htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8');
        return <<<EOT
            <button onclick="impersonateServerOwner('${serviceId}', '${systemUrl}')" type="button" class="btn btn-primary">Impersonate Server Owner</button>
            <span class="text-info">&nbsp;&nbsp;A valid VirtFusion admin session in the same browser is required for this functionality to work.</span>
EOT;
    }

    public static function serverObject($serverObject)
    {
        $serverObject = htmlspecialchars($serverObject, ENT_QUOTES, 'UTF-8');
        return <<<EOT
            <textarea class="form-control" name="modulefields[1]" rows="10" style="width: 100%" disabled>${serverObject}</textarea>
EOT;

    }

    public static function serverId($serverId)
    {
        return <<<EOT
            <input type="text" class="form-control input-200 input-inline" name="modulefields[0]" size="20" value="${serverId}" />
            <span class="text-info">&nbsp;&nbsp;Changing the Sever ID manually is not recommended. Alterations to this field are usually handled automatically.</span>
EOT;
    }

    public static function serverInfo($systemUrl, $serviceId)
    {
        $systemUrl = htmlspecialchars($systemUrl, ENT_QUOTES, 'UTF-8');
        return <<<EOT
            <link href="${systemUrl}modules/servers/VirtFusionDirect/templates/css/module.css?v=20260207" rel="stylesheet">
            <script src="${systemUrl}modules/servers/VirtFusionDirect/templates/js/module.js?v=20260207"></script>
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