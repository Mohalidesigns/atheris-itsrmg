<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsFinOps;
use App\Http\Requests\Finance\AnomalyActionRequest;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudAnomaly;
use App\Services\Finance\CloudAnomalyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Cloud cost anomalies and their handling (FIN-03.10). */
class CloudAnomalyController extends Controller
{
    use PresentsFinOps;

    public function __construct(private readonly CloudAnomalyService $anomalies) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', CloudAccount::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $ccIds = $this->visibleCcIds($user);
        $v = $request->validate(['status' => ['nullable', 'in:'.implode(',', CloudAnomaly::STATUSES)], 'account_id' => ['nullable', 'integer'], 'scope' => ['nullable', 'in:'.implode(',', CloudAnomaly::SCOPES)]]);

        $visible = CloudAnomaly::withoutGlobalScopes()->where('organization_id', $orgId)->when($ccIds !== null, function ($q) use ($ccIds, $user) {
            $accounts = CloudAccount::withoutGlobalScopes()->whereIn('default_cost_centre_id', $ccIds ?: [0])->orWhere('owner_id', $user->id);
            $q->whereIn('cloud_account_id', $accounts->select('id'));
        });
        $counts = array_fill_keys(CloudAnomaly::STATUSES, 0);
        foreach ((clone $visible)->selectRaw('status, COUNT(*) as n')->groupBy('status')->get() as $r) {
            $counts[$r->status] = (int) $r->n;
        }
        $page = (clone $visible)->with(['account:id,name,provider,currency', 'owner:id,name,job_title', 'acknowledgedBy:id,name,job_title', 'resolvedBy:id,name,job_title'])
            ->when(! empty($v['status']), fn ($q) => $q->where('status', $v['status']))->when(! empty($v['account_id']), fn ($q) => $q->where('cloud_account_id', (int) $v['account_id']))
            ->when(! empty($v['scope']), fn ($q) => $q->where('scope', $v['scope']))->orderByRaw("case status when 'open' then 0 when 'acknowledged' then 1 else 2 end")->orderByDesc('detected_on')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (CloudAnomaly $x) => $this->anomalyRow($x, $user))->values());
        $payload = $page->toArray();
        $payload['meta'] = ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'links' => $payload['links'] ?? []];
        $t = $this->anomalies->thresholds($orgId);

        return $this->renderFinOps('Finance/Cloud/Anomalies', [
            'filters' => ['status' => $v['status'] ?? '', 'account_id' => $v['account_id'] ?? '', 'scope' => $v['scope'] ?? ''], 'counts' => $counts, 'anomalies' => $payload,
            'thresholds' => ['pct' => $t['pct'], 'abs_floor' => $t['abs_floor'], 'baseline_days' => $t['baseline_days'], 'lookback_days' => $t['lookback_days']],
            'accounts' => CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'provider'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'provider' => $a->provider])->values(),
            'options' => ['statuses' => $this->opts(['open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', 'false_positive' => 'False positive']),
                'scopes' => $this->opts(['account' => 'Account total', 'service' => 'Service', 'application' => 'Application'])],
        ]);
    }

    public function acknowledge(AnomalyActionRequest $request, CloudAnomaly $cloudAnomaly)
    {
        Gate::authorize('handleAnomaly', $cloudAnomaly);
        $this->anomalies->acknowledge($cloudAnomaly, $request->validated('note'), $request->user());

        return back()->with('success', 'Anomaly acknowledged.');
    }

    public function resolve(AnomalyActionRequest $request, CloudAnomaly $cloudAnomaly)
    {
        Gate::authorize('handleAnomaly', $cloudAnomaly);
        $this->anomalies->resolve($cloudAnomaly, (string) $request->validated('note'), $request->user());

        return back()->with('success', 'Anomaly resolved.');
    }

    public function dismiss(AnomalyActionRequest $request, CloudAnomaly $cloudAnomaly)
    {
        Gate::authorize('handleAnomaly', $cloudAnomaly);
        $this->anomalies->dismiss($cloudAnomaly, (string) $request->validated('note'), $request->user());

        return back()->with('success', 'Anomaly dismissed as a false positive.');
    }
}
