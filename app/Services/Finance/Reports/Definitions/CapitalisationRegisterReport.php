<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Capitalisation;
use App\Models\Finance\FiscalYear;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\Reports\FinanceReport;
use Carbon\CarbonImmutable;

/** The register of capitalised assets with amortisation to date, net book value and the amortisation of the current and next four fiscal years. */
class CapitalisationRegisterReport extends FinanceReport
{
    private ?array $years = null;

    private int $orgId = 0;

    public function key(): string
    {
        return 'capitalisation_register';
    }

    public function title(): string
    {
        return 'Capitalisation register';
    }

    public function description(): string
    {
        return 'Capitalised assets under IAS 38 and IAS 16 with life, method, amortisation to date, net book value and the schedule by fiscal year.';
    }

    public function group(): string
    {
        return 'Cloud & FinOps';
    }

    public function filters(): array
    {
        return ['as_of'];
    }

    /** @return list<array{label:string,from:string,to:string}> current fiscal year and the next four, by calendar span of the stored years or of 12 months from the current year start */
    private function years(int $orgId): array
    {
        if ($this->years !== null && $this->orgId === $orgId) {
            return $this->years;
        }
        $this->orgId = $orgId;
        $cur = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_current', true)->first()
            ?? FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->orderByDesc('starts_on')->first();
        $start = $cur ? $cur->starts_on->copy() : FinanceClock::today()->startOfYear();
        $out = [];
        for ($i = 0; $i < 5; $i++) {
            $from = $start->copy()->addYears($i);
            $out[] = ['label' => $cur && $i === 0 ? (string) $cur->year_label : 'FY '.$from->format('Y'), 'from' => $from->toDateString(), 'to' => $from->copy()->addYear()->subDay()->toDateString()];
        }

        return $this->years = $out;
    }

    public function columns(): array
    {
        $cols = [
            'code' => ['label' => 'Code', 'type' => 'text'], 'title' => ['label' => 'Title', 'type' => 'text'], 'source' => ['label' => 'Source', 'type' => 'text'], 'standard' => ['label' => 'Standard', 'type' => 'text'],
            'asset' => ['label' => 'Asset', 'type' => 'text'], 'available_for_use_on' => ['label' => 'Available for use', 'type' => 'date'], 'capitalised_on' => ['label' => 'Capitalised on', 'type' => 'date'],
            'amount' => ['label' => 'Cost', 'type' => 'money'], 'life_months' => ['label' => 'Life (months)', 'type' => 'int'], 'method' => ['label' => 'Method', 'type' => 'text'],
            'amortised' => ['label' => 'Amortised to date', 'type' => 'money'], 'nbv' => ['label' => 'Net book value', 'type' => 'money'],
        ];
        foreach (range(0, 4) as $i) {
            $cols["fy{$i}"] = ['label' => 'Amortisation '.($this->orgId ? ($this->years[$i]['label'] ?? "FY+{$i}") : "FY+{$i}"), 'type' => 'money'];
        }

        return $cols;
    }

    public function totals(array $rows): ?array
    {
        $t = parent::totals($rows);
        if ($t) {
            $t['life_months'] = null;
        }

        return $t;
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $years = $this->years($orgId);
        $asOf = ! empty($filters['as_of']) ? CarbonImmutable::parse($filters['as_of']) : FinanceClock::today();
        $caps = Capitalisation::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'capitalised')->whereNull('deleted_at')
            ->when($costCentreIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $costCentreIds ?: [0]))->with(['lines', 'asset:id,name'])->orderBy('capitalisation_code')->get();

        return $caps->map(function (Capitalisation $c) use ($years, $asOf) {
            $row = [
                'code' => (string) $c->capitalisation_code, 'title' => (string) $c->title, 'source' => $c->source_label, 'standard' => strtoupper(str_replace('_', ' ', (string) $c->standard)),
                'asset' => (string) ($c->asset?->name ?? $c->asset_name ?? ''), 'available_for_use_on' => $c->available_for_use_on?->toDateString(), 'capitalised_on' => $c->capitalised_on?->toDateString(),
                'amount' => bcadd((string) $c->amount, '0', 2), 'life_months' => (int) $c->useful_life_months, 'method' => $c->method === 'declining' ? 'Declining balance' : 'Straight line',
                'amortised' => $c->amortisedTo($asOf), 'nbv' => $c->nbvOn($asOf),
            ];
            foreach ($years as $i => $y) {
                $row["fy{$i}"] = $c->lines->filter(fn ($l) => $l->month->toDateString() >= $y['from'] && $l->month->toDateString() <= $y['to'])
                    ->reduce(fn ($carry, $l) => bcadd($carry, (string) $l->amount, 2), '0.00');
            }

            return $row;
        })->all();
    }
}
