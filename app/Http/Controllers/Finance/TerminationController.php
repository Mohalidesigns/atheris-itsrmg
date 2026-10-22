<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Termination;
use App\Services\Finance\TerminationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Early-termination requests (plan H2): open the contract page on the request, decide it or withdraw it. */
class TerminationController extends Controller
{
    public function __construct(private readonly TerminationService $terminations) {}

    /** Approval notifications link here; the contract page carries the request, its trail and the decide buttons. */
    public function show(Request $request, Termination $termination)
    {
        Gate::authorize('view', $termination);

        return redirect()->route('finance.contracts.show', ['contract' => $termination->contract_id, 'tab' => 'renewal']);
    }

    public function approve(Request $request, Termination $termination)
    {
        Gate::authorize('decideTermination', $termination);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return back()->with('success', $this->terminations->approve($termination, $request->user(), $note));
    }

    public function reject(Request $request, Termination $termination)
    {
        Gate::authorize('decideTermination', $termination);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->terminations->reject($termination, $request->user(), $note);

        return back()->with('success', 'Termination request rejected.');
    }

    public function withdraw(Request $request, Termination $termination)
    {
        Gate::authorize('decideTermination', $termination);
        $this->terminations->withdraw($termination, $request->user());

        return back()->with('success', 'Termination request withdrawn.');
    }
}
