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
}
