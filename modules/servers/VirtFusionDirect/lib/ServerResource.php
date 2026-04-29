<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Transforms a VirtFusion API server response into a flat key-value array for Smarty templates and admin display.
 *
 * WHY A FLAT ARRAY
 * ----------------
 * Smarty templates can traverse nested structures (`{$data.network.interfaces[0].ipv4[0].address}`)
 * but that leaks the API shape into the template layer. A flat array ("hostname",
 * "primaryNetwork.ipv4[]", "memoryRaw", etc.) decouples the template from the upstream
 * schema: if VirtFusion renames `network.interfaces` tomorrow, only this file needs
 * to change.
 *
 * PRIMARY-INTERFACE-ONLY DESIGN
 * -----------------------------
 * process() only reads interfaces[0]. That's the primary network — the one the
 * client-area "Overview" card displays. Servers with multiple interfaces (common
 * for dedicated IPMI networks, storage networks, etc.) still work for display
 * because the primary interface holds the customer-facing IP.
 *
 * The reverse-DNS subsystem (PowerDns\IpUtil::extractIps) walks ALL interfaces
 * explicitly because PTRs matter for every IP no matter which NIC it's on.
 * If you add a feature that needs secondary-interface data for display, do NOT
 * generalise this class — add a new one or a helper that doesn't disturb the
 * well-tested primary-interface behaviour.
 *
 * UNIT CONVERSIONS
 * ----------------
 * VirtFusion stores:
 *   - traffic as bytes (usage) or GB (limits)
 *   - storage as GB (limits) or bytes (usage)
 *   - memory as MB
 * WHMCS expects MB for storage/traffic in tblhosting. This class produces two
 * pairs of values per resource: a human-readable string with unit suffix
 * (e.g. "200 GB") AND a raw integer without the unit (for slider UIs and
 * arithmetic). Keep both — removing one breaks a UI consumer somewhere.
 *
 * "-" SENTINELS
 * -------------
 * Fields that are missing or empty are rendered as "-" rather than empty strings.
 * That makes the client-area card always have content (a dash is a valid visual
 * placeholder) and distinguishes "missing data" from "empty string returned by
 * the API". Consumers who need boolean presence checks should test against "-",
 * not "" / null — and upstream (e.g. updateWhmcsServiceParamsOnServerObject)
 * already does.
 */
class ServerResource
{
    /**
     * Normalise a VirtFusion API server response into a flat associative array.
     *
     * @param  object  $data  VirtFusion API server response object (with a `data` property)
     * @return array Flat associative array containing server name, hostname, resources, network info, and usage
     */
    public function process($data)
    {
        $server = json_decode(json_encode($data->data), true);

        $traffic = '-';

        if (isset($server['settings']['resources']['traffic'])) {
            if ($server['settings']['resources']['traffic'] > 0) {
                $traffic = $server['settings']['resources']['traffic'] . ' GB';
            } else {
                // limit=0 in VirtFusion means "no cap on this period". We
                // surface that as "Unmetered" rather than "Unlimited" — limits
                // exist (the period still rolls over monthly, traffic is still
                // counted), the customer just isn't billed for overage.
                $traffic = 'Unmetered';
            }
        }

        // trafficUsedBytes is merged onto the response by Module::fetchServerData()
        // from the dedicated /servers/{id}/traffic endpoint. Reading it directly
        // (rather than the non-existent server.usage.traffic.used path that we
        // historically referenced) is what unblocks the "X GB / Unmetered" display
        // for unmetered plans — there IS usage to show even when there's no cap.
        $trafficUsed = '-';
        if (isset($server['trafficUsedBytes']) && is_numeric($server['trafficUsedBytes'])) {
            $bytes = (int) $server['trafficUsedBytes'];
            $trafficUsed = ($bytes > 0 ? round($bytes / 1073741824, 2) : 0) . ' GB';
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
            'vncEnabled' => isset($server['vnc']['enabled']) ? (bool) $server['vnc']['enabled'] : false,
            'memoryRaw' => isset($server['settings']['resources']['memory']) ? (int) $server['settings']['resources']['memory'] : 0,
            'cpuRaw' => isset($server['settings']['resources']['cpuCores']) ? (int) $server['settings']['resources']['cpuCores'] : 0,
            'storageRaw' => isset($server['settings']['resources']['storage']) ? (int) $server['settings']['resources']['storage'] : 0,
            'trafficRaw' => isset($server['settings']['resources']['traffic']) ? (int) $server['settings']['resources']['traffic'] : 0,
            'trafficUsedRaw' => isset($server['trafficUsedBytes']) ? round((int) $server['trafficUsedBytes'] / 1073741824, 2) : 0,

            // -- Identity / catalog ---------------------------------------
            // os.templateName is always present; qemuAgent.os.* only when
            // qemu-guest-agent is installed and running on the guest. Both
            // are surfaced; the template chooses which to emphasise.
            'osName' => $server['os']['templateName'] ?? '-',
            'osPretty' => $server['qemuAgent']['os']['pretty-name'] ?? null,
            'osKernel' => $server['qemuAgent']['os']['kernel-release'] ?? null,
            'osDistro' => $server['qemuAgent']['os']['id'] ?? null,
            'osIcon' => $server['qemuAgent']['os']['img'] ?? null,

            // -- Data center / hypervisor ---------------------------------
            'location' => $server['hypervisor']['group']['name'] ?? '-',
            'locationIcon' => $server['hypervisor']['group']['icon'] ?? null,
            'hypervisorMaintenance' => (bool) ($server['hypervisor']['maintenance'] ?? false),

            // -- Server lifetime ------------------------------------------
            'createdAt' => $server['created'] ?? null,
            'builtAt' => $server['built'] ?? null,

            // -- Live state (requires ?remoteState=true on the upstream call) -
            // Fields default to null when the live block is absent — happens
            // when remoteState wasn't requested or the hypervisor couldn't
            // reach libvirt at fetch time. Templates must isset()-guard each.
            'live' => [
                'state' => $server['remoteState']['state'] ?? null,
                'cpu' => isset($server['remoteState']['cpu']) ? (float) $server['remoteState']['cpu'] : null,
                // memory.* values are kilobytes (libvirt convention).
                'memoryActualKB' => isset($server['remoteState']['memory']['actual']) ? (int) $server['remoteState']['memory']['actual'] : null,
                'memoryUnusedKB' => isset($server['remoteState']['memory']['unused']) ? (int) $server['remoteState']['memory']['unused'] : null,
                'memoryAvailableKB' => isset($server['remoteState']['memory']['available']) ? (int) $server['remoteState']['memory']['available'] : null,
                'memoryRssKB' => isset($server['remoteState']['memory']['rss']) ? (int) $server['remoteState']['memory']['rss'] : null,
                // disk.{drive}.{rd,wr,fl}.{reqs,bytes,times} — surfacing the
                // primary drive (vda) cumulative byte counters. JS can derive
                // throughput rates from successive samples.
                'diskRdBytes' => isset($server['remoteState']['disk']['vda']['rd.bytes']) ? (int) $server['remoteState']['disk']['vda']['rd.bytes'] : null,
                'diskWrBytes' => isset($server['remoteState']['disk']['vda']['wr.bytes']) ? (int) $server['remoteState']['disk']['vda']['wr.bytes'] : null,
                // Filesystems: only present when qemu-guest-agent is running
                // inside the VM. Each entry is normalised to {name, mountpoint,
                // type, usedBytes, totalBytes}; pseudo-FS (devtmpfs, proc, sys)
                // are filtered out — only real mounts the customer cares about.
                'filesystems' => self::extractFilesystems($server['remoteState']['agent']['fsinfo'] ?? null),
            ],
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

    /**
     * Normalise the qemu-guest-agent fsinfo array into customer-facing rows.
     *
     * Only "real" filesystems are returned — pseudo-FS like proc/sysfs/devtmpfs
     * have no meaning in a usage context. Returned entries are sorted with the
     * root mount first so the most relevant row leads in the UI.
     *
     * @param  array|null  $fsinfo  remoteState.agent.fsinfo from the API
     * @return array List of {name, mountpoint, type, usedBytes, totalBytes}
     */
    private static function extractFilesystems($fsinfo): array
    {
        if (! is_array($fsinfo) || $fsinfo === []) {
            return [];
        }
        // Filesystems we never want to show — they're kernel/runtime, not user storage.
        $skipTypes = ['proc', 'sysfs', 'devtmpfs', 'devpts', 'tmpfs', 'cgroup', 'cgroup2',
            'pstore', 'bpf', 'mqueue', 'debugfs', 'tracefs', 'securityfs',
            'configfs', 'fusectl', 'autofs', 'hugetlbfs', 'rpc_pipefs',
            'binfmt_misc', 'overlay', 'squashfs', 'ramfs', 'fuse.gvfsd-fuse',
            'efivarfs', 'selinuxfs'];
        $rows = [];
        foreach ($fsinfo as $fs) {
            if (! is_array($fs)) {
                continue;
            }
            $type = $fs['type'] ?? '';
            if (in_array($type, $skipTypes, true)) {
                continue;
            }
            $mount = $fs['mountpoint'] ?? '';
            // Skip /boot* and /run* — useful in monitoring tools but noisy on
            // a customer-facing dashboard. Customers care about the root and
            // any data mounts.
            if ($mount === '/boot' || str_starts_with($mount, '/boot/')) {
                continue;
            }
            if ($mount === '/run' || str_starts_with($mount, '/run/')) {
                continue;
            }
            $rows[] = [
                'name' => (string) ($fs['name'] ?? '-'),
                'mountpoint' => (string) $mount,
                'type' => (string) $type,
                'usedBytes' => isset($fs['used-bytes']) ? (int) $fs['used-bytes'] : 0,
                'totalBytes' => isset($fs['total-bytes']) ? (int) $fs['total-bytes'] : 0,
            ];
        }
        // Root mount first; everything else by mountpoint alphabetical.
        usort($rows, function ($a, $b) {
            if ($a['mountpoint'] === '/') {
                return -1;
            }
            if ($b['mountpoint'] === '/') {
                return 1;
            }

            return strcmp($a['mountpoint'], $b['mountpoint']);
        });

        return $rows;
    }
}
