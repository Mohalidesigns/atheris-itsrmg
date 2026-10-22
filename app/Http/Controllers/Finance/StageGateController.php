<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\StageGate;
use App\Services\Finance\StageGateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Deciding and withdrawing gate requests (FIN-05.04). */
class StageGateController extends Controller
{
    public function __construct(private readonly StageGateService $gates) {}

    public function show(StageGate $stageGate)
    {
        Gate::authorize('view', $stageGate);

        return redirect()->route('finance.investments.show', ['investment' => $stageGate->investment_id, 'tab' => 'gates']);
    }

    public function approve(Request $request, StageGate $stageGate)
    {
        Gate::authorize('decideGate', $stageGate);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;
        $message = $this->gates->approve($stageGate, $request->user(), $note);

        return back()->with('success', $message);
    }

    public function reject(Request $request, StageGate $stageGate)
    {
        Gate::authorize('decideGate', $stageGate);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->gates->reject($stageGate, $request->user(), $note);

        return back()->with('success', 'Rejected.');
    }

    public function withdraw(Request $request, StageGate $stageGate)
    {
        Gate::authorize('updateInvestment', $stageGate);
        $this->gates->withdraw($stageGate, $request->user());

        return back()->with('success', 'Request withdrawn.');
    }
}
