<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsExpenses;
use App\Models\Finance\Actual;
use App\Models\Finance\ImportBatch;
use App\Policies\Finance\FinancePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The history of GL, expense and invoice imports (FIN-03.15). */
class FinanceImportController extends Controller
{
    use PresentsExpenses;

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', ImportBatch::class);
        $filters = ['type' => in_array($request->query('type'), ImportBatch::TYPES, true) ? $request->query('type') : null];
        $batches = ImportBatch::query()->with('importedBy:id,name')->when($filters['type'], fn ($q, $v) => $q->where('type', $v))->orderByDesc('id')->limit(200)->get();

        return $this->renderExpenses('Finance/Imports/Index', [
            'filters' => $filters, 'batches' => $batches->map(fn ($b) => $this->batchRow($b))->values(),
            'options' => ['types' => collect(ImportBatch::TYPES)->map(fn ($t) => ['value' => $t, 'label' => ucfirst($t)])->values()],
            'can' => ['import' => $user->can('import it-finance')],
        ]);
    }

    public function show(Request $request, ImportBatch $importBatch)
    {
        $user = $request->user();
        Gate::authorize('view', $importBatch);
        $actuals = $importBatch->type === 'actuals'
            ? Actual::query()->when(($vis = FinancePolicy::visibleCostCentreIds($user)) !== null, fn ($q) => $q->whereIn('cost_centre_id', $vis ?: [0]))->where('import_batch_id', $importBatch->id)->with(['period', 'costCentre:id,code,name', 'glAccount:id,code,name', 'budgetLine', 'vendor:id,name', 'contract:id,contract_code,title', 'invoice:id,invoice_code'])
                ->orderBy('id')->limit(500)->get() : collect();
        $this->primeLines($actuals->pluck('budget_line_id'));

        return $this->renderExpenses('Finance/Imports/Show', [
            'batch' => $this->batchRow($importBatch) + ['report' => $this->reportShape((array) $importBatch->report)], 'actuals' => $actuals->map(fn ($a) => $this->actualRow($a))->values(),
            'can' => ['match' => $user->can('import it-finance')],
        ]);
    }

    /** The same shape as the flash import_report so the page renders one report component. */
    private function reportShape(array $r): array
    {
        return $r + ['rows' => 0, 'accepted' => 0, 'rejected' => 0, 'duplicates' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => [], 'sample' => []];
    }
}
