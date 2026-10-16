<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\DoaBandVersion;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** DoA band proposals with maker-checker (ATH-FIN-003 S7/D5). */
class DoaBandController extends Controller
{
    public function __construct(private readonly DoaBandService $bands) {}

    private function done(string $message)
    {
        return redirect()->route('finance.settings', ['tab' => 'doa'])->with('success', $message);
    }

    public function store(Request $request)
    {
        Gate::authorize('proposeBands', DoaBandVersion::class);
        $data = $request->validate([
            'bands' => ['required', 'array', 'min:1', 'max:8'], 'bands.*.label' => ['required', 'string', 'max:40'],
            'bands.*.min' => ['required', 'regex:'.FinanceSettings::DOA_AMOUNT], 'bands.*.max' => ['nullable', 'regex:'.FinanceSettings::DOA_AMOUNT],
            'effective_from' => ['required', 'date', 'after_or_equal:today'], 'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $v = $this->bands->propose((int) $request->user()->organization_id, $data['bands'], $data['effective_from'], $data['reason'] ?? null, $request->user());

        return $this->done("Bands version {$v->version_no} proposed; a second person must approve it.");
    }

    public function approve(Request $request, DoaBandVersion $doaBandVersion)
    {
        Gate::authorize('checkBands', $doaBandVersion);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;
        $v = $this->bands->approve($doaBandVersion, $request->user(), $note);

        return $this->done($v->status === 'active' ? "Bands version {$v->version_no} approved and active." : "Bands version {$v->version_no} approved; it takes effect on {$v->effective_from->toDateString()}.");
    }

    public function reject(Request $request, DoaBandVersion $doaBandVersion)
    {
        Gate::authorize('checkBands', $doaBandVersion);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->bands->reject($doaBandVersion, $request->user(), $note);

        return $this->done('Bands rejected.');
    }

    public function withdraw(Request $request, DoaBandVersion $doaBandVersion)
    {
        Gate::authorize('proposeBands', $doaBandVersion);
        $this->bands->withdraw($doaBandVersion, $request->user());

        return $this->done('Proposal withdrawn.');
    }
}
