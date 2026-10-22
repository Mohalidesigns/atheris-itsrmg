<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\InvestmentChangeRequest;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentChange;
use App\Services\Finance\InvestmentChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Change control on approved investments (FIN-05.11). */
class InvestmentChangeController extends Controller
{
    public function __construct(private readonly InvestmentChangeService $changes) {}

    public function store(InvestmentChangeRequest $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $c = $this->changes->request($investment, $request->validated(), $request->user());

        return redirect()->route('finance.investments.show', ['investment' => $investment->id, 'tab' => 'changes'])
            ->with('success', $c->kind === 'reassign' ? "Reassignment {$c->change_code} awaits approval." : ($c->requires_approval ? "Change {$c->change_code} is beyond tolerance and awaits approval." : "Change {$c->change_code} applied within tolerance."));
    }

    public function withdraw(Request $request, InvestmentChange $investmentChange)
    {
        Gate::authorize('updateInvestment', $investmentChange);
        $this->changes->withdraw($investmentChange, $request->user());

        return back()->with('success', 'Change withdrawn.');
    }
}
