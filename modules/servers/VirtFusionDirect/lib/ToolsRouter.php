<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Authenticated WHMCS HTTP boundary for the explicit unified action list.
 *
 * Selects customer/admin permissions before dispatch, enforces POST and CSRF
 * for writes, limits JSON input size/depth and emits private, sanitized responses.
 * There is no route accepting a caller-supplied native API path.
 */
class ToolsRouter
{
    /**
     * Dispatch one authenticated HTTP action and terminate through Module::output().
     *
     * All exceptions become sanitized responses. Helpers receive server-resolved
     * context; request fields cannot choose a native path, panel token or owner.
     */
    public static function run(Module $module, string $action, bool $admin): void
    {
        try {
            if (! $admin) {
                Access::client();
            } else {
                $permission = in_array($action, ['providerSettings', 'providerSettingsSave'], true) ? 'Configure Servers'
                    : (in_array($action, ['packMigrationPreview', 'packMigrationApply'], true) ? 'Configure Products/Services' : 'View Clients Products/Services');
                Access::admin($permission);
            }
            if (in_array($action, ['packBindingStatus', 'packBindingReconcile'], true)) {
                if (! $admin) {
                    throw new \RuntimeException('Administrator action.', 403);
                }
                $serviceId = $module->validateServiceID(true);
                $service = Database::getWhmcsService($serviceId);
                if (! $service) {
                    throw new \RuntimeException('Service not found.', 404);
                }
                if ($action === 'packBindingReconcile') {
                    Access::csrf();
                    $result = (new ResourcePack)->reconcile($serviceId, self::input());
                } else {
                    $result = ['binding' => (array) ResourcePack::binding($serviceId)];
                }
            } elseif (in_array($action, ['providerSettingsSave', 'packMigrationApply', 'packMigrationPreview'], true)) {
                if (! $admin) {
                    throw new \RuntimeException('Administrator action.', 403);
                }
                Access::csrf();
                $input = self::input();
                if ($action === 'providerSettingsSave') {
                    ProviderSettings::save(ServiceTools::positiveId($input['panelId'] ?? null), $input['settings'] ?? []);
                    $result = ['saved' => true];
                } else {
                    $result = $action === 'packMigrationPreview' ? PackMigration::preview($input) : PackMigration::apply($input);
                }
            } elseif ($action === 'providerSettings') {
                if (! $admin) {
                    throw new \RuntimeException('Administrator action.', 403);
                }
                Access::admin('Configure Servers');
                $panel = ServiceTools::positiveId($_GET['panelId'] ?? null);
                $cp = $module->getCP($panel);
                if (! $cp) {
                    throw new \RuntimeException('Panel not found.', 404);
                }

                try {
                    $version = PanelVersion::detect($module, $cp);
                } catch (\RuntimeException $e) {
                    $version = null;
                }
                $result = ['settings' => ProviderSettings::get($panel), 'version' => $version];
            } else {
                $serviceId = $module->validateServiceID(true);
                if (! $admin && ! $module->validateUserOwnsService($serviceId)) {
                    throw new \RuntimeException('Service ownership mismatch.', 403);
                }
                $tools = new ServiceTools;
                if ($action === 'capabilities') {
                    $result = $tools->capabilities($serviceId, $admin);
                } elseif ($action === 'packData') {
                    $service = Database::getWhmcsService($serviceId);
                    $pack = (new ResourcePack)->fetchPack($serviceId, $module->getCP((int) $service->server));
                    $packCp = $module->getCP((int) $service->server);
                    foreach (ResourcePack::memberIds($pack ?? []) as $member) {
                        $module->verifyVmOwner($packCp, $member, (int) $service->userid);
                    }
                    $result = ['pack' => array_intersect_key($pack ?? [], array_flip(['id', 'name', 'limits', 'used', 'usage'])), 'members' => ResourcePack::memberIds($pack ?? [])];
                } else {
                    $tool = $_GET['tool'] ?? '';
                    if (! is_string($tool) || ! isset(ServiceTools::ACTIONS[$tool])) {
                        throw new \InvalidArgumentException('Unknown tool.');
                    }
                    $read = ServiceTools::ACTIONS[$tool][1] === 'get';
                    if (! $read) {
                        Access::csrf();
                        $input = self::input();
                    } else {
                        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
                            throw new \RuntimeException('GET required.', 405);
                        }
                        $input = $_GET;
                    }
                    $result = $tools->execute($serviceId, $tool, $input, $admin);
                }
            }
            $module->output(['success' => ! in_array($result['state'] ?? '', ['failed', 'unknown'], true), 'data' => $result], true, true, 200);
        } catch (\InvalidArgumentException $e) {
            $module->output(['success' => false, 'errors' => $e->getMessage()], true, true, 400);
        } catch (\Throwable $e) {
            $code = in_array($e->getCode(), [401, 403, 404, 405, 409, 502, 503], true) ? $e->getCode() : 500;
            Log::insert('ToolsRouter:rejected', ['action' => $action], ['code' => $code]);
            $module->output(['success' => false, 'errors' => $code === 500 ? 'Service tools unavailable.' : $e->getMessage()], true, true, $code);
        }
    }

    /**
     * Decode a JSON object/array with a 64 KiB size and 32-level nesting limit.
     *
     * @throws \InvalidArgumentException Malformed JSON or oversized input.
     */
    private static function input(): array
    {
        $raw = $_POST['payload'] ?? '{}';
        if (! is_string($raw) || strlen($raw) > 65536) {
            throw new \InvalidArgumentException('Invalid request body.');
        }

        try {
            $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid JSON request body.');
        }
        if (! is_array($input)) {
            throw new \InvalidArgumentException('Invalid request body.');
        }

        return $input;
    }
}
