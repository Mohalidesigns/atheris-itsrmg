<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\InvestmentFinancials;
use App\Services\Finance\Reports\FinanceReport;

/** The IT investment portfolio with score, business case returns, cost performance and benefits realised (FIN-07.07). */
class InvestmentPortfolioReport extends FinanceReport
{
    public function key(): string
    {
        return 'investment_portfolio';
    }

    public function title(): string
    {
        return 'Investment portfolio';
    }

    public function description(): string
    {
        return 'Every IT investment with its stage, priority score and rank, business case NPV and IRR, budget, actual and estimate at completion, and benefits realised.';
    }

    public function group(): string
    {
        return 'investments';
    }

    public function filters(): array
    {
        return ['fiscal_year_id'];
    }

    public function columns(): array
    {
        return [
            'code' => ['label' => 'Code', 'type' => 'text'], 'title' => ['label' => 'Title', 'type' => 'text'], 'rgt' => ['label' => 'Run/grow/transform', 'type' => 'text'],
            'driver' => ['label' => 'Driver', 'type' => 'text'], 'stage' => ['label' => 'Stage', 'type' => 'text'], 'status' => ['label' => 'Status', 'type' => 'text'],
            'regulatory' => ['label' => 'Regulatory', 'type' => 'text'], 'rank' => ['label' => 'Rank', 'type' => 'int'], 'score' => ['label' => 'Priority score', 'type' => 'text'],
            'npv' => ['label' => 'NPV', 'type' => 'money'], 'irr_pct' => ['label' => 'IRR %', 'type' => 'pct'], 'bac' => ['label' => 'BAC', 'type' => 'money'],
            'ac' => ['label' => 'Actual cost', 'type' => 'money'], 'eac' => ['label' => 'EAC', 'type' => 'money'], 'cpi' => ['label' => 'CPI', 'type' => 'pct'],
            'benefits_realised_pct' => ['label' => 'Benefits realised %', 'type' => 'text'],
        ];
    }

    public function totals(array $rows): ?array
    {
        return null; // BAC/AC/EAC are shown per investment; a total over mixed stages would mislead
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $invs = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
            ->when(! empty($filters['fiscal_year_id']), fn ($q) => $q->where('fiscal_year_id', (int) $filters['fiscal_year_id']))
            ->when($costCentreIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $costCentreIds ?: [0]))
            ->orderByRaw('priority_rank is null')->orderBy('priority_rank')->orderBy('id')->get();
        if ($invs->isEmpty()) {
            return [];
        }
        $fin = app(InvestmentFinancials::class)->forInvestments($invs);
        $pcts = app(BenefitsService::class)->pctsFor($invs);
        $bcs = BusinessCase::withoutGlobalScopes()->whereIn('investment_id', $invs->pluck('id'))->whereIn('status', ['approved', 'submitted', 'draft'])->orderBy('version_no')->get()
            ->groupBy('investment_id')->map(fn ($g) => $g->firstWhere('status', 'approved') ?? $g->last());

        return $invs->map(function (Investment $i) use ($fin, $pcts, $bcs) {
            $bc = $bcs->get($i->id);
            $f = $fin[$i->id] ?? [];

            return [
                'code' => $i->investment_code, 'title' => $i->title, 'rgt' => $i->rgt, 'driver' => $i->driver, 'stage' => $i->stage, 'status' => $i->status,
                'regulatory' => $i->is_regulatory ? 'Yes' : 'No', 'rank' => $i->priority_rank, 'score' => $i->priority_score === null ? null : (string) $i->priority_score,
                'npv' => $bc?->npv === null ? null : (string) $bc->npv, 'irr_pct' => $bc?->irr_pct === null ? null : (string) $bc->irr_pct,
                'bac' => $i->bac === null ? null : (string) $i->bac, 'ac' => $f['ac'] ?? null, 'eac' => $f['eac'] ?? null, 'cpi' => $f['cpi'] ?? null,
                'benefits_realised_pct' => isset($pcts[$i->id]) ? (string) $pcts[$i->id] : null,
            ];
        })->all();
    }
}
