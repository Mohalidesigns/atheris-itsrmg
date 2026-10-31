<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsBudgets;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\BudgetService;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

/** Budget versions: list, create, view, workbook page and the approval lifecycle (ATH-FIN-001 §3.2, §9). */
class BudgetController extends Controller
{
    use PresentsBudgets;

    public const TABS = ['lines', 'phasing', 'scenarios', 'approvals', 'versions', 'narrative', 'activity'];

    public function __construct(
        private readonly BudgetService $budgets,
        private readonly FinanceApprovals $approvals,
        private readonly FinanceSettings $settings,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Budget::class);
        $orgId = (int) $user->organization_id;
        $filters = [
            'fiscal_year_id' => $request->integer('fiscal_year_id') ?: null, 'cycle_id' => $request->integer('cycle_id') ?: null,
            'cost_centre_id' => $request->integer('cost_centre_id') ?: null,
            'status' => in_array($request->query('status'), Budget::STATUSES, true) ? $request->query('status') : null,
            'q' => trim((string) $request->query('q', '')) ?: null, 'all_versions' => $request->boolean('all_versions'),
        ];

        $q = Budget::query()->visibleTo($user)->with(['fiscalYear:id,year_label', 'cycle', 'costCentre:id,code,name', 'legalEntity:id,code,name', 'owner:id,name'])
            ->when(! $filters['all_versions'], fn ($q) => $q->latestVersions())
            ->when($filters['fiscal_year_id'], fn ($q, $v) => $q->where('fiscal_year_id', $v))
            ->when($filters['cycle_id'], fn ($q, $v) => $q->where('cycle_id', $v))
            ->when($filters['cost_centre_id'], fn ($q, $v) => $q->where('cost_centre_id', $v))
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['q'], fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%{$v}%")->orWhere('budget_code', 'like', "%{$v}%")))
            ->orderByDesc('fiscal_year_id')->orderBy('budget_code')->orderByDesc('version_no');
        $rows = $q->get();

        $sum = fn ($c) => $c->reduce(fn ($carry, $b) => bcadd($carry, (string) $b->total_base_amount, 2), '0');
        $adjMap = $this->adjustmentsByBudget($rows->pluck('id')->all());
        $sumAdj = fn ($c) => $c->reduce(fn ($carry, $b) => bcadd($carry, $adjMap[$b->id] ?? '0', 2), '0');
        $baselines = $rows->where('status', 'baseline');

        return $this->renderMasked('Finance/Budgets/Index', [
            'filters' => $filters,
            'budgets' => $rows->map(fn (Budget $b) => $this->budgetSummary($b, $adjMap))->values(),
            'summary' => [
                'count' => $rows->count(), 'draft' => $rows->where('status', 'draft')->count(),
                'in_approval' => $rows->whereIn('status', Budget::IN_APPROVAL)->count(), 'approved' => $rows->where('status', 'approved')->count(),
                'baseline' => $rows->where('status', 'baseline')->count(), 'total_baseline_base' => $sum($baselines),
                'total_baseline_adjustments_base' => $sumAdj($baselines),
                'total_baseline_current_base' => bcadd($sum($baselines), $sumAdj($baselines), 2),
                'total_in_flight_base' => $sum($rows->whereIn('status', ['draft', 'submitted', 'under_review', 'approved'])),
            ],
            'fiscalYears' => $this->fiscalYearsPayload(),
            'cycles' => BudgetCycle::query()->orderByDesc('opens_on')->get()->map(fn ($c) => ['id' => $c->id, 'cycle_code' => $c->cycle_code, 'name' => $c->name,
                'type' => $c->type, 'status' => $c->status, 'fiscal_year_id' => $c->fiscal_year_id])->values(),
            'costCentres' => $this->visibleCostCentres($user),
            'statuses' => Budget::STATUSES,
            'baseCurrency' => $this->settings->baseCurrency($orgId),
            'can' => ['create' => $user->can('create it-finance'), 'manageCycles' => $user->can('manage it-finance-settings')],
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        Gate::authorize('createPlan', Budget::class);
        $orgId = (int) $user->organization_id;
        $ccs = $this->visibleCostCentres($user, true);

        return $this->renderMasked('Finance/Budgets/Create', [
            'cycles' => BudgetCycle::query()->where('status', 'open')->orderBy('opens_on')->get()->map(fn ($c) => ['id' => $c->id, 'cycle_code' => $c->cycle_code, 'name' => $c->name,
                'type' => $c->type, 'status' => $c->status, 'fiscal_year_id' => $c->fiscal_year_id])->values(),
            'costCentres' => $ccs,
            'users' => $this->usersPayload($orgId),
            'modes' => Budget::MODES,
            'priorBaselines' => Budget::query()->visibleTo($user)->where('status', 'baseline')->with('fiscalYear:id,year_label')->orderByDesc('fiscal_year_id')->get()
                ->map(fn (Budget $b) => ['id' => $b->id, 'budget_code' => $b->budget_code, 'version_no' => $b->version_no, 'title' => $b->title,
                    'fiscal_year_label' => $b->fiscalYear?->year_label, 'cost_centre_id' => $b->cost_centre_id, 'total_base_amount' => (string) $b->total_base_amount])->values(),
            'defaults' => ['cycle_id' => BudgetCycle::query()->where('status', 'open')->orderBy('opens_on')->value('id'), 'owner_id' => $user->id],
            'baseCurrency' => $this->settings->baseCurrency($orgId),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        Gate::authorize('createPlan', Budget::class);
        $orgId = (int) $user->organization_id;
        $data = $request->validate([
            'cycle_id' => ['required', 'integer', Rule::exists('fin_budget_cycles', 'id')->where('organization_id', $orgId)],
            'cost_centre_id' => ['required', 'integer', Rule::exists('fin_cost_centres', 'id')->where('organization_id', $orgId)],
            'legal_entity_id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:200'],
            'mode' => ['required', Rule::in(Budget::MODES)], 'source_budget_id' => ['nullable', 'integer'],
            'increment_pct' => ['nullable', 'numeric', 'between:-100,100'], 'owner_id' => ['nullable', 'integer'],
            'executive_summary' => ['nullable', 'string', 'max:10000'],
        ]);
        $visible = FinancePolicy::visibleCostCentreIds($user);
        if ($visible !== null && ! in_array((int) $data['cost_centre_id'], $visible, true)) {
            abort(403);
        }
        $cycle = BudgetCycle::query()->findOrFail($data['cycle_id']);
        $cc = CostCentre::query()->findOrFail($data['cost_centre_id']);
        $budget = $this->budgets->createVersion($cycle, $cc, $data['mode'], $user, $data);

        return redirect()->route('finance.budgets.edit', $budget)->with('success', "Budget {$budget->budget_code} created.");
    }

    public function show(Request $request, Budget $budget)
    {
        $user = $request->user();
        Gate::authorize('view', $budget);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'lines';
        $orgId = (int) $budget->organization_id;
        $ceiling = $this->budgets->checkCeiling($budget);
        $lines = $this->linesPayload($budget, $ceiling['over_line_ids']);
        $periods = $this->periodsOf($budget);
        $process = 'finance.budget_approval';

        $approvalContext = ApprovalRequest::query()->where('process', $process)->where('subject_type', Budget::class)->where('subject_id', $budget->id)->latest('id')->value('context');
        $context = is_string($approvalContext) ? json_decode($approvalContext, true) : $approvalContext;

        return $this->renderMasked('Finance/Budgets/Show', [
            'tab' => $tab,
            'budget' => $this->budgetSummary($budget) + [
                'executive_summary' => $budget->executive_summary, 'assumptions' => $budget->assumptions, 'risks_summary' => $budget->risks_summary,
                'rejection_reason' => $budget->rejection_reason, 'increment_pct' => $budget->increment_pct === null ? null : (string) $budget->increment_pct,
                'previous_version_id' => $budget->previous_version_id,
                'superseded_by' => $budget->supersededBy ? ['id' => $budget->supersededBy->id, 'budget_code' => $budget->supersededBy->budget_code, 'version_no' => $budget->supersededBy->version_no] : null,
                'source_budget' => $budget->sourceBudget ? ['id' => $budget->sourceBudget->id, 'budget_code' => $budget->sourceBudget->budget_code, 'version_no' => $budget->sourceBudget->version_no] : null,
                'cycle' => $budget->cycle ? ['id' => $budget->cycle->id, 'cycle_code' => $budget->cycle->cycle_code, 'name' => $budget->cycle->name, 'type' => $budget->cycle->type,
                    'status' => $budget->cycle->status, 'opens_on' => $budget->cycle->opens_on->toDateString(), 'closes_on' => $budget->cycle->closes_on->toDateString()] : null,
            ],
            'lines' => $lines,
            'periods' => $this->periodsMini($periods),
            'totals' => $this->totalsPayload($lines),
            'ceiling' => $ceiling,
            'scenarios' => $budget->scenarios()->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'adjustment_pct' => (string) $s->adjustment_pct,
                'line_adjustments' => $s->line_adjustments ?? (object) [], 'is_promoted' => $s->is_promoted, 'promoted_at' => $s->promoted_at?->toIso8601String(),
                'total_base' => $this->budgets->scenarioTotal($budget, $s)])->values(),
            'versions' => $budget->versions()->get()->map(fn (Budget $v) => ['id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status,
                'total_base_amount' => (string) $v->total_base_amount, 'submitted_at' => $v->submitted_at?->toIso8601String(), 'approved_at' => $v->approved_at?->toIso8601String(),
                'baselined_at' => $v->baselined_at?->toIso8601String(), 'is_current' => $v->id === $budget->id])->values(),
            'approval' => ['process' => $process, 'configured' => $this->approvals->configured($process, $orgId), 'context' => $context],
            'comments' => $budget->comments()->with('user:id,name')->get()->map(fn ($c) => ['id' => $c->id, 'budget_line_id' => $c->budget_line_id,
                'user' => $this->userMini($c->user), 'kind' => $c->kind, 'body' => $c->body, 'created_at' => $c->created_at?->toIso8601String()])->values(),
            'activity' => $this->activity($budget),
            'availableDimensions' => $this->dimensionsPayload($orgId, $this->settings->baseCurrency($orgId)),
            'can' => $this->abilities($budget, $user),
            'baseCurrency' => $this->settings->baseCurrency($orgId),
        ]);
    }

    public function edit(Request $request, Budget $budget)
    {
        $user = $request->user();
        Gate::authorize('view', $budget);
        $orgId = (int) $budget->organization_id;
        $ceiling = $this->budgets->checkCeiling($budget);
        $lines = $this->linesPayload($budget, $ceiling['over_line_ids']);
        $can = $this->abilities($budget, $user);

        $lockedReason = null;
        if ($budget->is_locked) {
            $lockedReason = "Version {$budget->version_no} is {$budget->status} and immutable. Create a revision to edit it.";
        } elseif (in_array($budget->status, Budget::IN_APPROVAL, true)) {
            $lockedReason = "Version {$budget->version_no} is in approval; withdraw it to edit.";
        } elseif (! $can['edit']) {
            $lockedReason = 'You can view this budget but not edit it.';
        }

        return $this->renderMasked('Finance/Budgets/Workbook', [
            'budget' => $this->budgetSummary($budget),
            'lines' => $lines,
            'periods' => $this->periodsMini($this->periodsOf($budget)),
            'totals' => $this->totalsPayload($lines),
            'ceiling' => $ceiling,
            'availableDimensions' => $this->dimensionsPayload($orgId, $this->settings->baseCurrency($orgId)),
            'lockedReason' => $lockedReason,
            'can' => ['edit' => $can['edit'], 'import' => $can['import'], 'export' => $can['export'], 'revise' => $can['revise']],
            'baseCurrency' => $this->settings->baseCurrency($orgId),
        ]);
    }

    public function update(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'], 'executive_summary' => ['nullable', 'string', 'max:20000'],
            'assumptions' => ['nullable', 'string', 'max:20000'], 'risks_summary' => ['nullable', 'string', 'max:20000'], 'owner_id' => ['nullable', 'integer'],
        ]);
        if ($budget->is_locked) {
            $draft = $this->budgets->editLocked($budget, $request->user(), fn (Budget $d) => $this->budgets->updateHeader($d, $data));

            return redirect()->route('finance.budgets.show', [$draft, 'tab' => $request->query('tab', 'narrative')])
                ->with('warning', "Version {$budget->version_no} is {$budget->status}; your change was saved as version {$draft->version_no}.");
        }
        $this->budgets->updateHeader($budget, $data);

        return back()->with('success', 'Budget saved.');
    }

    public function submit(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        $this->budgets->submit($budget, $request->user());

        return redirect()->route('finance.budgets.show', [$budget, 'tab' => 'approvals'])->with('success', "Budget {$budget->budget_code} submitted for approval.");
    }

    public function withdraw(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        $this->budgets->withdraw($budget, $request->user());

        return back()->with('success', 'Budget withdrawn to draft.');
    }

    public function approve(Request $request, Budget $budget)
    {
        Gate::authorize('decidePlan', $budget);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return back()->with('success', $this->budgets->approve($budget, $request->user(), $note));
    }

    public function returnToDraft(Request $request, Budget $budget)
    {
        Gate::authorize('decidePlan', $budget);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'], 'line_comments' => ['nullable', 'array', 'max:50'],
            'line_comments.*.line_id' => ['nullable', 'integer'], 'line_comments.*.body' => ['required', 'string', 'max:2000'],
        ]);
        $this->budgets->returnToDraft($budget, $request->user(), $data['note'], $data['line_comments'] ?? []);

        return back()->with('success', 'Budget returned to draft.');
    }

    public function reject(Request $request, Budget $budget)
    {
        Gate::authorize('decidePlan', $budget);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'], 'line_comments' => ['nullable', 'array', 'max:50'],
            'line_comments.*.line_id' => ['nullable', 'integer'], 'line_comments.*.body' => ['required', 'string', 'max:2000'],
        ]);
        $this->budgets->reject($budget, $request->user(), $data['note'], $data['line_comments'] ?? []);

        return back()->with('success', 'Budget rejected.');
    }

    public function baseline(Request $request, Budget $budget)
    {
        Gate::authorize('baselinePlan', $budget);
        $this->budgets->baseline($budget, $request->user());

        return back()->with('success', "Budget {$budget->budget_code} {$budget->version_label} is now the baseline.");
    }

    public function revise(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        $new = $this->budgets->revise($budget, $request->user());

        return redirect()->route('finance.budgets.edit', $new)->with('success', "Version {$new->version_no} created from version {$budget->version_no}.");
    }

    public function diff(Request $request, Budget $budget)
    {
        Gate::authorize('view', $budget);
        $against = Budget::query()->findOrFail($request->validate(['against' => ['required', 'integer']])['against']);
        Gate::authorize('view', $against);

        $diff = $this->budgets->diff($against->version_no < $budget->version_no ? $against : $budget, $against->version_no < $budget->version_no ? $budget : $against);

        return response()->json($request->user()->can('view it-finance-amounts') ? $diff : $this->maskDiff($diff));
    }

    public function comment(Request $request, Budget $budget)
    {
        Gate::authorize('view', $budget);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000'], 'budget_line_id' => ['nullable', 'integer']]);
        $this->budgets->comment($budget, $request->user(), $data['body'], $data['budget_line_id'] ?? null);

        return back()->with('success', 'Comment added.');
    }

    /** @return array<string,bool> */
    private function abilities(Budget $b, User $u): array
    {
        $isParty = (int) $b->submitted_by_id === (int) $u->id || (int) $b->owner_id === (int) $u->id;
        $edit = $u->can('edit it-finance') && Gate::forUser($u)->allows('updatePlan', $b);
        $inApproval = in_array($b->status, Budget::IN_APPROVAL, true);
        $deciding = $inApproval && Gate::forUser($u)->allows('decidePlan', $b) && ! $isParty && $this->canDecide($b, $u);

        return [
            'edit' => $edit && $b->is_editable,
            'submit' => $edit && in_array($b->status, ['draft', 'rejected'], true),
            'withdraw' => $edit && $inApproval && $isParty,
            'approve' => $deciding, 'return' => $deciding, 'reject' => $deciding,
            'baseline' => $b->status === 'approved' && Gate::forUser($u)->allows('baselinePlan', $b),
            'revise' => $edit && in_array($b->status, ['approved', 'baseline'], true)
                && ! Budget::withoutGlobalScopes()->where('organization_id', $b->organization_id)->where('budget_code', $b->budget_code)->whereIn('status', ['draft', 'submitted', 'under_review', 'rejected'])->exists(),
            'comment' => true,
            'import' => $edit && $b->is_editable,
            'export' => $u->can('export it-finance'),
        ];
    }

    private function canDecide(Budget $b, User $u): bool
    {
        if (! $this->approvals->configured('finance.budget_approval', (int) $b->organization_id)) {
            return true;
        }
        foreach ($this->approvals->trail('finance.budget_approval', $b, $u)['request']['steps'] ?? [] as $step) {
            if (! empty($step['can_decide'])) {
                return true;
            }
        }

        return false;
    }

    private function activity(Budget $b): array
    {
        $lineIds = $b->lines()->pluck('id');
        $can = request()->user()->can('view it-finance-amounts');

        return Activity::query()->where('log_name', 'it-finance')->where(function ($q) use ($b, $lineIds) {
            $q->where(fn ($w) => $w->where('subject_type', $b->getMorphClass())->where('subject_id', $b->id))
                ->orWhere(fn ($w) => $w->where('subject_type', (new BudgetLine)->getMorphClass())->whereIn('subject_id', $lineIds));
        })->with('causer:id,name')->latest('id')->limit(50)->get()->map(fn ($a) => [
            'id' => $a->id, 'event' => $a->event, 'description' => $a->description, 'causer' => $this->userMini($a->causer),
            'changes' => $can ? ($a->properties?->toArray() ?: null) : null, 'created_at' => $a->created_at?->toIso8601String(),
        ])->values()->all();
    }

    private function maskDiff(array $d): array
    {
        $d = $this->maskMoney($d);
        foreach ($d['lines'] as &$l) {
            $l['periods'] = [];
            $l['base_delta'] = null;
            $l['revision_delta'] = null;
            $l['current_delta'] = null;
            foreach ($l['fields'] as $f => $pair) {
                if (in_array($f, ['annual_amount', 'base_annual_amount'], true)) {
                    $l['fields'][$f] = [null, null];
                }
            }
        }

        return $d;
    }
}
