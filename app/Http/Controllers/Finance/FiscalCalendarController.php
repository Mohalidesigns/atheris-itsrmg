<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodCloseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FiscalCalendarController extends Controller
{
    public function __construct(private readonly FiscalCalendarService $calendar, private readonly PeriodCloseService $closes) {}

    private function done(string $message)
    {
        return redirect()->route('finance.settings', ['tab' => 'calendar'])->with('success', $message);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', FiscalYear::class);
        $orgId = $request->user()->organization_id;
        $data = $request->validate([
            'year_label' => ['required', 'regex:/^[A-Za-z0-9 \/-]{2,16}$/', Rule::unique('fin_fiscal_years', 'year_label')->where('organization_id', $orgId)],
            'starts_on' => ['required', 'date'],
            'period_scheme' => ['required', Rule::in(FiscalYear::PERIOD_SCHEMES)],
            'status' => ['required', Rule::in(['planning', 'open'])],
            'is_current' => ['sometimes', 'boolean'],
        ]);
        $this->calendar->createYear($orgId, $data, $request->user());

        return $this->done('Fiscal year created.');
    }

    public function update(Request $request, FiscalYear $fiscalYear)
    {
        Gate::authorize('update', $fiscalYear);
        $data = $request->validate([
            'year_label' => ['required', 'regex:/^[A-Za-z0-9 \/-]{2,16}$/', Rule::unique('fin_fiscal_years', 'year_label')->where('organization_id', $fiscalYear->organization_id)->ignore($fiscalYear->id)],
            'status' => ['required', Rule::in(FiscalYear::STATUSES)],
            'is_current' => ['sometimes', 'boolean'],
        ]);
        $this->calendar->updateYear($fiscalYear, $data);

        return $this->done('Fiscal year updated.');
    }

    public function destroy(FiscalYear $fiscalYear)
    {
        Gate::authorize('delete', $fiscalYear);
        $this->calendar->deleteYear($fiscalYear);

        return $this->done('Fiscal year deleted.');
    }

    public function closePeriod(Request $request, Period $period)
    {
        Gate::authorize('closePeriod', $period);
        $this->closes->close($period, $request->user());

        return $this->done("{$period->name} closed.");
    }

    public function lockPeriod(Request $request, Period $period)
    {
        Gate::authorize('closePeriod', $period);
        $this->closes->lock($period, $request->user());

        return $this->done("{$period->name} locked.");
    }
}
