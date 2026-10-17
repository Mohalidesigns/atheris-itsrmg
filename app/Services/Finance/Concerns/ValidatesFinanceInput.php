<?php

namespace App\Services\Finance\Concerns;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** Shared input checks of the P3 services: plain decimals, same-bank foreign keys, baseline lines, open periods. */
trait ValidatesFinanceInput
{
    protected function money(mixed $value, string $key, bool $positive = true, string $label = 'The amount'): string
    {
        $v = is_scalar($value) ? trim((string) $value) : '';
        if (! preg_match('/^-?\d{1,16}(\.\d{1,2})?$/', $v)) {
            $this->fail("{$label} must be a plain number with up to 2 decimals.", $key);
        }
        if ($positive && (str_starts_with($v, '-') || bccomp($v, '0', 2) <= 0)) {
            $this->fail("{$label} must be greater than 0.", $key);
        }

        return bcadd($v, '0', 2);
    }

    protected function quantity(mixed $value, string $key): string
    {
        $v = is_scalar($value) ? trim((string) $value) : '';
        if (! preg_match('/^\d{1,13}(\.\d{1,4})?$/', $v) || bccomp($v, '0', 4) <= 0) {
            $this->fail('The quantity must be greater than 0 (up to 4 decimals).', $key);
        }

        return bcadd($v, '0', 4);
    }

    /** @param class-string<Model> $class */
    protected function inBank(string $class, int $orgId, mixed $id, string $key, string $message = 'That selection does not belong to this bank.'): Model
    {
        $row = $id ? $class::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) $id) : null;

        return $row ?? $this->fail($message, $key);
    }

    protected function date(mixed $value, string $key): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $value, config('finance.timezone'))->startOfDay();
        } catch (\Throwable) {
            $this->fail('Give a valid date.', $key);
        }
    }

    /** A line of a baseline budget in the fiscal year, in a cost centre the actor can see. */
    protected function baselineLineFor(int $orgId, int $lineId, FiscalYear $fy, ?User $by, string $key = 'budget_line_id'): BudgetLine
    {
        $line = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->find($lineId);
        $budget = $line ? Budget::withoutGlobalScopes()->find($line->budget_id) : null;
        if (! $line || ! $budget || $budget->status !== 'baseline' || (int) $budget->fiscal_year_id !== (int) $fy->id) {
            $this->fail('Pick a line of a baseline budget in this fiscal year.', $key);
        }
        if ($by) {
            $ids = FinancePolicy::visibleCostCentreIds($by);
            if ($ids !== null && ! in_array((int) $line->cost_centre_id, $ids, true) && ! $by->hasRole('Procurement Officer')) {
                $this->fail('That budget line belongs to a cost centre outside your access.', $key);
            }
        }

        return $line;
    }

    protected function periodContaining(int $orgId, CarbonInterface $date): ?Period
    {
        return Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false)
            ->whereDate('starts_on', '<=', $date->toDateString())->whereDate('ends_on', '>=', $date->toDateString())->orderBy('sequence')->orderBy('id')->first();
    }

    /** The period must exist and be open (plan P3-13). */
    protected function assertOpenPeriod(?Period $period, string $key = 'period_id'): Period
    {
        $period || $this->fail('No fiscal period covers that date.', $key);
        $period->status === 'open' || $this->fail("{$period->name} is {$period->status}; post into an open period.", $key);

        return $period;
    }
}
