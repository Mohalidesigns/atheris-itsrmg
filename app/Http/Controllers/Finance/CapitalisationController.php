<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsCapitalisations;
use App\Http\Requests\Finance\CapitalisationRequest;
use App\Models\Asset;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/** Capitalisation of investment and capex spend (FIN-03.13). */
class CapitalisationController extends Controller
{
    use PresentsCapitalisations;

    public function __construct(private readonly CapitalisationService $capitalisations, private readonly FinanceApprovals $approvals) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Capitalisation::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $ccIds = FinancePolicy::visibleCostCentreIds($user);
        $v = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Capitalisation::STATUSES)], 'source_type' => ['nullable', 'in:'.implode(',', Capitalisation::SOURCE_TYPES)],
            'search' => ['nullable', 'string', 'max:100'], 'source' => ['nullable', 'in:'.implode(',', Capitalisation::SOURCE_TYPES)], 'id' => ['nullable', 'integer'],
        ]);

        $scope = fn () => Capitalisation::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
            ->when($ccIds !== null, function ($q) use ($orgId, $user) { // a scoped viewer: resolve the visible ids once (the policy mixes cost centre, investment and ownership rules)
                $ids = Capitalisation::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->get()
                    ->filter(fn (Capitalisation $c) => Gate::forUser($user)->allows('view', $c))->pluck('id')->all();
                $q->whereIn('id', $ids ?: [0]);
            });
        $needle = ! empty($v['search']) ? mb_strtolower($v['search']) : null;
        $page = $scope()->with(['lines', 'asset:id,asset_id_code,name', 'investment:id,investment_code,title', 'expenseRequest:id,request_code,title'])
            ->when(! empty($v['status']), fn ($q) => $q->where('status', $v['status']))->when(! empty($v['source_type']), fn ($q) => $q->where('source_type', $v['source_type']))
            ->when($needle, fn ($q) => $q->where(fn ($b) => $b->whereRaw('lower(capitalisation_code) like ?', ["%{$needle}%"])->orWhereRaw('lower(title) like ?', ["%{$needle}%"])))
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (Capitalisation $c) => $this->capRow($c, $user))->values());
        $payload = $page->toArray();
        $payload['meta'] = ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'links' => $payload['links'] ?? []];
        $statusCounts = $scope()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        $today = FinanceClock::today();
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_current', true)->first();
        $done = $scope()->where('status', 'capitalised')->with('lines')->get();
        $thisFy = '0.00';
        foreach ($done as $c) {
            foreach ($c->lines as $l) {
                $fy && $l->month->toDateString() >= $fy->starts_on->toDateString() && $l->month->toDateString() <= $fy->ends_on->toDateString() && $thisFy = bcadd($thisFy, (string) $l->amount, 2);
            }
        }
        $canPrepare = $user->can('edit it-finance') && $user->can('view it-finance-amounts');

        return $this->renderCapitalisations('Finance/Capitalisations/Index', [
            'filters' => ['status' => $v['status'] ?? '', 'source_type' => $v['source_type'] ?? '', 'search' => $v['search'] ?? '', 'source' => $v['source'] ?? '', 'id' => $v['id'] ?? ''],
            'capitalisations' => $payload,
            'summary' => [
                'by_status' => array_merge(array_fill_keys(Capitalisation::STATUSES, 0), $statusCounts),
                'capitalised_total' => $done->reduce(fn ($c, $x) => bcadd($c, (string) $x->amount, 2), '0.00'),
                'nbv_total' => $done->reduce(fn ($c, $x) => bcadd($c, $x->nbvOn($today), 2), '0.00'), 'amortisation_this_fy' => $thisFy,
            ],
            'candidates' => $canPrepare ? $this->capitalisations->candidates($orgId, $ccIds, $user) : [],
            'assets' => Asset::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('asset_id_code')->limit(500)->get(['id', 'asset_id_code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->asset_id_code, 'name' => $a->name])->values(),
            'users' => $this->userList($orgId),
            'options' => $this->options(),
            'defaults' => ['life_months' => (array) config('finance.capitalisation.default_life_months')],
            'can' => ['create' => $canPrepare],
        ]);
    }

    public function show(Request $request, Capitalisation $capitalisation)
    {
        Gate::authorize('view', $capitalisation);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $c = $capitalisation->load(['lines', 'asset:id,asset_id_code,name', 'assetOwner:id,name,job_title', 'costCentre:id,code,name', 'bookingPeriod:id,name', 'requestedBy:id,name,job_title', 'approvedBy:id,name,job_title']);
        $schedule = $this->capitalisations->scheduleFor($c);
        $byYear = [];
        foreach ($schedule as $s) {
            $y = substr($s['month'], 0, 4);
            $byYear[$y] = bcadd($byYear[$y] ?? '0.00', $s['amount'], 2);
        }
        $sourceId = (int) ($c->investment_id ?: $c->expense_request_id);
        $e = $this->capitalisations->eligibility($c->source_type, $sourceId, $c->available_for_use_on->toDateString(), $orgId, (int) $c->id);

        $row = $this->capRow($c, $user) + [
            'excluded_amount' => (string) $c->excluded_amount, 'excluded_reason' => $c->excluded_reason, 'residual_value' => (string) $c->residual_value,
            'declining_rate_pct' => $c->declining_rate_pct === null ? null : (string) $c->declining_rate_pct, 'eligible_amount' => (string) $c->eligible_amount,
            'booking_period' => $c->bookingPeriod ? ['id' => $c->bookingPeriod->id, 'name' => $c->bookingPeriod->name] : null, 'create_asset' => (bool) $c->create_asset,
            'asset_name' => $c->asset_name, 'asset_type' => $c->asset_type, 'asset_owner' => $this->mini($c->assetOwner),
            'cost_centre' => $c->costCentre ? ['id' => $c->costCentre->id, 'code' => $c->costCentre->code, 'name' => $c->costCentre->name] : null,
            'criteria_checklist' => array_map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'] ?? $i['key'], 'met' => (bool) ($i['met'] ?? false), 'note' => $i['note'] ?? null, 'document_id' => $i['document_id'] ?? null], (array) $c->criteria_checklist),
            'requested_by' => $this->mini($c->requestedBy), 'requested_at' => $c->requested_at?->toIso8601String(), 'approved_by' => $this->mini($c->approvedBy), 'approved_at' => $c->approved_at?->toIso8601String(),
            'rejection_reason' => $c->rejection_reason, 'cancel_reason' => $c->cancel_reason, 'amount_band_label' => $c->amount_band_label,
        ];

        return $this->renderCapitalisations('Finance/Capitalisations/Show', [
            'capitalisation' => $row, 'schedule' => $schedule, 'scheduleByYear' => collect($byYear)->map(fn ($amt, $y) => ['year' => (int) $y, 'amount' => $amt])->values(),
            'eligibility' => ['pool' => $e['pool'], 'already' => $e['already'], 'remaining' => $e['remaining'], 'actuals' => array_map(fn ($a) => $a + ['href' => null], $e['actuals'])],
            'trail' => $c->approval_request_id || $c->status !== 'draft' ? $this->approvals->trail(CapitalisationService::PROCESS, $c, $user) : null,
            'documents' => FinanceDocument::query()->where('documentable_type', Capitalisation::class)->where('documentable_id', $c->id)->with(['evidence', 'creator:id,name,job_title'])->orderByDesc('id')->get()
                ->map(fn (FinanceDocument $d) => ['id' => $d->id, 'kind' => $d->kind, 'title' => $d->title, 'url' => $d->evidence?->file_path ? route('finance.documents.download', $d) : null,
                    'uploaded_by' => $this->mini($d->creator), 'uploaded_at' => $d->created_at?->toIso8601String()])->values(),
            'activity' => Activity::query()->where('subject_type', Capitalisation::class)->where('subject_id', $c->id)->with('causer:id,name')->orderByDesc('id')->limit(100)->get()
                ->map(fn ($a) => ['id' => $a->id, 'description' => $a->description, 'causer' => $a->causer ? ['id' => $a->causer->id, 'name' => $a->causer->name] : null, 'at' => $a->created_at?->toIso8601String(),
                    'changes' => $a->event === 'updated' && $user->can('view it-finance-amounts') ? ($a->properties['attributes'] ?? null) : null])->values(),
            'assets' => Asset::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('asset_id_code')->limit(500)->get(['id', 'asset_id_code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->asset_id_code, 'name' => $a->name])->values(),
            'users' => $this->userList($orgId), 'options' => $this->options(),
            'can' => $this->capCan($c, $user),
        ]);
    }

    public function store(CapitalisationRequest $request)
    {
        $d = $request->validated();
        abort_unless(in_array($d['source_type'] ?? null, Capitalisation::SOURCE_TYPES, true), 422);
        $source = $d['source_type'] === 'investment' ? Investment::query()->findOrFail((int) $d['source_id']) : ExpenseRequest::query()->findOrFail((int) $d['source_id']);
        Gate::authorize('capitalise', $source);
        $c = $this->capitalisations->store($d, $request->user());

        return redirect()->route('finance.capitalisations.show', $c)->with('success', "Capitalisation {$c->capitalisation_code} saved as a draft.");
    }

    public function update(CapitalisationRequest $request, Capitalisation $capitalisation)
    {
        Gate::authorize('updateCapitalisation', $capitalisation);
        $this->capitalisations->update($capitalisation, $request->validated(), $request->user());

        return back()->with('success', 'Capitalisation saved.');
    }

    public function submit(Request $request, Capitalisation $capitalisation)
    {
        Gate::authorize('updateCapitalisation', $capitalisation);
        $this->capitalisations->submit($capitalisation, $request->user());

        return back()->with('success', "Capitalisation {$capitalisation->capitalisation_code} sent for approval.");
    }

    public function withdraw(Request $request, Capitalisation $capitalisation)
    {
        Gate::authorize('updateCapitalisation', $capitalisation);
        $this->capitalisations->withdraw($capitalisation, $request->user());

        return back()->with('success', 'Capitalisation withdrawn; it is a draft again.');
    }

    public function cancel(Request $request, Capitalisation $capitalisation)
    {
        Gate::authorize('updateCapitalisation', $capitalisation);
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $this->capitalisations->cancel($capitalisation, $d['reason'], $request->user());

        return back()->with('success', 'Capitalisation cancelled.');
    }

    public function approve(Request $request, Capitalisation $capitalisation)
    {
        Gate::authorize('decideCapitalisation', $capitalisation);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return back()->with('success', $this->capitalisations->approve($capitalisation, $request->user(), $note));
    }

    public function reject(Request $request, Capitalisation $capitalisation)
    {
        Gate::authorize('decideCapitalisation', $capitalisation);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->capitalisations->reject($capitalisation, $request->user(), $note);

        return back()->with('success', 'Capitalisation rejected.');
    }

    /** @return array{id:int,name:string,job_title:?string}|null */
    private function mini(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title] : null;
    }

    /** @return list<array<string,mixed>> */
    private function userList(int $orgId): array
    {
        return User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'job_title'])->map(fn ($u) => $this->mini($u))->values()->all();
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        $opts = fn (array $labels) => collect($labels)->map(fn ($l, $v) => ['value' => (string) $v, 'label' => $l])->values()->all();

        return [
            'standards' => $opts((array) config('finance.capitalisation.standards')), 'methods' => $opts(['straight_line' => 'Straight line', 'declining' => 'Declining balance']),
            'statuses' => $opts(['draft' => 'Draft', 'submitted' => 'Awaiting approval', 'capitalised' => 'Capitalised', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']),
            'assetTypes' => $opts(['software' => 'Software', 'hardware' => 'Hardware', 'cloud_service' => 'Cloud service', 'network' => 'Network', 'facility' => 'Facility']),
            'checklists' => collect((array) config('finance.capitalisation.checklists'))->map(fn ($rows) => array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1]], $rows))->all(),
        ];
    }
}
