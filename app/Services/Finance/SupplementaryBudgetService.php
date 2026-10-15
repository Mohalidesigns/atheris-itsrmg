<?php

namespace App\Services\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\SupplementaryRequest;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Facades\DB;

/**
 * Supplementary budget requests (FIN-02.11): a mid-year top-up approved on top of the baseline.
 * Applying posts adjustments to an existing baseline line, or appends a new zero-original line.
 */
class SupplementaryBudgetService
{
    use LogsFinanceEvents;

    public const PROCESS = 'finance.supplementary_budget';

    public const TRANSITIONS = BudgetTransferService::TRANSITIONS;

    public function __construct(
        private readonly BudgetLedger $ledger,
        private readonly BudgetPhasing $phasing,
        private readonly BudgetService $budgets,
        private readonly CurrencyService $fx,
        private readonly FinanceApprovals $approvals,
        private readonly FinanceSettings $settings,
    ) {}

    public function create(array $data, User $by): SupplementaryRequest
    {
        $orgId = (int) $by->organization_id;
        $clean = $this->validated($orgId, $data, $by);
        $r = SupplementaryRequest::withoutGlobalScopes()->create($clean + [
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'request_code' => SupplementaryRequest::generateNextCode($orgId),
            'requested_by_id' => $by->id, 'status' => 'draft',
        ]);
        $this->audit($r, $by, 'created', "Supplementary request {$r->request_code} drafted");

        return $r->refresh();
    }

    public function update(SupplementaryRequest $r, array $data, User $by): SupplementaryRequest
    {
        $r->status === 'draft' || $this->fail("A {$r->status} request cannot be edited.");
        $r->fill($this->validated((int) $r->organization_id, $data, $by))->save();

        return $r->refresh();
    }

    public function submit(SupplementaryRequest $r, User $by): SupplementaryRequest
    {
        $this->assertTransition($r, 'submit');
        $this->assertRequester($r, $by);
        $this->assertBaselineFor($r);

        return DB::transaction(function () use ($r, $by) {
            $r->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'rejection_reason' => null])->save();
            $this->approvals->start(self::PROCESS, $r, $by);
            $this->audit($r, $by, 'submitted', "Supplementary request {$r->request_code} submitted", ['base_amount' => (string) $r->base_amount]);

            return $r->refresh();
        });
    }

    public function approve(SupplementaryRequest $r, User $by, ?string $note = null): string
    {
        $this->assertTransition($r, 'approve');
        $this->assertApprover($r, $by);

        return DB::transaction(function () use ($r, $by, $note) {
            $this->approvals->assertZeroStepSoD(self::PROCESS, $r, $by, 'requested_by_id');
            if (! $this->approvals->configured(self::PROCESS, (int) $r->organization_id)) {
                $this->approvals->assertDefaultApprover($r, $by, 'requested_by_id');
            } elseif ($message = $this->approvals->approve(self::PROCESS, $r, $by, $note)) {
                $this->audit($r, $by, 'stage_approved', $message);

                return $message;
            }
            $r->forceFill(['status' => 'approved', 'approved_by_id' => $by->id, 'approved_at' => now()])->save();
            $this->approvals->finalised(self::PROCESS, $r, $by, $note);
            $this->audit($r, $by, 'approved', "Supplementary request {$r->request_code} approved");

            return "Request {$r->request_code} approved. It takes effect when it is applied.";
        });
    }

    public function reject(SupplementaryRequest $r, User $by, string $note): SupplementaryRequest
    {
        $this->assertTransition($r, 'reject');
        blank($note) && $this->fail('Give a reason.', 'note');
        $this->assertApprover($r, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $r->organization_id)) {
            $this->approvals->assertDefaultApprover($r, $by, 'requested_by_id');
        }
        DB::transaction(function () use ($r, $by, $note) {
            $this->approvals->decline(self::PROCESS, $r, $by, $note, 'rejected');
            $r->forceFill(['status' => 'rejected', 'rejection_reason' => $note])->save();
            $this->audit($r, $by, 'rejected', "Supplementary request {$r->request_code} rejected", ['note' => $note]);
        });

        return $r->refresh();
    }

    public function withdraw(SupplementaryRequest $r, User $by): SupplementaryRequest
    {
        $this->assertTransition($r, 'withdraw');
        $this->assertRequester($r, $by);
        DB::transaction(function () use ($r, $by) {
            $wasSubmitted = $r->status === 'submitted';
            $r->forceFill(['status' => 'withdrawn'])->save();
            $wasSubmitted && $this->approvals->withdraw(self::PROCESS, $r, $by);
            $this->audit($r, $by, 'withdrawn', "Supplementary request {$r->request_code} withdrawn");
        });

        return $r->refresh();
    }

    public function apply(SupplementaryRequest $r, User $by): SupplementaryRequest
    {
        $this->assertTransition($r, 'apply');
        $by->can('approve it-finance') || $this->fail('You need the "approve it-finance" permission to apply a request.');

        return DB::transaction(function () use ($r, $by) {
            $r = SupplementaryRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($r->id);
            $this->assertTransition($r, 'apply');
            $orgId = (int) $r->organization_id;
            $open = $this->ledger->openPeriods($orgId, (int) $r->fiscal_year_id);
            $open->isEmpty() && $this->fail('The fiscal year has no open period to post to.');

            if ($r->target_line_id) {
                $line = BudgetLine::withoutGlobalScopes()->lockForUpdate()->findOrFail($r->target_line_id);
                $line = $this->budgets->resolveBaselineLine($line) ?? $this->fail('The target line no longer belongs to a baseline budget; raise a new request.');
                $current = $this->ledger->currentByPeriod($line);
                $weights = $open->map(fn ($p) => max('0', $current[$p->id] ?? '0'))->values()->all();
            } else {
                $budget = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('baseline_slot', "{$r->fiscal_year_id}:{$r->cost_centre_id}")->first()
                    ?? $this->fail('The cost centre has no baseline budget for this fiscal year.');
                BudgetLine::$allowLockedAppend = true;
                try {
                    $line = $this->budgets->saveLine($budget, null, [
                        'description' => $r->title, 'cost_pool_id' => $r->cost_pool_id, 'tower_id' => $r->tower_id, 'category_id' => $r->category_id,
                        'is_capex' => (bool) $r->is_capex, 'priority' => 'regulatory', 'rgt' => 'run', 'annual_amount' => '0',
                        'currency' => $this->settings->baseCurrency($orgId), 'phasing_method' => 'equal', 'origin' => 'supplementary',
                        'justification' => $r->justification,
                    ], $by);
                } finally {
                    BudgetLine::$allowLockedAppend = false;
                }
                $weights = array_fill(0, $open->count(), '1');
            }
            $spread = $this->phasing->spread((string) $r->base_amount, $weights, 2);
            foreach ($open->values() as $i => $p) {
                if (bccomp($spread[$i], '0', 2) !== 0) {
                    $this->ledger->post($line, $p, $spread[$i], 'supplementary', $r->id, $by);
                }
            }
            $r->forceFill(['status' => 'applied', 'applied_by_id' => $by->id, 'applied_at' => now(), 'resulting_line_id' => $line->id])->save();
            $this->audit($r, $by, 'applied', "Supplementary request {$r->request_code} applied", ['line_id' => $line->id, 'base_amount' => (string) $r->base_amount]);

            return $r->refresh();
        });
    }

    /** @return array<string,mixed> */
    private function validated(int $orgId, array $d, User $by): array
    {
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) ($d['fiscal_year_id'] ?? 0)) ?? $this->fail('Pick a fiscal year of this bank.', 'fiscal_year_id');
        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) ($d['cost_centre_id'] ?? 0)) ?? $this->fail('Pick a cost centre.', 'cost_centre_id');
        $visible = FinancePolicy::visibleCostCentreIds($by);
        if ($visible !== null && ! in_array($cc->id, $visible, true)) {
            $this->fail('You can only request for cost centres you own.', 'cost_centre_id');
        }

        $target = null;
        $pool = $tower = $cat = null;
        if (! empty($d['target_line_id'])) {
            $target = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) $d['target_line_id']);
            $budget = $target ? Budget::withoutGlobalScopes()->find($target->budget_id) : null;
            if (! $target || ! $budget || $budget->status !== 'baseline' || (int) $budget->fiscal_year_id !== (int) $fy->id || (int) $budget->cost_centre_id !== (int) $cc->id) {
                $this->fail('Pick a line of this cost centre\'s baseline budget for the fiscal year.', 'target_line_id');
            }
            $d['cost_pool_id'] = $target->cost_pool_id;
            $d['tower_id'] = $target->tower_id;
            $d['category_id'] = $d['category_id'] ?? $target->category_id ?? $target->tower_id;
            $d['is_capex'] = $target->is_capex;
        } else {
            $pool = Category::withoutGlobalScopes()->where('organization_id', $orgId)->where('kind', 'cost_pool')->find((int) ($d['cost_pool_id'] ?? 0)) ?? $this->fail('Pick a cost pool.', 'cost_pool_id');
            $tower = Category::withoutGlobalScopes()->where('organization_id', $orgId)->where('kind', 'tower')->find((int) ($d['tower_id'] ?? 0)) ?? $this->fail('Pick a tower.', 'tower_id');
        }
        if (empty($d['category_id'])) {
            $d['category_id'] = $d['tower_id'];
        }
        Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($d['category_id'])->exists() || $this->fail('Unknown category.', 'category_id');

        $title = trim((string) ($d['title'] ?? ''));
        ($title === '' || mb_strlen($title) > 200) && $this->fail('Give the request a title (max 200 characters).', 'title');
        blank($d['justification'] ?? null) && $this->fail('Explain why the money is needed.', 'justification');
        $amount = (string) ($d['amount'] ?? '');
        if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            $this->fail('The amount must be greater than 0 (up to 2 decimals).', 'amount');
        }
        $currency = strtoupper((string) ($d['currency'] ?? $this->settings->baseCurrency($orgId)));
        $conv = $this->fx->convert($orgId, $amount, $currency, now(), 'spot');

        return [
            'fiscal_year_id' => $fy->id, 'cost_centre_id' => $cc->id, 'cost_pool_id' => $d['cost_pool_id'], 'tower_id' => $d['tower_id'], 'category_id' => $d['category_id'],
            'target_line_id' => $target?->id, 'title' => $title, 'is_capex' => (bool) ($d['is_capex'] ?? false), 'amount' => $conv['amount'], 'currency' => $currency,
            'fx_rate' => $conv['fx_rate'], 'fx_rate_type' => $conv['rate_type'], 'fx_rate_date' => $conv['rate_date'], 'base_amount' => $conv['base_amount'],
            'justification' => trim((string) $d['justification']),
        ];
    }

    private function assertBaselineFor(SupplementaryRequest $r): void
    {
        if (! $r->target_line_id && ! Budget::withoutGlobalScopes()->where('organization_id', $r->organization_id)->where('baseline_slot', "{$r->fiscal_year_id}:{$r->cost_centre_id}")->exists()) {
            $this->fail('The cost centre has no baseline budget for this fiscal year.');
        }
    }

    private function assertTransition(SupplementaryRequest $r, string $action): void
    {
        if (! in_array($r->status, self::TRANSITIONS[$action]['from'], true)) {
            $this->fail("A {$r->status} request cannot be ".['submit' => 'submitted', 'withdraw' => 'withdrawn', 'approve' => 'approved', 'reject' => 'rejected', 'apply' => 'applied'][$action].'.');
        }
    }

    private function assertRequester(SupplementaryRequest $r, User $by): void
    {
        (int) $r->requested_by_id === (int) $by->id || $this->fail('Only the person who made the request can do that.');
    }

    private function assertApprover(SupplementaryRequest $r, User $by): void
    {
        (int) $by->organization_id === (int) $r->organization_id || $this->fail('This request belongs to another bank.');
        $by->can('approve it-finance') || $this->fail('You need the "approve it-finance" permission to decide a request.', 'approval');
    }
}
