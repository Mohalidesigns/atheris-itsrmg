<?php

namespace App\Services\Finance;

use App\Events\Finance\ExpenseFlagged;
use App\Models\Approvals\ApprovalDecision;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalStep;
use App\Models\Asset;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Invoice;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\Renewal;
use App\Models\Finance\TaxType;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Expense requests (FIN-03.01 to .03, .08): availability-checked submission, DoA approval through Workflow Studio, unbudgeted and
 * emergency spend routed one band higher, and the commitment released again when a request is closed or cancelled.
 */
class ExpenseService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public const PROCESS = 'finance.expense_approval';

    public const TRANSITIONS = [
        'submit' => ['from' => ['draft'], 'to' => 'submitted'],
        'withdraw' => ['from' => ['submitted'], 'to' => 'withdrawn'],
        'approve' => ['from' => ['submitted'], 'to' => 'approved'],
        'return' => ['from' => ['submitted'], 'to' => 'draft'],
        'reject' => ['from' => ['submitted'], 'to' => 'rejected'],
        'commit' => ['from' => ['approved'], 'to' => 'committed'],
        'cancel' => ['from' => ['draft', 'approved'], 'to' => 'cancelled'],
        'close' => ['from' => ['committed', 'partially_received', 'received', 'invoiced', 'approved'], 'to' => 'closed'],
    ];

    private const FILLABLE = [
        'title', 'description', 'fiscal_year_id', 'budget_line_id', 'cost_centre_id', 'category_id', 'gl_account_id', 'legal_entity_id', 'vendor_id', 'contract_id',
        'asset_id', 'is_capex', 'is_emergency', 'needed_by', 'currency', 'amount', 'tax_type_id', 'justification', 'overbudget_justification',
    ];

    public function __construct(
        private readonly FinanceApprovals $approvals,
        private readonly BudgetAvailability $availability,
        private readonly CurrencyService $fx,
        private readonly FinanceSettings $settings,
        private readonly BudgetService $budgets,
        private readonly FinanceTax $tax,
    ) {}

    public function create(array $data, User $by): ExpenseRequest
    {
        $orgId = (int) $by->organization_id;
        $attrs = $this->normalise($orgId, $data, null, $by);

        return DB::transaction(function () use ($orgId, $attrs, $by) {
            $e = ExpenseRequest::withoutGlobalScopes()->create($attrs + [
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'request_code' => ExpenseRequest::generateNextCode($orgId),
                'requested_by_id' => $by->id, 'status' => 'draft',
            ]);
            $this->audit($e, $by, 'created', "Expense request {$e->request_code} drafted");

            return $e->refresh();
        });
    }

    public function update(ExpenseRequest $e, array $data, User $by): ExpenseRequest
    {
        in_array($e->status, ExpenseRequest::EDITABLE, true) || $this->fail("A {$e->status} expense request cannot be edited.");
        $this->assertAuthor($e, $by);
        $e->fill($this->normalise((int) $e->organization_id, $data, $e, $by))->save();
        $this->audit($e, $by, 'updated', "Expense request {$e->request_code} updated");

        return $e->refresh();
    }

    public function delete(ExpenseRequest $e, User $by): void
    {
        $e->status === 'draft' || $this->fail('Only a draft expense request can be deleted.');
        $this->assertAuthor($e, $by);
        $this->audit($e, $by, 'deleted', "Draft expense request {$e->request_code} deleted");
        $e->delete();
    }

    public function submit(ExpenseRequest $e, User $by): ExpenseRequest
    {
        $this->assertTransition($e, 'submit');
        $this->assertAuthor($e, $by);

        return DB::transaction(function () use ($e, $by) {
            $e = ExpenseRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($e->id);
            $this->assertTransition($e, 'submit');
            FiscalYear::withoutGlobalScopes()->whereKey($e->fiscal_year_id)->value('status') === 'open' || $this->fail('The fiscal year is no longer open.', 'fiscal_year_id');
            $check = $this->checkAvailability($e);
            $reasons = [];
            if ($e->budget_line_id === null) {
                $e->category_id || $this->fail('Pick a category for an unbudgeted request.', 'category_id');
                $this->assertJustification($e->justification, 'justification', 'An unbudgeted request needs a justification');
                $reasons[] = 'unbudgeted';
            }
            if ($check['exceeds']) {
                $reasons[] = 'over_budget';
                $e->over_budget = true;
            } else {
                $e->over_budget = false;
            }
            if ($e->is_emergency) {
                $this->assertJustification($e->justification, 'justification', 'An emergency request needs a justification');
                $reasons[] = 'emergency';
            }
            $e->forceFill([
                'status' => 'submitted', 'submitted_at' => now(), 'rejection_reason' => null, 'availability_snapshot' => $check['snapshot'],
            ])->save();
            $this->approvals->start(self::PROCESS, $e, $by);
            $this->audit($e, $by, 'submitted', "Expense request {$e->request_code} submitted", ['band' => $e->amount_band, 'effective_band' => $e->effective_band, 'flags' => $reasons]);
            foreach ($reasons as $reason) {
                ExpenseFlagged::dispatch($e->refresh(), $reason);
            }

            return $e->refresh();
        });
    }

    public function withdraw(ExpenseRequest $e, User $by): ExpenseRequest
    {
        $this->assertTransition($e, 'withdraw');
        $this->assertAuthor($e, $by);
        DB::transaction(function () use ($e, $by) {
            $e->forceFill(['status' => 'withdrawn'])->save();
            $this->approvals->withdraw(self::PROCESS, $e, $by);
            $this->audit($e, $by, 'withdrawn', "Expense request {$e->request_code} withdrawn");
        });

        return $e->refresh();
    }

    /** Returns the message to show: the next stage awaited, or the final approval. */
    public function approve(ExpenseRequest $e, User $by, ?string $note = null): string
    {
        $this->assertTransition($e, 'approve');
        $this->assertDecider($e, $by);

        return DB::transaction(function () use ($e, $by, $note) {
            $e = ExpenseRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($e->id);
            $this->assertTransition($e, 'approve');
            $configured = $this->approvals->configured(self::PROCESS, (int) $e->organization_id);
            $configured && $this->approvals->assertZeroStepSoD(self::PROCESS, $e, $by, 'requested_by_id');
            if (! $configured) {
                $this->approvals->assertDefaultApprover($e, $by, 'requested_by_id');
            } elseif ($message = $this->approvals->approve(self::PROCESS, $e, $by, $note)) {
                $this->audit($e, $by, 'stage_approved', $message);

                return $message;
            }
            in_array((int) $by->id, $e->sodExcludedIds(), true) && $this->fail('You took part in this request, so you cannot approve it (segregation of duties).', 'approval');
            // Final approval: the budget may have been spent since the request was submitted (plan P3-4).
            $e->budget_line_id && $this->settings->for((int) $e->organization_id)->overbudget_mode === 'block' && $this->checkAvailability($e, true)['exceeds']
                && $this->fail('The budget line no longer has enough available budget for this request (another commitment or actual was posted since it was submitted).', 'amount');
            $e->forceFill(['status' => 'approved', 'approved_by_id' => $by->id, 'approved_at' => now()])->save();
            $this->approvals->finalised(self::PROCESS, $e, $by, $note);
            $this->audit($e, $by, 'approved', "Expense request {$e->request_code} approved");

            return "Expense request {$e->request_code} approved. It can now be committed as a purchase order.";
        });
    }

    public function returnToDraft(ExpenseRequest $e, User $by, string $note): ExpenseRequest
    {
        return $this->decline($e, $by, $note, 'return', 'returned', 'draft');
    }

    public function reject(ExpenseRequest $e, User $by, string $note): ExpenseRequest
    {
        return $this->decline($e, $by, $note, 'reject', 'rejected', 'rejected');
    }

    private function decline(ExpenseRequest $e, User $by, string $note, string $action, string $outcome, string $to): ExpenseRequest
    {
        $this->assertTransition($e, $action);
        blank(trim($note)) && $this->fail('Give a reason.', 'note');
        $this->assertDecider($e, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $e->organization_id)) {
            $this->approvals->assertDefaultApprover($e, $by, 'requested_by_id');
        }
        DB::transaction(function () use ($e, $by, $note, $outcome, $to, $action) {
            $this->approvals->decline(self::PROCESS, $e, $by, $note, $outcome);
            $e->forceFill(['status' => $to, 'rejection_reason' => $note])->save();
            $this->audit($e, $by, $action === 'return' ? 'returned' : 'rejected', "Expense request {$e->request_code} {$outcome}", ['note' => $note]);
        });

        return $e->refresh();
    }

    public function cancel(ExpenseRequest $e, User $by, string $reason): ExpenseRequest
    {
        $this->assertTransition($e, 'cancel');
        blank(trim($reason)) && $this->fail('Give a reason.', 'reason');
        (int) $by->organization_id === (int) $e->organization_id || $this->fail('This request belongs to another bank.');
        $this->isAuthor($e, $by) || $by->can('approve it-finance') || $this->fail('Only the requester or an approver can cancel this request.');
        $e->purchaseOrder()->whereIn('status', PurchaseOrder::OPEN)->exists() && $this->fail('Cancel or close the purchase order first.');
        $e->forceFill(['status' => 'cancelled', 'cancelled_reason' => trim($reason), 'closed_at' => now()])->save();
        $this->audit($e, $by, 'cancelled', "Expense request {$e->request_code} cancelled; its commitment is released", ['reason' => $reason]);

        return $e->refresh();
    }

    /** Closes the request and its purchase order; whatever is not yet invoiced stops being committed. */
    public function close(ExpenseRequest $e, User $by, ?string $note = null): ExpenseRequest
    {
        $this->assertTransition($e, 'close');
        (int) $by->organization_id === (int) $e->organization_id || $this->fail('This request belongs to another bank.');
        DB::transaction(function () use ($e, $by, $note) {
            $po = $e->purchaseOrder;
            $po && in_array($po->status, PurchaseOrder::OPEN, true)
                && $po->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_id' => $by->id])->save();
            $e->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            $this->audit($e, $by, 'closed', "Expense request {$e->request_code} closed; the remaining commitment is released", ['note' => $note]);
        });

        return $e->refresh();
    }

    /** Derives committed, partially_received, received and invoiced from the PO and its invoices; never moves a terminal or pre-commit request. */
    public function refreshStatus(ExpenseRequest $e): ExpenseRequest
    {
        $e->refresh();
        if (! in_array($e->status, ['committed', 'partially_received', 'received', 'invoiced'], true)) {
            return $e;
        }
        $po = $e->purchaseOrder()->first();
        if (! $po || in_array($po->status, ['cancelled', 'closed'], true)) {
            return $e;
        }
        $billed = (string) Invoice::withoutGlobalScopes()->where('po_id', $po->id)->whereIn('status', ['approved', 'scheduled', 'paid'])->sum('total_amount');
        $tol = $this->settings->for((int) $e->organization_id);
        $slack = bcmul((string) $po->total_amount, bcdiv((string) ($tol->match_tolerance_pct ?? 0), '100', 6), 2);
        $fully = bccomp((string) $po->total_amount, '0', 2) > 0 && bccomp(bcadd($billed, $slack, 2), (string) $po->total_amount, 2) >= 0;
        $status = $fully ? 'invoiced' : match ($po->status) {
            'partially_received' => 'partially_received', 'received' => 'received', default => 'committed',
        };
        $status !== $e->status && $e->forceFill(['status' => $status])->save();

        return $e;
    }

    /** Plan P3-16: a draft request for an executed renewal's first year; idempotent on renewal.expense_request_id. */
    public function createFromRenewal(Renewal $r, Contract $successor): ?ExpenseRequest
    {
        if ($r->expense_request_id && ExpenseRequest::withoutGlobalScopes()->whereKey($r->expense_request_id)->exists()) {
            return ExpenseRequest::withoutGlobalScopes()->find($r->expense_request_id);
        }
        $orgId = (int) $successor->organization_id;
        $requesterId = $successor->owner_id ?: $r->executed_by_id ?: $r->requested_by_id;
        $requester = $requesterId ? User::withoutGlobalScopes()->where('organization_id', $orgId)->find($requesterId) : null;
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'open')
            ->whereDate('starts_on', '<=', $successor->commenced_on->toDateString())->whereDate('ends_on', '>=', $successor->commenced_on->toDateString())->first();
        $line = $successor->budget_line_id ? BudgetLine::withoutGlobalScopes()->find($successor->budget_line_id) : null;
        $line = $line ? $this->budgets->resolveBaselineLine($line) : null;
        if ($line && $fy && (int) Budget::withoutGlobalScopes()->whereKey($line->budget_id)->value('fiscal_year_id') !== (int) $fy->id) {
            $line = null;
        }
        $ccId = $successor->cost_centre_id ?: $line?->cost_centre_id;
        if (! $requester || ! $fy || ! $ccId) {
            return null;
        }
        $months = max(1, (int) $successor->term_months);
        $share = min(12, $months);
        $amount = $share >= $months ? (string) $successor->contract_value : bcmul(bcdiv((string) $successor->contract_value, (string) $months, 8), (string) $share, 2);

        return DB::transaction(function () use ($r, $successor, $requester, $fy, $line, $ccId, $amount, $orgId) {
            $attrs = $this->normalise($orgId, [
                'title' => 'Renewal: '.$successor->title, 'description' => "First year of {$successor->contract_code}, renewed from {$r->renewal_code}.",
                'fiscal_year_id' => $fy->id, 'budget_line_id' => $line?->id, 'cost_centre_id' => $ccId, 'category_id' => $successor->category_id,
                'vendor_id' => $successor->vendor_id, 'contract_id' => $successor->id, 'currency' => $successor->currency, 'amount' => $amount,
                'needed_by' => $successor->commenced_on->toDateString(), 'justification' => $line ? null : 'Renewal of '.$successor->contract_code.' with no current budget line.',
            ], null, null, true);
            $e = ExpenseRequest::withoutGlobalScopes()->create($attrs + [
                'organization_id' => $orgId, 'created_by_id' => $requester->id, 'request_code' => ExpenseRequest::generateNextCode($orgId),
                'requested_by_id' => $requester->id, 'status' => 'draft', 'renewal_id' => $r->id,
            ]);
            $r->forceFill(['expense_request_id' => $e->id])->save();
            $this->audit($e, $requester, 'created', "Expense request {$e->request_code} drafted from renewal {$r->renewal_code}");

            return $e->refresh();
        });
    }

    /** Everyone who decided an approval stage of the request, plus whoever gave the final approval (plan P3-9). @return list<int> */
    public function approverIds(ExpenseRequest $e): array
    {
        $requests = ApprovalRequest::withoutGlobalScopes()->where('process', self::PROCESS)->where('subject_type', ExpenseRequest::class)->where('subject_id', $e->id)->select('id');
        $steps = ApprovalStep::withoutGlobalScopes()->whereIn('request_id', $requests)->select('id');
        $ids = ApprovalDecision::withoutGlobalScopes()->whereIn('step_id', $steps)->where('decision', 'approved')->pluck('user_id')->all();
        $e->approved_by_id && ($ids[] = $e->approved_by_id);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Checks the request against the line's availability (excluding itself) in the bank's over-budget mode (plan P3-4).
     *
     * @return array{exceeds:bool,snapshot:array<string,mixed>|null}
     */
    public function checkAvailability(ExpenseRequest $e, bool $final = false): array
    {
        if (! $e->budget_line_id) {
            return ['exceeds' => false, 'snapshot' => null];
        }
        $line = BudgetLine::withoutGlobalScopes()->findOrFail($e->budget_line_id);
        $current = $this->budgets->resolveBaselineLine($line) ?? $this->fail('The budget line no longer belongs to a baseline budget; pick the current line.', 'budget_line_id');
        $final && BudgetLine::withoutGlobalScopes()->whereKey($current->id)->lockForUpdate()->first();
        if ((int) $current->id !== (int) $line->id) {
            $e->forceFill(['budget_line_id' => $current->id]);
            $line = $current;
        }
        $avail = $this->availability->forLine($line, null, $e->id);
        $exceeds = bccomp((string) $e->base_total_amount, $avail['available'], 2) > 0;
        $snapshot = $avail + ['requested_base' => (string) $e->base_total_amount, 'captured_at' => now()->toIso8601String()];
        if ($exceeds && ! $final) {
            $mode = $this->settings->for((int) $e->organization_id)->overbudget_mode;
            if ($mode === 'block') {
                $this->fail("This request exceeds the {$avail['available']} available on the budget line (over-budget mode is block). Reduce it, pick another line, or ask for a supplementary budget or transfer.", 'amount');
            }
            $this->assertJustification($e->overbudget_justification, 'overbudget_justification', 'This request exceeds the available budget; explain why it should proceed');
        }

        return ['exceeds' => $exceeds, 'snapshot' => $snapshot];
    }

    private function assertJustification(?string $text, string $key, string $prefix): void
    {
        $min = (int) config('finance.expenses.justification_min', 20);
        mb_strlen(trim((string) $text)) >= $min || $this->fail("{$prefix} (at least {$min} characters).", $key);
    }

    /** @return array<string,mixed> persistable attributes */
    private function normalise(int $orgId, array $data, ?ExpenseRequest $existing, ?User $by, bool $system = false): array
    {
        $in = Arr::only($data, self::FILLABLE);
        $get = fn (string $k, $default = null) => array_key_exists($k, $in) ? $in[$k] : ($existing?->{$k} ?? $default);
        $title = trim((string) $get('title', ''));
        ($title === '' || mb_strlen($title) > 200) && $this->fail('Give the request a title (max 200 characters).', 'title');

        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) $get('fiscal_year_id', 0)) ?? $this->fail('Pick a fiscal year of this bank.', 'fiscal_year_id');
        $fy->status === 'open' || $this->fail("{$fy->year_label} is {$fy->status}; requests can only be raised in an open fiscal year.", 'fiscal_year_id');

        $line = null;
        $lineId = $get('budget_line_id');
        if ($lineId) {
            $line = $this->baselineLineFor($orgId, (int) $lineId, $fy, $system ? null : $by);
        }
        $ccId = $get('cost_centre_id') ?: $line?->cost_centre_id;
        $ccId || $this->fail('Pick a cost centre.', 'cost_centre_id');
        $this->inBank(CostCentre::class, $orgId, $ccId, 'cost_centre_id');
        $line && (int) $line->cost_centre_id !== (int) $ccId && $this->fail('The cost centre must be the budget line\'s cost centre.', 'cost_centre_id');
        if ($by && ! $system) {
            $ids = FinancePolicy::visibleCostCentreIds($by);
            $ids !== null && ! in_array((int) $ccId, $ids, true) && ! $by->hasRole('Procurement Officer') && $this->fail('You cannot raise a request on a cost centre outside your access.', 'cost_centre_id');
        }
        $categoryId = $get('category_id') ?: $line?->category_id;
        $line === null && ! $categoryId && ! $system && $this->fail('Pick a category for an unbudgeted request.', 'category_id');
        $categoryId && $this->inBank(Category::class, $orgId, $categoryId, 'category_id');
        $glId = $get('gl_account_id') ?: $line?->gl_account_id ?: ($categoryId ? Category::withoutGlobalScopes()->whereKey($categoryId)->value('default_gl_account_id') : null);
        $glId && $this->inBank(GlAccount::class, $orgId, $glId, 'gl_account_id');
        $leId = $get('legal_entity_id') ?: $line?->legal_entity_id ?: CostCentre::withoutGlobalScopes()->whereKey($ccId)->value('legal_entity_id');
        $leId && $this->inBank(LegalEntity::class, $orgId, $leId, 'legal_entity_id');
        $vendorId = $get('vendor_id') ?: null;
        $vendorId && $this->inBank(Vendor::class, $orgId, $vendorId, 'vendor_id');
        $contract = $get('contract_id') ? $this->inBank(Contract::class, $orgId, $get('contract_id'), 'contract_id') : null;
        $vendorId ??= $contract?->vendor_id;
        $get('asset_id') && $this->inBank(Asset::class, $orgId, $get('asset_id'), 'asset_id');

        $currency = strtoupper((string) $get('currency', $this->settings->baseCurrency($orgId)));
        $net = $this->money($get('amount'), 'amount');
        $vat = null;
        if ($get('tax_type_id')) {
            $vat = $this->inBank(TaxType::class, $orgId, $get('tax_type_id'), 'tax_type_id');
            $vat->is_active && $vat->appliesTo('expense') && in_array($vat->kind, ['vat', 'sales_tax', 'none'], true) || $this->fail('Pick a VAT or sales-tax type that applies to expenses.', 'tax_type_id');
        }
        $needed = $get('needed_by') ? $this->date($get('needed_by'), 'needed_by') : null;
        $on = $needed && $needed->gt(FinanceClock::today()) ? FinanceClock::today() : ($needed ?? FinanceClock::today()); // spot at the expected date, never a future rate
        $totals = $this->tax->compute($net, $currency, $vat, null);
        $conv = $this->fx->convert($orgId, $totals['subtotal'], $currency, $on, 'spot');
        $convTotal = $this->fx->convert($orgId, $totals['total_amount'], $currency, $on, 'spot');
        $period = $needed ? $this->periodContaining($orgId, $needed) : null;

        return [
            'title' => $title, 'description' => $get('description'), 'fiscal_year_id' => $fy->id, 'period_id' => $period?->id, 'budget_line_id' => $line?->id,
            'cost_centre_id' => (int) $ccId, 'category_id' => $categoryId ?: null, 'gl_account_id' => $glId ?: null, 'legal_entity_id' => $leId ?: null,
            'vendor_id' => $vendorId ?: null, 'contract_id' => $contract?->id, 'asset_id' => $get('asset_id') ?: null,
            'is_capex' => (bool) ($in['is_capex'] ?? $existing?->is_capex ?? $line?->is_capex ?? false), 'is_emergency' => (bool) $get('is_emergency', false),
            'is_lease' => (bool) $contract?->is_ifrs16_lease, 'needed_by' => $needed?->toDateString(),
            'currency' => $currency, 'fx_rate' => $conv['fx_rate'], 'fx_rate_type' => $conv['rate_type'], 'fx_rate_date' => $conv['rate_date'],
            'amount' => $totals['subtotal'], 'tax_type_id' => $vat?->id, 'tax_amount' => $totals['tax_amount'], 'total_amount' => $totals['total_amount'],
            'base_amount' => $conv['base_amount'], 'base_total_amount' => $convTotal['base_amount'],
            'justification' => ($j = trim((string) $get('justification', ''))) === '' ? null : $j,
            'overbudget_justification' => ($j = trim((string) $get('overbudget_justification', ''))) === '' ? null : $j,
        ];
    }

    private function isAuthor(ExpenseRequest $e, User $by): bool
    {
        return in_array((int) $by->id, [(int) $e->requested_by_id, (int) $e->created_by_id], true);
    }

    private function assertAuthor(ExpenseRequest $e, User $by): void
    {
        (int) $by->organization_id === (int) $e->organization_id || $this->fail('This request belongs to another bank.');
        $this->isAuthor($e, $by) || $this->fail('Only the person who raised the request can do that.');
    }

    private function assertTransition(ExpenseRequest $e, string $action): void
    {
        in_array($e->status, self::TRANSITIONS[$action]['from'], true)
            || $this->fail("A {$e->status} expense request cannot be ".['submit' => 'submitted', 'withdraw' => 'withdrawn', 'approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected', 'commit' => 'committed', 'cancel' => 'cancelled', 'close' => 'closed'][$action].'.');
    }

    /** The approve permission, or being the named owner of the stage now awaiting a decision (plan P3-6). */
    private function assertDecider(ExpenseRequest $e, User $by): void
    {
        (int) $by->organization_id === (int) $e->organization_id || $this->fail('This request belongs to another bank.', 'approval');
        $by->can('approve it-finance') || $this->approvals->isCurrentOwnerApprover(self::PROCESS, $e, $by)
            || $this->fail('You need the "approve it-finance" permission, or to be the cost-centre owner this is awaiting, to decide an expense request.', 'approval');
    }
}
