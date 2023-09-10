<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

class ServerResource
{
    public function process($data)
    {
        $server = json_decode(json_encode($data->data), true);

        $traffic = '∞';

        if ($server['settings']['resources']['traffic']) {
            if ($server['settings']['resources']['traffic'] > 0) {
                $traffic = $server['settings']['resources']['traffic'] . ' GB';
            }
        }

        $data = [
            'name' => $server['name'] ?: '-',
            'hostname' => $server['hostname'] ?: '-',
            'memory' => $server['settings']['resources']['memory'] . ' MB',
            'traffic' => $traffic,
            'storage' => $server['settings']['resources']['storage'] . ' GB',
            'cpu' => $server['settings']['resources']['cpuCores'] . ' Core(s)',
            'primaryNetwork' => [
                'ipv4' => ['-'],
                'ipv4Unformatted' => [],
                'ipv6' => ['-'],
                'ipv6Unformatted' => [],
            ]
        ];

        if (array_key_exists('network', $server)) {
            if (array_key_exists('interfaces', $server['network'])) {
                if (count($server['network']['interfaces'])) {

                    if (count($server['network']['interfaces'][0]['ipv4'])) {
                        $data['primaryNetwork']['ipv4'] = [];
                        foreach ($server['network']['interfaces'][0]['ipv4'] as $ip) {
                            $data['primaryNetwork']['ipv4'][] = $ip['address'];
                        }
                    }

                    if (count($server['network']['interfaces'][0]['ipv6'])) {
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