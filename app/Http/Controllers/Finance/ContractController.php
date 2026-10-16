<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsContracts;
use App\Http\Requests\Finance\StoreContractRequest;
use App\Http\Requests\Finance\UpdateContractRequest;
use App\Models\Asset;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Renewal;
use App\Models\LegalEntity;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\ContractService;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RenewalService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/** Contract and subscription register (ATH-FIN-001 §3.6, FIN-06.01 to .10). */
class ContractController extends Controller
{
    use PresentsContracts;

    public const TABS = ['overview', 'team', 'links', 'documents', 'renewal', 'timeline', 'spend'];

    public function __construct(
        private readonly ContractService $contracts,
        private readonly FinanceApprovals $approvals,
        private readonly FinanceSettings $settings,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Contract::class);
        $orgId = (int) $user->organization_id;
        $today = FinanceClock::today();
        $filters = [
            'q' => trim((string) $request->query('q', '')) ?: null,
            'status' => in_array($request->query('status'), Contract::STATUSES, true) ? $request->query('status') : null,
            'type' => in_array($request->query('type'), Contract::TYPES, true) ? $request->query('type') : null,
            'vendor_id' => $request->integer('vendor_id') ?: null, 'owner_id' => $request->integer('owner_id') ?: null,
            'cost_centre_id' => $request->integer('cost_centre_id') ?: null,
            'due' => in_array((int) $request->query('due'), [30, 60, 90], true) ? (int) $request->query('due') : null,
            'mine' => $request->boolean('mine'), 'passed' => $request->boolean('passed'),
        ];
        $base = fn () => Contract::query()->visibleTo($user);

        $rows = $base()->with(['vendor:id,name', 'owner:id,name', 'technicalOwner:id,name', 'costCentre:id,code,name', 'openRenewal'])
            ->when($filters['q'], fn ($q, $v) => $q->where(fn ($w) => $w->whereRaw('lower(fin_contracts.title) like ?', ['%'.mb_strtolower($v).'%'])
                ->orWhereRaw('lower(fin_contracts.contract_code) like ?', ['%'.mb_strtolower($v).'%'])
                ->orWhereIn('fin_contracts.vendor_id', Vendor::query()->whereRaw('lower(name) like ?', ['%'.mb_strtolower($v).'%'])->select('id'))))
            ->when($filters['status'], fn ($q, $v) => $q->where('fin_contracts.status', $v))
            ->when($filters['type'], fn ($q, $v) => $q->where('fin_contracts.type', $v))
            ->when($filters['vendor_id'], fn ($q, $v) => $q->where('fin_contracts.vendor_id', $v))
            ->when($filters['owner_id'], fn ($q, $v) => $q->where('fin_contracts.owner_id', $v))
            ->when($filters['cost_centre_id'], fn ($q, $v) => $q->where('fin_contracts.cost_centre_id', $v))
            ->when($filters['due'], fn ($q, $v) => $q->dueWithin($v))
            ->when($filters['passed'], fn ($q) => $q->passed($today))
            ->when($filters['mine'], fn ($q) => $q->where(fn ($w) => $w->where('fin_contracts.owner_id', $user->id)->orWhere('fin_contracts.technical_owner_id', $user->id)
                ->orWhere('fin_contracts.procurement_contact_id', $user->id)
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('fin_contract_team')->whereColumn('fin_contract_team.contract_id', 'fin_contracts.id')->where('fin_contract_team.user_id', $user->id))))
            ->orderBy('fin_contracts.ends_on')->orderBy('fin_contracts.id')->limit(1000)->get();

        $all = $base()->get(['id', 'status', 'ends_on', 'notice_deadline_on', 'decision', 'renewal_type', 'auto_renew', 'base_contract_value', 'term_months', 'vendor_id', 'organization_id']);
        $live = $all->whereIn('status', Contract::LIVE);
        $due = fn (int $days) => $all->filter(fn (Contract $c) => in_array($c->status, Contract::LIVE, true)
            && (($c->days_to_end >= 0 && $c->days_to_end <= $days) || ($c->days_to_notice >= 0 && $c->days_to_notice <= $days)))->count();
        $sum = fn ($items, callable $f) => $items->reduce(fn ($carry, $c) => bcadd($carry, (string) $f($c), 2), '0');

        $vendors = Vendor::query()->whereIn('id', $live->pluck('vendor_id')->filter()->unique())->pluck('name', 'id');
        $spend = $live->filter(fn ($c) => $c->vendor_id)->groupBy('vendor_id')->map(fn ($g, $vid) => [
            'vendor' => ['id' => (int) $vid, 'name' => $vendors[$vid] ?? 'Unknown'], 'contracts' => $g->count(),
            'base_contract_value' => $sum($g, fn ($c) => $c->base_contract_value), 'annualised_base_value' => $sum($g, fn ($c) => $c->annualised_base_value),
        ])->sortByDesc(fn ($r) => (float) $r['base_contract_value'])->take(10)->values();

        $ownerIds = $base()->whereNotNull('owner_id')->distinct()->pluck('owner_id');

        return $this->renderContracts('Finance/Contracts/Index', [
            'filters' => $filters,
            'contracts' => $rows->map(fn (Contract $c) => $this->contractRow($c))->values(),
            'summary' => [
                'count' => $all->count(), 'active' => $all->where('status', 'active')->count(), 'in_window' => $all->where('status', 'in_renewal_window')->count(),
                'passed' => $all->filter(fn (Contract $c) => $this->inPassed($c))->count(),
                'due_30' => $due(30), 'due_60' => $due(60), 'due_90' => $due(90), 'auto_renew' => $live->filter(fn (Contract $c) => $c->is_auto)->count(),
                'value_base_total' => $sum($live, fn ($c) => $c->base_contract_value), 'annualised_base_total' => $sum($live, fn ($c) => $c->annualised_base_value),
            ],
            'vendorSpend' => $spend,
            'options' => $this->optionsPayload(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values(),
            'owners' => User::query()->where('organization_id', $orgId)->whereIn('id', $ownerIds)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => $this->userMini($u))->values(),
            'costCentres' => $this->costCentresPayload($user),
            'baseCurrency' => $this->settings->baseCurrency($orgId),
            'can' => ['create' => Gate::forUser($user)->allows('createContract', Contract::class)],
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('createContract', Contract::class);

        return $this->renderContracts('Finance/Contracts/Create', ['contract' => null] + $this->formProps($request->user()));
    }

    public function store(StoreContractRequest $request)
    {
        Gate::authorize('createContract', Contract::class);
        $c = $this->contracts->create($request->validated(), $request->user());

        return redirect()->route('finance.contracts.show', $c)->with('success', "Contract {$c->contract_code} created.");
    }

    public function show(Request $request, Contract $contract)
    {
        $user = $request->user();
        Gate::authorize('view', $contract);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $orgId = (int) $contract->organization_id;
        $renewals = Renewal::query()->where('contract_id', $contract->id)->orderByDesc('id')->get();
        $active = $renewals->first(fn (Renewal $r) => in_array($r->status, Renewal::ACTIVE, true)) ?? $renewals->first();
        $line = $contract->budgetLine;
        $edit = Gate::forUser($user)->allows('updateContract', $contract) && ! $contract->is_terminal;

        $pick = fn (string $class, callable $label) => $class::query()->orderBy('id')->limit(500)->get()->map(fn ($m) => ['id' => $m->id, 'label' => $label($m)])->values();

        return $this->renderContracts('Finance/Contracts/Show', [
            'contract' => $this->contractFull($contract),
            'team' => $this->teamPayload($contract),
            'links' => $this->linksPayload($contract),
            'documents' => $this->documentsPayload($contract),
            'renewal' => $active ? $this->renewalSummary($active) : null,
            'renewals' => $renewals->map(fn (Renewal $r) => $this->renewalSummary($r))->values(),
            'approval' => $active && in_array($active->status, ['submitted', 'approved', 'rejected', 'executed'], true) ? $this->approvals->trail(RenewalService::PROCESS, $active, $user) : null,
            'events' => $this->eventsPayload($contract),
            'spend' => [
                'contract_value' => (string) $contract->contract_value, 'base_contract_value' => (string) $contract->base_contract_value,
                'annualised_base_value' => $contract->annualised_base_value, 'next_term_forecast' => $contract->next_term_forecast,
                'billing' => $this->contracts->billingSchedule($contract),
                'budget_line' => $line ? ['id' => $line->id, 'budget_id' => $line->budget_id, 'label' => "#{$line->line_no} {$line->description}", 'current_base_amount' => (string) $line->current_base_amount] : null,
                'actual_to_date' => null,
            ],
            'daysToEnd' => $contract->days_to_end, 'daysToNotice' => $contract->days_to_notice, 'daysToWindow' => $contract->days_to_window,
            'linkOptions' => [
                'asset' => $pick(Asset::class, fn ($a) => trim("{$a->asset_id_code} {$a->name}")),
                'ea_application' => $pick(EaApplication::class, fn ($a) => trim("{$a->code} {$a->name}")),
                'business_service' => $pick(BusinessService::class, fn ($a) => trim("{$a->code} {$a->name}")),
                'risk' => $pick(Risk::class, fn ($r) => trim("{$r->risk_id_code} {$r->title}")),
            ],
            'users' => $this->usersPayload($orgId),
            'options' => $this->optionsPayload(),
            'tab' => $tab,
            'baseCurrency' => $this->settings->baseCurrency($orgId),
            'currencies' => Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get()->map(fn ($c) => ['code' => $c->code, 'decimals' => $c->decimals, 'is_base' => $c->is_base])->values(),
            'can' => [
                'edit' => $edit, 'activate' => $edit && $contract->status === 'draft', 'recompute' => $edit && $contract->status !== 'draft',
                'terminate' => Gate::forUser($user)->allows('terminateContract', $contract) && in_array($contract->status, Contract::RENEWABLE, true),
                'manageTeam' => $edit, 'addDocument' => $edit,
                'openRenewal' => Gate::forUser($user)->allows('updateContract', $contract) && in_array($contract->status, Contract::RENEWABLE, true)
                    && ! $renewals->contains(fn (Renewal $r) => in_array($r->status, Renewal::ACTIVE, true)),
            ],
        ]);
    }

    public function edit(Request $request, Contract $contract)
    {
        Gate::authorize('updateContract', $contract);
        $contract->is_terminal && abort(403, 'Renewed, terminated and replaced contracts are read-only.');

        return $this->renderContracts('Finance/Contracts/Create', ['contract' => $this->contractFull($contract)] + $this->formProps($request->user()));
    }

    public function update(UpdateContractRequest $request, Contract $contract)
    {
        Gate::authorize('updateContract', $contract);
        $data = $request->validated();
        // Amount-blind editors see these masked, so the form posts them empty; never let that wipe the stored terms.
        $request->user()->can('view it-finance-amounts') || $data = Arr::except($data, ['price_escalation_pct', 'amount_per_billing', 'contract_value', 'currency']);
        $this->contracts->update($contract, $data, $request->user());

        return redirect()->route('finance.contracts.show', $contract)->with('success', 'Contract saved.');
    }

    public function destroy(Request $request, Contract $contract)
    {
        Gate::authorize('updateContract', $contract);
        $this->contracts->delete($contract, $request->user());

        return redirect()->route('finance.contracts.index')->with('success', 'Draft contract deleted.');
    }

    public function activate(Request $request, Contract $contract)
    {
        Gate::authorize('updateContract', $contract);
        $this->contracts->activate($contract, $request->user());

        return back()->with('success', "Contract {$contract->contract_code} activated.");
    }

    public function recompute(Request $request, Contract $contract)
    {
        Gate::authorize('updateContract', $contract);
        $contract->is_terminal && abort(403, 'Renewed, terminated and replaced contracts are read-only.');
        $this->contracts->recompute($contract, $request->user());

        return back()->with('success', 'Window, notice deadline and status recomputed.');
    }

    public function terminate(Request $request, Contract $contract)
    {
        Gate::authorize('terminateContract', $contract);
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000'], 'terminated_on' => ['nullable', 'date']]);
        $this->contracts->terminate($contract, $data['reason'], $data['terminated_on'] ?? null, $request->user());

        return back()->with('success', "Contract {$contract->contract_code} terminated.");
    }

    /** @return array<string,mixed> */
    private function formProps(User $user): array
    {
        $orgId = (int) $user->organization_id;
        $setting = $this->settings->for($orgId);
        $ids = FinancePolicy::visibleCostCentreIds($user);
        $budgets = Budget::query()->where('status', 'baseline')->when($ids !== null, fn ($q) => $q->whereIn('cost_centre_id', $ids ?: [0]))
            ->whereIn('fiscal_year_id', FiscalYear::query()->where('status', 'open')->select('id'))->get(['id', 'budget_code'])->keyBy('id');

        return [
            'options' => $this->optionsPayload(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values(),
            'users' => $this->usersPayload($orgId),
            'costCentres' => $this->costCentresPayload($user),
            'legalEntities' => LegalEntity::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name])->values(),
            'categories' => Category::query()->where('is_active', true)->orderBy('kind')->orderBy('code')->get(['id', 'code', 'name', 'kind']),
            'budgetLines' => BudgetLine::query()->whereIn('budget_id', $budgets->keys())->orderBy('budget_id')->orderBy('line_no')->get()
                ->map(fn (BudgetLine $l) => ['id' => $l->id, 'label' => "{$budgets[$l->budget_id]->budget_code} #{$l->line_no} {$l->description}", 'cost_centre_id' => $l->cost_centre_id,
                    'current_base_amount' => (string) $l->current_base_amount])->values(),
            'currencies' => Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get()->map(fn ($c) => ['code' => $c->code, 'decimals' => $c->decimals, 'is_base' => $c->is_base])->values(),
            'defaults' => [
                'renewal_lead_days' => (int) $setting->renewal_lead_days, 'notification_offsets' => $setting->renewal_notice_offsets ?: [],
                'notice_period_days' => (int) config('finance.contracts.default_notice_period_days'), 'currency' => $this->settings->baseCurrency($orgId), 'owner_id' => $user->id,
            ],
            'baseCurrency' => $this->settings->baseCurrency($orgId),
        ];
    }

    private function costCentresPayload(User $user): array
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);

        return CostCentre::query()->where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('code')->get()
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all();
    }

    private function usersPayload(int $orgId): array
    {
        return User::query()->where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'job_title'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title])->values()->all();
    }
}
