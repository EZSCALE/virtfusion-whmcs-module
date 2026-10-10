<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\IpUtil;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\PtrManager;

class PtrTools
{
    private ServiceTools $tools;

    public function __construct(ServiceTools $tools)
    {
        $this->tools = $tools;
    }

    public function execute(array $ctx, string $action, array $input, array $settings, bool $admin): array
    {
        $server = (object) ['data' => json_decode(json_encode($ctx['server']))];
        $assigned = IpUtil::extractIps($server);
        if ($action === 'ptrList') {
            if ($settings['rdnsBackend'] === 'powerdns' && Config::isEnabled()) {
                return ['data' => (new PtrManager)->listPtrs($server)];
            }

            return ['data' => array_map(fn ($ip) => ['address' => $ip], $assigned)];
        }
        $ip = $input['address'] ?? '';
        $hostname = $input['hostname'] ?? '';
        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP) || ! in_array($ip, $assigned, true)) {
            throw new \RuntimeException('IP is not currently assigned to this VM.', 403);
        }
        if (! is_string($hostname) || ($hostname !== '' && (! filter_var(rtrim($hostname, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || strlen($hostname) > 253))) {
            throw new \InvalidArgumentException('Invalid PTR hostname.');
        }
        if ($settings['rdnsBackend'] === 'virtfusion') {
            $family = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'ipv4' : 'ipv6';

            return $this->tools->mutate($ctx, $action, 'put', '/connectivity/' . $family . '/rdns', ['address' => $ip, 'ptr' => $hostname === '' ? null : strtolower(rtrim($hostname, '.'))], $input, $admin);
        }
        if (! Config::isEnabled()) {
            throw new \RuntimeException('Configured PTR backend is unavailable.', 503);
        }
        $op = Operation::begin($ctx, $action, ['address' => $ip, 'hostname' => $hostname], Access::actor($admin), (string) ($input['requestId'] ?? ''));

        try {
            $result = (new PtrManager)->setPtr($ip, $hostname);
            $state = ! empty($result['ok']) ? 'completed' : 'failed';
            Operation::finish($op->id, $state, ['reason' => $result['reason'] ?? 'unavailable']);

            return ['operationId' => $op->id, 'state' => $state];
        } catch (\Throwable $e) {
            Operation::finish($op->id, 'unknown');

            throw new \RuntimeException('PTR outcome unknown; reconcile before retrying.', 502);
        }
    }
}
