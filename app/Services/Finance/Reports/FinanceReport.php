<?php

namespace App\Services\Finance\Reports;

/** One finance report definition (FIN-07.07): its filters, columns and rows. Registered in FinanceReports. */
abstract class FinanceReport
{
    abstract public function key(): string;

    abstract public function title(): string;

    public function description(): string
    {
        return '';
    }

    /** Budget, spend, contracts or vendors (groups the report cards). */
    public function group(): string
    {
        return 'spend';
    }

    /** @return list<string> subset of fiscal_year_id, period_range, cost_centre_id, window, as_of */
    public function filters(): array
    {
        return ['fiscal_year_id'];
    }

    /** @return array<string, array{label:string,type:string}> key => label and type (text, money, pct, int, date) */
    abstract public function columns(): array;

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<int,int>|null  $costCentreIds  null means every cost centre
     * @return iterable<int, array<string,mixed>>
     */
    abstract public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable;

    /**
     * A totals row: money columns summed, the first column labelled.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<string,mixed>|null
     */
    public function totals(array $rows): ?array
    {
        $sums = [];
        foreach ($this->columns() as $key => $c) {
            if (in_array($c['type'], ['money', 'int'], true)) {
                $sums[$key] = array_reduce($rows, fn ($carry, $r) => bcadd($carry, (string) ($r[$key] ?? '0'), $c['type'] === 'int' ? 0 : 2), $c['type'] === 'int' ? '0' : '0.00');
            }
        }
        if (! $sums) {
            return null;
        }
        $first = array_key_first($this->columns());

        return array_merge(array_fill_keys(array_keys($this->columns()), null), [$first => 'Total'], $sums);
    }
}
