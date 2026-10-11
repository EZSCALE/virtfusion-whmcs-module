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
 * Legacy accounts whose services already disagree are "conflicted": every self-service
 * order (type > 0) is refused, and a normal (type 0) order is allowed only when at least
 * one existing service is already normal.
 */
class AccountType
{
    /** accountType() result for a client whose existing services disagree. */
    public const CONFLICTED = -1;

    /** Service statuses that hold the account's billing type. */
    public const COUNTED_STATUSES = ['Active', 'Suspended', 'Pending'];

    public const CHECKOUT_MESSAGE = 'This product uses a different billing type from the services already on your account. Please use a separate account or contact support.';

    public const PROVISION_MESSAGE = 'Billing type conflict: this client already has VirtFusion services with a different billing type. Provisioning was not attempted.';

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

        if (count($types) > 1) {
            Log::insert('AccountType:legacyConflict', ['client' => $clientId, 'types' => $types], 'existing services disagree');
        }

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
        if ($account !== self::CONFLICTED) {
            return $account === $productType;
        }

        return $productType === 0 && in_array(0, $existingTypes, true);
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
     * Provisioning guard: the refusal message, or null when the create may proceed.
     *
     * Compares the product with the client's other counted services (this service excluded).
     * A database error is logged and does not block; reEnableSelfService() still refuses to
     * overwrite a different live mode on an existing VirtFusion user.
     *
     * @param  array  $params  WHMCS module params
     */
    public static function provisioningConflict(array $params): ?string
    {
        $clientId = (int) ($params['userid'] ?? 0);
        $serviceId = (int) ($params['serviceid'] ?? 0);

        try {
            $type = self::billingType($params);
            $existing = self::existingTypes($clientId, $serviceId);
            if (self::compatible($type, $existing)) {
                return null;
            }
            Log::insert('createAccount:billingTypeConflict', ['client' => $clientId, 'service' => $serviceId,
                'productType' => $type, 'existingTypes' => $existing], self::PROVISION_MESSAGE);

            return self::PROVISION_MESSAGE;
        } catch (\Throwable $e) {
            Log::insert('createAccount:billingTypeCheckFailed', ['client' => $clientId, 'service' => $serviceId], $e->getMessage());

            return null;
        }
    }
}
