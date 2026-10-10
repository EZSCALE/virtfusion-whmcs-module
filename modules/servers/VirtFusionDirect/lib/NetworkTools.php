<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/** Admin service tools; customer allocation/filter/isolation edits never enter here. */
class NetworkTools
{
    private ServiceTools $tools;

    public function __construct(ServiceTools $tools)
    {
        $this->tools = $tools;
    }

    public function execute(array $ctx, string $action, array $input): array
    {
        Access::admin('Perform Module Command Operations');
        $base = '/servers/' . $ctx['serverId'] . '/interfaces';
        $body = [];
        $method = 'post';
        $path = $base;
        if ($action !== 'interfaceAdd') {
            $nic = ServiceTools::select($this->tools->read($ctx, $base), $input['interfaceRef'] ?? '', ['tag']);
            $tag = (string) $nic['tag'];
            if (! preg_match('/^[0-9]{10}$/D', $tag)) {
                throw new \RuntimeException('Invalid interface tag.', 403);
            }
            $path .= '/' . $tag;
        }
        if ($action === 'interfaceAdd' || $action === 'interfaceSettings') {
            $fields = $input['settings'] ?? [];
            $allowed = $action === 'interfaceAdd' ? ['role', 'hypervisorNetworkId', 'name', 'apply'] : ['name', 'hypervisorNetworkId', 'driver', 'processQueues', 'ipFilter', 'portIsolated', 'dhcpV4', 'dhcpV6', 'inAverage', 'outAverage', 'networkProfile', 'enabled', 'apply'];
            if (! is_array($fields) || array_diff(array_keys($fields), $allowed)) {
                throw new \InvalidArgumentException('Invalid interface fields.');
            }
            if (isset($fields['name']) && (! is_string($fields['name']) || ! preg_match('/^[A-Za-z0-9_-]{2,20}$/D', $fields['name']))) {
                throw new \InvalidArgumentException('Invalid guest device name.');
            }
            if (isset($fields['role']) && ! in_array($fields['role'], ['primary', 'secondary'], true)) {
                throw new \InvalidArgumentException('Invalid interface role.');
            }
            if (isset($fields['hypervisorNetworkId'])) {
                $hv = ServiceTools::positiveId($ctx['server']['hypervisor']['id'] ?? null);
                ServiceTools::select($this->tools->read($ctx, '/compute/hypervisors/' . $hv . '/networks'), ServiceTools::positiveId($fields['hypervisorNetworkId']), ['id']);
            }
            foreach (['ipFilter', 'portIsolated', 'dhcpV4', 'dhcpV6', 'enabled', 'apply'] as $key) {
                if (isset($fields[$key]) && ! is_bool($fields[$key])) {
                    throw new \InvalidArgumentException('Interface flags must be booleans.');
                }
            }
            foreach (['inAverage', 'outAverage', 'networkProfile', 'driver', 'processQueues'] as $key) {
                if (isset($fields[$key]) && (! is_int($fields[$key]) || $fields[$key] < 0)) {
                    throw new \InvalidArgumentException('Interface tuning must be non-negative integers.');
                }
            }
            $body = $fields;
            $method = $action === 'interfaceAdd' ? 'post' : 'put';
        } elseif ($action === 'interfaceIpv4Add') {
            $quantity = ServiceTools::positiveId($input['quantity'] ?? null);
            if ($quantity > 100) {
                throw new \InvalidArgumentException('At most 100 addresses.');
            }
            $body = ['quantity' => $quantity];
            $path .= '/ipv4';
        } elseif (in_array($action, ['interfaceIpv4Remove', 'interfaceIpv4Order', 'interfaceIpv6Remove'], true)) {
            $v6 = $action === 'interfaceIpv6Remove';
            $ids = $input['ids'] ?? [];
            if (! is_array($ids) || ! $ids) {
                throw new \InvalidArgumentException('Select assigned addresses.');
            }
            $ids = array_map([ServiceTools::class, 'positiveId'], $ids);
            if (count($ids) !== count(array_unique($ids))) {
                throw new \InvalidArgumentException('Duplicate address.');
            }
            foreach ($ids as $id) {
                ServiceTools::select($nic[$v6 ? 'ipv6' : 'ipv4'] ?? [], $id, ['id']);
            }
            $path .= $v6 ? '/ipv6' : '/ipv4';
            if ($action === 'interfaceIpv4Order') {
                $expected = array_map(fn ($a) => (int) $a['id'], $nic['ipv4'] ?? []);
                $sorted = $ids;
                sort($sorted);
                sort($expected);
                if ($sorted !== $expected) {
                    throw new \InvalidArgumentException('Order every assigned IPv4 exactly once.');
                }
                $body = ['order' => $ids];
                $path .= '/order';
                $method = 'put';
            } else {
                ServiceTools::confirm($input, 'detachConfirmed');
                $body = ['ids' => $ids];
                $method = 'delete';
            }
        } elseif ($action === 'interfaceIpv6Add') {
            $path .= '/ipv6';
        } elseif (str_starts_with($action, 'vlan')) {
            $id = ServiceTools::positiveId($input['vlanId'] ?? null);
            if ($id > 4094) {
                throw new \InvalidArgumentException('VLAN range is 1–4094.');
            }
            $path .= '/vlans';
            if ($action === 'vlanAdd') {
                $body = ['vlanId' => $id];
            } else {
                ServiceTools::select($this->tools->read($ctx, $path), $id, ['vlanId', 'id']);
                $path .= '/' . $id;
                if ($action === 'vlanDelete') {
                    ServiceTools::confirm($input, 'detachConfirmed');
                    $method = 'delete';
                } else {
                    foreach (['nativeMode', 'trunk'] as $key) {
                        if (isset($input[$key])) {
                            if (! in_array($input[$key], [0, 1, 2], true)) {
                                throw new \InvalidArgumentException('Invalid VLAN mode.');
                            }
                            $body[$key] = $input[$key];
                        }
                    }
                    $method = 'put';
                }
            }
        } elseif ($action === 'macSet') {
            $address = $input['address'] ?? '';
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new \InvalidArgumentException('An IPv4 address is required.');
            }
            ServiceTools::select($nic['ipv4'] ?? [], $address, ['address', 'ip']);
            $mac = $input['mac'] ?? null;
            if ($mac !== null && (! is_string($mac) || ! preg_match('/^(?:[0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/D', $mac))) {
                throw new \InvalidArgumentException('Invalid MAC address.');
            }
            $body = ['address' => $address, 'mac' => $mac];
            $path = '/connectivity/ipv4/mac';
            $method = 'put';
        } else {
            throw new \InvalidArgumentException('Unsupported network tool.');
        }

        return $this->tools->mutate($ctx, $action, $method, $path, $body ?: null, $input, true);
    }
}
