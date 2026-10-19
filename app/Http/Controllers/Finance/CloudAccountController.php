<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsFinOps;
use App\Http\Requests\Finance\CloudAccountRequest;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudTagRule;
use App\Models\Finance\ImportBatch;
use App\Services\Finance\CloudCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Cloud accounts, their tag rules and recent imports (FIN-03.09). */
class CloudAccountController extends Controller
{
    use PresentsFinOps;

    public function __construct(private readonly CloudCostService $cloud) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', CloudAccount::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $ccIds = $this->visibleCcIds($user);

        $accounts = CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->with(['owner:id,name,job_title', 'defaultCostCentre:id,code,name', 'contract:id,contract_code,title'])
            ->when($ccIds !== null, fn ($q) => $q->where(fn ($b) => $b->whereIn('default_cost_centre_id', $ccIds ?: [0])->orWhere('owner_id', $user->id)))->orderBy('name')->get();
        $stats = $this->accountStats($orgId, $accounts->pluck('id')->all());
        $lastBatch = ImportBatch::withoutGlobalScopes()->where('organization_id', $orgId)->where('type', 'cloud')->whereIn('cloud_account_id', $accounts->pluck('id')->all() ?: [0])
            ->orderByDesc('id')->get()->unique('cloud_account_id')->keyBy('cloud_account_id');
        $rules = CloudTagRule::withoutGlobalScopes()->where('organization_id', $orgId)->with('account:id,name')
            ->when($ccIds !== null, fn ($q) => $q->where(fn ($b) => $b->whereNull('cloud_account_id')->orWhereIn('cloud_account_id', $accounts->pluck('id')->all() ?: [0])))->orderBy('tag_key')->orderBy('tag_value')->limit(500)->get();
        $targets = $this->ruleTargets($orgId, $rules);
        $batches = ImportBatch::withoutGlobalScopes()->where('organization_id', $orgId)->where('type', 'cloud')->whereIn('cloud_account_id', $accounts->pluck('id')->all() ?: [0])
            ->with(['importedBy:id,name', 'cloudAccount:id,name'])->orderByDesc('id')->limit(20)->get();

        return $this->renderFinOps('Finance/Cloud/Accounts', [
            'accounts' => $accounts->map(fn (CloudAccount $a) => $this->accountRow($a, $stats[$a->id] ?? [], $lastBatch[$a->id] ?? null, $user))->values(),
            'tagRules' => $rules->map(fn (CloudTagRule $r) => $this->ruleRow($r, $targets, $user))->values(),
            'batches' => $batches->map(fn (ImportBatch $b) => [
                'id' => $b->id, 'file_name' => $b->file_name, 'account' => $b->cloudAccount?->name, 'format' => $b->format, 'row_count' => $b->row_count, 'accepted_count' => $b->accepted_count,
                'rejected_count' => $b->rejected_count, 'status' => $b->status, 'imported_by' => $b->importedBy?->name, 'created_at' => $b->created_at?->toIso8601String(), 'href' => route('finance.imports.show', $b->id),
            ])->values(),
            'options' => $this->optionsPayload(), 'costCentres' => $this->finCostCentres($user, true), 'applications' => $this->finApplications($orgId), 'businessServices' => $this->finBusinessServices($orgId),
            'contracts' => $this->finContracts($orgId), 'users' => $this->finUsers($orgId),
            'can' => ['create' => Gate::forUser($user)->allows('manageCloud', CloudAccount::class), 'import' => Gate::forUser($user)->allows('manageCloud', CloudAccount::class),
                'manageRules' => Gate::forUser($user)->allows('manageCloud', CloudAccount::class)],
        ]);
    }

    public function store(CloudAccountRequest $request)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        $a = $this->cloud->storeAccount($request->validated(), $request->user());

        return back()->with('success', "Cloud account {$a->name} added.");
    }

    public function update(CloudAccountRequest $request, CloudAccount $cloudAccount)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        Gate::authorize('view', $cloudAccount);
        $this->cloud->updateAccount($cloudAccount, $request->validated(), $request->user());

        return back()->with('success', 'Cloud account saved.');
    }

    public function destroy(Request $request, CloudAccount $cloudAccount)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        Gate::authorize('view', $cloudAccount);
        $this->cloud->destroyAccount($cloudAccount, $request->user());

        return back()->with('success', 'Cloud account deleted.');
    }

    public function reallocate(Request $request, CloudAccount $cloudAccount)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        Gate::authorize('view', $cloudAccount);
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $n = $this->cloud->allocateByTags($cloudAccount, $d['from'] ?? null, $d['to'] ?? null, $request->user());

        return back()->with('success', $n === 1 ? '1 cloud line re-attributed.' : "{$n} cloud lines re-attributed (open periods only).");
    }
}
