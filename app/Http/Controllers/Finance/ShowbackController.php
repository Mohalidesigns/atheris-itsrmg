<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsFinOps;
use App\Models\Finance\Allocation;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\FiscalYear;
use App\Services\Finance\ChargebackService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\ShowbackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Showback statements, their export, and the chargeback card (FIN-03.12). */
class ShowbackController extends Controller
{
    use PresentsFinOps;

    public function __construct(
        private readonly ShowbackService $showback,
        private readonly ChargebackService $chargeback,
        private readonly MonitoringService $monitoring,
        private readonly FinanceSettings $settings,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Allocation::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $ccIds = $this->visibleCcIds($user);
        [$f, $fy, $periods] = $this->filters($request, $orgId);

        $statement = $fy && $f['from_period_id'] && $f['to_period_id']
            ? $this->showback->statement($orgId, $f['target_type'], $fy->id, $f['from_period_id'], $f['to_period_id'], $f['basis'], $ccIds, $user)
            : ['target_type' => $f['target_type'], 'pools' => [], 'rows' => [], 'totals' => ['direct' => '0.00', 'allocated' => '0.00', 'total' => '0.00', 'retained' => '0.00', 'it_total' => '0.00', 'reconciles' => true, 'difference' => '0.00']];
        $detail = $f['target'] && $fy ? $this->showback->detail($orgId, $f['target_type'], $f['target'], $f['from_period_id'], $f['to_period_id'], $f['basis'], $ccIds) : null;

        $closed = collect($periods)->filter(fn ($p) => in_array($p['status'], ['closed', 'locked'], true))->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'status' => $p['status']])->values()->all();
        $preview = null;
        if ($f['cb_period_id']) {
            $p = $this->chargeback->preview($orgId, $f['cb_period_id'], $f['cb_target_type']);
            $preview = ['period_id' => $f['cb_period_id'], 'target_type' => $f['cb_target_type'], 'line_count' => count($p['lines']), 'total_debit' => $p['total_debit'], 'total_credit' => $p['total_credit'], 'blocked_reason' => $p['blocked_reason']];
        }
        $canRun = Gate::forUser($user)->allows('runChargeback', ChargebackRun::class);
        $runs = ! $canRun ? collect() : ChargebackRun::withoutGlobalScopes()->where('organization_id', $orgId)->with(['period:id,name', 'exportedBy:id,name', 'voidedBy:id,name'])->orderByDesc('id')->limit(30)->get();
        $units = $this->showback->units($orgId);

        return $this->renderFinOps('Finance/Monitor/Showback', [
            'filters' => $f, 'enabled' => (bool) $this->settings->for($orgId)->showback_enabled, 'statement' => $statement, 'detail' => $detail,
            'cloudMemo' => $fy ? $this->showback->cloudMemo($orgId, $f['target_type'], $f['from_period_id'], $f['to_period_id'], $ccIds) : [],
            'chargeback' => [
                'periods' => $closed, 'preview' => $preview, 'units' => $units,
                'runs' => $runs->map(fn (ChargebackRun $r) => [
                    'id' => $r->id, 'run_code' => $r->run_code, 'period' => ['id' => $r->period?->id, 'name' => $r->period?->name], 'target_type' => $r->target_type, 'status' => $r->status, 'line_count' => (int) $r->line_count,
                    'total_debit' => (string) $r->total_debit, 'total_credit' => (string) $r->total_credit, 'exported_by' => $r->exportedBy?->name, 'exported_at' => $r->exported_at?->toIso8601String(),
                    'voided_by' => $r->voidedBy?->name, 'voided_at' => $r->voided_at?->toIso8601String(), 'void_reason' => $r->void_reason, 'download_url' => route('finance.chargeback.download', $r->id),
                ])->values(),
            ],
            'fiscalYears' => $this->finFiscalYears($orgId), 'periods' => collect($periods)->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'status' => $p['status']])->values(),
            'options' => [
                'targetTypes' => $this->opts(['business_unit' => 'Business unit', 'cost_centre' => 'Cost centre', 'business_service' => 'Business service', 'ea_application' => 'EA application', 'investment' => 'Investment', 'legal_entity' => 'Legal entity']),
                'bases' => $this->opts(['actual' => 'Actual', 'budget' => 'Budget']),
            ],
            'can' => ['export' => Gate::forUser($user)->allows('exportChargeback', ChargebackRun::class), 'run' => $canRun, 'void' => $canRun],
        ]);
    }

    /** The statement as csv, xlsx or pdf through the P4 report writers. */
    public function export(Request $request, FinanceReportController $reports)
    {
        Gate::authorize('exportChargeback', ChargebackRun::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        [$f, $fy] = $this->filters($request, $orgId);
        abort_unless($fy && $f['from_period_id'] && $f['to_period_id'], 404);
        $format = in_array($request->query('format'), ['csv', 'xlsx', 'pdf'], true) ? $request->query('format') : 'csv';
        $s = $this->showback->statement($orgId, $f['target_type'], $fy->id, $f['from_period_id'], $f['to_period_id'], $f['basis'], $this->visibleCcIds($user), $user);
        $codes = collect($s['pools'])->pluck('code')->all();
        $columns = [['key' => 'label', 'label' => 'Target', 'type' => 'text'], ['key' => 'direct', 'label' => 'Direct', 'type' => 'money'], ['key' => 'allocated', 'label' => 'Allocated', 'type' => 'money'], ['key' => 'total', 'label' => 'Total', 'type' => 'money']]
            + array_map(fn ($c) => ['key' => 'pool_'.$c, 'label' => strtoupper($c), 'type' => 'money'], $codes);
        $columns = array_values($columns);
        $rows = array_map(fn ($r) => $r + collect($codes)->mapWithKeys(fn ($c) => ['pool_'.$c => $r['by_pool'][$c] ?? '0.00'])->all(), $s['rows']);
        $rows[] = ['label' => 'Retained by IT (not allocated)', 'direct' => '0.00', 'allocated' => '0.00', 'total' => $s['totals']['retained']];
        $result = ['columns' => $columns, 'rows' => $rows, 'totals' => ['label' => 'IT total', 'total' => $s['totals']['it_total']]];

        return $reports->download($result, 'Showback statement', 'it-finance-showback', $format);
    }

    /** @return array{0:array<string,mixed>,1:?FiscalYear,2:list<array<string,mixed>>} */
    private function filters(Request $request, int $orgId): array
    {
        $v = $request->validate([
            'fiscal_year_id' => ['nullable', 'integer'], 'from_period_id' => ['nullable', 'integer'], 'to_period_id' => ['nullable', 'integer'], 'target_type' => ['nullable', 'in:'.implode(',', Allocation::TARGET_TYPES)],
            'basis' => ['nullable', 'in:actual,budget'], 'target' => ['nullable', 'string', 'max:80'], 'cb_period_id' => ['nullable', 'integer'], 'cb_target_type' => ['nullable', 'in:'.implode(',', Allocation::TARGET_TYPES)],
        ]);
        $fy = $this->monitoring->resolveFiscalYear($orgId, ! empty($v['fiscal_year_id']) ? (int) $v['fiscal_year_id'] : null);
        $periods = $fy ? $this->finPeriods($orgId, $fy->id) : [];
        $ids = collect($periods)->pluck('id')->all();
        $today = FinanceClock::today()->toDateString();
        $asOf = collect($periods)->filter(fn ($p) => $p['ends_on'] < $today)->last() ?? collect($periods)->first();
        $from = in_array((int) ($v['from_period_id'] ?? 0), $ids, true) ? (int) $v['from_period_id'] : ($periods[0]['id'] ?? null);
        $to = in_array((int) ($v['to_period_id'] ?? 0), $ids, true) ? (int) $v['to_period_id'] : ($asOf['id'] ?? null);

        return [[
            'fiscal_year_id' => $fy?->id, 'from_period_id' => $from, 'to_period_id' => $to, 'target_type' => $v['target_type'] ?? 'business_unit', 'basis' => $v['basis'] ?? 'actual', 'target' => $v['target'] ?? null,
            'cb_period_id' => ! empty($v['cb_period_id']) ? (int) $v['cb_period_id'] : null, 'cb_target_type' => $v['cb_target_type'] ?? ($v['target_type'] ?? 'business_unit'),
        ], $fy, $periods];
    }
}
