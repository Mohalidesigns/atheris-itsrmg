<?php

namespace App\Services\Finance;

use App\Models\Asset;
use App\Models\Finance\Category;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\GoodsReceipt;
use App\Models\Finance\GoodsReceiptLine;
use App\Models\Finance\Invoice;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\PurchaseOrderLine;
use App\Models\Finance\TaxType;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Illuminate\Support\Facades\DB;

/** Purchase orders and goods receipts (FIN-03.03): commit an approved request, receive goods, close or cancel. */
class ProcurementService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public const TRANSITIONS = [
        'receive' => ['from' => ['open', 'partially_received']],
        'close' => ['from' => ['open', 'partially_received', 'received']],
        'cancel' => ['from' => ['open']],
    ];

    public function __construct(
        private readonly CurrencyService $fx,
        private readonly FinanceSettings $settings,
        private readonly FinanceTax $tax,
        private readonly ThreeWayMatcher $matcher,
        private readonly ExpenseService $expenses,
        private readonly BudgetAvailability $availability,
        private readonly BudgetPhasing $phasing,
    ) {}

    /** @param array{po_date?:?string,expected_delivery_on?:?string,external_reference?:?string,tax_type_id?:?int,lines?:array<int,array<string,mixed>>} $data */
    public function commit(ExpenseRequest $e, array $data, User $by): PurchaseOrder
    {
        (int) $by->organization_id === (int) $e->organization_id || $this->fail('This request belongs to another bank.');
        $by->can('create it-finance') || $this->fail('You need the "create it-finance" permission to commit a request.');
        (int) $e->requested_by_id === (int) $by->id && $this->fail('The requester cannot commit their own request (segregation of duties).');
        $e->status === 'approved' || $this->fail("A {$e->status} expense request cannot be committed.");
        $e->vendor_id || $this->fail('Pick the vendor on the request before committing it.', 'vendor_id');
        $orgId = (int) $e->organization_id;

        return DB::transaction(function () use ($e, $data, $by, $orgId) {
            $e = ExpenseRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($e->id);
            $e->status === 'approved' || $this->fail("A {$e->status} expense request cannot be committed.");
            $old = PurchaseOrder::withoutGlobalScopes()->withTrashed()->where('expense_request_id', $e->id)->first();
            if ($old) {
                $old->status === 'cancelled' || $this->fail('This request already has a purchase order.');
                $old->forceDelete();
            }
            $poDate = ! empty($data['po_date']) ? $this->date($data['po_date'], 'po_date') : FinanceClock::today();
            $poDate->gt(FinanceClock::today()) && $this->fail('The PO date cannot be in the future.', 'po_date');
            $lines = array_values((array) ($data['lines'] ?? []));
            $lines || $this->fail('Add at least one PO line.', 'lines');
            $subtotal = '0.00';
            $rows = [];
            foreach ($lines as $i => $l) {
                $desc = trim((string) ($l['description'] ?? ''));
                ($desc === '' || mb_strlen($desc) > 255) && $this->fail('Describe every line (max 255 characters).', "lines.{$i}.description");
                $qty = $this->quantity($l['quantity'] ?? null, "lines.{$i}.quantity");
                $unit = $this->money($l['unit_cost'] ?? null, "lines.{$i}.unit_cost", true, 'The unit cost');
                $amount = $this->phasing->round(bcmul($qty, $unit, 8), 2);
                $subtotal = bcadd($subtotal, $amount, 2);
                $catId = $l['category_id'] ?? $e->category_id;
                $assetId = $l['asset_id'] ?? $e->asset_id;
                $catId && $this->inBank(Category::class, $orgId, $catId, "lines.{$i}.category_id");
                $assetId && $this->inBank(Asset::class, $orgId, $assetId, "lines.{$i}.asset_id");
                $rows[] = ['line_no' => $i + 1, 'description' => $desc, 'quantity' => $qty, 'unit_cost' => $unit, 'amount' => $amount,
                    'category_id' => $catId, 'asset_id' => $assetId];
            }
            $vat = ($data['tax_type_id'] ?? $e->tax_type_id) ? $this->inBank(TaxType::class, $orgId, $data['tax_type_id'] ?? $e->tax_type_id, 'tax_type_id') : null;
            $totals = $this->tax->compute($subtotal, $e->currency, $vat, null);
            $conv = $this->fx->convert($orgId, $totals['total_amount'], $e->currency, $poDate, 'spot');

            // The PO may exceed the approved total only within match tolerance (plan P3-11).
            $excess = bcsub($totals['total_amount'], (string) $e->total_amount, 2);
            if (bccomp($excess, '0', 2) > 0) {
                $s = $this->settings->for($orgId);
                $this->matcher->within($excess, (string) $e->total_amount, $conv['fx_rate'], (string) $s->match_tolerance_pct, (string) $s->match_tolerance_abs)
                    || $this->fail("The PO total ({$totals['total_amount']}) is above the approved {$e->total_amount} beyond the match tolerance; raise a new request or re-approve.", 'lines');
            }

            $po = PurchaseOrder::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'po_code' => PurchaseOrder::generateNextCode($orgId), 'expense_request_id' => $e->id,
                'vendor_id' => $e->vendor_id, 'contract_id' => $e->contract_id, 'cost_centre_id' => $e->cost_centre_id, 'budget_line_id' => $e->budget_line_id,
                'fiscal_year_id' => $e->fiscal_year_id, 'po_date' => $poDate->toDateString(),
                'expected_delivery_on' => ! empty($data['expected_delivery_on']) ? $this->date($data['expected_delivery_on'], 'expected_delivery_on')->toDateString() : null,
                'currency' => $e->currency, 'fx_rate' => $conv['fx_rate'], 'fx_rate_type' => $conv['rate_type'], 'fx_rate_date' => $conv['rate_date'],
                'subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax_amount'], 'total_amount' => $totals['total_amount'], 'base_total_amount' => $conv['base_amount'],
                'tax_type_id' => $vat?->id, 'is_capex' => $e->is_capex, 'is_lease' => $e->is_lease, 'status' => 'open', 'issued_by_id' => $by->id,
                'external_reference' => ! empty($data['external_reference']) ? mb_substr(trim((string) $data['external_reference']), 0, 64) : null,
            ]);
            foreach ($rows as $r) {
                PurchaseOrderLine::withoutGlobalScopes()->create($r + ['organization_id' => $orgId, 'created_by_id' => $by->id, 'po_id' => $po->id, 'budget_line_id' => $e->budget_line_id]);
            }
            $e->forceFill(['status' => 'committed'])->save();
            $this->audit($po, $by, 'created', "Purchase order {$po->po_code} issued for {$e->request_code}", ['total' => $totals['total_amount'], 'currency' => $e->currency]);
            $this->audit($e, $by, 'committed', "Expense request {$e->request_code} committed as {$po->po_code}");

            return $po->refresh();
        });
    }

    public function updateHeader(PurchaseOrder $po, array $data, User $by): PurchaseOrder
    {
        in_array($po->status, PurchaseOrder::OPEN, true) || $this->fail("A {$po->status} purchase order cannot be edited.");
        $po->fill([
            'expected_delivery_on' => ! empty($data['expected_delivery_on']) ? $this->date($data['expected_delivery_on'], 'expected_delivery_on')->toDateString() : null,
            'external_reference' => ! empty($data['external_reference']) ? mb_substr(trim((string) $data['external_reference']), 0, 64) : null,
        ])->save();
        $this->audit($po, $by, 'updated', "Purchase order {$po->po_code} updated");

        return $po->refresh();
    }

    /** @param array{received_on?:?string,notes?:?string,lines?:array<int,array<string,mixed>>} $data */
    public function receive(PurchaseOrder $po, array $data, User $by): GoodsReceipt
    {
        (int) $by->organization_id === (int) $po->organization_id || $this->fail('This purchase order belongs to another bank.');
        in_array($po->status, self::TRANSITIONS['receive']['from'], true) || $this->fail("A {$po->status} purchase order cannot receive goods.");
        $reqBy = ExpenseRequest::withoutGlobalScopes()->whereKey($po->expense_request_id)->value('requested_by_id');
        $reqBy && (int) $reqBy === (int) $by->id && $this->fail('You requested this purchase, so you cannot confirm its receipt (segregation of duties).');

        return DB::transaction(function () use ($po, $data, $by) {
            $po = PurchaseOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($po->id);
            in_array($po->status, self::TRANSITIONS['receive']['from'], true) || $this->fail("A {$po->status} purchase order cannot receive goods.");
            $on = ! empty($data['received_on']) ? $this->date($data['received_on'], 'received_on') : FinanceClock::today();
            ($on->toDateString() < $po->po_date->toDateString() || $on->toDateString() > FinanceClock::today()->toDateString()) && $this->fail('The receipt date must be between the PO date and today.', 'received_on');
            $lines = array_values((array) ($data['lines'] ?? []));
            $lines || $this->fail('Receive at least one line.', 'lines');
            $grn = GoodsReceipt::withoutGlobalScopes()->create([
                'organization_id' => $po->organization_id, 'created_by_id' => $by->id, 'grn_code' => GoodsReceipt::generateNextCode((int) $po->organization_id),
                'po_id' => $po->id, 'received_on' => $on->toDateString(), 'received_by_id' => $by->id, 'notes' => $data['notes'] ?? null,
            ]);
            $seen = [];
            foreach ($lines as $i => $l) {
                $pl = PurchaseOrderLine::withoutGlobalScopes()->lockForUpdate()->where('po_id', $po->id)->find((int) ($l['po_line_id'] ?? 0)) ?? $this->fail('That is not a line of this purchase order.', "lines.{$i}.po_line_id");
                in_array($pl->id, $seen, true) && $this->fail('List each PO line once per receipt.', "lines.{$i}.po_line_id");
                $seen[] = $pl->id;
                $qty = $this->quantity($l['quantity'] ?? null, "lines.{$i}.quantity");
                $accepted = ! array_key_exists('accepted', $l) || filter_var($l['accepted'], FILTER_VALIDATE_BOOLEAN);
                $reason = trim((string) ($l['rejection_reason'] ?? ''));
                ! $accepted && $reason === '' && $this->fail('Give the reason for rejecting delivered goods.', "lines.{$i}.rejection_reason");
                if ($accepted) {
                    $total = bcadd((string) $pl->received_quantity, $qty, 4);
                    bccomp($total, (string) $pl->quantity, 4) > 0 && $this->fail("Line {$pl->line_no}: receiving {$qty} would exceed the {$pl->quantity} ordered.", "lines.{$i}.quantity");
                    $pl->forceFill(['received_quantity' => $total])->save();
                }
                GoodsReceiptLine::withoutGlobalScopes()->create([
                    'organization_id' => $po->organization_id, 'created_by_id' => $by->id, 'grn_id' => $grn->id, 'po_line_id' => $pl->id, 'quantity' => $qty,
                    'accepted' => $accepted, 'rejection_reason' => $accepted ? null : mb_substr($reason, 0, 255),
                ]);
            }
            $this->refresh($po);
            $this->audit($grn, $by, 'created', "Goods receipt {$grn->grn_code} recorded against {$po->po_code}", ['lines' => count($lines)]);

            return $grn->refresh();
        });
    }

    public function close(PurchaseOrder $po, User $by, ?string $note = null): PurchaseOrder
    {
        (int) $by->organization_id === (int) $po->organization_id || $this->fail('This purchase order belongs to another bank.');
        in_array($po->status, self::TRANSITIONS['close']['from'], true) || $this->fail("A {$po->status} purchase order cannot be closed.");
        DB::transaction(function () use ($po, $by, $note) {
            $po->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_id' => $by->id])->save();
            $e = ExpenseRequest::withoutGlobalScopes()->find($po->expense_request_id);
            $e && ! in_array($e->status, ExpenseRequest::TERMINAL, true) && $e->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            $this->audit($po, $by, 'closed', "Purchase order {$po->po_code} closed; the remaining commitment is released", ['note' => $note]);
        });

        return $po->refresh();
    }

    public function cancel(PurchaseOrder $po, User $by, string $reason): PurchaseOrder
    {
        (int) $by->organization_id === (int) $po->organization_id || $this->fail('This purchase order belongs to another bank.');
        $po->status === 'open' || $this->fail("A {$po->status} purchase order cannot be cancelled.");
        blank(trim($reason)) && $this->fail('Give a reason.', 'reason');
        GoodsReceipt::withoutGlobalScopes()->where('po_id', $po->id)->exists() && $this->fail('Goods have been received against this purchase order; close it instead.');
        Invoice::withoutGlobalScopes()->where('po_id', $po->id)->whereNotIn('status', ['cancelled', 'rejected'])->exists() && $this->fail('Invoices exist for this purchase order; close it instead.');
        DB::transaction(function () use ($po, $by, $reason) {
            $po->forceFill(['status' => 'cancelled', 'cancelled_reason' => trim($reason)])->save();
            ExpenseRequest::withoutGlobalScopes()->whereKey($po->expense_request_id)->where('status', 'committed')->update(['status' => 'approved']);
            $this->audit($po, $by, 'cancelled', "Purchase order {$po->po_code} cancelled; the request returns to approved", ['reason' => $reason]);
        });

        return $po->refresh();
    }

    /** Derives the PO status from its lines and refreshes the request. */
    public function refresh(PurchaseOrder $po): PurchaseOrder
    {
        $po->refresh();
        if (in_array($po->status, PurchaseOrder::OPEN, true)) {
            $lines = PurchaseOrderLine::withoutGlobalScopes()->where('po_id', $po->id)->get();
            $any = $lines->contains(fn ($l) => bccomp((string) $l->received_quantity, '0', 4) > 0);
            $all = $lines->isNotEmpty() && $lines->every(fn ($l) => bccomp((string) $l->received_quantity, (string) $l->quantity, 4) >= 0);
            $status = $all ? 'received' : ($any ? 'partially_received' : 'open');
            $status !== $po->status && $po->forceFill(['status' => $status])->save();
        }
        $e = ExpenseRequest::withoutGlobalScopes()->find($po->expense_request_id);
        $e && $this->expenses->refreshStatus($e);

        return $po;
    }

    public function remainingCommitmentBase(PurchaseOrder $po): string
    {
        return $this->availability->remainingForPo($po);
    }
}
