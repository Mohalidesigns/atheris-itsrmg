<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsBudgets;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Currency;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\SupplementaryRequest;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\BudgetLedger;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\SupplementaryBudgetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Supplementary budget requests (FIN-02.11). */
class SupplementaryRequestController extends Controller
{
    use PresentsBudgets;

    public function __construct(
        private readonly SupplementaryBudgetService $requests,
        private readonly BudgetLedger $ledger,
        private readonly FinanceSettings $settings,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', SupplementaryRequest::class);
        $orgId = (int) $user->organization_id;
        $fys = FiscalYear::query()->orderByDesc('starts_on')->get();
        $fyId = $request->integer('fiscal_year_id') ?: ($fys->firstWhere('is_current', true)?->id ?? $fys->first()?->id);
        $status = in_array($request->query('status'), SupplementaryRequest::STATUSES, true) ? $request->query('status') : null;

        $rows = SupplementaryRequest::query()->where('fiscal_year_id', $fyId)->when($status, fn ($q, $s) => $q->where('status', $s))
            ->with(['fiscalYear:id,year_label', 'costCentre:id,code,name', 'costPool:id,code,name', 'tower:id,code,name', 'category:id,code,name', 'targetLine:id,line_no,description',
                'resultingLine:id,line_no,description,budget_id', 'requestedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('id')->get()->filter(fn ($r) => Gate::forUser($user)->allows('view', $r))->values();
        $all = SupplementaryRequest::query()->where('fiscal_year_id', $fyId)->get();

        $ids = FinancePolicy::visibleCostCentreIds($user);
        $budgets = Budget::query()->where('fiscal_year_id', $fyId)->where('status', 'baseline')->when($ids !== null, fn ($q) => $q->whereIn('cost_centre_id', $ids ?: [0]))
            ->with('costCentre:id,code')->get()->keyBy('id');
        $lineOptions = BudgetLine::query()->whereIn('budget_id', $budgets->keys())->orderBy('budget_id')->orderBy('line_no')->get()->map(function (BudgetLine $l) use ($budgets) {
            $b = $budgets[$l->budget_id];

            return ['id' => $l->id, 'label' => "{$b->budget_code} {$b->version_label} · #{$l->line_no} {$l->description} ({$b->costCentre?->code})",
                'cost_centre_id' => $l->cost_centre_id, 'is_capex' => $l->is_capex, 'current_base_amount' => $l->current_base_amount,
                'available_by_period' => $this->ledger->currentByPeriod($l)];
        })->values();

        $mini = fn ($c) => $c ? ['id' => $c->id, 'code' => $c->code, 'name' => $c->name] : null;

        return $this->renderMasked('Finance/Budgets/Supplementary', [
            'filters' => ['fiscal_year_id' => $fyId, 'status' => $status, 'highlight' => $request->integer('highlight') ?: null],
            'requests' => $rows->map(fn (SupplementaryRequest $r) => $this->item($r, $user, $mini))->values(),
            'summary' => [
                'draft' => $all->where('status', 'draft')->count(), 'pending' => $all->where('status', 'submitted')->count(),
                'approved_unapplied' => $all->where('status', 'approved')->count(), 'applied_count' => $all->where('status', 'applied')->count(),
                'applied_base' => $all->where('status', 'applied')->reduce(fn ($c, $r) => bcadd($c, (string) $r->base_amount, 2), '0'),
            ],
            'lineOptions' => $lineOptions,
            'costCentres' => $this->visibleCostCentres($user),
            'costPools' => Category::query()->where('kind', 'cost_pool')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'towers' => Category::query()->where('kind', 'tower')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'subCategories' => Category::query()->where('kind', 'sub_category')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'parent_id']),
            'currencies' => Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get()->map(fn ($c) => ['code' => $c->code, 'decimals' => $c->decimals, 'is_base' => $c->is_base])->values(),
            'fiscalYears' => $this->fiscalYearsPayload(),
            'baseCurrency' => $this->settings->baseCurrency($orgId),
            'can' => ['create' => $user->can('create it-finance')],
        ]);
    }

    private function item(SupplementaryRequest $r, User $u, callable $mini): array
    {
        $mine = (int) $r->requested_by_id === (int) $u->id;
        $approver = Gate::forUser($u)->allows('decidePlan', $r) && ! $mine;

        return [
            'id' => $r->id, 'request_code' => $r->request_code, 'title' => $r->title, 'status' => $r->status,
            'fiscal_year' => $r->fiscalYear ? ['id' => $r->fiscalYear->id, 'year_label' => $r->fiscalYear->year_label] : null,
            'cost_centre' => $mini($r->costCentre), 'cost_pool' => $mini($r->costPool), 'tower' => $mini($r->tower), 'category' => $mini($r->category),
            'target_line' => $r->targetLine ? ['id' => $r->targetLine->id, 'line_no' => $r->targetLine->line_no, 'description' => $r->targetLine->description] : null,
            'resulting_line' => $r->resultingLine ? ['id' => $r->resultingLine->id, 'line_no' => $r->resultingLine->line_no, 'description' => $r->resultingLine->description, 'budget_id' => $r->resultingLine->budget_id] : null,
            'is_capex' => $r->is_capex, 'amount' => (string) $r->amount, 'currency' => $r->currency, 'fx_rate' => (string) $r->fx_rate, 'base_amount' => (string) $r->base_amount,
            'amount_band' => $r->amount_band, 'amount_band_label' => app(DoaBandService::class)->bandFor((int) $r->organization_id, (string) $r->base_amount)['label'], 'capex_opex' => $r->capex_opex, 'justification' => $r->justification,
            'requested_by' => $this->userMini($r->requestedBy), 'approved_by' => $this->userMini($r->approvedBy),
            'approved_at' => $r->approved_at?->toIso8601String(), 'applied_at' => $r->applied_at?->toIso8601String(), 'rejection_reason' => $r->rejection_reason,
            'can' => [
                'edit' => $r->status === 'draft' && $mine, 'submit' => $r->status === 'draft' && $mine,
                'withdraw' => in_array($r->status, ['draft', 'submitted'], true) && $mine,
                'approve' => $r->status === 'submitted' && $approver, 'reject' => $r->status === 'submitted' && $approver,
                'apply' => $r->status === 'approved' && Gate::forUser($u)->allows('decidePlan', $r),
            ],
        ];
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'fiscal_year_id' => ['required', 'integer'], 'cost_centre_id' => ['required', 'integer'], 'target_line_id' => ['nullable', 'integer'],
            'cost_pool_id' => ['nullable', 'integer'], 'tower_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:200'], 'is_capex' => ['sometimes', 'boolean'], 'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'], 'justification' => ['required', 'string', 'max:5000'],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('createPlan', SupplementaryRequest::class);
        $r = $this->requests->create($this->data($request), $request->user());

        return back()->with('success', "Request {$r->request_code} drafted.");
    }

    public function update(Request $request, SupplementaryRequest $supplementary)
    {
        Gate::authorize('updatePlan', $supplementary);
        $this->requests->update($supplementary, $this->data($request), $request->user());

        return back()->with('success', 'Request saved.');
    }

    public function submit(Request $request, SupplementaryRequest $supplementary)
    {
        Gate::authorize('updatePlan', $supplementary);
        $this->requests->submit($supplementary, $request->user());

        return back()->with('success', "Request {$supplementary->request_code} submitted for approval.");
    }

    public function withdraw(Request $request, SupplementaryRequest $supplementary)
    {
        Gate::authorize('updatePlan', $supplementary);
        $this->requests->withdraw($supplementary, $request->user());

        return back()->with('success', 'Request withdrawn.');
    }

    public function approve(Request $request, SupplementaryRequest $supplementary)
    {
        Gate::authorize('decidePlan', $supplementary);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return back()->with('success', $this->requests->approve($supplementary, $request->user(), $note));
    }

    public function reject(Request $request, SupplementaryRequest $supplementary)
    {
        Gate::authorize('decidePlan', $supplementary);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->requests->reject($supplementary, $request->user(), $note);

        return back()->with('success', 'Request rejected.');
    }

    public function apply(Request $request, SupplementaryRequest $supplementary)
    {
        Gate::authorize('decidePlan', $supplementary);
        $this->requests->apply($supplementary, $request->user());

        return back()->with('success', "Request {$supplementary->request_code} applied.");
    }
}
