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
 * qualify. Anything mixed (domains, addons, upgrades, other modules or panels),
 * partly provisioned, or with any payment or applied credit is left for a human.
 */
class UnpaidOrderCanceller
{
    /** Upper bound on cancellations per run. */
    const MAX_PER_RUN = 100;

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
     * Whether every item is a Pending VirtFusionDirect service on $panel and nothing was paid.
     */
    private function qualifies(object $order, int $panel): bool
    {
        $id = (int) $order->id;
        foreach (['tblhostingaddons', 'tbldomains', 'tblupgrades'] as $table) {
            if (DB::table($table)->where('orderid', $id)->exists()) {
                return false;
            }
        }

        $items = DB::table('tblhosting as h')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.orderid', $id)
            ->get(['h.domainstatus', 'h.server', 'p.servertype'])
            ->all();
        if (! $items) {
            return false;
        }
        foreach ($items as $item) {
            if ($item->domainstatus !== 'Pending' || (int) $item->server !== $panel || $item->servertype !== 'VirtFusionDirect') {
                return false;
            }
        }

        $invoiceId = (int) $order->invoiceid;
        if ($invoiceId <= 0) {
            return true;
        }
        $invoice = DB::table('tblinvoices')->where('id', $invoiceId)->first();
        if (! $invoice) {
            return true;
        }
        if ($invoice->status !== 'Unpaid') {
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

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (! isset($this->columns[$key])) {
            $this->columns[$key] = DB::schema()->hasColumn($table, $column);
        }

        return $this->columns[$key];
    }
}
