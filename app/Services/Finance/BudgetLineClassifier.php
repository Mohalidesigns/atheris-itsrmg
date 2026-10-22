<?php

namespace App\Services\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tags budget lines to an EA application or a business service for application TCO (plan P6-17). It is classification only, no money
 * changes, so it works on any budget status including a baseline, without a revision (as C134). Each changed line is audited.
 */
class BudgetLineClassifier
{
    use LogsFinanceEvents;

    public function __construct(private readonly MonitoringService $monitoring) {}

    /**
     * @param  list<int>  $lineIds
     * @param  array{ea_application_id?:?int,business_service_id?:?int}  $dims  a key present with null clears the tag
     */
    public function classify(array $lineIds, array $dims, User $by): int
    {
        $orgId = (int) $by->organization_id;
        $columns = array_intersect_key($dims, array_flip(['ea_application_id', 'business_service_id']));
        $columns || $this->fail('Pick an EA application or a business service.', 'ea_application_id');
        foreach ($columns as $col => $id) {
            if ($id === null || $id === '') {
                $columns[$col] = null;

                continue;
            }
            $class = $col === 'ea_application_id' ? EaApplication::class : BusinessService::class;
            $ok = $class::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->whereKey((int) $id)->exists();
            $ok || $this->fail('That application or service does not belong to this bank.', $col);
            $columns[$col] = (int) $id;
        }
        $ids = array_values(array_unique(array_map('intval', $lineIds)));
        $ids || $this->fail('Pick at least one budget line.', 'line_ids');
        $ids = array_slice($ids, 0, 500);

        $lines = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $ids)->get()->keyBy('id');
        $budgets = Budget::withoutGlobalScopes()->whereIn('id', $lines->pluck('budget_id')->unique())->get()->keyBy('id');
        $years = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $budgets->pluck('fiscal_year_id')->unique())->get()->keyBy('id');
        $refused = [];
        // Spend already booked in a closed or locked period is frozen into the allocation results: re-tagging its line would move it
        // between targets (or count it as direct and allocated) after the books are shut.
        $frozen = DB::table('fin_actuals as a')->join('fin_periods as p', 'p.id', '=', 'a.period_id')
            ->where('a.organization_id', $orgId)->whereIn('a.budget_line_id', $ids)->whereIn('p.status', ['closed', 'locked'])
            ->distinct()->pluck('a.budget_line_id')->map(fn ($v) => (int) $v)->flip();
        foreach ($ids as $id) {
            $l = $lines[$id] ?? null;
            if (! $l) {
                $refused[] = "#{$id} (not in this bank)";

                continue;
            }
            $label = "#{$l->line_no} {$l->description}";
            $fy = $years[$budgets[$l->budget_id]->fiscal_year_id ?? 0] ?? null;
            if (! Gate::forUser($by)->allows('updatePlan', $l)) {
                $refused[] = "{$label} (outside your cost centres)";
            } elseif (! $fy || $fy->status === 'closed') {
                $refused[] = "{$label} (fiscal year closed)";
            } elseif ($frozen->has($id)) {
                $refused[] = "{$label} (has actuals in a closed or locked period)";
            }
        }
        $refused && $this->fail('These lines cannot be classified: '.implode('; ', array_slice($refused, 0, 5)).(count($refused) > 5 ? ' and '.(count($refused) - 5).' more' : '').'.', 'line_ids');

        $changed = 0;
        DB::transaction(function () use ($lines, $columns, $by, &$changed) {
            foreach ($lines as $l) {
                $old = $l->only(array_keys($columns));
                $new = array_map(fn ($v) => $v === null ? null : (int) $v, $columns);
                if (array_map(fn ($v) => $v === null ? null : (int) $v, $old) === $new) {
                    continue;
                }
                DB::table('fin_budget_lines')->where('id', $l->id)->update($new + ['updated_at' => now()]);
                $this->audit($l, $by, 'classified', "Budget line #{$l->line_no} classified", ['old' => $old, 'attributes' => $new]);
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * Baseline lines still without the subject's tag, with their current-year budget, for the "attribute spend" picker.
     *
     * @param  list<int>|null  $ccIds
     * @return list<array{id:int,label:string,cost_centre:?string,current_base_amount:string}>
     */
    public function unattributed(int $orgId, int $fiscalYearId, string $subject, ?array $ccIds, ?string $search, int $limit = 100): array
    {
        $column = $subject === 'business_service' ? 'business_service_id' : 'ea_application_id';
        $needle = $search ? mb_strtolower(trim($search)) : null;
        $lines = $this->monitoring->baselineLines($orgId, $fiscalYearId, $ccIds)->filter(fn (BudgetLine $l) => $l->{$column} === null
            && ($needle === null || str_contains(mb_strtolower($l->description), $needle)))->take($limit)->values();
        if ($lines->isEmpty()) {
            return [];
        }
        $fig = $this->monitoring->lineFigures($lines, $fiscalYearId);
        $codes = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('code', 'id');

        return $lines->map(fn (BudgetLine $l) => [
            'id' => (int) $l->id, 'label' => "#{$l->line_no} {$l->description}", 'cost_centre' => $codes[$l->cost_centre_id] ?? null, 'current_base_amount' => $fig[$l->id]['budget_fy'] ?? '0.00',
        ])->all();
    }
}
