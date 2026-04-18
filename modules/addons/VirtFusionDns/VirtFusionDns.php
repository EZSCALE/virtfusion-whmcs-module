<?php

use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Client;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config;

/**
 * VirtFusion DNS — companion WHMCS addon module that holds PowerDNS settings for
 * the VirtFusionDirect server module. Keeps the server module decoupled from the
 * addon: the server module reads settings from tbladdonmodules and never loads
 * addon code at runtime.
 *
 * Activation: WHMCS Admin -> System Settings -> Addon Modules -> Activate -> Configure.
 *
 * API key handling: WHMCS encrypts password-type addon fields in tbladdonmodules;
 * the server module calls decrypt() on read (see lib/PowerDns/Config.php).
 */
if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Load the server module's PowerDNS classes on demand. Done inside functions rather
 * than at file scope so the WHMCS addon list still works if the server module is
 * absent (e.g., uninstalled while the addon is still activated). Returns true when
 * the classes are available.
 */
function virtfusiondns_load_server_libs(): bool
{
    $base = __DIR__ . '/../../servers/VirtFusionDirect/lib/';
    $files = [
        'Curl.php',
        'Log.php',
        'Cache.php',
        'PowerDns/Config.php',
        'PowerDns/IpUtil.php',
        'PowerDns/Client.php',
    ];
    foreach ($files as $f) {
        if (! is_file($base . $f)) {
            return false;
        }
        require_once $base . $f;
    }
    // PtrManager + IpUtil are only needed for the diagnostic tool below; load them
    // if present but don't require them for the basic status page to work.
    foreach (['PowerDns/Resolver.php', 'PowerDns/PtrManager.php'] as $optional) {
        if (is_file($base . $optional)) {
            require_once $base . $optional;
        }
    }

    return true;
}

/**
 * WHMCS addon metadata.
 */
function VirtFusionDns_config()
{
    return [
        'name' => 'VirtFusion DNS',
        'description' => 'Adds reverse DNS (PTR) management to the VirtFusionDirect server module using a PowerDNS HTTP API. Zones must already exist in PowerDNS; the addon never creates zones. Requires the VirtFusionDirect server module.',
        'version' => '1.0',
        'author' => 'VirtFusionDirect',
        'language' => 'english',
        'fields' => [
            'enabled' => [
                'FriendlyName' => 'Enable rDNS Sync',
                'Type' => 'yesno',
                'Description' => 'Master switch. When off, the server module skips every PowerDNS call.',
            ],
            'endpoint' => [
                'FriendlyName' => 'PowerDNS API Endpoint',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'http://ns1.example.com:8081',
                'Description' => 'Scheme + host + port (no path). The /api/v1/... path is appended automatically.',
            ],
            'apiKey' => [
                'FriendlyName' => 'PowerDNS API Key',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'X-API-Key. Stored encrypted by WHMCS; decrypted only server-side when PowerDNS is called.',
            ],
            'serverId' => [
                'FriendlyName' => 'PowerDNS Server ID',
                'Type' => 'text',
                'Size' => '20',
                'Default' => 'localhost',
                'Description' => 'Almost always "localhost" (the PowerDNS API server identifier, not a hostname).',
            ],
            'defaultTtl' => [
                'FriendlyName' => 'Default PTR TTL (seconds)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '3600',
                'Description' => 'TTL applied to PTR records created by the module.',
            ],
            'cacheTtl' => [
                'FriendlyName' => 'Cache TTL (seconds)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '60',
                'Description' => 'How long zone lists and DNS-resolution results are cached. Minimum 10s.',
            ],
        ],
    ];
}

/**
 * Called when the addon is activated. No schema to create — settings live in tbladdonmodules.
 */
function VirtFusionDns_activate()
{
    return [
        'status' => 'success',
        'description' => 'VirtFusion DNS activated. Fill in the endpoint + API key in the addon configuration, then use the Test Connection button on the addon page.',
    ];
}

/**
 * Called when the addon is deactivated. Settings preserved (re-activating restores them).
 */
function VirtFusionDns_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'VirtFusion DNS deactivated. Server lifecycle PowerDNS calls will now be skipped. Settings are preserved.',
    ];
}

/**
 * Admin status page — rendered by WHMCS when the addon is clicked from the Addons menu.
 *
 * Shows a settings summary, a Test Connection button (calls PowerDNS ping), the current
 * zone count, and a recent log extract filtered to PowerDNS-related entries.
 */
function VirtFusionDns_output($vars)
{
    if (! virtfusiondns_load_server_libs()) {
        echo '<div style="max-width:900px;padding:16px;border-radius:4px;background:#f8d7da;color:#721c24">';
        echo '<strong>VirtFusionDirect server module not found.</strong> ';
        echo 'This addon requires the VirtFusionDirect server module at <code>modules/servers/VirtFusionDirect/</code>. ';
        echo 'Install or restore that module and reload this page.';
        echo '</div>';

        return;
    }

    Config::reset();
    $config = Config::get();

    $pingResult = null;
    $zoneCount = null;
    $zoneSample = [];

    if (! empty($_GET['vfdns_test'])) {
        if (Config::isEnabled()) {
            $client = new Client;
            $pingResult = $client->ping();
            if ($pingResult['ok']) {
                $client->forgetZoneCache();
                $zones = $client->listZones();
                $zoneCount = count($zones);
                $zoneSample = array_slice($zones, 0, 8);
            }
        } else {
            $pingResult = ['ok' => false, 'http' => 0, 'error' => 'Not enabled or missing endpoint/apiKey.'];
        }
    }

    $modulelink = htmlspecialchars($vars['modulelink'] ?? '', ENT_QUOTES, 'UTF-8');
    $endpoint = htmlspecialchars($config['endpoint'], ENT_QUOTES, 'UTF-8');
    $serverId = htmlspecialchars($config['serverId'], ENT_QUOTES, 'UTF-8');
    $ttl = (int) $config['defaultTtl'];
    $cacheTtl = (int) $config['cacheTtl'];
    $enabledBadge = $config['enabled']
        ? '<span style="color:#28a745;font-weight:bold">enabled</span>'
        : '<span style="color:#dc3545;font-weight:bold">disabled</span>';
    $keyBadge = $config['apiKey'] !== '' ? '<span style="color:#28a745">set</span>' : '<span style="color:#dc3545">missing</span>';

    echo '<div style="max-width:900px">';
    echo '<h2 style="margin-top:0">VirtFusion DNS</h2>';
    echo '<p>Reverse DNS management for the VirtFusionDirect server module. All PTR writes happen through the VirtFusion server lifecycle (create, rename, terminate) and through the client-area Reverse DNS panel. Forward DNS (A/AAAA) is verified before every PTR write; mismatches are skipped and logged.</p>';

    echo '<h3>Current settings</h3>';
    echo '<table class="table table-sm" style="max-width:700px"><tbody>';
    echo '<tr><th style="text-align:left;width:180px">Status</th><td>' . $enabledBadge . '</td></tr>';
    echo '<tr><th style="text-align:left">Endpoint</th><td><code>' . ($endpoint ?: '<em>not set</em>') . '</code></td></tr>';
    echo '<tr><th style="text-align:left">API Key</th><td>' . $keyBadge . '</td></tr>';
    echo '<tr><th style="text-align:left">Server ID</th><td><code>' . $serverId . '</code></td></tr>';
    echo '<tr><th style="text-align:left">Default PTR TTL</th><td>' . $ttl . 's</td></tr>';
    echo '<tr><th style="text-align:left">Cache TTL</th><td>' . $cacheTtl . 's</td></tr>';
    echo '</tbody></table>';

    echo '<h3>Test Connection</h3>';
    echo '<p>Calls <code>GET /api/v1/servers/' . $serverId . '</code> and, on success, lists available zones.</p>';
    echo '<a href="' . $modulelink . '&vfdns_test=1" class="btn btn-primary btn-sm">Run Test</a>';

    if ($pingResult !== null) {
        echo '<div style="margin-top:12px;padding:10px;border-radius:4px;background:' . ($pingResult['ok'] ? '#d4edda' : '#f8d7da') . ';color:' . ($pingResult['ok'] ? '#155724' : '#721c24') . '">';
        if ($pingResult['ok']) {
            echo '<strong>OK.</strong> PowerDNS reachable and authenticated. ';
            if ($zoneCount !== null) {
                echo $zoneCount . ' zone(s) visible.';
                if (! empty($zoneSample)) {
                    echo '<div style="margin-top:8px;font-family:monospace;font-size:12px">';
                    foreach ($zoneSample as $z) {
                        echo htmlspecialchars($z, ENT_QUOTES, 'UTF-8') . '<br>';
                    }
                    if ($zoneCount > count($zoneSample)) {
                        echo '<em>... and ' . ($zoneCount - count($zoneSample)) . ' more</em>';
                    }
                    echo '</div>';
                }
            }
        } else {
            echo '<strong>Failed.</strong> HTTP ' . (int) $pingResult['http'] . ': ' . htmlspecialchars((string) ($pingResult['error'] ?? 'unknown error'), ENT_QUOTES, 'UTF-8');
        }
        echo '</div>';
    }

    echo '<h3 style="margin-top:24px">Operation</h3>';
    echo '<ul>';
    echo '<li><strong>On server creation:</strong> a PTR is created for each assigned IP, set to the server hostname, <em>only if the forward DNS already resolves to that IP</em>.</li>';
    echo '<li><strong>On server rename:</strong> PTRs whose current content matches the <em>previous</em> hostname are updated to the new hostname; custom PTRs set by the client are preserved.</li>';
    echo '<li><strong>On server termination:</strong> every PTR for the server\'s IPs is deleted from PowerDNS.</li>';
    echo '<li><strong>Clients:</strong> may set a custom PTR per IP via the Reverse DNS panel on the service overview page. Forward DNS must resolve to the IP; mismatch rejects the write.</li>';
    echo '<li><strong>Reconcile cron:</strong> runs daily, additive-only — creates PTRs where none exist, never overwrites.</li>';
    echo '<li><strong>Reconcile (admin):</strong> a button on the admin services tab triggers an explicit reconcile with optional <em>force</em> to reset client-custom PTRs back to the server hostname.</li>';
    echo '</ul>';

    echo '<h3>Requirements</h3>';
    echo '<ul>';
    echo '<li>PowerDNS Authoritative with HTTP API enabled (<code>webserver=yes</code>, <code>api=yes</code>).</li>';
    echo '<li>Reverse zones (<code>*.in-addr.arpa</code> / <code>*.ip6.arpa</code>) for your IP ranges must exist in PowerDNS already — the addon never creates zones.</li>';
    echo '<li><code>api-allow-from</code> must include the WHMCS host\'s IP.</li>';
    echo '</ul>';

    // -----------------------------------------------------------------------
    // Diagnostic: "What does the module see for IP X?"
    //
    // Runs the full pipeline an admin would otherwise have to trace through
    // multiple log lines to reproduce:
    //   1. Current config (what values is Config::get() actually returning?)
    //   2. Zone list (what does Client::listZones() return right now, post-cache?)
    //   3. Zone match for an input IP (is findZoneAndPtrName selecting the right zone?)
    //   4. Current PTR content at the located (zone, ptrName) pair
    //
    // Catches every common failure mode: wrong API key (empty zones, auth error),
    // wrong server ID (404), forgotten zone (no match), stale cache (mismatched
    // zones), and typos in the RFC 2317 zone name (parseClasslessZone rejection).
    // -----------------------------------------------------------------------

    echo '<h3 style="margin-top:24px">Diagnose an IP</h3>';
    echo '<p>Runs the exact same pipeline the client-area rDNS panel uses. Useful when a specific IP shows "no zone" in the UI and you need to see <em>why</em>.</p>';

    $diagIp = isset($_GET['diag_ip']) ? trim((string) $_GET['diag_ip']) : '';
    echo '<form method="get" action="" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">';
    // WHMCS passes the module slug via ?module=... — preserve any existing query params
    // by re-emitting the current GET state as hidden fields (except diag_ip itself).
    foreach ($_GET as $k => $v) {
        if ($k === 'diag_ip') {
            continue;
        }
        echo '<input type="hidden" name="' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<input type="text" name="diag_ip" placeholder="IP address (e.g. 198.51.100.42 or 2001:db8::1)" value="' . htmlspecialchars($diagIp, ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" style="max-width:320px;font-family:monospace">';
    echo '<button type="submit" class="btn btn-primary btn-sm">Diagnose</button>';
    echo '</form>';

    if ($diagIp !== '') {
        echo '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;padding:12px;font-family:monospace;font-size:13px;white-space:pre-wrap;word-break:break-all">';

        if (filter_var($diagIp, FILTER_VALIDATE_IP) === false) {
            echo '<span style="color:#dc3545">Invalid IP address.</span>';
        } elseif (! Config::isEnabled()) {
            echo '<span style="color:#dc3545">Addon disabled or missing endpoint/API key. Diagnosis skipped.</span>';
        } else {
            $client = new Client;

            echo '<strong>Config snapshot:</strong>' . "\n";
            echo '  endpoint  = ' . htmlspecialchars($config['endpoint'], ENT_QUOTES, 'UTF-8') . "\n";
            echo '  serverId  = ' . htmlspecialchars($config['serverId'], ENT_QUOTES, 'UTF-8') . "\n";
            echo '  cacheTtl  = ' . $cacheTtl . 's' . "\n";
            echo '  apiKey    = ' . ($config['apiKey'] !== '' ? '(set, ' . strlen($config['apiKey']) . ' chars)' : '(MISSING)') . "\n\n";

            // Always forget cache before diagnose so we see the LIVE state, not a
            // potentially-stale cached list from an earlier misconfigured call.
            $client->forgetZoneCache();
            $zones = $client->listZones();

            echo '<strong>Live zone list (cache purged, ' . count($zones) . ' zones):</strong>' . "\n";
            if (empty($zones)) {
                echo '  <span style="color:#dc3545">NO ZONES RETURNED.</span>' . "\n";
                echo '  Likely causes: wrong API key (PowerDNS returned 401/403), wrong Server ID' . "\n";
                echo '  (PowerDNS returned 404), or api-allow-from blocking the WHMCS host IP.' . "\n";
                echo '  Run the Test Connection button above to see the exact HTTP error.' . "\n\n";
            } else {
                foreach (array_slice($zones, 0, 15) as $z) {
                    echo '  ' . htmlspecialchars($z, ENT_QUOTES, 'UTF-8') . "\n";
                }
                if (count($zones) > 15) {
                    echo '  ... and ' . (count($zones) - 15) . ' more' . "\n";
                }
                echo "\n";
            }

            $ptrName = IpUtil::ptrNameForIp($diagIp);
            echo '<strong>Computed PTR name for ' . htmlspecialchars($diagIp, ENT_QUOTES, 'UTF-8') . ':</strong>' . "\n";
            echo '  ' . htmlspecialchars((string) $ptrName, ENT_QUOTES, 'UTF-8') . "\n\n";

            $loc = IpUtil::findZoneAndPtrName($diagIp, $zones);
            echo '<strong>Zone match (IpUtil::findZoneAndPtrName):</strong>' . "\n";
            if ($loc === null) {
                echo '  <span style="color:#dc3545">NO MATCH.</span>' . "\n";
                echo '  The IP does not fall within any zone returned above.' . "\n";
                if (IpUtil::isIpv4($diagIp)) {
                    $oct = (int) explode('.', $diagIp)[3];
                    echo "  For IPv4: confirm a standard reverse zone exists (one of the listed\n";
                    echo "  zones should end with the first-three-octets-reversed of $diagIp), OR\n";
                    echo "  that an RFC 2317 classless zone exists whose range covers octet $oct.\n";
                }
                if (IpUtil::isIpv6($diagIp)) {
                    echo "  For IPv6: confirm a reverse zone exists ending in .ip6.arpa. whose\n";
                    echo "  nibble prefix matches the high-order bits of $diagIp.\n";
                }
                echo "\n";
            } else {
                echo '  zone    = ' . htmlspecialchars($loc['zone'], ENT_QUOTES, 'UTF-8') . "\n";
                echo '  ptrName = ' . htmlspecialchars($loc['ptrName'], ENT_QUOTES, 'UTF-8') . "\n\n";

                // Actual current PTR content, if any.
                echo '<strong>Current PTR record in PowerDNS:</strong>' . "\n";
                $zoneData = $client->getZone($loc['zone']);
                if ($zoneData === null) {
                    echo '  <span style="color:#dc3545">Unable to fetch zone contents (HTTP error or not found).</span>' . "\n";
                } else {
                    $found = null;
                    foreach ($zoneData['rrsets'] ?? [] as $rr) {
                        if (($rr['type'] ?? '') === 'PTR' && rtrim($rr['name'], '.') === rtrim($loc['ptrName'], '.')) {
                            foreach ($rr['records'] ?? [] as $rec) {
                                if (empty($rec['disabled']) && ! empty($rec['content'])) {
                                    $found = [
                                        'content' => $rec['content'],
                                        'ttl' => (int) ($rr['ttl'] ?? 0),
                                    ];
                                    break 2;
                                }
                            }
                        }
                    }
                    if ($found === null) {
                        echo '  (no PTR record present at ' . htmlspecialchars($loc['ptrName'], ENT_QUOTES, 'UTF-8') . ')' . "\n";
                    } else {
                        echo '  content = ' . htmlspecialchars($found['content'], ENT_QUOTES, 'UTF-8') . "\n";
                        echo '  ttl     = ' . $found['ttl'] . 's' . "\n";
                    }
                }
            }
        }

        echo '</div>';
    }

    echo '</div>';
}
