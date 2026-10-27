<input type="hidden" name="token" value="{$token|escape:'htmlall'}">
<script src="{$systemURL|escape:'htmlall'}modules/servers/VirtFusionDirect/templates/js/module.js" defer></script>
<script src="{$systemURL|escape:'htmlall'}modules/servers/VirtFusionDirect/templates/js/tools.js" defer></script>
<div class="panel card panel-default">
    <div class="panel-heading card-header"><h3>Resource pack</h3></div>
    <div class="panel-body card-body">
        <p>{$pack.name|default:'Resource pack'|escape:'htmlall'}</p>
        <p>Create virtual machines in VirtFusion. Select a current member below to manage it.</p>
        {if $serviceStatus eq 'Active' or $serviceStatus eq 'Suspended'}
        <button type="button" class="btn btn-primary" onclick="vfLoginAsServerOwner('{$serviceid|escape:'htmlall'}','{$systemURL|escape:'htmlall'}',true)">Login to Control Panel</button>
        {/if}
    </div>
</div>
<div class="vfd-tools" data-base="{$systemURL|escape:'htmlall'}" data-service="{$serviceid|escape:'htmlall'}" data-admin="0" data-pack="1"></div>
