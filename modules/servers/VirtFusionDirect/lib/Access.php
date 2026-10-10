<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule as DB;

/**
 * WHMCS session and role checks shared by legacy and unified entry points.
 *
 * Permissions are evaluated against the selected client account, not merely
 * the login user. Unsupported runtimes and missing membership fail closed.
 */
class Access
{
    /**
     * Evaluate one authenticated-user/client membership link.
     *
     * An owner flag grants permissions only on that specific account link.
     *
     * @param  object|null  $link  Row from tblusers_clients.
     * @param  string  $permission  WHMCS account permission such as manageproducts.
     */
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

    /**
     * Require an authenticated user with permission on the selected client.
     *
     * @return int Authorized WHMCS client ID, not the login-user ID.
     *
     * @throws \RuntimeException Authentication or account permission denied.
     */
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

    /**
     * Require the exact WHMCS admin permission and return the actor ID.
     *
     * @throws \RuntimeException No authenticated admin or permission.
     */
    public static function admin(string $permission): int
    {
        $current = new CurrentUser;
        $admin = $current->admin();
        if (! $current->isAuthenticatedAdmin() || ! $admin || ! $admin->hasPermission($permission)) {
            throw new \RuntimeException('Administrator permission required.', 403);
        }

        return (int) $admin->id;
    }

    /**
     * Require module-command permission plus membership in the recovery role list.
     *
     * An empty allowlist denies every role. This supplements ordinary admin access.
     *
     * @param  array  $settings  Validated provider settings for the target panel.
     */
    public static function recovery(array $settings): void
    {
        self::admin('Perform Module Command Operations');
        $admin = (new CurrentUser)->admin();
        if (! in_array((int) $admin->roleid, $settings['recoveryRoles'], true)) {
            throw new \RuntimeException('Recovery role required.', 403);
        }
    }

    /**
     * Require POST and validate the current WHMCS session token.
     *
     * @throws \RuntimeException Missing validator, rejected token or wrong method.
     */
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

    /**
     * Identify an already authenticated actor for persistent operation auditing.
     *
     * Call the appropriate permission guard before this helper.
     *
     * @return array{type: string, id: int}
     */
    public static function actor(bool $admin): array
    {
        $current = new CurrentUser;
        $actor = $admin ? $current->admin() : $current->user();

        return ['type' => $admin ? 'admin' : 'user', 'id' => (int) $actor->id];
    }
}
