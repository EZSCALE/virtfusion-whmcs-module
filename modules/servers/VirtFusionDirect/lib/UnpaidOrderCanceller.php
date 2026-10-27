<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

use WHMCS\Database\Capsule as DB;

/**
 * Daily cancellation of abandoned, unpaid VirtFusion-only orders.
 *
 * A client's Pending VirtFusionDirect services count towards the account's billing
 * type (AccountType::COUNTED_STATUSES), so an order abandoned at the payment step
 * can block the client from ordering a product of the other type. Each panel's
 * provider setting `unpaidOrderCancelDays` (default 3, 0 = off) sets the age after
 * which such an order is cancelled through the WHMCS CancelOrder API.
 *
 * Only orders made up entirely of Pending VirtFusionDirect services on that panel
 * qualify. Anything mixed (domains, addons, upgrades, renewals, other modules or
 * panels, foreign invoice lines), partly provisioned (module row, pack binding or
 * open operation), or with any payment or applied credit on any invoice billing
 * its services is left for a human.
 *
 * Disabled panels (tblservers.disabled = 1) are still processed: they take no new
 * orders, but orders already abandoned on them still need cancelling. The age cutoff
 * uses PHP's default timezone, the same one WHMCS writes tblorders.date in.
 */
class UnpaidOrderCanceller
{
    /** Upper bound on cancellations per run. */
    const MAX_PER_RUN = 100;

    /** Invoice line types that bill a hosting service (relid = tblhosting.id). */
    const SERVICE_LINES = ['Hosting', 'PromoHosting', 'Setup'];

    /** Candidate orders read per query page. */
    const PAGE = 200;

    /** @var array<string,bool> */
    private $columns = [];

    /**
     * Cancel qualifying orders on every VirtFusionDirect panel.
     *
     * @return array{checked:int,cancelled:int,skipped:int,failed:int,orderIds:list<int>}
     */
    public function run(): array
    {
        $result = ['checked' => 0, 'cancelled' => 0, 'skipped' => 0, 'failed' => 0, 'orderIds' => []];

        try {
            $panels = DB::table('tblservers')->where('type', 'VirtFusionDirect')->orderBy('id')->pluck('id')->all();
        } catch (\Throwable $e) {
            Log::insert('UnpaidOrderCanceller:panels', [], $e->getMessage());

            return $result;
        }

        foreach ($panels as $panel) {
            if (count($result['orderIds']) >= self::MAX_PER_RUN) {
                break;
            }

            try {
                $days = (int) ProviderSettings::get((int) $panel)['unpaidOrderCancelDays'];
                if ($days > 0) {
                    $this->panel((int) $panel, $days, $result);
                }
            } catch (\Throwable $e) {
                Log::insert('UnpaidOrderCanceller:panel', ['panel' => (int) $panel], $e->getMessage());
            }
        }

        Log::insert('UnpaidOrderCanceller:run', ['panels' => count($panels)], $result);

        return $result;
    }

    /**
     * Walk one panel's old Pending orders that contain a VirtFusionDirect service.
     */
    private function panel(int $panel, int $days, array &$result): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $after = 0;
        while (count($result['orderIds']) < self::MAX_PER_RUN) {
            $ids = DB::table('tblorders as o')
                ->join('tblhosting as h', 'h.orderid', '=', 'o.id')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('o.status', 'Pending')
                ->where('o.date', '<', $cutoff)
                ->where('o.id', '>', $after)
                ->where('h.server', $panel)
                ->where('p.servertype', 'VirtFusionDirect')
                ->orderBy('o.id')
                ->distinct()
                ->limit(self::PAGE)
                ->pluck('o.id')
                ->all();
            if (! $ids) {
                return;
            }
            foreach ($ids as $id) {
                $id = (int) $id;
                $after = $id;
                if (count($result['orderIds']) >= self::MAX_PER_RUN) {
                    return;
                }
                $result['checked']++;

                try {
                    $order = DB::table('tblorders')->where('id', $id)->first();
                    if (! $order || ! $this->qualifies($order, $panel)) {
                        $result['skipped']++;

                        continue;
                    }
                    $this->cancel($order, $result);
                } catch (\Throwable $e) {
                    $result['failed']++;
                    Log::insert('UnpaidOrderCanceller:failed', ['orderid' => $id], $e->getMessage());
                }
            }
        }
    }

    /**
     * Whether the order is VirtFusion-only, untouched by provisioning and unpaid.
     *
     * Any doubt means no: a missing invoice row, an unknown invoice line, a renewal,
     * a module row, pack binding or open operation for one of its services, or any
     * payment or credit on any invoice that bills one of its services.
     */
    private function qualifies(object $order, int $panel): bool
    {
        $id = (int) $order->id;
        foreach (['tblhostingaddons', 'tbldomains', 'tblupgrades'] as $table) {
            if (DB::table($table)->where('orderid', $id)->exists()) {
                return false;
            }
        }
        if ($this->hasColumn('tblorders', 'renewals') && trim((string) ($order->renewals ?? '')) !== '') {
            return false;
        }

        $items = DB::table('tblhosting as h')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.orderid', $id)
            ->get(['h.id', 'h.domainstatus', 'h.server', 'p.servertype'])
            ->all();
        if (! $items) {
            return false;
        }
        $serviceIds = [];
        foreach ($items as $item) {
            if ($item->domainstatus !== 'Pending' || (int) $item->server !== $panel || $item->servertype !== 'VirtFusionDirect') {
                return false;
            }
            $serviceIds[] = (int) $item->id;
        }

        if ($this->provisioningStarted($serviceIds)) {
            return false;
        }

        $invoiceId = (int) $order->invoiceid;
        $invoices = [];
        if ($invoiceId > 0) {
            if (! DB::table('tblinvoices')->where('id', $invoiceId)->exists()) {
                return false;
            }
            foreach (DB::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get(['type', 'relid'])->all() as $line) {
                if (in_array($line->type, self::SERVICE_LINES, true)) {
                    if (! in_array((int) $line->relid, $serviceIds, true)) {
                        return false;
                    }
                } elseif ($line->type !== 'GroupDiscount') {
                    return false;
                }
            }
            $invoices[] = $invoiceId;
        }

        // Every invoice that bills one of these services, the order's own included.
        $billing = DB::table('tblinvoiceitems')
            ->whereIn('type', self::SERVICE_LINES)
            ->whereIn('relid', $serviceIds)
            ->distinct()
            ->pluck('invoiceid')
            ->all();
        foreach (array_unique(array_merge($invoices, array_map('intval', $billing))) as $invoice) {
            if (! $this->unpaid((int) $invoice)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any service already has a module row, a pack binding or an open operation.
     *
     * @param  list<int>  $serviceIds
     */
    private function provisioningStarted(array $serviceIds): bool
    {
        foreach ([Database::SYSTEM_TABLE, ResourcePack::TABLE] as $table) {
            if ($this->hasTable($table) && DB::table($table)->whereIn('service_id', $serviceIds)->exists()) {
                return true;
            }
        }

        return $this->hasTable(Operation::TABLE) && DB::table(Operation::TABLE)
            ->whereIn('service_id', $serviceIds)
            ->whereNotIn('state', ['completed', 'failed'])
            ->exists();
    }

    /**
     * Whether an invoice exists, is Unpaid and has no payment, credit or transaction.
     */
    private function unpaid(int $invoiceId): bool
    {
        $invoice = DB::table('tblinvoices')->where('id', $invoiceId)->first();
        if (! $invoice || $invoice->status !== 'Unpaid') {
            return false;
        }
        foreach (['credit', 'amountpaid'] as $column) {
            if ($this->hasColumn('tblinvoices', $column) && (float) $invoice->{$column} > 0) {
                return false;
            }
        }

        return ! DB::table('tblaccounts')->where('invoiceid', $invoiceId)->exists();
    }

    /**
     * Cancel the order, then make sure its invoice is not left Unpaid.
     *
     * WHMCS's order cancellation normally moves an Unpaid invoice to Cancelled itself,
     * but the CancelOrder API documentation does not promise it. The invoice is
     * re-read afterwards and only an invoice still Unpaid is cancelled explicitly.
     */
    private function cancel(object $order, array &$result): void
    {
        $id = (int) $order->id;
        $response = localAPI('CancelOrder', ['orderid' => $id, 'cancelsub' => false, 'noemail' => true]);
        if (($response['result'] ?? '') !== 'success') {
            $result['failed']++;
            Log::insert('UnpaidOrderCanceller:failed', ['orderid' => $id], (string) ($response['message'] ?? 'CancelOrder failed'));

            return;
        }
        $result['cancelled']++;
        $result['orderIds'][] = $id;

        $invoiceId = (int) $order->invoiceid;
        if ($invoiceId <= 0) {
            return;
        }

        try {
            $status = DB::table('tblinvoices')->where('id', $invoiceId)->value('status');
            if ($status === 'Unpaid') {
                $update = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
                if (($update['result'] ?? '') !== 'success') {
                    Log::insert('UnpaidOrderCanceller:invoice', ['orderid' => $id, 'invoiceid' => $invoiceId], (string) ($update['message'] ?? 'UpdateInvoice failed'));
                }
            }
        } catch (\Throwable $e) {
            Log::insert('UnpaidOrderCanceller:invoice', ['orderid' => $id, 'invoiceid' => $invoiceId], $e->getMessage());
        }
    }

    private function hasTable(string $table): bool
    {
        $key = $table . '.';
        if (! isset($this->columns[$key])) {
            $this->columns[$key] = DB::schema()->hasTable($table);
        }

        return $this->columns[$key];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (! isset($this->columns[$key])) {
            $this->columns[$key] = DB::schema()->hasColumn($table, $column);
        }

        return $this->columns[$key];
    }
}
