<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Budget;
use App\Services\Finance\BudgetWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Workbook export and import of a budget version (FIN-02.13); CSV in P1. */
class BudgetWorkbookController extends Controller
{
    public function __construct(private readonly BudgetWorkbook $workbook) {}

    public function export(Request $request, Budget $budget)
    {
        Gate::authorize('view', $budget);
        $format = strtolower((string) $request->query('format', 'csv'));

        return $this->workbook->export($budget, $format, ! $request->user()->can('view it-finance-amounts'));
    }

    public function import(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        abort_unless($request->user()->can('view it-finance-amounts'), 403, 'Importing needs permission to view amounts.');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('finance.workbook.max_kb')]]);
        $report = $this->workbook->import($budget, $request->file('file'), $request->user());

        if ($report['rejected_count'] > 0) {
            return back()->with('import_report', $report)->with('error', 'The workbook has errors; nothing was imported.');
        }

        return back()->with('import_report', $report)->with('success', "Imported: {$report['created']} created, {$report['updated']} updated, {$report['unchanged']} unchanged.");
    }
}
