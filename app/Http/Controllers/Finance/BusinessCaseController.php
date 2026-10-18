<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\AdvanceGateRequest;
use App\Http\Requests\Finance\BusinessCaseFormRequest;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Services\Finance\BusinessCaseService;
use App\Services\Finance\StageGateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Business-case versions and the live evaluation endpoint (FIN-05.02). Approval rides the stage gate (plan C129). */
class BusinessCaseController extends Controller
{
    public function __construct(private readonly BusinessCaseService $cases, private readonly StageGateService $gates) {}

    public function store(Request $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $bc = $this->cases->createVersion($investment, $request->user());

        return redirect()->route('finance.investments.show', ['investment' => $investment->id, 'tab' => 'business-case', 'version' => $bc->version_no])->with('success', "Business case v{$bc->version_no} started.");
    }

    public function update(BusinessCaseFormRequest $request, BusinessCase $businessCase)
    {
        Gate::authorize('updateInvestment', $businessCase);
        $this->cases->update($businessCase, $request->validated(), $request->user());

        return back()->with('success', 'Business case saved.');
    }

    public function submit(AdvanceGateRequest $request, BusinessCase $businessCase)
    {
        Gate::authorize('updateInvestment', $businessCase);
        $i = Investment::findOrFail($businessCase->investment_id);
        $d = $request->validated();
        $this->gates->advance($i, 'approved', $d['ticked'] ?? [], $d['payload'] ?? [], $d['note'] ?? null, $request->user());

        return redirect()->route('finance.investments.show', ['investment' => $i->id, 'tab' => 'gates'])->with('success', 'Business case sent for approval.');
    }

    public function evaluate(BusinessCaseFormRequest $request)
    {
        abort_unless($request->user()->can('view it-finance-amounts'), 403);

        return response()->json($this->cases->evaluate($request->validated(), (int) $request->user()->organization_id));
    }

    public function show(BusinessCase $businessCase)
    {
        Gate::authorize('view', $businessCase);

        return redirect()->route('finance.investments.show', ['investment' => $businessCase->investment_id, 'tab' => 'business-case', 'version' => $businessCase->version_no]);
    }
}
