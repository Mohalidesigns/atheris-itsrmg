<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BenefitFormRequest;
use App\Http\Requests\Finance\BenefitReadingRequest;
use App\Models\Finance\Benefit;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\FinanceDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Benefits register and readings (FIN-05.06). */
class BenefitController extends Controller
{
    public function __construct(private readonly BenefitsService $benefits, private readonly FinanceDocuments $documents) {}

    public function store(BenefitFormRequest $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $b = $this->benefits->store($investment, $request->validated(), $request->user());

        return redirect()->route('finance.investments.show', ['investment' => $investment->id, 'tab' => 'benefits'])->with('success', "Benefit {$b->benefit_code} added.");
    }

    public function update(BenefitFormRequest $request, Benefit $benefit)
    {
        Gate::authorize('updateInvestment', $benefit);
        $this->benefits->update($benefit, $request->validated(), $request->user());

        return back()->with('success', 'Benefit updated.');
    }

    public function cancel(Request $request, Benefit $benefit)
    {
        Gate::authorize('updateInvestment', $benefit);
        $this->benefits->cancel($benefit, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $request->user());

        return back()->with('success', 'Benefit cancelled.');
    }

    public function storeReading(BenefitReadingRequest $request, Benefit $benefit)
    {
        Gate::authorize('recordBenefit', $benefit);
        $d = $request->validated();
        $user = $request->user();
        $period = Period::withoutGlobalScopes()->where('organization_id', $user->organization_id)->findOrFail($d['period_id']);
        DB::transaction(function () use ($request, $benefit, $d, $user, $period) {
            $doc = $request->hasFile('file')
                ? $this->documents->attach($benefit, ['kind' => 'benefit_evidence', 'title' => $d['document_title'] ?? ($benefit->benefit_code.' evidence')], $request->file('file'), $user)
                : null;
            $this->benefits->record($benefit, $period, $d['value'], $doc, $d['note'] ?? null, $user);
        });

        return back()->with('success', 'Reading recorded.');
    }
}
