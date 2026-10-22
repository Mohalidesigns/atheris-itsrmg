<?php

namespace App\Services\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceLine;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\PurchaseOrderLine;

/**
 * Three-way match of an invoice against its purchase order and goods receipts (FIN-03.04, plan P3-10). Pure: it reads and returns
 * a verdict, the InvoiceService stores it. Tolerance is the stricter of a percentage of the expected amount and an absolute
 * amount in base currency; billing above what was received and accepted, or in another currency, is always a variance.
 */
class ThreeWayMatcher
{
    public function __construct(private readonly BudgetPhasing $phasing) {}

    /**
     * @return array{status:string,expected:string,invoiced:string,variance:string,variance_base:string,limit:string,lines:list<array<string,mixed>>}
     */
    public function evaluate(Invoice $i, string $tolPct, string $tolAbsBase): array
    {
        $po = $i->po_id ? PurchaseOrder::withoutGlobalScopes()->find($i->po_id) : null;
        $lines = InvoiceLine::withoutGlobalScopes()->where('invoice_id', $i->id)->orderBy('line_no')->get();
        $invoiced = '0.00';
        foreach ($lines as $l) {
            $invoiced = bcadd($invoiced, (string) $l->amount, 2);
        }
        if (! $po) {
            return ['status' => 'unmatched', 'expected' => '0.00', 'invoiced' => $invoiced, 'variance' => '0.00', 'variance_base' => '0.00', 'limit' => '0.00', 'lines' => []];
        }
        $rate = (string) $i->fx_rate;
        $currencyMismatch = $i->currency !== $po->currency;
        $expected = '0.00';
        $rows = [];
        $hard = false;
        foreach ($lines as $l) {
            $pl = $l->po_line_id ? PurchaseOrderLine::withoutGlobalScopes()->where('po_id', $po->id)->find($l->po_line_id) : null;
            $flags = [];
            if (! $pl) {
                $flags[] = 'no_po_line';
                $lineExpected = '0.00';
                $received = $prior = $ordered = '0.0000';
                $poUnit = null;
            } else {
                $prior = (string) InvoiceLine::withoutGlobalScopes()->where('po_line_id', $pl->id)->where('invoice_id', '!=', $i->id)
                    ->whereIn('invoice_id', Invoice::withoutGlobalScopes()->whereNotIn('status', ['cancelled', 'rejected'])->whereNull('deleted_at')->select('id'))->sum('quantity');
                $received = bcadd((string) $pl->received_quantity, '0', 4);
                $ordered = bcadd((string) $pl->quantity, '0', 4);
                $available = bcsub($received, bcadd($prior, '0', 4), 4);
                bccomp($available, '0', 4) < 0 && ($available = '0.0000');
                $qty = bcadd((string) $l->quantity, '0', 4);
                bccomp($qty, $available, 4) > 0 && ($flags[] = 'over_quantity');
                bccomp((string) $l->unit_cost, (string) $pl->unit_cost, 2) !== 0 && ($flags[] = 'price_change');
                $payable = bccomp($qty, $available, 4) < 0 ? $qty : $available;
                $lineExpected = $this->phasing->round(bcmul($payable, (string) $pl->unit_cost, 8), 2);
                $poUnit = bcadd((string) $pl->unit_cost, '0', 2);
            }
            $currencyMismatch && ($flags[] = 'currency_mismatch');
            ! empty(array_intersect($flags, ['over_quantity', 'no_po_line', 'currency_mismatch'])) && ($hard = true);
            $expected = bcadd($expected, $lineExpected, 2);
            $rows[] = [
                'po_line_id' => $l->po_line_id, 'description' => $l->description, 'ordered_qty' => $ordered, 'received_qty' => $received, 'prior_invoiced_qty' => bcadd($prior, '0', 4),
                'invoiced_qty' => bcadd((string) $l->quantity, '0', 4), 'po_unit_cost' => $poUnit, 'invoice_unit_cost' => bcadd((string) $l->unit_cost, '0', 2),
                'expected' => $lineExpected, 'amount' => bcadd((string) $l->amount, '0', 2), 'variance' => bcsub((string) $l->amount, $lineExpected, 2), 'flags' => $flags,
            ];
        }
        $variance = bcsub($invoiced, $expected, 2);
        $limit = $this->limit($expected, $rate, $tolPct, $tolAbsBase);
        $within = ! $hard && bccomp($this->abs($variance), $limit, 2) <= 0;

        return [
            'status' => $within ? 'matched' : 'variance', 'expected' => $expected, 'invoiced' => $invoiced, 'variance' => $variance,
            'variance_base' => $this->phasing->round(bcmul($variance, $rate, 8), 2), 'limit' => $limit, 'lines' => $rows,
        ];
    }

    /** The tolerated difference in document currency: the lower of the percentage of the expected amount and the absolute base amount. */
    public function limit(string $expected, string $rate, string $tolPct, string $tolAbsBase): string
    {
        $byPct = $this->phasing->round(bcmul($this->abs($expected), bcdiv($tolPct, '100', 8), 8), 2);
        $byAbs = bccomp($rate, '0', 8) > 0 ? $this->phasing->round(bcdiv($tolAbsBase, $rate, 8), 2) : $byPct;

        return bccomp($byPct, $byAbs, 2) <= 0 ? $byPct : $byAbs;
    }

    public function within(string $delta, string $expected, string $rate, string $tolPct, string $tolAbsBase): bool
    {
        return bccomp($this->abs($delta), $this->limit($expected, $rate, $tolPct, $tolAbsBase), 2) <= 0;
    }

    private function abs(string $v): string
    {
        return ltrim($v, '-') === '' ? '0' : ltrim($v, '-');
    }
}
