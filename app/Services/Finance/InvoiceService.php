<?php

namespace App\Services\Finance;

use App\Models\Approvals\ApprovalDecision;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalStep;
use App\Models\Asset;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceLine;
use App\Models\Finance\Period;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\PurchaseOrderLine;
use App\Models\Finance\TaxType;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Vendor invoices (FIN-03.04, .06): capture, three-way match, approval through Workflow Studio (with the "final approver is not a
 * PO approver" rule above the SoD band), payment status and dispute handling. Approval posts the actual; payment never does.
 */
class InvoiceService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public const PROCESS = 'finance.invoice_approval';

    public const PERMISSION = 'approve it-finance-invoices';

    public const TRANSITIONS = [
        'update' => ['from' => ['draft']],
        'match' => ['from' => ['draft']],
        'override' => ['from' => ['draft'], 'match' => ['variance']],
        'submit' => ['from' => ['draft'], 'to' => 'submitted'],
        'withdraw' => ['from' => ['submitted'], 'to' => 'draft'],
        'approve' => ['from' => ['submitted'], 'to' => 'approved'],
        'return' => ['from' => ['submitted'], 'to' => 'draft'],
        'reject' => ['from' => ['submitted'], 'to' => 'rejected'],
        'schedule' => ['from' => ['approved'], 'to' => 'scheduled'],
        'pay' => ['from' => ['approved', 'scheduled'], 'to' => 'paid'],
        'dispute' => ['from' => ['draft', 'submitted', 'approved', 'scheduled'], 'to' => 'disputed'],
        'resolve' => ['from' => ['disputed']],
        'cancel' => ['from' => ['draft', 'disputed'], 'to' => 'cancelled'],
    ];

    private const FILLABLE = [
        'vendor_invoice_no', 'vendor_id', 'po_id', 'expense_request_id', 'contract_id', 'invoice_date', 'due_on', 'received_on', 'currency', 'subtotal', 'lines',
        'tax_type_id', 'wht_tax_type_id', 'budget_line_id', 'cost_centre_id', 'category_id', 'gl_account_id', 'period_id', 'description', 'prepayment_months', 'is_capex',
    ];

    public function __construct(
        private readonly FinanceApprovals $approvals,
        private readonly CurrencyService $fx,
        private readonly FinanceSettings $settings,
        private readonly FinanceTax $tax,
        private readonly ThreeWayMatcher $matcher,
        private readonly BudgetPhasing $phasing,
        private readonly ExpenseService $expenses,
        private readonly ActualsService $actuals,
        private readonly AccrualService $accruals,
    ) {}

    public function capture(array $data, User $by): Invoice
    {
        $orgId = (int) $by->organization_id;
        $parsed = $this->normalise($orgId, $data, null, $by);

        return DB::transaction(function () use ($orgId, $parsed, $by) {
            $i = Invoice::withoutGlobalScopes()->create($parsed['attrs'] + [
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'invoice_code' => Invoice::generateNextCode($orgId), 'status' => 'draft',
            ]);
            $this->writeLines($i, $parsed['lines'], $by);
            $this->audit($i, $by, 'created', "Invoice {$i->invoice_code} captured");
            $this->match($i, $by);

            return $i->refresh();
        });
    }

    public function update(Invoice $i, array $data, User $by): Invoice
    {
        $this->assertTransition($i, 'update');
        (int) $by->organization_id === (int) $i->organization_id || $this->fail('This invoice belongs to another bank.');
        $current = $i->only(['vendor_invoice_no', 'vendor_id', 'po_id', 'expense_request_id', 'contract_id', 'currency', 'tax_type_id', 'wht_tax_type_id', 'budget_line_id', 'cost_centre_id',
            'category_id', 'gl_account_id', 'period_id', 'description', 'prepayment_months', 'is_capex']) + [
                'invoice_date' => $i->invoice_date?->toDateString(), 'due_on' => $i->due_on?->toDateString(), 'received_on' => $i->received_on?->toDateString(),
            ];
        $parsed = $this->normalise((int) $i->organization_id, $data + $current + ['lines' => null], $i, $by);

        return DB::transaction(function () use ($i, $parsed, $by) {
            $before = [$i->subtotal, $i->tax_amount, $i->po_id];
            $i->fill($parsed['attrs'] + ['is_expected' => $parsed['attrs']['is_expected'] ?? $i->is_expected]);
            if (str_starts_with($i->vendor_invoice_no, 'EXPECTED-') === false) {
                $i->is_expected = false;
            }
            $i->forceFill(['match_override_reason' => null, 'match_override_by_id' => null, 'match_override_at' => null])->save();
            if ($parsed['lines'] !== null) {
                InvoiceLine::withoutGlobalScopes()->where('invoice_id', $i->id)->delete();
                $this->writeLines($i, $parsed['lines'], $by);
            }
            $this->audit($i, $by, 'updated', "Invoice {$i->invoice_code} updated");
            $this->match($i, $by);

            return $i->refresh();
        });
    }

    public function delete(Invoice $i, User $by): void
    {
        $i->status === 'draft' || $this->fail('Only a draft invoice can be deleted.');
        $this->audit($i, $by, 'deleted', "Draft invoice {$i->invoice_code} deleted");
        $i->delete();
    }

    /** Runs the three-way match and stores the verdict (an override survives only while nothing changed). */
    public function match(Invoice $i, ?User $by = null): Invoice
    {
        $i->refresh();
        $s = $this->settings->for((int) $i->organization_id);
        $r = $this->matcher->evaluate($i, (string) $s->match_tolerance_pct, (string) $s->match_tolerance_abs);
        $status = $r['status'];
        if ($status === 'variance' && $i->match_override_reason && $i->match_status === 'override') {
            $status = 'override';
        }
        $i->forceFill([
            'match_status' => $status, 'match_variance_amount' => $i->po_id ? $r['variance'] : null, 'match_variance_base' => $i->po_id ? $r['variance_base'] : null,
            'match_detail' => $i->po_id ? $r : null, 'matched_at' => now(),
        ])->save();

        return $i;
    }

    public function override(Invoice $i, string $reason, User $by): Invoice
    {
        $this->assertTransition($i, 'override');
        (int) $by->organization_id === (int) $i->organization_id || $this->fail('This invoice belongs to another bank.');
        $i->match_status === 'variance' || $this->fail('Only an invoice with a match variance needs an override.');
        mb_strlen(trim($reason)) >= (int) config('finance.invoices.override_reason_min', 10)
            || $this->fail('Give the reason for accepting the variance (at least '.config('finance.invoices.override_reason_min', 10).' characters).', 'reason');
        $i->forceFill(['match_status' => 'override', 'match_override_reason' => trim($reason), 'match_override_by_id' => $by->id, 'match_override_at' => now()])->save();
        $this->audit($i, $by, 'match_overridden', "Match variance on {$i->invoice_code} overridden", ['reason' => $reason, 'variance' => (string) $i->match_variance_amount]);

        return $i->refresh();
    }

    public function submit(Invoice $i, User $by): Invoice
    {
        $this->assertTransition($i, 'submit');
        (int) $by->organization_id === (int) $i->organization_id || $this->fail('This invoice belongs to another bank.');

        return DB::transaction(function () use ($i, $by) {
            $i = Invoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($i->id);
            $this->assertTransition($i, 'submit');
            str_starts_with($i->vendor_invoice_no, 'EXPECTED-') && $this->fail("Enter the vendor's own invoice number before submitting.", 'vendor_invoice_no');
            $i = $this->match($i, $by);
            $i->match_status === 'variance' && $this->fail('The invoice does not match its purchase order and goods receipts. Fix it or override the variance with a reason.', 'match_status');
            $i->po_id && $i->match_status === 'unmatched' && $this->fail('The invoice has a purchase order but could not be matched.', 'match_status');
            $i->forceFill(['status' => 'submitted', 'submitted_by_id' => $by->id, 'submitted_at' => now(), 'rejection_reason' => null])->save();
            $this->approvals->start(self::PROCESS, $i, $by);
            $this->audit($i, $by, 'submitted', "Invoice {$i->invoice_code} submitted", ['match_status' => $i->match_status]);

            return $i->refresh();
        });
    }

    public function withdraw(Invoice $i, User $by): Invoice
    {
        $this->assertTransition($i, 'withdraw');
        $this->assertSubmitter($i, $by);
        DB::transaction(function () use ($i, $by) {
            $i->forceFill(['status' => 'draft'])->save();
            $this->approvals->withdraw(self::PROCESS, $i, $by);
            $this->audit($i, $by, 'withdrawn', "Invoice {$i->invoice_code} withdrawn to draft");
        });

        return $i->refresh();
    }

    /** Returns the message to show: the next stage awaited, or the final approval. */
    public function approve(Invoice $i, User $by, ?string $note = null): string
    {
        $this->assertTransition($i, 'approve');
        $this->assertDecider($i, $by);

        return DB::transaction(function () use ($i, $by, $note) {
            $i = Invoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($i->id);
            $this->assertTransition($i, 'approve');
            $configured = $this->approvals->configured(self::PROCESS, (int) $i->organization_id);
            $configured && $this->approvals->assertZeroStepSoD(self::PROCESS, $i, $by, 'submitted_by_id', null, self::PERMISSION);
            if (! $configured) {
                $this->approvals->assertDefaultApprover($i, $by, 'submitted_by_id', null, self::PERMISSION);
            } elseif ($message = $this->approvals->approve(self::PROCESS, $i, $by, $note)) {
                $this->audit($i, $by, 'stage_approved', $message);

                return $message;
            }
            // Final decision: above the SoD band the approver must not have approved the purchase order (plan P3-9, FIN-07.02).
            if ($i->high_value === 'yes' && in_array((int) $by->id, $this->poApproverIds($i), true)) {
                $this->fail('You approved the purchase order for this invoice, so you cannot give the final approval of a high-value invoice (segregation of duties).', 'approval');
            }
            in_array((int) $by->id, $i->sodExcludedIds(), true) && $this->fail('You took part in this invoice (capture, override or requester), so you cannot approve it (segregation of duties).', 'approval');
            (int) $i->submitted_by_id === (int) $by->id && $this->fail('You submitted this invoice, so you cannot approve it (segregation of duties).', 'approval');
            $period = $this->assertOpenPeriod(Period::withoutGlobalScopes()->find($i->period_id));
            $i->forceFill(['status' => 'approved', 'approved_by_id' => $by->id, 'approved_at' => now()])->save();
            if ($i->prepayment_months) {
                $this->accruals->schedulePrepayment($i, $by);
            } else {
                $this->actuals->postInvoice($i, $by);
            }
            $this->approvals->finalised(self::PROCESS, $i, $by, $note);
            $i->expense_request_id && ($e = ExpenseRequest::withoutGlobalScopes()->find($i->expense_request_id)) && $this->expenses->refreshStatus($e);
            $this->audit($i, $by, 'approved', "Invoice {$i->invoice_code} approved".($i->prepayment_months ? ' and scheduled for release' : " and posted to {$period->name}"));

            return "Invoice {$i->invoice_code} approved".($i->prepayment_months ? '; the prepayment will be released period by period.' : " and posted as an actual in {$period->name}.");
        });
    }

    public function returnToDraft(Invoice $i, User $by, string $note): Invoice
    {
        return $this->decline($i, $by, $note, 'return', 'returned', 'draft');
    }

    public function reject(Invoice $i, User $by, string $note): Invoice
    {
        return $this->decline($i, $by, $note, 'reject', 'rejected', 'rejected');
    }

    private function decline(Invoice $i, User $by, string $note, string $action, string $outcome, string $to): Invoice
    {
        $this->assertTransition($i, $action);
        blank(trim($note)) && $this->fail('Give a reason.', 'note');
        $this->assertDecider($i, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $i->organization_id)) {
            $this->approvals->assertDefaultApprover($i, $by, 'submitted_by_id', null, self::PERMISSION);
        }
        DB::transaction(function () use ($i, $by, $note, $outcome, $to, $action) {
            $this->approvals->decline(self::PROCESS, $i, $by, $note, $outcome);
            $i->forceFill(['status' => $to, 'rejection_reason' => $note])->save();
            $this->audit($i, $by, $action === 'return' ? 'returned' : 'rejected', "Invoice {$i->invoice_code} {$outcome}", ['note' => $note]);
        });

        return $i->refresh();
    }

    public function schedule(Invoice $i, string $on, User $by): Invoice
    {
        $this->assertTransition($i, 'schedule');
        $this->assertPayer($i, $by);
        $date = $this->date($on, 'scheduled_on');
        $date->toDateString() < $i->invoice_date->toDateString() && $this->fail('The payment cannot be scheduled before the invoice date.', 'scheduled_on');
        $i->forceFill(['status' => 'scheduled', 'scheduled_on' => $date->toDateString(), 'scheduled_by_id' => $by->id])->save();
        $this->audit($i, $by, 'scheduled', "Payment of {$i->invoice_code} scheduled for {$date->toDateString()}");

        return $i->refresh();
    }

    public function markPaid(Invoice $i, string $paidOn, string $reference, User $by): Invoice
    {
        $this->assertTransition($i, 'pay');
        $this->assertPayer($i, $by);
        $date = $this->date($paidOn, 'paid_on');
        $date->gt(FinanceClock::today()) && $this->fail('The payment date cannot be in the future.', 'paid_on');
        blank(trim($reference)) && $this->fail('Give the payment reference.', 'payment_reference');
        $i->forceFill(['status' => 'paid', 'paid_on' => $date->toDateString(), 'paid_by_id' => $by->id, 'payment_reference' => mb_substr(trim($reference), 0, 64)])->save();
        $this->audit($i, $by, 'paid', "Invoice {$i->invoice_code} paid", ['reference' => $reference]);

        return $i->refresh();
    }

    public function dispute(Invoice $i, string $reason, User $by): Invoice
    {
        $this->assertTransition($i, 'dispute');
        blank(trim($reason)) && $this->fail('Give the reason for the dispute.', 'reason');
        (int) $by->organization_id === (int) $i->organization_id || $this->fail('This invoice belongs to another bank.');
        DB::transaction(function () use ($i, $by, $reason) {
            $was = $i->status;
            $i->forceFill(['status' => 'disputed', 'dispute_reason' => trim($reason), 'disputed_at' => now(), 'disputed_by_id' => $by->id])->save();
            $was === 'submitted' && $this->approvals->withdraw(self::PROCESS, $i, $by);
            $this->audit($i, $by, 'disputed', "Invoice {$i->invoice_code} disputed", ['reason' => $reason, 'was' => $was]);
        });

        return $i->refresh();
    }

    public function resolveDispute(Invoice $i, User $by, ?string $note = null): Invoice
    {
        $this->assertTransition($i, 'resolve');
        (int) $by->organization_id === (int) $i->organization_id || $this->fail('This invoice belongs to another bank.');
        $to = $i->approved_at ? ($i->scheduled_on ? 'scheduled' : 'approved') : 'draft';
        $i->forceFill(['status' => $to, 'dispute_reason' => null, 'disputed_at' => null, 'disputed_by_id' => null])->save();
        $this->audit($i, $by, 'dispute_resolved', "Dispute on {$i->invoice_code} resolved", ['note' => $note, 'status' => $to]);

        return $i->refresh();
    }

    public function cancel(Invoice $i, string $reason, User $by): Invoice
    {
        $this->assertTransition($i, 'cancel');
        $i->approved_at && $this->fail('An approved invoice cannot be cancelled; credit notes are not supported yet.');
        blank(trim($reason)) && $this->fail('Give a reason.', 'reason');
        $i->forceFill(['status' => 'cancelled', 'cancelled_reason' => trim($reason)])->save();
        $this->audit($i, $by, 'cancelled', "Invoice {$i->invoice_code} cancelled", ['reason' => $reason]);

        return $i->refresh();
    }

    /** Everyone who approved the purchase order's expense request (plan P3-9). @return list<int> */
    public function poApproverIds(Invoice $i): array
    {
        $requestId = $i->po_id ? PurchaseOrder::withoutGlobalScopes()->whereKey($i->po_id)->value('expense_request_id') : $i->expense_request_id;
        $e = $requestId ? ExpenseRequest::withoutGlobalScopes()->find($requestId) : null;

        return $e ? $this->expenses->approverIds($e) : [];
    }

    /** @return array{attrs:array<string,mixed>,lines:?list<array<string,mixed>>} */
    private function normalise(int $orgId, array $data, ?Invoice $existing, User $by): array
    {
        $in = Arr::only($data, self::FILLABLE);
        $get = fn (string $k, $default = null) => array_key_exists($k, $in) ? $in[$k] : ($existing?->{$k} ?? $default);
        $invNo = trim((string) $get('vendor_invoice_no', ''));
        ($invNo === '' || mb_strlen($invNo) > 64) && $this->fail("Enter the vendor's invoice number (max 64 characters).", 'vendor_invoice_no');

        $po = $get('po_id') ? $this->inBank(PurchaseOrder::class, $orgId, $get('po_id'), 'po_id') : null;
        $po && ! in_array($po->status, PurchaseOrder::OPEN, true) && $this->fail("Purchase order {$po->po_code} is {$po->status}; it cannot be invoiced.", 'po_id');
        $expense = ! $po && $get('expense_request_id') ? $this->inBank(ExpenseRequest::class, $orgId, $get('expense_request_id'), 'expense_request_id') : null;
        $expense && ! in_array($expense->status, ['approved'], true) && $this->fail('Only an approved request without a purchase order can be invoiced directly.', 'expense_request_id');
        $contract = $get('contract_id') ? $this->inBank(Contract::class, $orgId, $get('contract_id'), 'contract_id') : null;
        $src = $po ?? $expense;

        $vendorId = $po?->vendor_id ?? $expense?->vendor_id ?? $get('vendor_id') ?? $contract?->vendor_id;
        $vendorId || $this->fail('Pick the vendor.', 'vendor_id');
        $this->inBank(Vendor::class, $orgId, $vendorId, 'vendor_id');
        $dup = Invoice::withoutGlobalScopes()->withTrashed()->where('organization_id', $orgId)->where('vendor_id', $vendorId)->where('vendor_invoice_no', $invNo)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->first();
        $dup && $this->fail("This vendor's invoice number {$invNo} was already captured as {$dup->invoice_code}".($dup->trashed() ? ' (deleted; restore it instead)' : '').'.', 'vendor_invoice_no');

        $lineId = $po?->budget_line_id ?? $expense?->budget_line_id ?? $get('budget_line_id') ?? $contract?->budget_line_id;
        $line = $lineId ? BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->find($lineId) : null;
        $ccId = $src?->cost_centre_id ?? $get('cost_centre_id') ?? $line?->cost_centre_id ?? $contract?->cost_centre_id;
        $ccId || $this->fail('Pick a cost centre.', 'cost_centre_id');
        $this->inBank(CostCentre::class, $orgId, $ccId, 'cost_centre_id');
        $categoryId = $get('category_id') ?? $expense?->category_id ?? $line?->category_id ?? $contract?->category_id;
        $categoryId && $this->inBank(Category::class, $orgId, $categoryId, 'category_id');
        $glId = $get('gl_account_id') ?? $expense?->gl_account_id ?? $line?->gl_account_id;
        $glId && $this->inBank(GlAccount::class, $orgId, $glId, 'gl_account_id');

        $currency = strtoupper((string) ($po?->currency ?? $expense?->currency ?? $get('currency', $contract?->currency ?? $this->settings->baseCurrency($orgId))));
        $invoiceDate = $this->date($get('invoice_date'), 'invoice_date');
        $invoiceDate->gt(FinanceClock::today()->addDay()) && $this->fail('The invoice date cannot be in the future.', 'invoice_date');

        // Lines: PO-linked invoices pick PO lines; others give one amount or free lines.
        $lines = $in['lines'] ?? null;
        $parsedLines = null;
        if ($lines === null && $existing === null) {
            if ($po) {
                $lines = PurchaseOrderLine::withoutGlobalScopes()->where('po_id', $po->id)->orderBy('line_no')->get()->map(fn ($pl) => [
                    'po_line_id' => $pl->id, 'description' => $pl->description, 'quantity' => (string) $pl->received_quantity, 'unit_cost' => (string) $pl->unit_cost,
                ])->filter(fn ($l) => bccomp($l['quantity'], '0', 4) > 0)->values()->all();
            } elseif (array_key_exists('subtotal', $in)) {
                $lines = [['description' => trim((string) ($get('description') ?: $invNo)), 'quantity' => '1', 'unit_cost' => (string) $in['subtotal']]];
            }
        } elseif ($lines === null && $existing !== null && array_key_exists('subtotal', $in) && ! $po) {
            $lines = [['description' => trim((string) ($get('description') ?: $invNo)), 'quantity' => '1', 'unit_cost' => (string) $in['subtotal']]];
        }
        if ($lines !== null) {
            $lines = array_values((array) $lines);
            $lines || $this->fail('Add at least one invoice line.', 'lines');
            $parsedLines = [];
            foreach ($lines as $n => $l) {
                $poLine = null;
                if (! empty($l['po_line_id'])) {
                    $po || $this->fail('A PO line needs a purchase order.', "lines.{$n}.po_line_id");
                    $poLine = PurchaseOrderLine::withoutGlobalScopes()->where('po_id', $po->id)->find((int) $l['po_line_id']) ?? $this->fail('That is not a line of the purchase order.', "lines.{$n}.po_line_id");
                }
                $desc = trim((string) ($l['description'] ?? $poLine?->description ?? ''));
                $desc === '' && $this->fail('Describe every line.', "lines.{$n}.description");
                $qty = $this->quantity($l['quantity'] ?? '1', "lines.{$n}.quantity");
                $unit = $this->money($l['unit_cost'] ?? null, "lines.{$n}.unit_cost", true, 'The unit cost');
                ! empty($l['category_id']) && $this->inBank(Category::class, $orgId, $l['category_id'], "lines.{$n}.category_id");
                ! empty($l['asset_id']) && $this->inBank(Asset::class, $orgId, $l['asset_id'], "lines.{$n}.asset_id");
                $parsedLines[] = ['line_no' => $n + 1, 'po_line_id' => $poLine?->id, 'description' => mb_substr($desc, 0, 255), 'quantity' => $qty, 'unit_cost' => $unit,
                    'amount' => $this->phasing->round(bcmul($qty, $unit, 8), 2), 'category_id' => $l['category_id'] ?? null, 'asset_id' => $l['asset_id'] ?? null];
            }
            $subtotal = array_reduce($parsedLines, fn ($c, $l) => bcadd($c, $l['amount'], 2), '0.00');
        } else {
            $subtotal = (string) ($existing?->subtotal ?? $this->fail('Add at least one invoice line.', 'lines'));
        }

        $vat = $get('tax_type_id') ? $this->inBank(TaxType::class, $orgId, $get('tax_type_id'), 'tax_type_id') : null;
        $vat && ! in_array($vat->kind, ['vat', 'sales_tax', 'none'], true) && $this->fail('Pick a VAT or sales-tax type.', 'tax_type_id');
        $wht = $get('wht_tax_type_id') ? $this->inBank(TaxType::class, $orgId, $get('wht_tax_type_id'), 'wht_tax_type_id') : null;
        $wht && $wht->kind !== 'wht' && $this->fail('Pick a withholding tax type.', 'wht_tax_type_id');
        $totals = $this->tax->compute($subtotal, $currency, $vat, $wht);
        $on = $invoiceDate->gt(FinanceClock::today()) ? FinanceClock::today() : $invoiceDate;
        $conv = $this->fx->convert($orgId, $totals['total_amount'], $currency, $on, 'spot');

        // P7-10: invoices booked straight against an approved request may not exceed its total beyond the match tolerance.
        if ($expense) {
            // Serialise concurrent captures on the request row, and count only live invoices (a rejected, cancelled or deleted one frees its share).
            ExpenseRequest::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($expense->id)->lockForUpdate()->value('id');
            $already = (string) Invoice::withoutGlobalScopes()->whereNull('deleted_at')->where('organization_id', $orgId)->where('expense_request_id', $expense->id)->whereNull('po_id')
                ->whereIn('status', ['draft', 'submitted', 'approved', 'scheduled', 'paid', 'disputed'])
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->sum('total_amount');
            $excess = bcsub(bcadd($already, $totals['total_amount'], 2), (string) $expense->total_amount, 2);
            if (bccomp($excess, '0', 2) > 0) {
                $s = $this->settings->for($orgId);
                $this->matcher->within($excess, (string) $expense->total_amount, $conv['fx_rate'], (string) $s->match_tolerance_pct, (string) $s->match_tolerance_abs)
                    || $this->fail("The invoices against {$expense->request_code} would total more than the approved amount beyond the match tolerance; raise a new request or re-approve.", 'subtotal');
            }
        }

        $paymentDays = (int) ($contract?->payment_terms_days ?? config('finance.invoices.default_payment_days', 30));
        $due = $get('due_on') ? $this->date($get('due_on'), 'due_on') : $invoiceDate->addDays($paymentDays);
        $due->lt($invoiceDate) && $this->fail('The due date cannot be before the invoice date.', 'due_on');

        $period = $get('period_id') ? Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false)->find((int) $get('period_id')) : $this->periodContaining($orgId, $invoiceDate);
        $period || $this->fail('No fiscal period covers the invoice date; pick a period.', 'period_id');
        $months = $get('prepayment_months');
        if ($months !== null && $months !== '') {
            $po && $this->fail('A purchase-order invoice cannot be a prepayment.', 'prepayment_months');
            ((int) $months < 2 || (int) $months > 36) && $this->fail('A prepayment is released over 2 to 36 periods.', 'prepayment_months');
        }

        return ['attrs' => [
            'vendor_invoice_no' => $invNo, 'vendor_id' => (int) $vendorId, 'po_id' => $po?->id, 'contract_id' => $contract?->id ?? $po?->contract_id ?? $expense?->contract_id,
            'expense_request_id' => $po?->expense_request_id ?? $expense?->id, 'budget_line_id' => $line?->id, 'cost_centre_id' => (int) $ccId,
            'category_id' => $categoryId ?: null, 'gl_account_id' => $glId ?: null, 'legal_entity_id' => $src?->legal_entity_id ?? $line?->legal_entity_id,
            'fiscal_year_id' => (int) $period->fiscal_year_id, 'period_id' => $period->id, 'invoice_date' => $invoiceDate->toDateString(), 'due_on' => $due->toDateString(),
            'received_on' => $get('received_on') ? $this->date($get('received_on'), 'received_on')->toDateString() : null,
            'currency' => $currency, 'fx_rate' => $conv['fx_rate'], 'fx_rate_type' => $conv['rate_type'], 'fx_rate_date' => $conv['rate_date'],
            'subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax_amount'], 'wht_amount' => $totals['wht_amount'], 'total_amount' => $totals['total_amount'],
            'base_total_amount' => $conv['base_amount'], 'tax_type_id' => $vat?->id, 'wht_tax_type_id' => $wht?->id,
            'is_capex' => (bool) ($po?->is_capex ?? $expense?->is_capex ?? $in['is_capex'] ?? $existing?->is_capex ?? $line?->is_capex ?? false),
            'is_lease' => (bool) ($po?->is_lease ?? $expense?->is_lease ?? $contract?->is_ifrs16_lease ?? false),
            'prepayment_months' => $months === null || $months === '' ? null : (int) $months, 'description' => $get('description'),
            'is_expected' => $existing?->is_expected ?? false,
        ], 'lines' => $parsedLines];
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeLines(Invoice $i, array $lines, User $by): void
    {
        foreach ($lines as $l) {
            InvoiceLine::withoutGlobalScopes()->create($l + ['organization_id' => $i->organization_id, 'created_by_id' => $by->id, 'invoice_id' => $i->id]);
        }
    }

    private function assertTransition(Invoice $i, string $action): void
    {
        in_array($i->status, self::TRANSITIONS[$action]['from'], true) || $this->fail("A {$i->status} invoice cannot be {$this->past($action)}.");
    }

    private function past(string $a): string
    {
        return ['update' => 'edited', 'match' => 'matched', 'override' => 'overridden', 'submit' => 'submitted', 'withdraw' => 'withdrawn', 'approve' => 'approved', 'return' => 'returned',
            'reject' => 'rejected', 'schedule' => 'scheduled', 'pay' => 'paid', 'dispute' => 'disputed', 'resolve' => 'resolved', 'cancel' => 'cancelled'][$a];
    }

    private function assertSubmitter(Invoice $i, User $by): void
    {
        (int) $by->organization_id === (int) $i->organization_id || $this->fail('This invoice belongs to another bank.');
        in_array((int) $by->id, array_filter([(int) $i->submitted_by_id, (int) $i->created_by_id]), true) || $this->fail('Only the person who submitted the invoice can do that.');
    }

    /** payer is neither the approver nor the submitter (three-way standard, plan C100). */
    private function assertPayer(Invoice $i, User $by): void
    {
        (int) $by->organization_id === (int) $i->organization_id || $this->fail('This invoice belongs to another bank.');
        $by->can(self::PERMISSION) || $this->fail('You need the "'.self::PERMISSION.'" permission to schedule or record a payment.', 'approval');
        $decided = ApprovalDecision::withoutGlobalScopes()->whereIn('step_id', ApprovalStep::withoutGlobalScopes()
            ->whereIn('request_id', ApprovalRequest::withoutGlobalScopes()->where('process', self::PROCESS)->where('subject_type', $i::class)->where('subject_id', $i->id)->select('id'))->select('id'))
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        in_array((int) $by->id, array_filter(array_merge([(int) $i->approved_by_id, (int) $i->submitted_by_id], $i->sodExcludedIds(), $decided)), true)
            && $this->fail('The person who approved or submitted an invoice cannot also pay it (segregation of duties).', 'approval');
    }

    private function assertDecider(Invoice $i, User $by): void
    {
        (int) $by->organization_id === (int) $i->organization_id || $this->fail('This invoice belongs to another bank.', 'approval');
        $by->can(self::PERMISSION) || $this->approvals->isCurrentOwnerApprover(self::PROCESS, $i, $by)
            || $this->fail('You need the "'.self::PERMISSION.'" permission, or to be the cost-centre owner this is awaiting, to decide an invoice.', 'approval');
    }
}
