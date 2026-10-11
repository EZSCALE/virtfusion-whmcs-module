<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\IpUtil;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\PtrManager;

/**
 * PTR operations limited to current VM addresses or allocated IPv6 subnets.
 *
 * The provider-selected backend is either native VirtFusion or PowerDNS.
 * Both paths require a strict hostname and forward-confirmed DNS before
 * reserving, recheck assignment before writing, release reservations when
 * nothing was sent and retain ambiguous outcomes.
 */
class PtrTools
{
    /** Legacy client rdnsUpdate rule: dotted labels ending in an alphabetic TLD, optional root dot. */
    const HOSTNAME_PATTERN = '/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}\.?$/D';

    private ServiceTools $tools;

    /**
     * Use the verified service gateway and the provider-configured PTR backend.
     */
    public function __construct(ServiceTools $tools)
    {
        $this->tools = $tools;
    }

    /**
     * List current addresses or change PTR only for a verified assigned address.
     *
     * Both backends perform forward-DNS (FCrDNS) validation before any reservation;
     * an empty hostname (PTR delete) skips it. Native/PowerDNS writes recheck current
     * ownership after reserving the VM; neither path exposes global IPAM or
     * arbitrary zone editing.
     */
    public function execute(array $ctx, string $action, array $input, array $settings, bool $admin): array
    {
        $server = (object) ['data' => json_decode(json_encode($ctx['server']))];
        $assigned = IpUtil::extractIps($server);
        if ($action === 'ptrList') {
            if ($settings['rdnsBackend'] === 'powerdns' && Config::isEnabled()) {
                return ['data' => (new PtrManager)->listPtrs($server)];
            }

            return ['data' => array_map(fn ($ip) => ['address' => $ip], $assigned['addresses']), 'subnets' => $assigned['subnets']];
        }
        $ip = $input['address'] ?? '';
        $hostname = $input['hostname'] ?? '';
        if (! is_string($ip) || ! ServiceTools::assignedAddress($ctx['server'], $ip)) {
            throw new \RuntimeException('IP is not currently assigned to this VM.', 403);
        }
        if (! is_string($hostname) || ($hostname !== '' && (! preg_match(self::HOSTNAME_PATTERN, $hostname) || strlen($hostname) > 253))) {
            throw new \InvalidArgumentException('Invalid PTR hostname.');
        }
        if ($hostname !== '' && ! $this->tools->forwardDnsMatches(rtrim($hostname, '.'), $ip)) {
            throw new \RuntimeException('Forward DNS for this hostname does not resolve to ' . $ip . '. Create the A/AAAA record first, then try again.', 400);
        }
        if ($settings['rdnsBackend'] === 'virtfusion') {
            $family = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'ipv4' : 'ipv6';

            return $this->tools->mutate($ctx, $action, 'put', '/connectivity/' . $family . '/rdns', ['address' => $ip, 'ptr' => $hostname === '' ? null : strtolower(rtrim($hostname, '.'))], $input, $admin);
        }
        if (! Config::isEnabled()) {
            throw new \RuntimeException('Configured PTR backend is unavailable.', 503);
        }
        $op = Operation::begin($ctx, $action, ['address' => $ip, 'hostname' => $hostname], Access::actor($admin), (string) ($input['requestId'] ?? ''));
        $sent = false;

        try {
            $fresh = $this->tools->revalidate($ctx, $admin);
            if (! ServiceTools::assignedAddress($fresh['server'], $ip)) {
                throw new \RuntimeException('Address assignment changed before submission.', 403);
            }
            $sent = true;
            $result = (new PtrManager)->setPtr($ip, $hostname);
            $state = ! empty($result['ok']) ? 'completed' : 'failed';
            Operation::finish($op->id, $state, ['reason' => $result['reason'] ?? 'unavailable']);

            return ['operationId' => $op->id, 'state' => $state];
        } catch (\Throwable $e) {
            if (! $sent) {
                throw ServiceTools::unsent($op->id, $e);
            }
            Operation::finish($op->id, 'unknown');

            throw new \RuntimeException('PTR outcome unknown; reconcile before retrying.', 502);
        }
    }
}
