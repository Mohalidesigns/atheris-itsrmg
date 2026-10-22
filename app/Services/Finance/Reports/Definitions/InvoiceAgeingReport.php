<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Invoice;
use App\Models\Vendor;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\Reports\FinanceReport;
use Carbon\CarbonImmutable;

/** Unpaid invoices bucketed by days past due as of a date, with a total per vendor. */
class InvoiceAgeingReport extends FinanceReport
{
    public const BUCKETS = ['current' => 'Current', 'd1_30' => '1-30 days', 'd31_60' => '31-60 days', 'd61_90' => '61-90 days', 'd90_plus' => 'Over 90 days'];

    public function key(): string
    {
        return 'invoice_ageing';
    }

    public function title(): string
    {
        return 'Invoice ageing';
    }

    public function description(): string
    {
        return 'Unpaid vendor invoices by how long they are past due as of the chosen date, with a total per vendor.';
    }

    public function filters(): array
    {
        return ['as_of', 'cost_centre_id'];
    }

    public function columns(): array
    {
        $c = ['vendor' => ['label' => 'Vendor', 'type' => 'text'], 'invoices' => ['label' => 'Invoices', 'type' => 'int']];
        foreach (self::BUCKETS as $k => $label) {
            $c[$k] = ['label' => $label, 'type' => 'money'];
        }

        return $c + ['total' => ['label' => 'Total', 'type' => 'money']];
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $asOf = ! empty($filters['as_of']) ? CarbonImmutable::parse($filters['as_of'], config('finance.timezone'))->startOfDay() : FinanceClock::today();
        $inv = Invoice::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('status', Invoice::UNPAID)
            ->when($costCentreIds !== null, fn ($w) => $w->whereIn('cost_centre_id', $costCentreIds ?: [0]))
            ->when(! empty($filters['cost_centre_id']), fn ($w) => $w->where('cost_centre_id', (int) $filters['cost_centre_id']))
            ->get(['id', 'vendor_id', 'due_on', 'invoice_date', 'base_total_amount']);
        $vendors = Vendor::withoutGlobalScopes()->whereIn('id', $inv->pluck('vendor_id')->filter()->unique())->pluck('name', 'id');
        $by = [];
        foreach ($inv as $i) {
            $name = (string) ($vendors[$i->vendor_id] ?? 'No vendor');
            $by[$name] ??= array_merge(['vendor' => $name, 'invoices' => 0], array_fill_keys(array_keys(self::BUCKETS), '0.00'), ['total' => '0.00']);
            $due = $i->due_on ?? $i->invoice_date;
            $late = $due ? (int) $due->startOfDay()->diffInDays($asOf, false) : 0;
            $b = match (true) {
                $late <= 0 => 'current', $late <= 30 => 'd1_30', $late <= 60 => 'd31_60', $late <= 90 => 'd61_90', default => 'd90_plus',
            };
            $amt = bcadd((string) $i->base_total_amount, '0', 2);
            $by[$name]['invoices']++;
            $by[$name][$b] = bcadd($by[$name][$b], $amt, 2);
            $by[$name]['total'] = bcadd($by[$name]['total'], $amt, 2);
        }
        ksort($by);

        return array_values($by);
    }
}
