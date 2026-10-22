<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ValueReviewRequest;
use App\Models\Finance\Investment;
use App\Models\Finance\ValueReview;
use App\Services\Finance\ValueReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Post-implementation value reviews (FIN-05.07). */
class ValueReviewController extends Controller
{
    public function __construct(private readonly ValueReviewService $reviews) {}

    public function store(ValueReviewRequest $request, Investment $investment)
    {
        Gate::authorize('updateInvestment', $investment);
        $d = $request->validated();
        $this->reviews->adHoc($investment, $d['due_on'], isset($d['reviewer_id']) ? (int) $d['reviewer_id'] : null, $request->user());

        return back()->with('success', 'Value review added.');
    }

    public function update(ValueReviewRequest $request, ValueReview $valueReview)
    {
        Gate::authorize('updateInvestment', $valueReview);
        $this->reviews->update($valueReview, $request->validated(), $request->user());

        return back()->with('success', 'Value review updated.');
    }

    public function complete(Request $request, ValueReview $valueReview)
    {
        Gate::authorize('completeReview', $valueReview);
        $d = $request->validate(['summary' => ['required', 'string', 'max:10000'], 'lessons' => ['nullable', 'string', 'max:10000']]);
        $this->reviews->complete($valueReview, $d, $request->user());

        return back()->with('success', 'Value review completed.');
    }

    public function show(ValueReview $valueReview)
    {
        Gate::authorize('view', $valueReview);

        return redirect()->route('finance.investments.show', ['investment' => $valueReview->investment_id, 'tab' => 'reviews']);
    }
}
