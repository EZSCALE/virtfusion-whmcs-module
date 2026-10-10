<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule as DB;

/** All permission errors fail closed, including unsupported WHMCS runtimes. */
class Access
{
    public static function clientAllowed($link, string $permission): bool
    {
        if (! $link) {
            return false;
        }
        if ((int) ($link->owner ?? 0) === 1) {
            return true;
        }
        $permissions = $link->permissions ?? '';
        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);
            $permissions = is_array($decoded) ? $decoded : explode(',', $permissions);
        }

        return is_array($permissions) && (in_array($permission, $permissions, true) || ($permissions[$permission] ?? false) === true);
    }

    public static function client(string $permission = 'manageproducts'): int
    {
        $current = new CurrentUser;
        $user = $current->user();
        $client = $current->client();
        if (! $user || ! $client || ! $current->isAuthenticatedUser()) {
            throw new \RuntimeException('Authentication required.', 401);
        }
        $link = DB::table('tblusers_clients')->where('auth_user_id', (int) $user->id)->where('client_id', (int) $client->id)->first();
        if (! self::clientAllowed($link, $permission)) {
            throw new \RuntimeException('Service permission required.', 403);
        }

        return (int) $client->id;
    }

    public static function admin(string $permission): int
    {
        $current = new CurrentUser;
        $admin = $current->admin();
        if (! $current->isAuthenticatedAdmin() || ! $admin || ! $admin->hasPermission($permission)) {
            throw new \RuntimeException('Administrator permission required.', 403);
        }

        return (int) $admin->id;
    }

    public static function recovery(array $settings): void
    {
        self::admin('Perform Module Command Operations');
        $admin = (new CurrentUser)->admin();
        if (! in_array((int) $admin->roleid, $settings['recoveryRoles'], true)) {
            throw new \RuntimeException('Recovery role required.', 403);
        }
    }

    public static function csrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new \RuntimeException('POST required.', 405);
        }
        if (! function_exists('check_token')) {
            throw new \RuntimeException('CSRF validation unavailable.', 403);
        }
        if (check_token('WHMCS.default') === false) {
            throw new \RuntimeException('Invalid CSRF token.', 403);
        }
    }

    public static function actor(bool $admin): array
    {
        $current = new CurrentUser;
        $actor = $admin ? $current->admin() : $current->user();

        return ['type' => $admin ? 'admin' : 'user', 'id' => (int) $actor->id];
    }
}
