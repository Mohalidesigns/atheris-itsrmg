<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Category as FinCategory;
use App\Models\Finance\CostCentre;
use App\Services\Finance\ForecastService;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReport;
use App\Services\Finance\VarianceService;

/** Budget versus actual per baseline line for a period range, with the full-year budget and the EAC. Takes the BvA drill filters. */
class BvaReport extends FinanceReport
{
    public function key(): string
    {
        return 'bva';
    }

    public function title(): string
    {
        return 'Budget vs actual';
    }

    public function description(): string
    {
        return 'Budget, actual and variance per budget line for the selected periods, with the full-year budget and the estimate at completion.';
    }

    public function group(): string
    {
        return 'budget';
    }

    public function filters(): array
    {
        return ['fiscal_year_id', 'period_range', 'cost_centre_id'];
    }

    public function columns(): array
    {
        return [
            'cost_centre' => ['label' => 'Cost centre', 'type' => 'text'], 'tower' => ['label' => 'TBM tower', 'type' => 'text'], 'line' => ['label' => 'Budget line', 'type' => 'text'],
            'budget' => ['label' => 'Budget', 'type' => 'money'], 'actual' => ['label' => 'Actual', 'type' => 'money'], 'variance' => ['label' => 'Variance', 'type' => 'money'],
            'variance_pct' => ['label' => 'Variance %', 'type' => 'pct'], 'budget_fy' => ['label' => 'Full-year budget', 'type' => 'money'], 'eac' => ['label' => 'EAC', 'type' => 'money'],
        ];
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $mon = app(MonitoringService::class);
        $f = $mon->normalise($orgId, $filters);
        if (! $f['fiscal_year_id']) {
            return [];
        }
        $periods = $mon->periodsOf($orgId, $f['fiscal_year_id']);
        $from = $periods->get((int) $f['from_period_id'])?->sequence;
        $to = $periods->get((int) $f['to_period_id'])?->sequence;
        $lines = $mon->baselineLines($orgId, $f['fiscal_year_id'], $costCentreIds, $f);
        $figs = $mon->lineFigures($lines, $f['fiscal_year_id'], $from, $to);
        $eac = app(ForecastService::class)->eacForLines($lines, $f['fiscal_year_id']);
        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'code', 'name'])->keyBy('id');
        $tw = FinCategory::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'name'])->keyBy('id');
        $vs = app(VarianceService::class);
        $rows = [];
        foreach ($lines as $l) {
            $g = $figs[$l->id];
            $t = $vs->thresholds($orgId, $l);
            $c = VarianceService::classify($g['budget'], $g['actual'], $t['amber_pct'], $t['red_pct'], $t['abs_floor']);
            $rows[] = [
                'cost_centre' => ($cc[$l->cost_centre_id]->code ?? '').' '.($cc[$l->cost_centre_id]->name ?? ''), 'tower' => $tw[$l->tower_id]->name ?? '', 'line' => "#{$l->line_no} {$l->description}",
                'budget' => $g['budget'], 'actual' => $g['actual'], 'variance' => $c['variance'], 'variance_pct' => $c['pct'], 'budget_fy' => $g['budget_fy'], 'eac' => $eac[$l->id]['eac'],
            ];
        }

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        $t = parent::totals($rows);
        if ($t) {
            $t['variance_pct'] = bccomp((string) $t['budget'], '0', 2) !== 0 ? bcadd(bcmul(bcdiv((string) $t['variance'], (string) $t['budget'], 8), '100', 8), '0', 2) : null;
        }

        return $t;
    }
}
