<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CloudTagRuleRequest;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudTagRule;
use App\Services\Finance\CloudCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Tag value to cost centre / application / service rules (plan P6-3). */
class CloudTagRuleController extends Controller
{
    public function __construct(private readonly CloudCostService $cloud) {}

    public function store(CloudTagRuleRequest $request)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        $this->cloud->storeRule($request->validated(), $request->user());

        return back()->with('success', 'Tag rule added. Re-attribute the account to apply it to open periods.');
    }

    public function update(CloudTagRuleRequest $request, CloudTagRule $cloudTagRule)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        $this->cloud->updateRule($cloudTagRule, $request->validated(), $request->user());

        return back()->with('success', 'Tag rule saved.');
    }

    public function destroy(Request $request, CloudTagRule $cloudTagRule)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        $this->cloud->destroyRule($cloudTagRule, $request->user());

        return back()->with('success', 'Tag rule deleted.');
    }
}
