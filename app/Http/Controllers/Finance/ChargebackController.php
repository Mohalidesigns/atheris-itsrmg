<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ChargebackRequest;
use App\Models\Finance\ChargebackRun;
use App\Services\Finance\ChargebackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Export, re-download and void of the chargeback journal of a closed period (plan P6-10). */
class ChargebackController extends Controller
{
    public function __construct(private readonly ChargebackService $chargeback) {}

    public function store(ChargebackRequest $request)
    {
        Gate::authorize('runChargeback', ChargebackRun::class);
        $run = $this->chargeback->export((int) $request->user()->organization_id, (int) $request->validated('period_id'), $request->validated('target_type'), $request->user());

        return $this->file($run);
    }

    public function download(Request $request, ChargebackRun $chargebackRun)
    {
        Gate::authorize('runChargeback', ChargebackRun::class);

        return $this->file($chargebackRun);
    }

    public function void(Request $request, ChargebackRun $chargebackRun)
    {
        Gate::authorize('voidChargeback', $chargebackRun);
        $d = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->chargeback->void($chargebackRun, $d['reason'], $request->user());

        return back()->with('success', "Chargeback run {$chargebackRun->run_code} voided; the period can be exported again.");
    }

    private function file(ChargebackRun $run)
    {
        $run->loadMissing('period:id,name');

        return response($run->content, 200, [
            'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$run->file_name.'"', 'X-Content-SHA256' => $run->file_sha256,
        ]);
    }
}
