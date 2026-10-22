<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReport;

/** The budget book: every baseline line with its original, adjustments and current amount, subtotalled per cost centre. */
class BudgetBookReport extends FinanceReport
{
    public function key(): string
    {
        return 'budget_book';
    }

    public function title(): string
    {
        return 'Budget book';
    }

    public function description(): string
    {
        return 'The approved baseline per budget line: original amount, adjustments and the current budget, with a subtotal for each cost centre.';
    }

    public function group(): string
    {
        return 'budget';
    }

    public function filters(): array
    {
        return ['fiscal_year_id', 'cost_centre_id'];
    }

    public function columns(): array
    {
        return [
            'cost_centre' => ['label' => 'Cost centre', 'type' => 'text'], 'tower' => ['label' => 'TBM tower', 'type' => 'text'], 'cost_pool' => ['label' => 'Cost pool', 'type' => 'text'],
            'line' => ['label' => 'Budget line', 'type' => 'text'], 'nature' => ['label' => 'CapEx/OpEx', 'type' => 'text'], 'rgt' => ['label' => 'Run/Grow/Transform', 'type' => 'text'],
            'original' => ['label' => 'Original', 'type' => 'money'], 'adjustments' => ['label' => 'Adjustments', 'type' => 'money'], 'current' => ['label' => 'Current', 'type' => 'money'],
        ];
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $mon = app(MonitoringService::class);
        $fy = $mon->resolveFiscalYear($orgId, ! empty($filters['fiscal_year_id']) ? (int) $filters['fiscal_year_id'] : null);
        if (! $fy) {
            return [];
        }
        $lines = $mon->baselineLines($orgId, (int) $fy->id, $costCentreIds, ['cost_centre_id' => $filters['cost_centre_id'] ?? null]);
        $adj = BudgetAdjustment::withoutGlobalScopes()->whereIn('budget_line_id', $lines->pluck('id'))->selectRaw('budget_line_id, sum(base_amount) as s')->groupBy('budget_line_id')->pluck('s', 'budget_line_id');
        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'code', 'name'])->keyBy('id');
        $cat = Category::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'name'])->keyBy('id');
        $ccLabel = fn (BudgetLine $l) => trim(($cc[$l->cost_centre_id]->code ?? '').' '.($cc[$l->cost_centre_id]->name ?? ''));
        $groups = $lines->groupBy(fn (BudgetLine $l) => $ccLabel($l))->sortKeys();
        $rows = [];
        foreach ($groups as $label => $set) {
            $sub = ['o' => '0.00', 'a' => '0.00', 'c' => '0.00'];
            foreach ($set as $l) {
                $a = bcadd((string) ($adj[$l->id] ?? '0'), '0', 2);
                $o = bcadd((string) $l->base_annual_amount, '0', 2);
                $c = bcadd($o, $a, 2);
                $sub = ['o' => bcadd($sub['o'], $o, 2), 'a' => bcadd($sub['a'], $a, 2), 'c' => bcadd($sub['c'], $c, 2)];
                $rows[] = [
                    'cost_centre' => $label, 'tower' => $cat[$l->tower_id]->name ?? '', 'cost_pool' => $cat[$l->cost_pool_id]->name ?? '', 'line' => "#{$l->line_no} {$l->description}",
                    'nature' => $l->is_capex ? 'CapEx' : 'OpEx', 'rgt' => ucfirst((string) $l->rgt), 'original' => $o, 'adjustments' => $a, 'current' => $c,
                ];
            }
            $rows[] = ['cost_centre' => $label, 'tower' => '', 'cost_pool' => '', 'line' => 'Subtotal', 'nature' => '', 'rgt' => '', 'original' => $sub['o'], 'adjustments' => $sub['a'], 'current' => $sub['c']];
        }

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        return parent::totals(array_values(array_filter($rows, fn ($r) => $r['line'] !== 'Subtotal')));
    }
}
