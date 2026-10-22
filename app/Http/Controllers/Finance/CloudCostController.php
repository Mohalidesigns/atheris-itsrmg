<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsFinOps;
use App\Http\Requests\Finance\UntaggedAssignRequest;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\Period;
use App\Services\Finance\CloudCostService;
use App\Services\Finance\FinanceClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The Costs page: FinOps spend by service, account, cost centre and application, the untagged queue, and the ledger reconciliation. */
class CloudCostController extends Controller
{
    use PresentsFinOps;

    private const GROUP_BY = ['service' => 'Service', 'account' => 'Account', 'cost_centre' => 'Cost centre', 'ea_application' => 'EA application', 'business_service' => 'Business service', 'region' => 'Region', 'day' => 'Day'];

    public function __construct(private readonly CloudCostService $cloud) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', CloudAccount::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $ccIds = $this->visibleCcIds($user);
        $v = $request->validate([
            'tab' => ['nullable', 'in:costs,untagged'], 'account_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'group_by' => ['nullable', 'in:'.implode(',', array_keys(self::GROUP_BY))], 'tagged' => ['nullable', 'in:all,tagged,untagged'], 'service' => ['nullable', 'string', 'max:160'], 'search' => ['nullable', 'string', 'max:100'],
        ]);
        $today = FinanceClock::today();
        $filters = [
            'account_id' => ! empty($v['account_id']) ? (int) $v['account_id'] : null, 'from' => $v['from'] ?? $today->subDays(29)->toDateString(), 'to' => $v['to'] ?? $today->toDateString(),
            'group_by' => $v['group_by'] ?? 'service', 'tagged' => $v['tagged'] ?? 'all', 'service' => $v['service'] ?? null, 'search' => $v['search'] ?? null,
        ];
        $tab = $v['tab'] ?? 'costs';
        $scoped = $filters + ['owned_accounts' => $ccIds === null ? [] : $this->ownedAccountIds($user)];

        $s = $this->cloud->summary($orgId, $scoped, $ccIds);
        $untagged = $tab === 'untagged' ? $this->cloud->untaggedQueue($orgId, $scoped, $ccIds) : [];
        $period = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false)->whereDate('starts_on', '<=', $filters['to'])->orderByDesc('starts_on')->first();

        $accounts = CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
            ->when($ccIds !== null, fn ($q) => $q->where(fn ($b) => $b->whereIn('default_cost_centre_id', $ccIds ?: [0])->orWhere('owner_id', $user->id)))->orderBy('name')->get();
        $tagKeys = [];
        foreach ($accounts as $a) {
            $tagKeys[$a->id] = array_keys((array) $a->tag_mapping);
        }
        foreach ($untagged as $row) {
            $tagKeys[$row['cloud_account_id']] = array_values(array_unique(array_merge($tagKeys[$row['cloud_account_id']] ?? [], array_keys((array) $row['tags']))));
        }
        $canAssign = Gate::forUser($user)->allows('manageCloud', CloudAccount::class);

        return $this->renderFinOps('Finance/Cloud/Costs', [
            'tab' => $tab, 'filters' => $filters, 'summary' => $s['summary'], 'series' => $s['series'], 'groups' => $s['groups'], 'untagged' => $untagged,
            'ledger' => $period ? $this->cloud->ledgerReconciliation($orgId, (int) $period->id, $ccIds) : ['period' => null, 'finops_total' => '0.00', 'cloud_ledger_actual' => '0.00', 'gap' => '0.00', 'gap_pct' => null],
            'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'provider' => $a->provider])->values(), 'tagKeys' => (object) $tagKeys,
            'options' => ['dimensions' => $this->opts((array) config('finance.cloud.dimensions')), 'groupBy' => $this->opts(self::GROUP_BY)],
            'applications' => $this->finApplications($orgId), 'businessServices' => $this->finBusinessServices($orgId), 'costCentres' => $this->finCostCentres($user, true),
            'can' => ['assign' => $canAssign, 'manageRules' => $canAssign],
        ]);
    }

    public function assignUntagged(UntaggedAssignRequest $request)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        $d = $request->validated();
        $account = CloudAccount::query()->findOrFail((int) $d['cloud_account_id']);
        Gate::authorize('view', $account);
        $n = $this->cloud->assignUntagged($account, (string) ($d['resource_id'] ?? ''), $d['service'] ?? null, $d['dimension'], (int) $d['target_id'], (bool) ($d['create_rule'] ?? false), $d['tag_key'] ?? null, $request->user());

        return back()->with('success', $n === 0 ? 'No open-period lines matched that resource.' : "{$n} cloud lines assigned.");
    }
}
