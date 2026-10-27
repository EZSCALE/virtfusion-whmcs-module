<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * Source contracts for client.php, which boots WHMCS and cannot be executed in tests.
 */
final class EntryPointContractTest extends TestCase
{
    private const CLIENT = __DIR__ . '/../modules/servers/VirtFusionDirect/client.php';

    private const ADMIN = __DIR__ . '/../modules/servers/VirtFusionDirect/admin.php';

    private const MODULE_JS = __DIR__ . '/../modules/servers/VirtFusionDirect/templates/js/module.js';

    private function caseBlock(string $file, string $action): string
    {
        $source = (string) file_get_contents($file);
        $start = strpos($source, "case '" . $action . "':");
        $this->assertNotFalse($start, "case '{$action}' not found");
        $ends = array_filter([
            strpos($source, "\n        case '", $start + 1),
            strpos($source, "\n        default:", $start + 1),
        ], static fn ($p) => $p !== false);

        return substr($source, $start, ($ends ? min($ends) : strlen($source)) - $start);
    }

    #[Test]
    public function reset_password_requires_productsso_and_active_status(): void
    {
        $block = $this->caseBlock(self::CLIENT, 'resetPassword');
        $sso = strpos($block, "Access::client('productsso')");
        $this->assertNotFalse($sso);
        $this->assertLessThan(strpos($block, 'resetUserPassword'), $sso);
        $this->assertStringContainsString("requireServiceStatus(\$serviceID, ['Active'])", $block);
    }

    #[Test]
    public function legacy_mutations_gate_status_and_unified_locks(): void
    {
        foreach (['powerAction', 'rename', 'resetServerPassword', 'rebuild'] as $action) {
            $block = $this->caseBlock(self::CLIENT, $action);
            $this->assertStringContainsString("requireServiceStatus(\$serviceID, ['Active'])", $block, $action);
            $lock = strpos($block, 'requireNoUnresolvedOperation($serviceID)');
            $this->assertNotFalse($lock, $action);
            $rate = strpos($block, 'requireRateLimit');
            if ($rate !== false) {
                $this->assertLessThan($rate, $lock, $action);
            }
        }
    }

    #[Test]
    public function mutations_keep_post_and_same_origin(): void
    {
        foreach (['resetPassword', 'powerAction', 'rename', 'resetServerPassword', 'rebuild'] as $action) {
            $block = $this->caseBlock(self::CLIENT, $action);
            $this->assertStringContainsString('requirePost()', $block, $action);
            $this->assertStringContainsString('requireSameOrigin()', $block, $action);
        }
    }

    #[Test]
    public function client_server_data_is_throttled_and_cached(): void
    {
        $block = $this->caseBlock(self::CLIENT, 'serverData');
        $this->assertStringContainsString('serverDataView(', $block);
        $this->assertStringContainsString("requireRateLimit('serverData:'", $block);
        $this->assertStringNotContainsString('fetchServerData(', $block);
    }

    #[Test]
    public function admin_server_data_requires_post_and_csrf(): void
    {
        $block = $this->caseBlock(self::ADMIN, 'serverData');
        $fetch = strpos($block, 'fetchServerData');
        $this->assertNotFalse($fetch);
        foreach (['requirePost()', 'requireSameOrigin()'] as $guard) {
            $at = strpos($block, $guard);
            $this->assertNotFalse($at, $guard);
            $this->assertLessThan($fetch, $at, $guard);
        }
    }

    #[Test]
    public function rebuild_validates_hostname_length_and_labels(): void
    {
        $block = $this->caseBlock(self::CLIENT, 'rebuild');
        $check = strpos($block, 'Module::isValidHostname($hostname, 63)');
        $this->assertNotFalse($check);
        $this->assertLessThan(strpos($block, 'rebuildServer('), $check);
        $this->assertStringNotContainsString("preg_replace('/[^a-zA-Z0-9.\\-]/'", $block);
    }

    #[Test]
    public function module_js_posts_admin_server_data(): void
    {
        $source = (string) file_get_contents(self::MODULE_JS);
        $start = strpos($source, 'function vfServerDataAdmin(');
        $this->assertNotFalse($start);
        $end = strpos($source, "\nfunction ", $start + 1);
        $body = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
        $this->assertStringContainsString('type: "POST"', $body);
        $this->assertStringNotContainsString('type: "GET"', $body);
    }

    #[Test]
    public function client_top_level_catch_maps_runtime_codes(): void
    {
        $source = (string) file_get_contents(self::CLIENT);
        $catch = substr($source, (int) strrpos($source, '} catch (Throwable $e) {'));
        $this->assertStringContainsString('Module::clientError($e)', $catch);
        $this->assertStringContainsString("\$error['status']", $catch);
        $this->assertStringNotContainsString(', 500);', $catch);
    }

    #[Test]
    public function rebuild_validates_input_before_burning_the_rate_limit(): void
    {
        $block = $this->caseBlock(self::CLIENT, 'rebuild');
        $rate = strpos($block, "requireRateLimit('rebuild:'");
        $this->assertNotFalse($rate);
        foreach (['Module::isValidHostname($hostname, 63)', '$osId <= 0', 'SshKeys::publicKey($sshKey)'] as $check) {
            $at = strpos($block, $check);
            $this->assertNotFalse($at, $check);
            $this->assertLessThan($rate, $at, $check);
        }
        foreach (['requireSameOrigin()', 'validateUserOwnsService(', 'requireProvisionedService(', 'requireServiceStatus(', 'requireNoUnresolvedOperation('] as $guard) {
            $at = strpos($block, $guard);
            $this->assertNotFalse($at, $guard);
            $this->assertLessThan(strpos($block, 'Module::isValidHostname($hostname, 63)'), $at, $guard);
        }
    }

    #[Test]
    public function busy_server_maps_to_409_on_locked_mutations(): void
    {
        foreach (['powerAction' => 'serverPowerAction(', 'resetServerPassword' => 'resetServerPassword(', 'rebuild' => 'rebuildServer('] as $action => $call) {
            $block = $this->caseBlock(self::CLIENT, $action);
            $busy = strpos($block, '$vf->serverWasBusy()');
            $this->assertNotFalse($busy, $action);
            $this->assertLessThan($busy, strpos($block, $call), $action);
            $this->assertStringContainsString("'errors' => Module::SERVER_BUSY_MESSAGE], true, true, 409);", $block, $action);
        }
    }

    #[Test]
    public function ssh_key_mutations_are_post_same_origin_owned_and_throttled(): void
    {
        foreach (['sshKeyAdd' => 'addClientSshKey(', 'sshKeyDelete' => 'deleteClientSshKey('] as $action => $call) {
            $block = $this->caseBlock(self::CLIENT, $action);
            $api = strpos($block, 'new ConfigureService(');
            $this->assertNotFalse($api, $action);
            $this->assertLessThan(strpos($block, $call), $api, $action);
            $previous = -1;
            $limit = $action === 'sshKeyAdd' ? "\$vf->requireRateLimit('sshKeyAdd:' . \$clientId, 5)" : "\$vf->requireRateLimit('sshKeyDelete:' . \$clientId . ':' . \$keyId, 2)";
            foreach (['$vf->requirePost()', '$vf->requireSameOrigin()', '$vf->validateUserOwnsService($serviceID)', $limit] as $guard) {
                $at = strpos($block, $guard);
                $this->assertNotFalse($at, "{$action}: {$guard}");
                $this->assertGreaterThan($previous, $at, "{$action}: {$guard} order");
                $this->assertLessThan($api, $at, "{$action}: {$guard} before API");
                $previous = $at;
            }
            $this->assertStringContainsString("'service <> owner mismatch'], true, true, 403);", $block, $action);
        }
    }

    #[Test]
    public function ssh_key_add_validates_input_before_the_rate_limit(): void
    {
        $block = $this->caseBlock(self::CLIENT, 'sshKeyAdd');
        $rate = strpos($block, 'requireRateLimit(');
        foreach (['SshKeys::name(', 'SshKeys::publicKey('] as $check) {
            $this->assertNotFalse(strpos($block, $check), $check);
            $this->assertLessThan($rate, strpos($block, $check), $check);
        }
        $delete = $this->caseBlock(self::CLIENT, 'sshKeyDelete');
        $this->assertLessThan(strpos($delete, 'requireRateLimit('), strpos($delete, 'ServiceTools::positiveId('));
    }

    #[Test]
    public function ssh_key_list_never_returns_key_bodies(): void
    {
        $block = $this->caseBlock(self::CLIENT, 'sshKeys');
        $this->assertStringContainsString('SshKeys::present(', $block);
        $this->assertStringNotContainsString("'publicKey'", $block);
    }

    #[Test]
    public function admin_top_level_catch_maps_runtime_codes(): void
    {
        $source = (string) file_get_contents(self::ADMIN);
        $catch = substr($source, (int) strrpos($source, '} catch (Throwable $e) {'));
        $this->assertStringContainsString('Module::clientError($e)', $catch);
        $this->assertStringNotContainsString(', 500);', $catch);
    }

    #[Test]
    public function ssh_key_write_with_failed_refresh_still_succeeds(): void
    {
        foreach (['sshKeyAdd', 'sshKeyDelete'] as $action) {
            $block = $this->caseBlock(self::CLIENT, $action);
            $this->assertStringContainsString('$keys === null', $block, $action);
            $this->assertStringContainsString('Refresh the page', $block, $action);
        }
        $js = (string) file_get_contents(self::MODULE_JS);
        $this->assertMatchesRegularExpression('/response\.data === null[\s\S]{0,200}vfLoadSshKeys\(/', $js);
    }

    #[Test]
    public function rebuild_reports_module_refusals(): void
    {
        $block = $this->caseBlock(self::CLIENT, 'rebuild');
        $this->assertStringContainsString('$vf->lastRefusal()', $block);
    }
}
