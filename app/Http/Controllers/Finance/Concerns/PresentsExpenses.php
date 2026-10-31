<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\GlAccount;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceLine;
use App\Models\Finance\Period;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\RecurringExpense;
use App\Models\Finance\TaxType;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

/**
 * Shapes shared by the expense, purchase-order, invoice, actuals, recurring, accrual and import pages (props contract, plan §7).
 * Decimals stay strings; without `view it-finance-amounts` every amount is removed server-side. Self-contained on purpose: it
 * must not be combined with PresentsBudgets or PresentsContracts (method names collide).
 */
trait PresentsExpenses
{
    private const EXPENSE_MONEY_KEYS = [
        'amount', 'base_amount', 'tax_amount', 'total_amount', 'base_total_amount', 'subtotal', 'wht_amount', 'payable_amount', 'unit_cost', 'match_variance_amount',
        'match_variance_base', 'expected', 'invoiced', 'variance', 'variance_base', 'limit', 'budget', 'committed', 'actual', 'available', 'used_pct', 'current_base_amount',
        'remaining_commitment_base', 'invoiced_amount', 'amount_band', 'amount_band_label', 'effective_band', 'unpaid_base', 'overdue_base', 'committed_base', 'unbudgeted_base',
        'posted_base', 'unmatched_base', 'proposed_base', 'reversed_base', 'prepaid_balance_base', 'annual_run_rate_base', 'base_amount_est', 'open_commitment_base',
        'requested_base', 'fx_rate', 'current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'unpaid_base_total',
    ];

    private const EXPENSE_MONEY_LISTS = ['ageing', 'by_source', 'availability_snapshot', 'sample'];

    /** @var array<int, array{budget_code:?string,current:string}> */
    private array $lineMemo = [];

    /** @var array<int, string> subject id => current stage name */
    private array $stageMemo = [];

    /** @var array<int, array{invoiced:string,accrued:string}> */
    private array $poSumMemo = [];

    /** @var array<int, User|null> users looked up by id for "posted by" / "matched by" columns, one query per distinct id */
    private array $userMemo = [];

    protected function renderExpenses(string $component, array $props)
    {
        $can = request()->user()->can('view it-finance-amounts');
        $props['baseCurrency'] ??= app(FinanceSettings::class)->baseCurrency((int) request()->user()->organization_id);
        $props['can'] = array_merge((array) ($props['can'] ?? []), ['viewAmounts' => $can]);
        if (! $can) {
            $props = $this->maskExpenseMoney(json_decode(json_encode($props), true));
        }

        return Inertia::render($component, $props);
    }

    protected function maskExpenseMoney(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::EXPENSE_MONEY_LISTS, true)) {
                $data[$k] = is_array($v) && array_is_list($v) ? [] : null;
            } elseif ($k === 'band' && is_array($v)) {
                $data[$k] = ['label' => null, 'min' => null, 'max' => null];
            } elseif (is_string($k) && in_array($k, self::EXPENSE_MONEY_KEYS, true) && ! is_array($v)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->maskExpenseMoney($v);
            }
        }

        return $data;
    }

    /** Loads budget codes and current amounts for many lines in two queries so the row builders never go N+1. */
    protected function primeLines(iterable $ids): void
    {
        $ids = collect($ids)->filter()->map(fn ($i) => (int) $i)->unique()->diff(array_keys($this->lineMemo))->values();
        if ($ids->isEmpty()) {
            return;
        }
        $lines = BudgetLine::withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'budget_id', 'base_annual_amount']);
        $codes = Budget::withoutGlobalScopes()->whereIn('id', $lines->pluck('budget_id')->unique())->pluck('budget_code', 'id');
        $adj = BudgetAdjustment::withoutGlobalScopes()->whereIn('budget_line_id', $ids)->selectRaw('budget_line_id, sum(base_amount) as total')->groupBy('budget_line_id')->pluck('total', 'budget_line_id');
        foreach ($lines as $l) {
            $this->lineMemo[$l->id] = ['budget_code' => $codes[$l->budget_id] ?? null, 'current' => bcadd((string) $l->base_annual_amount, (string) ($adj[$l->id] ?? '0'), 2)];
        }
    }

    /** Names of the stage each pending request awaits, for many records at once. */
    protected function primeStages(string $process, string $class, iterable $ids): void
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        foreach (ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $class)->whereIn('subject_id', $ids)->where('status', 'pending')->with('steps')->get() as $r) {
            $this->stageMemo[(int) $r->subject_id] = (string) $r->currentStep()?->name;
        }
    }

    protected function stageOf(int $id): ?string
    {
        return $this->stageMemo[$id] ?? null;
    }

    /** Primes the user memo for many ids with one query. */
    protected function primeUsers(iterable $ids): void
    {
        $ids = collect($ids)->filter()->map(fn ($i) => (int) $i)->unique()->diff(array_keys($this->userMemo))->values();
        if ($ids->isEmpty()) {
            return;
        }
        $found = User::withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');
        foreach ($ids as $id) {
            $this->userMemo[$id] = $found[$id] ?? null;
        }
    }

    protected function userById($id): ?User
    {
        if (! $id) {
            return null;
        }
        $this->primeUsers([$id]);

        return $this->userMemo[(int) $id] ?? null;
    }

    protected function userMini(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name] : null;
    }

    protected function ccMini(?CostCentre $c): ?array
    {
        return $c ? ['id' => $c->id, 'code' => $c->code, 'name' => $c->name] : null;
    }

    protected function codeMini($m): ?array
    {
        return $m ? ['id' => $m->id, 'code' => $m->code, 'name' => $m->name] : null;
    }

    protected function vendorMini($v): ?array
    {
        return $v ? ['id' => $v->id, 'name' => $v->name] : null;
    }

    protected function contractMini(?Contract $c): ?array
    {
        return $c ? ['id' => $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title] : null;
    }

    protected function periodMini(?Period $p): ?array
    {
        return $p ? ['id' => $p->id, 'name' => $p->name, 'status' => $p->status, 'fiscal_year_id' => $p->fiscal_year_id] : null;
    }

    protected function lineMini(?BudgetLine $l): ?array
    {
        if (! $l) {
            return null;
        }
        $this->primeLines([$l->id]);
        $m = $this->lineMemo[$l->id] ?? ['budget_code' => null, 'current' => '0.00'];

        return ['id' => $l->id, 'budget_id' => $l->budget_id, 'budget_code' => $m['budget_code'], 'line_no' => $l->line_no, 'label' => "#{$l->line_no} {$l->description}",
            'cost_centre_id' => $l->cost_centre_id, 'current_base_amount' => $m['current']];
    }

    protected function dateOf($d): ?string
    {
        return $d?->toDateString();
    }

    /** @return array<string,mixed> */
    protected function expenseRow(ExpenseRequest $e): array
    {
        $e->loadMissing(['costCentre:id,code,name', 'budgetLine', 'vendor:id,name', 'requestedBy:id,name', 'purchaseOrder:id,expense_request_id,po_code,status']);

        return [
            'id' => $e->id, 'request_code' => $e->request_code, 'title' => $e->title, 'status' => $e->status, 'cost_centre' => $this->ccMini($e->costCentre),
            'budget_line' => $this->lineMini($e->budgetLine), 'unbudgeted' => $e->budget_line_id === null, 'is_emergency' => $e->is_emergency, 'over_budget' => $e->over_budget,
            'is_capex' => $e->is_capex, 'is_lease' => $e->is_lease, 'vendor' => $this->vendorMini($e->vendor), 'currency' => $e->currency, 'amount' => (string) $e->amount,
            'total_amount' => (string) $e->total_amount, 'base_total_amount' => (string) $e->base_total_amount, 'amount_band' => $e->amount_band, 'amount_band_label' => $e->amount_band_label,
            'effective_band' => $e->effective_band, 'needed_by' => $this->dateOf($e->needed_by), 'requested_by' => $this->userMini($e->requestedBy),
            'submitted_at' => $e->submitted_at?->toIso8601String(), 'approved_at' => $e->approved_at?->toIso8601String(), 'current_stage' => $this->stageOf($e->id),
            'po' => $e->purchaseOrder ? ['id' => $e->purchaseOrder->id, 'po_code' => $e->purchaseOrder->po_code, 'status' => $e->purchaseOrder->status] : null,
        ];
    }

    /** @return array<string,mixed> */
    protected function expenseFull(ExpenseRequest $e): array
    {
        $e->loadMissing(['category', 'glAccount', 'legalEntity', 'contract', 'renewal', 'asset', 'period', 'fiscalYear', 'taxType', 'approvedBy:id,name', 'creator:id,name']);

        return $this->expenseRow($e) + [
            'description' => $e->description, 'category' => $this->codeMini($e->category), 'gl_account' => $this->codeMini($e->glAccount),
            'legal_entity' => $e->legalEntity ? ['id' => $e->legalEntity->id, 'code' => $e->legalEntity->code, 'name' => $e->legalEntity->name] : null,
            'contract' => $this->contractMini($e->contract), 'renewal' => $e->renewal ? ['id' => $e->renewal->id, 'renewal_code' => $e->renewal->renewal_code] : null,
            'asset' => $e->asset ? ['id' => $e->asset->id, 'label' => trim(($e->asset->asset_id_code ?? '').' '.$e->asset->name)] : null,
            'period' => $this->periodMini($e->period), 'fiscal_year' => $e->fiscalYear ? ['id' => $e->fiscalYear->id, 'year_label' => $e->fiscalYear->year_label] : null,
            'fx_rate' => (string) $e->fx_rate, 'fx_rate_date' => $this->dateOf($e->fx_rate_date),
            'tax_type' => $e->taxType ? ['id' => $e->taxType->id, 'code' => $e->taxType->code, 'rate' => (string) $e->taxType->rate, 'is_inclusive' => $e->taxType->is_inclusive] : null,
            'tax_amount' => (string) $e->tax_amount, 'base_amount' => (string) $e->base_amount, 'justification' => $e->justification, 'overbudget_justification' => $e->overbudget_justification,
            'availability_snapshot' => $e->availability_snapshot, 'rejection_reason' => $e->rejection_reason, 'cancelled_reason' => $e->cancelled_reason,
            'approved_by' => $this->userMini($e->approvedBy), 'closed_at' => $e->closed_at?->toIso8601String(), 'created_by' => $this->userMini($e->creator),
            'created_at' => $e->created_at?->toIso8601String(), 'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    /** Invoiced / accrued totals of many purchase orders in two queries, for poRow(). */
    protected function primePoSums(iterable $pos): void
    {
        $pos = collect($pos)->reject(fn ($po) => isset($this->poSumMemo[$po->id]))->values();
        $this->poSumMemo += app(BudgetAvailability::class)->poSums($pos);
    }

    /** @return array{invoiced:string,accrued:string}|null */
    protected function poSums(PurchaseOrder $po): ?array
    {
        return $this->poSumMemo[$po->id] ?? null;
    }

    protected function poRow(PurchaseOrder $po): array
    {
        $po->loadMissing(['vendor:id,name', 'expenseRequest:id,request_code,title', 'costCentre:id,code,name', 'issuedBy:id,name', 'lines']);
        $sums = $this->poSumMemo[$po->id] ?? null;
        $invoiced = (string) ($sums ? $sums['invoiced'] : Invoice::withoutGlobalScopes()->where('po_id', $po->id)->whereIn('status', ['approved', 'scheduled', 'paid'])->sum('total_amount'));

        return [
            'id' => $po->id, 'po_code' => $po->po_code, 'status' => $po->status, 'vendor' => $this->vendorMini($po->vendor),
            'expense_request' => $po->expenseRequest ? ['id' => $po->expenseRequest->id, 'request_code' => $po->expenseRequest->request_code, 'title' => $po->expenseRequest->title] : null,
            'cost_centre' => $this->ccMini($po->costCentre), 'po_date' => $this->dateOf($po->po_date), 'expected_delivery_on' => $this->dateOf($po->expected_delivery_on),
            'currency' => $po->currency, 'subtotal' => (string) $po->subtotal, 'tax_amount' => (string) $po->tax_amount, 'total_amount' => (string) $po->total_amount,
            'base_total_amount' => (string) $po->base_total_amount, 'received_pct' => $po->received_pct, 'invoiced_amount' => bcadd($invoiced, '0', 2),
            'remaining_commitment_base' => app(BudgetAvailability::class)->remainingForPo($po, $sums), 'issued_by' => $this->userMini($po->issuedBy),
            'external_reference' => $po->external_reference, 'is_lease' => $po->is_lease,
        ];
    }

    /** @return array<string,mixed> */
    protected function poFull(PurchaseOrder $po): array
    {
        $po->loadMissing(['budgetLine', 'lines.category', 'lines.asset', 'receipts.receivedBy:id,name', 'receipts.lines.poLine']);
        $invoicedQty = InvoiceLine::withoutGlobalScopes()->whereIn('po_line_id', $po->lines->pluck('id'))
            ->whereIn('invoice_id', Invoice::withoutGlobalScopes()->whereIn('status', ['submitted', 'approved', 'scheduled', 'paid'])->select('id'))
            ->selectRaw('po_line_id, sum(quantity) as q')->groupBy('po_line_id')->pluck('q', 'po_line_id');

        return $this->poRow($po) + [
            'fx_rate' => (string) $po->fx_rate, 'fx_rate_date' => $this->dateOf($po->fx_rate_date), 'budget_line' => $this->lineMini($po->budgetLine),
            'lines' => $po->lines->map(fn ($l) => [
                'id' => $l->id, 'line_no' => $l->line_no, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_cost' => (string) $l->unit_cost, 'amount' => (string) $l->amount,
                'received_quantity' => (string) $l->received_quantity, 'invoiced_quantity' => bcadd((string) ($invoicedQty[$l->id] ?? '0'), '0', 4), 'remaining_quantity' => $l->remaining_quantity,
                'category' => $this->codeMini($l->category), 'asset' => $l->asset ? ['id' => $l->asset->id, 'label' => $l->asset->name] : null,
            ])->values()->all(),
            'receipts' => $po->receipts->map(fn ($g) => [
                'id' => $g->id, 'grn_code' => $g->grn_code, 'received_on' => $this->dateOf($g->received_on), 'received_by' => $this->userMini($g->receivedBy), 'notes' => $g->notes,
                'lines' => $g->lines->map(fn ($gl) => ['po_line_id' => $gl->po_line_id, 'line_no' => $gl->poLine?->line_no, 'description' => $gl->poLine?->description,
                    'quantity' => (string) $gl->quantity, 'accepted' => $gl->accepted, 'rejection_reason' => $gl->rejection_reason])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    protected function invoiceRow(Invoice $i): array
    {
        $i->loadMissing(['vendor:id,name', 'po:id,po_code', 'contract:id,contract_code,title', 'expenseRequest:id,request_code', 'costCentre:id,code,name', 'budgetLine', 'period',
            'submittedBy:id,name', 'approvedBy:id,name']);

        return [
            'id' => $i->id, 'invoice_code' => $i->invoice_code, 'vendor_invoice_no' => $i->vendor_invoice_no, 'vendor' => $this->vendorMini($i->vendor), 'status' => $i->status,
            'match_status' => $i->match_status, 'match_variance_amount' => $i->match_variance_amount === null ? null : (string) $i->match_variance_amount,
            'po' => $i->po ? ['id' => $i->po->id, 'po_code' => $i->po->po_code] : null, 'contract' => $this->contractMini($i->contract),
            'expense_request' => $i->expenseRequest ? ['id' => $i->expenseRequest->id, 'request_code' => $i->expenseRequest->request_code] : null,
            'cost_centre' => $this->ccMini($i->costCentre), 'budget_line' => $this->lineMini($i->budgetLine), 'invoice_date' => $this->dateOf($i->invoice_date), 'due_on' => $this->dateOf($i->due_on),
            'days_to_due' => $i->days_to_due, 'is_overdue' => $i->is_overdue, 'period' => $this->periodMini($i->period), 'currency' => $i->currency, 'subtotal' => (string) $i->subtotal,
            'tax_amount' => (string) $i->tax_amount, 'wht_amount' => (string) $i->wht_amount, 'total_amount' => (string) $i->total_amount, 'payable_amount' => $i->payable_amount,
            'base_total_amount' => (string) $i->base_total_amount, 'amount_band' => $i->amount_band, 'amount_band_label' => $i->amount_band_label, 'is_expected' => $i->is_expected,
            'is_lease' => $i->is_lease, 'prepayment_months' => $i->prepayment_months, 'current_stage' => $this->stageOf($i->id), 'submitted_by' => $this->userMini($i->submittedBy),
            'approved_by' => $this->userMini($i->approvedBy), 'paid_on' => $this->dateOf($i->paid_on), 'payment_reference' => $i->payment_reference,
        ];
    }

    /** @return array<string,mixed> */
    protected function invoiceFull(Invoice $i): array
    {
        $i->loadMissing(['category', 'glAccount', 'taxType', 'whtTaxType', 'lines', 'recurringExpense:id,recurring_code', 'matchOverrider']);
        $detail = $i->match_detail;
        $s = app(FinanceSettings::class)->for((int) $i->organization_id);

        return $this->invoiceRow($i) + [
            'description' => $i->description, 'category' => $this->codeMini($i->category), 'gl_account' => $this->codeMini($i->glAccount), 'fx_rate' => (string) $i->fx_rate,
            'fx_rate_date' => $this->dateOf($i->fx_rate_date),
            'tax_type' => $i->taxType ? ['id' => $i->taxType->id, 'code' => $i->taxType->code, 'rate' => (string) $i->taxType->rate, 'is_inclusive' => $i->taxType->is_inclusive] : null,
            'wht_tax_type' => $i->whtTaxType ? ['id' => $i->whtTaxType->id, 'code' => $i->whtTaxType->code, 'rate' => (string) $i->whtTaxType->rate, 'is_inclusive' => false] : null,
            'match_variance_base' => $i->match_variance_base === null ? null : (string) $i->match_variance_base,
            'lines' => $i->lines->map(fn ($l) => ['id' => $l->id, 'line_no' => $l->line_no, 'po_line_id' => $l->po_line_id, 'description' => $l->description,
                'quantity' => (string) $l->quantity, 'unit_cost' => (string) $l->unit_cost, 'amount' => (string) $l->amount])->values()->all(),
            'match' => $detail ? [
                'status' => $detail['status'], 'expected' => $detail['expected'], 'invoiced' => $detail['invoiced'], 'variance' => $detail['variance'], 'variance_base' => $detail['variance_base'],
                'limit' => $detail['limit'], 'tolerance' => ['pct' => (string) $s->match_tolerance_pct, 'abs' => (string) $s->match_tolerance_abs], 'lines' => $detail['lines'],
                'override' => $i->match_override_reason ? ['reason' => $i->match_override_reason, 'by' => $this->userMini($i->matchOverrider), 'at' => $i->match_override_at?->toIso8601String()] : null,
            ] : null,
            'scheduled_on' => $this->dateOf($i->scheduled_on), 'dispute_reason' => $i->dispute_reason, 'disputed_at' => $i->disputed_at?->toIso8601String(),
            'rejection_reason' => $i->rejection_reason, 'cancelled_reason' => $i->cancelled_reason,
            'recurring' => $i->recurringExpense ? ['id' => $i->recurringExpense->id, 'recurring_code' => $i->recurringExpense->recurring_code] : null,
            'created_at' => $i->created_at?->toIso8601String(), 'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    protected function actualRow(Actual $a): array
    {
        $a->loadMissing(['period', 'costCentre:id,code,name', 'glAccount:id,code,name', 'budgetLine', 'vendor:id,name', 'contract:id,contract_code,title', 'invoice:id,invoice_code']);

        return [
            'id' => $a->id, 'period' => $this->periodMini($a->period), 'posted_on' => $this->dateOf($a->posted_on), 'source' => $a->source, 'source_ref' => $a->source_ref,
            'description' => $a->description, 'cost_centre' => $this->ccMini($a->costCentre), 'gl_account' => $this->codeMini($a->glAccount), 'budget_line' => $this->lineMini($a->budgetLine),
            'vendor' => $this->vendorMini($a->vendor), 'contract' => $this->contractMini($a->contract),
            'invoice' => $a->invoice ? ['id' => $a->invoice->id, 'invoice_code' => $a->invoice->invoice_code] : null, 'batch_id' => $a->import_batch_id, 'currency' => $a->currency,
            'amount' => (string) $a->amount, 'base_amount' => (string) $a->base_amount, 'is_capex' => $a->is_capex, 'is_lease' => $a->is_lease, 'match_status' => $a->match_status,
            'match_reason' => $a->match_reason, 'matched_by' => $a->matched_by_id ? $this->userMini($this->userById($a->matched_by_id)) : null,
            'matched_at' => $a->matched_at?->toIso8601String(), 'duplicate_of_id' => $a->duplicate_of_id,
        ];
    }

    /** @return array<string,mixed> */
    protected function accrualRow(Accrual $a): array
    {
        $a->loadMissing(['period', 'reversalPeriod', 'po:id,po_code', 'invoice:id,invoice_code', 'recurringExpense:id,recurring_code', 'budgetLine', 'costCentre:id,code,name']);
        $by = fn ($id) => $id ? $this->userMini($this->userById($id)) : null;

        return [
            'id' => $a->id, 'type' => $a->type, 'source' => $a->source, 'status' => $a->status, 'period' => $this->periodMini($a->period), 'reversal_period' => $this->periodMini($a->reversalPeriod),
            'po' => $a->po ? ['id' => $a->po->id, 'po_code' => $a->po->po_code] : null, 'invoice' => $a->invoice ? ['id' => $a->invoice->id, 'invoice_code' => $a->invoice->invoice_code] : null,
            'recurring' => $a->recurringExpense ? ['id' => $a->recurringExpense->id, 'recurring_code' => $a->recurringExpense->recurring_code] : null, 'budget_line' => $this->lineMini($a->budgetLine),
            'cost_centre' => $this->ccMini($a->costCentre), 'description' => $a->description, 'currency' => $a->currency, 'amount' => (string) $a->amount, 'base_amount' => (string) $a->base_amount,
            'posted_by' => $by($a->posted_by_id), 'posted_at' => $a->posted_at?->toIso8601String(), 'reversed_at' => $a->reversed_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    protected function recurringRow(RecurringExpense $r): array
    {
        $r->loadMissing(['contract:id,contract_code,title', 'vendor:id,name', 'budgetLine', 'costCentre:id,code,name', 'category', 'glAccount', 'taxType']);
        $inv = $r->invoices()->orderByDesc('recurring_due_on')->limit(3)->get()->map(fn ($i) => ['type' => 'invoice', 'id' => $i->id, 'code' => $i->invoice_code, 'due_on' => $this->dateOf($i->recurring_due_on), 'href' => route('finance.invoices.show', $i)]);
        $acc = $r->accruals()->orderByDesc('id')->limit(3)->get()->map(fn ($a) => ['type' => 'accrual', 'id' => $a->id, 'code' => "ACR-{$a->id}", 'due_on' => null, 'href' => route('finance.accruals.index', ['period_id' => $a->period_id])]);

        return [
            'id' => $r->id, 'recurring_code' => $r->recurring_code, 'title' => $r->title, 'contract' => $this->contractMini($r->contract), 'vendor' => $this->vendorMini($r->vendor),
            'budget_line' => $this->lineMini($r->budgetLine), 'cost_centre' => $this->ccMini($r->costCentre), 'category' => $this->codeMini($r->category), 'gl_account' => $this->codeMini($r->glAccount),
            'tax_type' => $r->taxType ? ['id' => $r->taxType->id, 'code' => $r->taxType->code] : null, 'frequency' => $r->frequency, 'amount' => (string) $r->amount, 'currency' => $r->currency,
            'base_amount_est' => null, 'starts_on' => $this->dateOf($r->starts_on), 'ends_on' => $this->dateOf($r->ends_on), 'next_due_on' => $this->dateOf($r->next_due_on),
            'days_to_due' => $r->days_to_due, 'last_generated_on' => $this->dateOf($r->last_generated_on), 'auto_generate' => $r->auto_generate, 'is_active' => $r->is_active, 'notes' => $r->notes,
            'generated' => $inv->concat($acc)->take(3)->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    protected function batchRow(ImportBatch $b): array
    {
        $b->loadMissing('importedBy:id,name');

        return [
            'id' => $b->id, 'type' => $b->type, 'file_name' => $b->file_name, 'status' => $b->status, 'row_count' => $b->row_count, 'accepted_count' => $b->accepted_count,
            'rejected_count' => $b->rejected_count, 'duplicate_count' => $b->duplicate_count, 'matched_count' => $b->matched_count, 'unmatched_count' => $b->unmatched_count,
            'imported_by' => $this->userMini($b->importedBy), 'created_at' => $b->created_at?->toIso8601String(), 'completed_at' => $b->completed_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string,mixed>> */
    protected function documentRows(Model $subject): array
    {
        return FinanceDocument::query()->where('documentable_type', $subject::class)->where('documentable_id', $subject->getKey())->with(['evidence', 'creator:id,name'])
            ->orderByDesc('id')->get()->map(fn (FinanceDocument $d) => [
                'id' => $d->id, 'kind' => $d->kind, 'title' => $d->title, 'sha256' => $d->sha256, 'file_name' => $d->evidence?->file_name,
                'download_url' => $d->evidence?->file_path ? route('finance.documents.download', $d) : null, 'uploaded_by' => $this->userMini($d->creator),
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    protected function activityRows(Model $subject, int $limit = 100): array
    {
        $can = request()->user()->can('view it-finance-amounts');

        return Activity::query()->where('subject_type', $subject::class)->where('subject_id', $subject->getKey())->with('causer:id,name')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($a) => ['id' => $a->id, 'event' => $a->event, 'description' => $a->description, 'causer' => $a->causer ? ['id' => $a->causer->id, 'name' => $a->causer->name] : null,
                'at' => $a->created_at?->toIso8601String(),
                'changes' => $can && $a->event === 'updated' ? ($a->properties['attributes'] ?? null) : null])->values()->all();
    }

    /** Fiscal-year, cost-centre, vendor, category lookups shared by the form pages. @return Collection<int, array<string,mixed>> */
    protected function optionList(iterable $models, callable $label): Collection
    {
        return collect($models)->map(fn ($m) => ['id' => $m->id, 'label' => $label($m)])->values();
    }

    /* ------------------------------------------------------------------ option lists shared by the form pages */

    /** @return list<array<string,mixed>> current baseline lines of the open fiscal years, in cost centres the user can see */
    protected function budgetLineOptions(User $user): array
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);
        $fyIds = FiscalYear::query()->where('status', 'open')->pluck('id');
        $budgets = Budget::query()->where('status', 'baseline')->whereIn('fiscal_year_id', $fyIds)->get(['id', 'budget_code', 'fiscal_year_id'])->keyBy('id');
        $lines = BudgetLine::query()->whereIn('budget_id', $budgets->keys())
            ->when($ids !== null && ! $user->hasRole('Procurement Officer'), fn ($q) => $q->whereIn('cost_centre_id', $ids ?: [0]))->orderBy('budget_id')->orderBy('line_no')->get();
        $this->primeLines($lines->pluck('id'));
        $contractOf = Contract::query()->whereIn('budget_line_id', $lines->pluck('id'))->selectRaw('budget_line_id, min(id) as first_id')->groupBy('budget_line_id')->pluck('first_id', 'budget_line_id');

        return $lines->map(fn (BudgetLine $l) => $this->lineMini($l) + [
            'category_id' => $l->category_id, 'gl_account_id' => $l->gl_account_id, 'vendor_id' => $l->vendor_id, 'contract_id' => $contractOf[$l->id] ?? null,
            'is_capex' => $l->is_capex, 'currency' => $l->currency, 'fiscal_year_id' => $budgets[$l->budget_id]->fiscal_year_id,
        ])->values()->all();
    }

    protected function costCentreOptions(User $user): array
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);

        return CostCentre::query()->where('is_active', true)->when($ids !== null && ! $user->hasRole('Procurement Officer'), fn ($q) => $q->whereIn('id', $ids ?: [0]))
            ->orderBy('code')->get()->map(fn ($c) => $this->ccMini($c))->values()->all();
    }

    protected function currencyOptions(): array
    {
        return Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get()
            ->map(fn ($c) => ['code' => $c->code, 'decimals' => $c->decimals, 'is_base' => $c->is_base])->values()->all();
    }

    protected function taxTypeOptions(): array
    {
        return TaxType::query()->where('is_active', true)->orderBy('code')->get()
            ->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'rate' => (string) $t->rate, 'is_inclusive' => $t->is_inclusive, 'kind' => $t->kind])->values()->all();
    }

    protected function vendorOptions(): array
    {
        return Vendor::query()->orderBy('name')->get(['id', 'name'])->map(fn ($v) => $this->vendorMini($v))->values()->all();
    }

    protected function categoryOptions(): array
    {
        return Category::query()->where('is_active', true)->orderBy('kind')->orderBy('code')->get(['id', 'code', 'name', 'kind'])->map(fn ($c) => $this->codeMini($c) + ['kind' => $c->kind])->values()->all();
    }

    protected function glOptions(): array
    {
        return GlAccount::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($g) => $this->codeMini($g))->values()->all();
    }

    protected function contractOptions(User $user): array
    {
        return Contract::query()->visibleTo($user)->whereIn('status', array_merge(Contract::LIVE, ['expired']))->orderBy('contract_code')->get()
            ->map(fn (Contract $c) => $this->contractMini($c) + ['vendor_id' => $c->vendor_id, 'budget_line_id' => $c->budget_line_id, 'is_ifrs16_lease' => $c->is_ifrs16_lease])->values()->all();
    }

    protected function openFiscalYears(): array
    {
        return FiscalYear::query()->where('status', 'open')->orderByDesc('starts_on')->get()
            ->map(fn ($f) => ['id' => $f->id, 'year_label' => $f->year_label, 'is_current' => $f->is_current])->values()->all();
    }

    /** Can $user decide the stage now awaiting a submitted record? Configured stages via the trail; otherwise the default-approver rule. */
    protected function canDecideNow(FinanceApprovals $approvals, Model $subject, User $user, array $trail, string $permission, string $initiatorAttr): bool
    {
        if (! ($trail['configured'] ?? false) || empty($trail['request']['steps'] ?? [])) {
            try {
                $approvals->assertDefaultApprover($subject, $user, $initiatorAttr, null, $permission);

                return true;
            } catch (ValidationException) {
                return false;
            }
        }
        foreach ($trail['request']['steps'] as $step) {
            if (! empty($step['can_decide'])) {
                return true;
            }
        }

        return false;
    }
}
