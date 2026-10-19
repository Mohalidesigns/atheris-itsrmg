<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsMonitoring;
use App\Http\Requests\Finance\MonitorFilterRequest;
use App\Models\Finance\Period;
use App\Models\ScheduledReport;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReports;
use App\Services\Finance\Reports\ReportExporter;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** The finance reports hub (FIN-07.07): filter, preview and download as XLSX, CSV or PDF. */
class FinanceReportController extends Controller
{
    use PresentsMonitoring;

    public function __construct(private readonly FinanceReports $reports, private readonly ReportExporter $exporter, private readonly MonitoringService $monitoring) {}

    public function index(MonitorFilterRequest $request)
    {
        Gate::authorize('viewAny', Period::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $v = $request->validated();
        $available = $this->reports->available();
        $selected = isset($v['report'], $available[$v['report']]) ? $v['report'] : null;
        $fy = $this->monitoring->resolveFiscalYear($orgId, $v['fiscal_year_id'] ?? null);
        $window = $v['window'] ?? null;
        $filters = [
            'report' => $selected, 'fiscal_year_id' => $fy?->id, 'from_period_id' => $v['from_period_id'] ?? null, 'to_period_id' => $v['to_period_id'] ?? null,
            'cost_centre_id' => $v['cost_centre_id'] ?? null, 'window' => $window && $window !== 'all' ? $window : null, 'as_of' => $v['as_of'] ?? null,
        ] + array_intersect_key($v, array_flip(['account_id', 'from', 'to', 'target_type', 'basis', 'subject']));
        $schedules = ScheduledReport::withoutGlobalScopes()->where('organization_id', $orgId)->where(fn ($q) => $q->where('report_type', 'finance')->orWhere('report_type', 'like', 'finance.%'))
            ->orderBy('name')->get();

        return $this->renderMonitoring('Finance/Reports/Index', [
            'reports' => collect($available)->map(function ($class, $key) {
                $d = new $class;

                return ['key' => $key, 'title' => $d->title(), 'description' => $d->description(), 'group' => $d->group(), 'filters' => $d->filters(), 'formats' => ['xlsx', 'csv', 'pdf']];
            })->values()->all(),
            'filters' => $filters, 'fiscalYears' => $this->fiscalYearsMini($orgId), 'periods' => $this->periodsMini($orgId, $fy?->id), 'costCentres' => $this->visibleCostCentresMini($user),
            'schedules' => $schedules->map(fn (ScheduledReport $s) => ['id' => $s->id, 'name' => $s->name, 'report_type' => $s->report_type, 'frequency' => $s->frequency,
                'next_run_at' => $s->next_run_at?->toIso8601String(), 'active' => (bool) $s->active])->values()->all(),
            'can' => ['export' => $user->can('export it-finance'), 'schedule' => $user->can('create reports') && $user->can('export it-finance')],
            'preview' => Inertia::optional(function () use ($selected, $orgId, $filters, $user) {
                if (! $selected) {
                    return null;
                }
                $r = $this->reports->run($selected, $orgId, $filters, $user);
                $r['rows'] = array_slice($r['rows'], 0, (int) config('finance.reports.preview_rows', 50));

                return ['key' => $selected, 'title' => (new ($this->reports->available()[$selected]))->title()] + $r;
            }),
        ]);
    }

    public function show(MonitorFilterRequest $request, string $report)
    {
        Gate::authorize('exportFinance', Period::class);
        $user = $request->user();
        $def = $this->reports->get($report) ?? abort(404);
        $format = strtolower((string) $request->validated('format', 'csv'));
        in_array($format, ['csv', 'xlsx', 'pdf'], true) || $format = 'csv';
        $result = $this->reports->run($report, (int) $user->organization_id, $request->validated(), $user);

        return $this->download($result, $def->title(), 'it-finance-'.str_replace('_', '-', $report), $format);
    }

    /** @param array<string,mixed> $result */
    public function download(array $result, string $title, string $file, string $format)
    {
        if ($format === 'xlsx') {
            class_exists(\ZipArchive::class) || abort(422, 'XLSX is not available on this server.');

            return response($this->exporter->xlsx($result, $title), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$file.'.xlsx"',
            ]);
        }
        if ($format === 'pdf') {
            return response($this->exporter->pdf($result, $title), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$file.'.pdf"']);
        }

        return response($this->exporter->csv($result, $title), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$file.'.csv"']);
    }
}
