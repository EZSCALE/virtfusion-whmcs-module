<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class ServerResource
{
    public function process($data)
    {
        $server = json_decode(json_encode($data->data), true);

        $traffic = '-';

        if (isset($server['settings']['resources']['traffic'])) {
            if ($server['settings']['resources']['traffic'] > 0) {
                $traffic = $server['settings']['resources']['traffic'] . ' GB';
            } else {
                $traffic = 'Unlimited';
            }
        }

        $trafficUsed = '-';
        if (isset($server['usage']['traffic']['used'])) {
            $trafficUsed = round($server['usage']['traffic']['used'] / 1073741824, 2) . ' GB';
        } elseif (isset($server['settings']['resources']['traffic']) && $server['settings']['resources']['traffic'] > 0) {
            $trafficUsed = '0 GB';
        }

        $data = [
            'name' => $server['name'] ?: '-',
            'hostname' => $server['hostname'] ?: '-',
            'memory' => isset($server['settings']['resources']['memory']) ? $server['settings']['resources']['memory'] . ' MB' : '-',
            'traffic' => $traffic,
            'trafficUsed' => $trafficUsed,
            'storage' => isset($server['settings']['resources']['storage']) ? $server['settings']['resources']['storage'] . ' GB' : '-',
            'cpu' => isset($server['settings']['resources']['cpuCores']) ? $server['settings']['resources']['cpuCores'] . ' Core(s)' : '-',
            'status' => isset($server['state']) ? $server['state'] : 'unknown',
            'powerStatus' => isset($server['hypervisor']['settings']['state']) ? $server['hypervisor']['settings']['state'] : 'unknown',
            'username' => isset($server['owner']['email']) ? $server['owner']['email'] : '',
            'password' => '',
            'primaryNetwork' => [
                'ipv4' => ['-'],
                'ipv4Unformatted' => [],
                'ipv6' => ['-'],
                'ipv6Unformatted' => [],
                'mac' => '-',
            ],
            'networkSpeed' => [
                'inbound' => isset($server['settings']['resources']['networkSpeedInbound']) ? $server['settings']['resources']['networkSpeedInbound'] . ' Mbps' : '-',
                'outbound' => isset($server['settings']['resources']['networkSpeedOutbound']) ? $server['settings']['resources']['networkSpeedOutbound'] . ' Mbps' : '-',
            ],
            'vncEnabled' => isset($server['vnc']['enabled']) ? (bool) $server['vnc']['enabled'] : false,
            'memoryRaw' => isset($server['settings']['resources']['memory']) ? (int) $server['settings']['resources']['memory'] : 0,
            'cpuRaw' => isset($server['settings']['resources']['cpuCores']) ? (int) $server['settings']['resources']['cpuCores'] : 0,
            'storageRaw' => isset($server['settings']['resources']['storage']) ? (int) $server['settings']['resources']['storage'] : 0,
            'trafficRaw' => isset($server['settings']['resources']['traffic']) ? (int) $server['settings']['resources']['traffic'] : 0,
            'trafficUsedRaw' => isset($server['usage']['traffic']['used']) ? round($server['usage']['traffic']['used'] / 1073741824, 2) : 0,
            'networkSpeedInboundRaw' => isset($server['settings']['resources']['networkSpeedInbound']) ? (int) $server['settings']['resources']['networkSpeedInbound'] : 0,
            'networkSpeedOutboundRaw' => isset($server['settings']['resources']['networkSpeedOutbound']) ? (int) $server['settings']['resources']['networkSpeedOutbound'] : 0,
        ];

        if (array_key_exists('network', $server)) {
            if (array_key_exists('interfaces', $server['network'])) {
                if (count($server['network']['interfaces'])) {

                    if (isset($server['network']['interfaces'][0]['mac'])) {
                        $data['primaryNetwork']['mac'] = $server['network']['interfaces'][0]['mac'];
                    }

                    if (isset($server['network']['interfaces'][0]['ipv4']) && count($server['network']['interfaces'][0]['ipv4'])) {
                        $data['primaryNetwork']['ipv4'] = [];
                        foreach ($server['network']['interfaces'][0]['ipv4'] as $ip) {
                            $data['primaryNetwork']['ipv4'][] = $ip['address'];
                        }
                    }

                    if (isset($server['network']['interfaces'][0]['ipv6']) && count($server['network']['interfaces'][0]['ipv6'])) {
                        $data['primaryNetwork']['ipv6'] = [];
                        foreach ($server['network']['interfaces'][0]['ipv6'] as $ip) {
                            $data['primaryNetwork']['ipv6'][] = $ip['subnet'] . '/' . $ip['cidr'];
                        }
                    }
                }
            }
        }

        $data['primaryNetwork']['ipv4Unformatted'] = $data['primaryNetwork']['ipv4'];
        $data['primaryNetwork']['ipv6Unformatted'] = $data['primaryNetwork']['ipv6'];
        $data['primaryNetwork']['ipv4'] = implode(', ', $data['primaryNetwork']['ipv4']);
        $data['primaryNetwork']['ipv6'] = implode(', ', $data['primaryNetwork']['ipv6']);

        return $data;
    }
}
