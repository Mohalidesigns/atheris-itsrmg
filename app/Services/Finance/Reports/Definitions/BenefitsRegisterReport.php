<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Benefit;
use App\Models\Finance\Investment;
use App\Models\User;
use App\Services\Finance\Reports\FinanceReport;

/** The register of expected benefits with baseline, target and realised values (FIN-07.07). Currency benefits are money; hours, counts and scores are not. */
class BenefitsRegisterReport extends FinanceReport
{
    public function key(): string
    {
        return 'benefits_register';
    }

    public function title(): string
    {
        return 'Benefits register';
    }

    public function description(): string
    {
        return 'Every benefit promised by an IT investment: owner, baseline, target, what has been realised and its status.';
    }

    public function group(): string
    {
        return 'investments';
    }

    public function filters(): array
    {
        return ['fiscal_year_id'];
    }

    /** Value columns are text so that only currency rows are masked (see rowMoneyKeys); non-currency figures stay visible. */
    public function columns(): array
    {
        return [
            'code' => ['label' => 'Benefit', 'type' => 'text'], 'investment' => ['label' => 'Investment', 'type' => 'text'], 'type' => ['label' => 'Type', 'type' => 'text'],
            'owner' => ['label' => 'Owner', 'type' => 'text'], 'unit' => ['label' => 'Unit', 'type' => 'text'], 'baseline' => ['label' => 'Baseline', 'type' => 'text'],
            'target' => ['label' => 'Target', 'type' => 'text'], 'realised' => ['label' => 'Realised', 'type' => 'text'], 'realised_pct' => ['label' => 'Realised %', 'type' => 'text'],
            'due_on' => ['label' => 'Due', 'type' => 'date'], 'status' => ['label' => 'Status', 'type' => 'text'],
        ];
    }

    public function totals(array $rows): ?array
    {
        return null;
    }

    public function rowMoneyKeys(array $row): array
    {
        return ($row['unit'] ?? null) === 'currency' ? ['baseline', 'target', 'realised', 'realised_pct'] : [];
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $invs = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
            ->when(! empty($filters['fiscal_year_id']), fn ($q) => $q->where('fiscal_year_id', (int) $filters['fiscal_year_id']))
            ->when($costCentreIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $costCentreIds ?: [0]))->pluck('investment_code', 'id');
        if ($invs->isEmpty()) {
            return [];
        }
        $benefits = Benefit::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('investment_id', $invs->keys())->orderBy('benefit_code')->get();
        $owners = User::withoutGlobalScopes()->whereIn('id', $benefits->pluck('owner_id')->filter())->pluck('name', 'id');
        $s = fn ($v) => $v === null ? null : (string) $v;

        return $benefits->map(fn (Benefit $b) => [
            'code' => $b->benefit_code, 'investment' => $invs[$b->investment_id] ?? '', 'type' => $b->type, 'owner' => $owners[$b->owner_id] ?? null, 'unit' => $b->unit,
            'baseline' => $s($b->baseline_value), 'target' => $s($b->target_value), 'realised' => $s($b->realised_value), 'realised_pct' => $s($b->realised_pct),
            'due_on' => $b->due_on?->toDateString(), 'status' => $b->status,
        ])->all();
    }
}
