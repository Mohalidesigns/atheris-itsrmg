<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsContracts;
use App\Http\Requests\Finance\ExecuteRenewalRequest;
use App\Http\Requests\Finance\RecommendRenewalRequest;
use App\Models\Finance\Contract;
use App\Models\Finance\Currency;
use App\Models\Finance\Renewal;
use App\Models\User;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RenewalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Contract renewal decisions (FIN-06.05): list, detail and the recommend / approve / execute lifecycle. */
class RenewalController extends Controller
{
    use PresentsContracts;

    public function __construct(
        private readonly RenewalService $renewals,
        private readonly FinanceApprovals $approvals,
        private readonly FinanceSettings $settings,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Renewal::class);
        $filters = [
            'status' => in_array($request->query('status'), Renewal::STATUSES, true) ? $request->query('status') : null,
            'recommendation' => in_array($request->query('recommendation'), Renewal::DECISIONS, true) ? $request->query('recommendation') : null,
            'q' => trim((string) $request->query('q', '')) ?: null, 'mine' => $request->boolean('mine'),
        ];
        $base = fn () => Renewal::query()->whereIn('contract_id', Contract::query()->visibleTo($user)->select('fin_contracts.id'));

        $rows = $base()->with(['contract.vendor:id,name', 'contract.owner:id,name', 'requestedBy:id,name', 'approvedBy:id,name', 'executedBy:id,name', 'newContract:id,contract_code,title'])
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['recommendation'], fn ($q, $v) => $q->where('recommendation', $v))
            ->when($filters['q'], fn ($q, $v) => $q->where(fn ($w) => $w->whereRaw('lower(renewal_code) like ?', ['%'.mb_strtolower($v).'%'])
                ->orWhereIn('contract_id', Contract::withoutGlobalScopes()->where('organization_id', $user->organization_id)
                    ->where(fn ($c) => $c->whereRaw('lower(title) like ?', ['%'.mb_strtolower($v).'%'])->orWhereRaw('lower(contract_code) like ?', ['%'.mb_strtolower($v).'%']))->select('id'))))
            ->when($filters['mine'], fn ($q) => $q->where(fn ($w) => $w->where('requested_by_id', $user->id)
                ->orWhereIn('contract_id', Contract::withoutGlobalScopes()->where('organization_id', $user->organization_id)->where('owner_id', $user->id)->select('id'))))
            ->orderByDesc('id')->limit(1000)->get();

        $all = $base()->get(['id', 'status', 'executed_at']);
        $since = FinanceClock::now()->subDays(90);
        $pending = Contract::query()->visibleTo($user)->where('fin_contracts.status', 'in_renewal_window')
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('fin_contract_renewals')->whereColumn('fin_contract_renewals.contract_id', 'fin_contracts.id')
                ->whereIn('fin_contract_renewals.status', Renewal::ACTIVE)->whereNull('fin_contract_renewals.deleted_at'))
            ->with(['vendor:id,name', 'owner:id,name', 'technicalOwner:id,name', 'costCentre:id,code,name', 'openRenewal'])->orderBy('fin_contracts.ends_on')->get();
        $options = $this->optionsPayload();

        return $this->renderContracts('Finance/Renewals/Index', [
            'filters' => $filters,
            'renewals' => $rows->map(fn (Renewal $r) => $this->renewalSummary($r))->values(),
            'summary' => [
                'open' => $all->where('status', 'open')->count(), 'submitted' => $all->where('status', 'submitted')->count(), 'approved' => $all->where('status', 'approved')->count(),
                'executed_90d' => $all->filter(fn ($r) => $r->status === 'executed' && $r->executed_at && $r->executed_at->gte($since))->count(),
                'rejected' => $all->where('status', 'rejected')->count(),
            ],
            'pendingWindow' => $pending->map(fn (Contract $c) => $this->contractRow($c))->values(),
            'options' => ['statuses' => $options['renewalStatuses'], 'decisions' => $options['decisions']],
            'can' => ['edit' => $user->can('edit it-finance')],
        ]);
    }

    public function show(Request $request, Renewal $renewal)
    {
        $user = $request->user();
        Gate::authorize('view', $renewal);
        $renewal->loadMissing('contract');
        $contract = $renewal->contract;
        $orgId = (int) $renewal->organization_id;
        $options = $this->optionsPayload();

        return $this->renderContracts('Finance/Renewals/Show', [
            'renewal' => $this->renewalSummary($renewal),
            'contract' => $this->contractFull($contract),
            'approval' => in_array($renewal->status, ['submitted', 'approved', 'rejected', 'executed'], true) ? $this->approvals->trail(RenewalService::PROCESS, $renewal, $user) : null,
            'events' => $this->eventsPayload($contract, ['renewal_opened', 'renewal_submitted', 'renewal_rejected', 'renewal_withdrawn', 'decision_recorded', 'renewed', 'terminated']),
            'options' => ['decisions' => $options['decisions']],
            'currencies' => Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get()->map(fn ($c) => ['code' => $c->code, 'decimals' => $c->decimals, 'is_base' => $c->is_base])->values(),
            'baseCurrency' => $this->settings->baseCurrency($orgId),
            'can' => $this->abilities($renewal, $contract, $user),
        ]);
    }

    public function open(Request $request, Contract $contract)
    {
        Gate::authorize('updateContract', $contract);
        $r = $this->renewals->open($contract, $request->user());

        return redirect()->route('finance.renewals.show', $r)->with('success', "Renewal {$r->renewal_code} opened.");
    }

    public function recommend(RecommendRenewalRequest $request, Renewal $renewal)
    {
        Gate::authorize('updateContract', $renewal);
        $this->renewals->recommend($renewal, $request->validated(), $request->user());

        return back()->with('success', 'Recommendation saved.');
    }

    public function submit(Request $request, Renewal $renewal)
    {
        Gate::authorize('updateContract', $renewal);
        $this->renewals->submit($renewal, $request->user());

        return back()->with('success', "Renewal {$renewal->renewal_code} submitted for approval.");
    }

    public function withdraw(Request $request, Renewal $renewal)
    {
        Gate::authorize('updateContract', $renewal);
        $this->renewals->withdraw($renewal, $request->user());

        return back()->with('success', 'Renewal withdrawn.');
    }

    public function approve(Request $request, Renewal $renewal)
    {
        Gate::authorize('decideRenewal', $renewal);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return back()->with('success', $this->renewals->approve($renewal, $request->user(), $note));
    }

    public function reject(Request $request, Renewal $renewal)
    {
        Gate::authorize('decideRenewal', $renewal);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->renewals->reject($renewal, $request->user(), $note);

        return back()->with('success', 'Renewal rejected.');
    }

    public function execute(ExecuteRenewalRequest $request, Renewal $renewal)
    {
        Gate::authorize('updateContract', $renewal);
        $new = $this->renewals->execute($renewal, $request->user(), $request->validated());

        return back()->with('success', $new ? "Renewal executed: {$new->contract_code} created." : 'Renewal executed: the contract is terminated.');
    }

    /** @return array<string,bool> */
    private function abilities(Renewal $r, Contract $c, User $u): array
    {
        $edit = Gate::forUser($u)->allows('updateContract', $r);
        $party = (int) $r->requested_by_id === (int) $u->id || (int) $c->owner_id === (int) $u->id;
        $deciding = $r->status === 'submitted' && Gate::forUser($u)->allows('decideRenewal', $r) && ! $party && $this->canDecide($r, $u);

        return [
            'recommend' => $edit && $r->status === 'open',
            'submit' => $edit && $r->status === 'open' && $r->recommendation !== null && ! blank($r->justification),
            'withdraw' => $edit && in_array($r->status, ['open', 'submitted'], true)
                && ($party || (! $r->requested_by_id && (int) $r->created_by_id === (int) $u->id)),
            'approve' => $deciding, 'reject' => $deciding,
            'execute' => $edit && $r->status === 'approved',
        ];
    }

    private function canDecide(Renewal $r, User $u): bool
    {
        if (! $this->approvals->configured(RenewalService::PROCESS, (int) $r->organization_id)) {
            return true;
        }
        foreach ($this->approvals->trail(RenewalService::PROCESS, $r, $u)['request']['steps'] ?? [] as $step) {
            if (! empty($step['can_decide'])) {
                return true;
            }
        }

        return false;
    }
}
