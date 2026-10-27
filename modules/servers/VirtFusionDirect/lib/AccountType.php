<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * One billing type per client account.
 *
 * VirtFusion self-service is a per-user setting, so a client's VirtFusion user can only
 * run one mode at a time. A product's billing type is its Self-Service Mode
 * (configoption4: 0 Disabled, 1 Hourly, 2 Resource Packs, 3 Both). A client may only
 * hold VirtFusionDirect services that share one billing type; checkout and provisioning
 * refuse anything else instead of overwriting the user's live mode.
 *
 * An order must match ALL existing services, so a legacy account whose services already
 * disagree ("conflicted") refuses every new VirtFusion order until an operator resolves it.
 */
class AccountType
{
    /** accountType() result for a client whose existing services disagree. */
    public const CONFLICTED = -1;

    /** checkoutDecision() results. */
    public const DECISION_ALLOW = 'allow';

    public const DECISION_REFUSE = 'refuse';

    /** Account conflict for a client who is not authenticated: log only, never reveal it. */
    public const DECISION_LOG = 'log';

    /** Service statuses that hold the account's billing type. */
    public const COUNTED_STATUSES = ['Active', 'Suspended', 'Pending'];

    public const CHECKOUT_MESSAGE = 'This product uses a different billing type from the services already on your account. Please use a separate account or contact support.';

    public const PROVISION_MESSAGE = 'Billing type conflict: this client already has VirtFusion services with a different billing type. Provisioning was not attempted.';

    public const CHECK_FAILED_MESSAGE = 'Billing type check failed (database error); provisioning was not attempted. Retry Create.';

    public const CHANGE_MESSAGE = "Billing type conflict: the new package uses a different billing type from this client's other VirtFusion services. Package change was not attempted.";

    public const CHANGE_CHECK_FAILED_MESSAGE = 'Billing type check failed (database error); package change was not attempted. Retry Change Package.';

    /**
     * Billing type of a product row or WHMCS module params.
     *
     * Accepts the stored dropdown form ("1|Hourly") as well as the bare key. Blank or
     * non-numeric means Disabled (0) for server products and Resource Packs (2) for
     * Resource Pack products.
     *
     * @param  object|array  $product  tblproducts row or module params
     */
    public static function billingType($product): int
    {
        $product = (array) $product;
        $isPack = ($product['configoption9'] ?? '') === 'Resource Pack';
        $raw = trim((string) ($product['configoption4'] ?? ''));
        if (preg_match('/^(\d+)(?:\|.*)?$/s', $raw, $m)) {
            return (int) $m[1];
        }

        return $isPack ? 2 : 0;
    }

    /**
     * Distinct billing types of a client's counted VirtFusionDirect services, sorted.
     *
     * Throws on database errors; callers decide how to fail.
     *
     * @return int[]
     */
    public static function existingTypes(int $clientId, int $excludeServiceId = 0): array
    {
        $query = DB::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->where('h.userid', $clientId)
            ->where('p.servertype', 'VirtFusionDirect')
            ->whereIn('h.domainstatus', self::COUNTED_STATUSES);
        if ($excludeServiceId > 0) {
            $query->where('h.id', '!=', $excludeServiceId);
        }

        $types = [];
        foreach ($query->get(['p.configoption4', 'p.configoption9']) as $row) {
            $types[self::billingType($row)] = true;
        }
        $types = array_keys($types);
        sort($types);

        return $types;
    }

    /**
     * The account's billing type: null with no services, CONFLICTED when they disagree.
     *
     * @param  int[]  $existingTypes  From existingTypes()
     */
    public static function accountType(array $existingTypes): ?int
    {
        if ($existingTypes === []) {
            return null;
        }

        return count($existingTypes) === 1 ? (int) reset($existingTypes) : self::CONFLICTED;
    }

    /**
     * Whether a product of this billing type may join an account with these existing types.
     *
     * @param  int[]  $existingTypes  From existingTypes()
     */
    public static function compatible(int $productType, array $existingTypes): bool
    {
        $account = self::accountType($existingTypes);
        if ($account === null) {
            return true;
        }

        // A conflicted account (CONFLICTED is never a product type) refuses everything.
        return $account === $productType;
    }

    /**
     * Checkout decision: true when cart items disagree with each other or with the account.
     *
     * @param  int[]  $cartTypes  Billing types of the VirtFusionDirect products in the cart
     * @param  int[]  $existingTypes  The client's existing types ([] when logged out)
     */
    public static function checkoutConflict(array $cartTypes, array $existingTypes): bool
    {
        $distinct = array_values(array_unique(array_map('intval', $cartTypes)));
        if ($distinct === []) {
            return false;
        }
        if (count($distinct) > 1) {
            return true;
        }

        return ! self::compatible($distinct[0], $existingTypes);
    }

    /**
     * Checkout outcome.
     *
     * Cart items that disagree are refused for everyone. The account rule refuses only for
     * an already-authenticated client: a "login at checkout" visitor has not proven they own
     * the account yet (the module never checks passwords), and refusing would disclose that
     * the account exists, has VirtFusion services and their billing type. That case is LOG
     * only; provisioning's fail-closed guard refuses it later.
     *
     * @param  int[]  $cartTypes  Billing types of the VirtFusionDirect products in the cart
     * @param  int[]  $existingTypes  Existing types of the resolved client ([] when none)
     * @param  bool  $authenticated  Whether the client came from CurrentUser, not loginemail
     */
    public static function checkoutDecision(array $cartTypes, array $existingTypes, bool $authenticated): string
    {
        if (self::checkoutConflict($cartTypes, [])) {
            return self::DECISION_REFUSE;
        }
        if (! self::checkoutConflict($cartTypes, $existingTypes)) {
            return self::DECISION_ALLOW;
        }

        return $authenticated ? self::DECISION_REFUSE : self::DECISION_LOG;
    }

    /**
     * Provisioning guard: the refusal message, or null when the create may proceed.
     *
     * Compares the product with the client's other counted services (this service excluded).
     * Fails closed: a database error refuses the create, since this is the last check before
     * VirtFusion's per-user self-service mode is touched.
     *
     * @param  array  $params  WHMCS module params
     */
    public static function provisioningConflict(array $params): ?string
    {
        return self::guard($params, 'createAccount', self::PROVISION_MESSAGE, self::CHECK_FAILED_MESSAGE);
    }

    /**
     * ChangePackage guard, same rule as provisioningConflict().
     *
     * WHMCS has already switched tblhosting.packageid to the new product when the module
     * runs, so the previous product cannot be read reliably from $params. The new product
     * is compared with the client's OTHER counted services only; a client with no other
     * services may change to any billing type. Unlike Create, a conflicted legacy account
     * may change to a type already among its other services, so a type-preserving change
     * is never blocked by pre-existing disagreement.
     *
     * @param  array  $params  WHMCS module params (new product)
     */
    public static function changeConflict(array $params): ?string
    {
        return self::guard($params, 'changePackage', self::CHANGE_MESSAGE, self::CHANGE_CHECK_FAILED_MESSAGE, true);
    }

    /**
     * @param  bool  $allowAnyExisting  Accept a type already held by any other service (ChangePackage)
     */
    private static function guard(array $params, string $action, string $conflictMessage, string $failedMessage, bool $allowAnyExisting = false): ?string
    {
        $clientId = (int) ($params['userid'] ?? 0);
        $serviceId = (int) ($params['serviceid'] ?? 0);

        try {
            $type = self::billingType($params);
            $existing = self::existingTypes($clientId, $serviceId);
            if (self::compatible($type, $existing) || ($allowAnyExisting && in_array($type, $existing, true))) {
                return null;
            }
            if (self::accountType($existing) === self::CONFLICTED) {
                Log::insert('AccountType:legacyConflict', ['client' => $clientId, 'types' => $existing], 'existing services disagree');
            }
            Log::insert($action . ':billingTypeConflict', ['client' => $clientId, 'service' => $serviceId,
                'productType' => $type, 'existingTypes' => $existing], $conflictMessage);

            return $conflictMessage;
        } catch (\Throwable $e) {
            Log::insert($action . ':billingTypeCheckFailed', ['client' => $clientId, 'service' => $serviceId], $e->getMessage());

            return $failedMessage;
        }
    }

    /**
     * The WHMCS client whose account a checkout will land on, or null when unknown.
     *
     * Uses the logged-in client; otherwise, for the "existing customer" login-at-checkout
     * form, resolves loginemail via the owning user (tblusers -> tblusers_clients owner=1),
     * falling back to tblclients.email. Ambiguous matches (several owned clients or several
     * clients with the email) return null: provisioning re-checks before any write.
     * Throws on database errors; the checkout hook fails open.
     *
     * @param  array  $vars  ShoppingCartValidateCheckout hook vars
     */
    public static function clientForCheckout(?int $currentClientId, array $vars): ?int
    {
        if ($currentClientId !== null && $currentClientId > 0) {
            return $currentClientId;
        }
        $email = strtolower(trim((string) ($vars['loginemail'] ?? '')));
        if (($vars['custtype'] ?? '') !== 'existing' || $email === '') {
            return null;
        }

        $schema = DB::schema();
        if ($schema->hasTable('tblusers') && $schema->hasTable('tblusers_clients')) {
            $userIds = DB::table('tblusers')->whereRaw('LOWER(email) = ?', [$email])->pluck('id')->all();
            if ($userIds !== []) {
                $owned = DB::table('tblusers_clients')->whereIn('auth_user_id', $userIds)->where('owner', 1)
                    ->distinct()->pluck('client_id')->all();
                if (count($owned) === 1) {
                    return (int) $owned[0];
                }
                if (count($owned) > 1) {
                    return null;
                }
            }
        }

        $clients = DB::table('tblclients')->whereRaw('LOWER(email) = ?', [$email])->pluck('id')->all();

        return count($clients) === 1 ? (int) $clients[0] : null;
    }
}
