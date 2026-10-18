<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsInvestments;
use App\Http\Requests\Finance\AdvanceGateRequest;
use App\Http\Requests\Finance\InvestmentFormRequest;
use App\Http\Requests\Finance\InvestmentProgressRequest;
use App\Models\Change\ChangeRequest;
use App\Models\Ea\BusinessService;
use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Actual;
use App\Models\Finance\Benefit;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentLink;
use App\Models\Finance\InvestmentScore;
use App\Models\Finance\Period;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
use App\Models\LegalEntity;
use App\Models\Risk;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\BudgetLedger;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\InvestmentFinancials;
use App\Services\Finance\InvestmentScoring;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\StageGateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The investment register, detail page and lifecycle actions (FIN-05.01 to .05, .08, .09). */
class InvestmentController extends Controller
{
    use PresentsInvestments;

    public const TABS = ['overview', 'business-case', 'scoring', 'gates', 'budget', 'benefits', 'reviews', 'changes', 'activity'];

    public function __construct(
        private readonly InvestmentService $investments,
        private readonly InvestmentFinancials $financials,
        private readonly InvestmentScoring $scoring,
        private readonly StageGateService $gates,
        private readonly BenefitsService $benefits,
        private readonly FinanceSettings $settings,
        private readonly FinanceApprovals $approvals,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Investment::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'stage' => ['nullable', Rule::in(Investment::STAGES)], 'rgt' => ['nullable', Rule::in(Investment::RGT)],
            'driver' => ['nullable', Rule::in(Investment::DRIVERS)], 'status' => ['nullable', Rule::in(Investment::STATUSES)], 'fiscal_year_id' => ['nullable', 'integer'],
            'cost_centre_id' => ['nullable', 'integer'], 'regulatory' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
        ]);
        $base = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->visibleTo($user);
        $query = (clone $base)->with(['sponsor:id,name,job_title', 'manager:id,name,job_title', 'costCentre:id,code,name', 'fiscalYear:id,year_label'])
            ->when(! empty($f['search']), function ($q) use ($f) {
                $needle = '%'.mb_strtolower($f['search']).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(title) LIKE ?', [$needle])->orWhereRaw('LOWER(investment_code) LIKE ?', [$needle]));
            })
            ->when(! empty($f['stage']), fn ($q) => $q->where('stage', $f['stage']))->when(! empty($f['rgt']), fn ($q) => $q->where('rgt', $f['rgt']))
            ->when(! empty($f['driver']), fn ($q) => $q->where('driver', $f['driver']))->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['fiscal_year_id']), fn ($q) => $q->where('fiscal_year_id', $f['fiscal_year_id']))
            ->when(! empty($f['cost_centre_id']), fn ($q) => $q->where('cost_centre_id', $f['cost_centre_id']))
            ->when(isset($f['regulatory']) && $f['regulatory'] !== '', fn ($q) => $q->where('is_regulatory', (bool) (int) $f['regulatory']))
            ->orderByRaw('priority_rank IS NULL')->orderBy('priority_rank')->orderByDesc('id');
        $page = $query->paginate(25)->withQueryString();
        $rows = $page->getCollection();
        $fin = $this->financials->forInvestments($rows);
        $pcts = $this->benefits->pctsFor($rows);
        $bcs = BusinessCase::withoutGlobalScopes()->whereIn('investment_id', $rows->pluck('id'))->whereIn('status', ['approved', 'submitted', 'draft'])->orderBy('version_no')->get()
            ->groupBy('investment_id')->map(fn ($g) => $g->firstWhere('status', 'approved') ?? $g->last());
        $pending = StageGate::withoutGlobalScopes()->whereIn('investment_id', $rows->pluck('id'))->where('status', 'submitted')->get()->keyBy('investment_id');
        $page->setCollection($rows->map(fn (Investment $i) => $this->investmentRow($i, $fin[$i->id] ?? null, $bcs->get($i->id), $pcts[$i->id] ?? null, $pending->get($i->id)))->values());
        $payload = $page->toArray();
        $payload['meta'] = ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'links' => $payload['links'] ?? []];

        $all = (clone $base)->whereIn('stage', Investment::OPEN_STAGES)->get();
        $allFin = $this->financials->forInvestments($all);
        $byStage = $all->groupBy('stage')->map->count()->all();

        return $this->renderInvestments('Finance/Investments/Index', [
            'filters' => ['search' => $f['search'] ?? '', 'stage' => $f['stage'] ?? '', 'rgt' => $f['rgt'] ?? '', 'driver' => $f['driver'] ?? '', 'status' => $f['status'] ?? '',
                'fiscal_year_id' => $f['fiscal_year_id'] ?? '', 'cost_centre_id' => $f['cost_centre_id'] ?? '', 'regulatory' => $f['regulatory'] ?? ''],
            'investments' => $payload,
            'summary' => [
                'count' => $all->count(), 'by_stage' => $byStage,
                'bac_total' => $all->filter(fn ($i) => $i->bac !== null)->reduce(fn ($s, $i) => bcadd($s, (string) $i->bac, 2), '0.00'),
                'ac_total' => $all->reduce(fn ($s, $i) => bcadd($s, $allFin[$i->id]['ac'] ?? '0.00', 2), '0.00'),
                'at_risk' => $all->filter(fn ($i) => in_array($allFin[$i->id]['health'] ?? null, ['at_risk', 'off_track'], true) || $i->is_late)->count(),
            ],
            'fiscalYears' => $this->fiscalYearsOpts($orgId), 'costCentres' => $this->costCentresOpts($user), 'options' => $this->optionsPayload(),
            'can' => ['create' => $user->can('create it-finance')],
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('createInvestment', Investment::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;

        return $this->renderInvestments('Finance/Investments/Create', [
            'options' => $this->optionsPayload(), 'users' => $this->usersMini($orgId), 'costCentres' => $this->costCentresOpts($user),
            'legalEntities' => LegalEntity::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->name])->values()->all(),
            'fiscalYears' => $this->fiscalYearsOpts($orgId),
            'defaults' => ['discount_rate_pct' => $this->settings->discountRatePct($orgId), 'horizon_years' => (int) config('finance.investments.default_horizon_years'),
                'cost_tolerance_pct' => $this->settings->costTolerancePct($orgId)],
            'can' => ['create' => true],
        ]);
    }

    public function store(InvestmentFormRequest $request)
    {
        Gate::authorize('createInvestment', Investment::class);
        $i = $this->investments->store($request->validated(), $request->user());

        return redirect()->route('finance.investments.show', $i)->with('success', "Investment {$i->investment_code} created.");
    }

    public function show(Request $request, Investment $investment)
    {
        Gate::authorize('view', $investment);
        $user = $request->user();
        $orgId = (int) $investment->organization_id;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $i = $investment->load(['sponsor:id,name,job_title', 'manager:id,name,job_title', 'costCentre:id,code,name', 'fiscalYear:id,year_label', 'legalEntity:id,code,name']);
        $edit = Gate::forUser($user)->allows('updateInvestment', $i);
        $open = $i->is_open;
        $held = $i->status === 'on_hold';

        $fin = $this->financials->forInvestments(collect([$i]))[$i->id] ?? null;
        $evm = $this->financials->evm($i);
        $cases = BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->with(['approvedBy:id,name,job_title', 'submittedBy:id,name,job_title'])->orderByDesc('version_no')->get();
        $wantVersion = (int) $request->query('version');
        $current = $cases->firstWhere('version_no', $wantVersion) ?? $cases->first(fn ($c) => in_array($c->status, ['draft', 'submitted'], true)) ?? $cases->firstWhere('status', 'approved') ?? $cases->first();
        $pending = StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'submitted')->first();
        $approvedCase = $cases->firstWhere('status', 'approved') ?? $current;

        $gates = StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->with(['requestedBy:id,name,job_title', 'decidedBy:id,name,job_title', 'passedBy:id,name,job_title'])->orderByDesc('id')->get();
        $scores = InvestmentScore::withoutGlobalScopes()->where('investment_id', $i->id)->with('scoredBy:id,name,job_title')->get()->keyBy('criterion');
        $next = $open ? $this->gates->nextGate($i) : null;
        $labels = collect($this->optionsPayload()['criteria'])->pluck('label', 'value');

        $tagged = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->where('investment_id', $i->id)->with(['budget:id,budget_code,fiscal_year_id', 'costCentre:id,code,name'])->orderBy('id')->get();
        $ledger = app(BudgetLedger::class);
        $lineActual = Actual::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('budget_line_id', $tagged->pluck('id'))->where('match_status', '!=', 'duplicate')
            ->selectRaw('budget_line_id, sum(base_amount) as total')->groupBy('budget_line_id')->pluck('total', 'budget_line_id');
        $fyLabels = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('year_label', 'id');

        $benefits = Benefit::withoutGlobalScopes()->where('investment_id', $i->id)->with(['owner:id,name,job_title', 'readings.period:id,name', 'readings.document.evidence', 'readings.recordedBy:id,name'])->orderBy('id')->get();
        $reviews = $i->valueReviews()->withoutGlobalScopes()->with('reviewer:id,name,job_title')->get();
        $changes = $i->changes()->withoutGlobalScopes()->with('requestedBy:id,name,job_title')->get();
        $changeGates = $gates->whereNotNull('investment_change_id')->keyBy('investment_change_id');

        return $this->renderInvestments('Finance/Investments/Show', [
            'tab' => $tab,
            'investment' => $this->investmentRow($i, $fin, $approvedCase, $this->benefits->investmentRealisedPct($i), $pending) + [
                'description' => $i->description, 'strategic_objective' => $i->strategic_objective, 'regulatory_reference' => $i->regulatory_reference,
                'legal_entity' => $i->legalEntity ? ['id' => $i->legalEntity->id, 'code' => $i->legalEntity->code, 'name' => $i->legalEntity->name] : null,
                'cost_tolerance_pct' => (string) $i->cost_tolerance_pct, 'percent_complete' => (string) $i->percent_complete, 'percent_complete_as_of' => $i->percent_complete_as_of?->toDateString(),
                'eac_override' => $i->eac === null ? null : (string) $i->eac, 'eac_reason' => $i->eac_reason, 'external_reference' => $i->external_reference,
                'status_before_hold' => $i->status_before_hold, 'cancel_reason' => $i->cancel_reason, 'value_review_dates' => $i->value_review_dates ?? [],
            ],
            'businessCases' => $cases->map(fn ($c) => $this->bcPayload($c, $i, $edit))->values()->all(),
            'businessCase' => $current ? $this->bcPayload($current, $i, $edit) : null,
            'scores' => collect(Investment::CRITERIA)->filter(fn ($c) => $scores->has($c))->map(fn ($c) => [
                'criterion' => $c, 'label' => $labels[$c] ?? $c, 'score' => (int) $scores[$c]->score, 'weight_pct' => (string) $scores[$c]->weight_pct, 'weighted' => (string) $scores[$c]->weighted,
                'note' => $scores[$c]->note, 'scored_by' => $this->userMini($scores[$c]->scoredBy), 'scored_at' => $scores[$c]->updated_at?->toIso8601String(),
            ])->values()->all(),
            'weights' => $this->scoring->weights($orgId),
            'gates' => $gates->map(fn (StageGate $g) => $this->gatePayload($g, $user, $i))->values()->all(),
            'nextGate' => $next,
            'evm' => $evm,
            'lines' => $tagged->map(fn ($l) => [
                'id' => $l->id, 'budget_id' => $l->budget_id, 'budget_code' => $l->budget?->budget_code, 'line_no' => $l->line_no, 'description' => $l->description,
                'fiscal_year' => $fyLabels[$l->budget?->fiscal_year_id] ?? null, 'cost_centre' => $l->costCentre ? "{$l->costCentre->code} {$l->costCentre->name}" : null,
                'current_base_amount' => $ledger->available($l), 'actual' => bcadd((string) ($lineActual[$l->id] ?? '0'), '0', 2), 'href' => route('finance.budgets.show', $l->budget_id),
            ])->values()->all(),
            'lineOptions' => $edit && $open ? $this->lineOptions($user, $i, $fyLabels) : [],
            'benefits' => $benefits->map(fn (Benefit $b) => $this->benefitPayload($b, $user, $i))->values()->all(),
            'reviews' => $reviews->map(fn ($r) => [
                'id' => $r->id, 'review_type' => $r->review_type, 'due_on' => $r->due_on->toDateString(), 'completed_on' => $r->completed_on?->toDateString(), 'status' => $r->status,
                'reviewer' => $this->userMini($r->reviewer), 'benefits_realised_pct' => $r->benefits_realised_pct === null ? null : (string) $r->benefits_realised_pct,
                'cost_outcome' => $r->cost_outcome, 'summary' => $r->summary, 'lessons' => $r->lessons,
                'can' => ['complete' => in_array($r->status, ValueReview::OPEN, true) && Gate::forUser($user)->allows('completeReview', $r),
                    'edit' => in_array($r->status, ValueReview::OPEN, true) && $edit],
            ])->values()->all(),
            'changes' => $changes->map(fn ($c) => [
                'id' => $c->id, 'change_code' => $c->change_code, 'description' => $c->description, 'reason' => $c->reason, 'cost_delta' => (string) $c->cost_delta,
                'schedule_delta_days' => (int) $c->schedule_delta_days, 'status' => $c->status, 'requires_approval' => (bool) $c->requires_approval, 'requested_by' => $this->userMini($c->requestedBy),
                'requested_at' => $c->requested_at?->toIso8601String(), 'gate_id' => $changeGates->get($c->id)?->id, 'applied_at' => $c->applied_at?->toIso8601String(),
                'can' => ['withdraw' => $c->status === 'submitted' && $edit && ((int) $c->requested_by_id === (int) $user->id || (int) $i->manager_id === (int) $user->id)],
            ])->values()->all(),
            'links' => $i->links()->withoutGlobalScopes()->with(['linkable' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('id')->get()->map(fn (InvestmentLink $l) => $this->linkPayload($l))->values()->all(),
            'linkOptions' => $edit ? $this->linkOptions($orgId) : ['ea_application' => [], 'capability' => [], 'business_service' => [], 'risk' => [], 'change' => []],
            'documents' => FinanceDocument::query()->where('documentable_type', Investment::class)->where('documentable_id', $i->id)->with(['evidence', 'creator:id,name'])->orderByDesc('id')->get()
                ->map(fn ($d) => $this->documentPayload($d))->values()->all(),
            'activity' => $tab === 'activity' || $tab === 'overview' ? $this->activityRows($i) : [],
            'users' => $this->usersMini($orgId),
            'periods' => Period::withoutGlobalScopes()->where('fin_periods.organization_id', $orgId)->where('is_adjustment', false)->join('fin_fiscal_years as y', 'y.id', '=', 'fin_periods.fiscal_year_id')
                ->orderByDesc('y.starts_on')->orderBy('fin_periods.sequence')->get(['fin_periods.id', 'fin_periods.name', 'fin_periods.status', 'y.year_label'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'fiscal_year' => $p->year_label, 'status' => $p->status])->values()->all(),
            'options' => $this->optionsPayload(),
            'can' => [
                'edit' => $edit && $open, 'advance' => $edit && $open && ! $held && ! $pending && $next !== null, 'score' => $open && Gate::forUser($user)->allows('scoreInvestment', $i),
                'hold' => $edit && $open && ! $held, 'resume' => $edit && $open && $held, 'cancel' => $edit && in_array($i->stage, ['idea', 'business_case', 'approved', 'in_delivery'], true),
                'progress' => $edit && in_array($i->stage, ['approved', 'in_delivery', 'delivered', 'benefits_tracking'], true),
                'addBenefit' => $edit && $open, 'addReview' => $edit && $open, 'requestChange' => $edit && in_array($i->stage, ['approved', 'in_delivery'], true) && ! $held && ! $pending,
            ],
        ]);
    }

    public function update(InvestmentFormRequest $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $this->investments->update($investment, $request->validated(), $request->user());

        return back()->with('success', 'Investment updated.');
    }

    public function destroy(Request $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $this->investments->destroy($investment, $request->user());

        return redirect()->route('finance.investments.index')->with('success', 'Investment deleted.');
    }

    public function score(Request $request, Investment $investment)
    {
        Gate::authorize('scoreInvestment', $investment);
        $d = $request->validate(['scores' => ['required', 'array'], 'scores.*' => ['required', 'integer', 'between:1,5'], 'notes' => ['nullable', 'array'], 'notes.*' => ['nullable', 'string', 'max:500']]);
        $this->scoring->score($investment, $d['scores'], $request->user(), $d['notes'] ?? null);

        return back()->with('success', 'Scores saved.');
    }

    public function advanceGate(AdvanceGateRequest $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $d = $request->validated();
        $gate = $this->gates->advance($investment, $d['to_stage'], $d['ticked'] ?? [], $d['payload'] ?? [], $d['note'] ?? null, $request->user());

        return back()->with('success', $gate->status === 'submitted' ? 'Sent for approval.' : 'Gate passed.');
    }

    public function hold(Request $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $this->investments->hold($investment, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $request->user());

        return back()->with('success', 'Investment put on hold.');
    }

    public function resume(Request $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $this->investments->resume($investment, $request->user());

        return back()->with('success', 'Investment resumed.');
    }

    public function cancel(Request $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $this->investments->cancel($investment, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $request->user());

        return back()->with('success', 'Investment cancelled.');
    }

    public function progress(InvestmentProgressRequest $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $this->investments->progress($investment, $request->validated(), $request->user());

        return back()->with('success', 'Progress recorded.');
    }

    public function syncLines(Request $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $d = $request->validate(['line_ids' => ['present', 'nullable', 'array', 'max:200'], 'line_ids.*' => ['integer']]);
        $this->investments->linkLines($investment, $d['line_ids'] ?? [], $request->user());

        return back()->with('success', 'Budget lines updated.');
    }

    public function storeLink(Request $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $d = $request->validate(['type' => ['required', Rule::in(array_keys(InvestmentLink::TYPES))], 'id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:300']]);
        $this->investments->addLink($investment, $d['type'], (int) $d['id'], $d['note'] ?? null, $request->user());

        return back()->with('success', 'Link added.');
    }

    public function destroyLink(Request $request, Investment $investment, int $link)
    {
        Gate::authorize('updateInvestment', $investment);
        $l = InvestmentLink::query()->where('investment_id', $investment->id)->findOrFail($link);
        $this->investments->removeLink($l, $request->user());

        return back()->with('success', 'Link removed.');
    }

    /** @return array<string,mixed> */
    private function bcPayload(BusinessCase $c, Investment $i, bool $edit): array
    {
        $editable = $edit && $c->status === 'draft';

        return [
            'id' => $c->id, 'version_no' => $c->version_no, 'status' => $c->status, 'horizon_years' => (int) $c->horizon_years, 'discount_rate_pct' => (string) $c->discount_rate_pct,
            'option_summary' => $c->option_summary, 'recommended_option' => $c->recommended_option, 'costs' => $c->costs ?? [], 'benefits' => $c->benefits ?? [],
            'non_financial_benefits' => $c->non_financial_benefits ?? [], 'npv' => $c->npv === null ? null : (string) $c->npv, 'irr_pct' => $c->irr_pct === null ? null : (string) $c->irr_pct,
            'irr_multiple' => (bool) $c->irr_multiple, 'payback_months' => $c->payback_months, 'discounted_payback_months' => $c->discounted_payback_months,
            'roi_pct' => $c->roi_pct === null ? null : (string) $c->roi_pct, 'tco' => (string) $c->tco, 'one_off_cost' => (string) $c->one_off_cost, 'total_benefit' => (string) $c->total_benefit,
            'assumptions' => $c->assumptions, 'risks_summary' => $c->risks_summary, 'submitted_at' => $c->submitted_at?->toIso8601String(), 'approved_by' => $this->userMini($c->approvedBy),
            'approved_at' => $c->approved_at?->toIso8601String(), 'rejection_reason' => $c->rejection_reason,
            'can' => ['edit' => $editable, 'submit' => $editable && in_array($i->stage, ['idea', 'business_case'], true) && $i->status !== 'on_hold'],
        ];
    }

    /** @return array<string,mixed> */
    private function gatePayload(StageGate $g, User $user, Investment $i): array
    {
        $open = $g->status === 'submitted';
        $excluded = in_array((int) $user->id, $g->sodExcludedIds(), true);
        $deciding = $open && ! $excluded && Gate::forUser($user)->allows('decideGate', $g) && $this->canDecide($g, $user);

        return [
            'id' => $g->id, 'gate' => $g->gate, 'from_stage' => $g->from_stage, 'status' => $g->status, 'criteria' => $g->criteria ?? [], 'payload' => $g->payload,
            'requested_by' => $this->userMini($g->requestedBy), 'requested_at' => $g->requested_at?->toIso8601String(), 'request_note' => $g->request_note,
            'decided_by' => $this->userMini($g->decidedBy), 'decided_at' => $g->decided_at?->toIso8601String(), 'rejection_reason' => $g->rejection_reason,
            'passed_at' => $g->passed_at?->toIso8601String(), 'passed_by' => $this->userMini($g->passedBy), 'approval_base_amount' => $g->approval_base_amount === null ? null : (string) $g->approval_base_amount,
            'amount_band_label' => $g->amount_band_label, 'trail' => $g->approval_request_id ? $this->approvals->trail(StageGateService::PROCESS, $g, $user) : null,
            'can' => ['approve' => $deciding, 'reject' => $deciding, 'withdraw' => $open && ((int) $g->requested_by_id === (int) $user->id || (int) $i->manager_id === (int) $user->id)],
        ];
    }

    private function canDecide(StageGate $g, User $u): bool
    {
        if (! $this->approvals->configured(StageGateService::PROCESS, (int) $g->organization_id)) {
            return true;
        }
        foreach ($this->approvals->trail(StageGateService::PROCESS, $g, $u)['request']['steps'] ?? [] as $step) {
            if (! empty($step['can_decide'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function benefitPayload(Benefit $b, User $user, Investment $i): array
    {
        $live = ! in_array($b->status, Benefit::FINAL, true);

        return [
            'id' => $b->id, 'benefit_code' => $b->benefit_code, 'title' => $b->title, 'description' => $b->description, 'type' => $b->type, 'owner' => $this->userMini($b->owner),
            'measurement_method' => $b->measurement_method, 'baseline_value' => (string) $b->baseline_value, 'target_value' => (string) $b->target_value, 'unit' => $b->unit,
            'aggregation' => $b->aggregation, 'currency' => $b->currency, 'due_on' => $b->due_on?->toDateString(), 'status' => $b->status,
            'realised_value' => $b->realised_value === null ? null : (string) $b->realised_value, 'realised_pct' => $b->realised_pct === null ? null : (string) $b->realised_pct,
            'readings' => $b->readings->map(fn ($r) => [
                'id' => $r->id, 'period' => $r->period ? ['id' => $r->period->id, 'name' => $r->period->name] : null, 'value' => (string) $r->value,
                'document' => $r->document ? ['id' => $r->document->id, 'title' => $r->document->title, 'url' => $r->document->evidence?->file_path ? route('finance.documents.download', $r->document) : null] : null,
                'note' => $r->note, 'recorded_by' => $r->recordedBy?->name, 'recorded_at' => $r->updated_at?->toIso8601String(),
            ])->values()->all(),
            'can' => [
                'edit' => $live && Gate::forUser($user)->allows('updateInvestment', $i), 'record' => $b->status !== 'cancelled' && $i->stage !== 'cancelled' && Gate::forUser($user)->allows('recordBenefit', $b),
                'cancel' => in_array($b->status, ['planned', 'tracking'], true) && Gate::forUser($user)->allows('updateInvestment', $i),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function documentPayload(FinanceDocument $d): array
    {
        return ['id' => $d->id, 'kind' => $d->kind, 'title' => $d->title, 'url' => $d->evidence?->file_path ? route('finance.documents.download', $d) : null,
            'uploaded_by' => $d->creator?->name, 'uploaded_at' => $d->created_at?->toIso8601String()];
    }

    /** @return array<string,mixed> */
    private function linkPayload(InvestmentLink $l): array
    {
        $t = $l->linkable;
        [$label, $href] = match ($l->type_key) {
            'ea_application' => [$t ? trim("{$t->code} {$t->name}") : null, $t ? route('ea.applications.show', $t->id) : null],
            'capability' => [$t?->name, $t ? route('ea.capabilities.show', $t->id) : null],
            'business_service' => [$t ? trim("{$t->code} {$t->name}") : null, null],
            'risk' => [$t ? trim("{$t->risk_id_code} {$t->title}") : null, $t ? route('risks.show', $t->id) : null],
            'change' => [$t ? trim("{$t->change_code} {$t->title}") : null, $t ? route('changes.show', $t->id) : null],
            default => [null, null],
        };

        return ['id' => $l->id, 'type' => $l->type_key, 'label' => $label ?? 'Removed record', 'href' => $href];
    }

    /** @return array<string,list<array{id:int,label:string}>> */
    private function linkOptions(int $orgId): array
    {
        $shared = fn (string $class, callable $label) => $class::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->orderBy('id')->limit(500)->get()
            ->map(fn ($m) => ['id' => $m->id, 'label' => $label($m)])->values()->all();
        $own = fn (string $class, callable $label) => $class::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->limit(500)->get()
            ->map(fn ($m) => ['id' => $m->id, 'label' => $label($m)])->values()->all();

        return [
            'ea_application' => $shared(EaApplication::class, fn ($a) => trim("{$a->code} {$a->name}")),
            'capability' => $shared(Capability::class, fn ($a) => (string) $a->name),
            'business_service' => $shared(BusinessService::class, fn ($a) => trim("{$a->code} {$a->name}")),
            'risk' => $own(Risk::class, fn ($r) => trim("{$r->risk_id_code} {$r->title}")),
            'change' => $own(ChangeRequest::class, fn ($c) => trim("{$c->change_code} {$c->title}")),
        ];
    }

    /** @return list<array<string,mixed>> baseline and draft lines the user may tag, not tagged elsewhere */
    private function lineOptions(User $user, Investment $i, $fyLabels): array
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);
        $budgets = Budget::withoutGlobalScopes()->where('organization_id', $i->organization_id)->whereIn('status', ['baseline', 'draft'])->get(['id', 'budget_code', 'fiscal_year_id'])->keyBy('id');

        return BudgetLine::withoutGlobalScopes()->where('organization_id', $i->organization_id)->whereIn('budget_id', $budgets->keys())
            ->where(fn ($q) => $q->whereNull('investment_id')->orWhere('investment_id', $i->id))
            ->when($ids !== null, fn ($q) => $q->whereIn('cost_centre_id', $ids ?: [0]))->with('costCentre:id,code,name')->orderBy('budget_id')->orderBy('line_no')->limit(500)->get()
            ->map(fn ($l) => ['id' => $l->id, 'label' => "{$budgets[$l->budget_id]->budget_code} #{$l->line_no} {$l->description}", 'fiscal_year' => $fyLabels[$budgets[$l->budget_id]->fiscal_year_id] ?? null,
                'cost_centre' => $l->costCentre ? "{$l->costCentre->code} {$l->costCentre->name}" : null])->values()->all();
    }
}
