<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsExpenses;
use App\Models\Finance\Actual;
use App\Models\Finance\ImportBatch;
use App\Policies\Finance\FinancePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $cloudSummary = $importBatch->type === 'cloud' ? $this->cloudSummary($importBatch, $user) : null;

        return $this->renderExpenses('Finance/Imports/Show', [
            'batch' => $this->batchRow($importBatch) + ['report' => $this->reportShape((array) $importBatch->report)], 'actuals' => $actuals->map(fn ($a) => $this->actualRow($a))->values(), 'cloudSummary' => $cloudSummary,
            'can' => ['match' => $user->can('import it-finance')],
        ]);
    }

    /** What a cloud batch loaded: its lines, the untagged share and the cost by service (no actuals are posted for cloud). @return array<string,mixed> */
    private function cloudSummary(ImportBatch $b, $user): array
    {
        $lines = DB::table('fin_cloud_cost_lines')->where('organization_id', $b->organization_id)->where('import_batch_id', $b->id);
        $vis = FinancePolicy::visibleCostCentreIds($user);
        $vis !== null && $lines->whereIn('cost_centre_id', $vis ?: [0]);
        $amounts = $user->can('view it-finance-amounts');
        $by = (clone $lines)->selectRaw('service, SUM(base_cost) as cost')->groupBy('service')->orderByRaw('SUM(base_cost) DESC')->limit(20)->get()
            ->map(fn ($r) => ['service' => $r->service, 'cost' => $amounts ? bcadd((string) $r->cost, bccomp((string) $r->cost, '0', 10) >= 0 ? '0.005' : '-0.005', 2) : null])->all();

        return [
            'lines' => (int) (clone $lines)->count(), 'untagged' => (int) (clone $lines)->where('is_untagged', true)->count(),
            'dates' => ['from' => $b->date_from?->toDateString(), 'to' => $b->date_to?->toDateString()], 'by_service' => $by,
        ];
    }

    /** The same shape as the flash import_report so the page renders one report component. */
    private function reportShape(array $r): array
    {
        return $r + ['rows' => 0, 'accepted' => 0, 'rejected' => 0, 'duplicates' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => [], 'sample' => []];
    }
}
