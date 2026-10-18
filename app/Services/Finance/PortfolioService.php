<?php

namespace App\Services\Finance;

use App\Models\Ea\Capability;
use App\Models\Finance\Benefit;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentLink;
use App\Models\Finance\ValueReview;
use App\Models\User;
use Illuminate\Support\Collection;

/** Portfolio ranking, fund line, splits and summary figures (plan P5-10, P5-11). Raw (unmasked) values; controllers mask. */
class PortfolioService
{
    public function __construct(
        private readonly InvestmentFinancials $financials,
        private readonly BenefitsService $benefits,
        private readonly BudgetAvailability $availability,
    ) {}

    /** @return array<string,mixed> */
    public function portfolio(int $orgId, ?int $fiscalYearId, ?string $envelope, User $viewer): array
    {
        $fy = $fiscalYearId ? FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->find($fiscalYearId) : null;
        $fy ??= FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderByDesc('is_current')->orderByDesc('starts_on')->first();
        $default = $fy ? $this->defaultEnvelope($orgId, (int) $fy->id) : '0.00';
        $manual = $envelope !== null && $envelope !== '' && preg_match(FinanceSettings::DOA_AMOUNT, $envelope);
        $used = $manual ? bcadd($envelope, '0', 2) : $default;

        $all = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->visibleTo($viewer)->whereIn('stage', Investment::OPEN_STAGES)
            ->with(['sponsor:id,name', 'manager:id,name'])->get();
        $inYear = $fy ? $all->where('fiscal_year_id', $fy->id) : $all;
        $fin = $this->financials->forInvestments($all);
        $bcs = BusinessCase::withoutGlobalScopes()->whereIn('investment_id', $all->pluck('id'))->whereIn('status', ['approved', 'submitted', 'draft'])->orderBy('version_no')->get()
            ->groupBy('investment_id')->map(fn ($g) => $g->firstWhere('status', 'approved') ?? $g->last());

        $ranked = $inYear->filter(fn ($i) => $i->priority_rank !== null)->sortBy('priority_rank')->values();
        $unranked = $inYear->filter(fn ($i) => $i->priority_rank === null)->sortBy('id')->values();
        $cum = '0.00';
        $ranking = [];
        foreach ($ranked->concat($unranked) as $i) {
            $isRanked = $i->priority_rank !== null;
            if ($isRanked) {
                $cum = bcadd($cum, (string) ($i->planned_cost ?? '0'), 2);
            }
            $bc = $bcs->get($i->id);
            $ranking[] = [
                'id' => $i->id, 'investment_code' => $i->investment_code, 'title' => $i->title, 'rgt' => $i->rgt, 'driver' => $i->driver, 'stage' => $i->stage,
                'is_regulatory' => (bool) $i->is_regulatory, 'priority_score' => $i->priority_score, 'priority_rank' => $i->priority_rank,
                'planned_cost' => $i->planned_cost, 'cumulative_cost' => $isRanked ? $cum : null, 'above_line' => $isRanked && bccomp($cum, $used, 2) <= 0,
                'npv' => $bc?->npv, 'roi_pct' => $bc?->roi_pct, 'href' => route('finance.investments.show', $i->id),
            ];
        }

        $value = fn (Investment $i) => (string) ($i->bac ?? $i->planned_cost ?? '0');
        $sumBy = fn (Collection $c) => $c->reduce(fn ($s, $i) => bcadd($s, $value($i), 2), '0.00');
        $rgt = [];
        foreach (Investment::RGT as $k) {
            $g = $all->where('rgt', $k);
            $rgt[$k] = ['count' => $g->count(), 'bac' => $sumBy($g)];
        }
        $driver = [];
        foreach (Investment::DRIVERS as $k) {
            $g = $all->where('driver', $k);
            $g->isNotEmpty() && $driver[$k] = ['count' => $g->count(), 'bac' => $sumBy($g)];
        }
        $reg = $all->where('is_regulatory', true);
        $total = $sumBy($all);
        $regBac = $sumBy($reg);
        $regulatory = ['count' => $reg->count(), 'bac' => $regBac, 'share_pct' => $this->share($regBac, $total)];

        $pipeline = [];
        foreach (Investment::OPEN_STAGES as $stage) {
            $g = $all->where('stage', $stage);
            $pipeline[] = ['stage' => $stage, 'count' => $g->count(), 'value' => $sumBy($g)];
        }

        $baselined = $all->filter(fn ($i) => $i->bac !== null);
        $map = $this->financials->lineMap($baselined);
        $lineIds = array_values(array_unique(array_merge(...array_values($map ?: [[]]))));
        $costs = [
            'bac' => $baselined->reduce(fn ($s, $i) => bcadd($s, (string) $i->bac, 2), '0.00'),
            'ac' => $baselined->reduce(fn ($s, $i) => bcadd($s, $fin[$i->id]['ac'], 2), '0.00'),
            'eac' => $baselined->reduce(fn ($s, $i) => bcadd($s, (string) ($fin[$i->id]['eac'] ?? $i->bac), 2), '0.00'),
            'committed' => $lineIds ? $this->availability->committed($lineIds) : '0.00',
        ];

        $benefitRows = Benefit::withoutGlobalScopes()->whereIn('investment_id', $all->pluck('id'))->where('status', '!=', 'cancelled')->get()->groupBy('investment_id');
        $pcts = $this->benefits->pctsFor($all);
        $byInvestment = [];
        $planned = '0.00';
        $realised = '0.00';
        foreach ($all as $i) {
            $rows = $benefitRows->get($i->id);
            if (! $rows) {
                continue;
            }
            $m = $rows->where('unit', 'currency');
            $p = $m->reduce(fn ($s, $b) => bcadd($s, (string) $b->target_value, 2), '0.00');
            $r = $m->reduce(fn ($s, $b) => bcadd($s, (string) ($b->realised_value ?? '0'), 2), '0.00');
            $planned = bcadd($planned, $p, 2);
            $realised = bcadd($realised, $r, 2);
            $byInvestment[] = ['id' => $i->id, 'title' => $i->title, 'planned' => $p, 'realised' => $r, 'pct' => $pcts[$i->id] ?? null];
        }
        $withPct = array_filter($pcts, fn ($v) => $v !== null);
        $benefits = ['planned' => $planned, 'realised' => $realised, 'pct' => $withPct ? bcadd(bcdiv(array_reduce($withPct, fn ($s, $v) => bcadd($s, $v, 4), '0'), (string) count($withPct), 6), '0.005', 2) : null, 'by_investment' => $byInvestment];

        $atRisk = [];
        foreach ($all as $i) {
            $f = $fin[$i->id] ?? null;
            $health = $f['health'] ?? null;
            if (in_array($health, ['at_risk', 'off_track'], true) || $i->is_late) {
                $atRisk[] = [
                    'id' => $i->id, 'investment_code' => $i->investment_code, 'title' => $i->title, 'status' => $i->status, 'health' => $health ?? 'at_risk', 'cpi' => $f['cpi'] ?? null,
                    'days_late' => $i->is_late ? abs((int) $i->days_to_planned_end) : 0, 'href' => route('finance.investments.show', $i->id),
                ];
            }
        }

        $bubble = [];
        foreach ($all as $i) {
            $bc = $bcs->get($i->id);
            if ($i->priority_score !== null && $bc) {
                $bubble[] = ['id' => $i->id, 'label' => $i->investment_code, 'x' => $i->priority_score, 'y' => $bc->npv, 'z' => $value($i), 'rgt' => $i->rgt];
            }
        }

        $caps = [];
        $links = InvestmentLink::withoutGlobalScopes()->where('organization_id', $orgId)->where('linkable_type', Capability::class)->whereIn('investment_id', $all->pluck('id'))->get();
        if ($links->isNotEmpty()) {
            $names = Capability::withoutGlobalScopes()->whereIn('id', $links->pluck('linkable_id'))->pluck('name', 'id');
            foreach ($links->groupBy('linkable_id') as $cid => $g) {
                $caps[] = ['id' => (int) $cid, 'name' => $names[$cid] ?? "Capability {$cid}", 'investments' => $g->count(),
                    'bac' => $g->reduce(fn ($s, $l) => bcadd($s, $value($all->firstWhere('id', $l->investment_id) ?? new Investment), 2), '0.00')];
            }
        }

        return [
            'fiscal_year_id' => $fy?->id, 'envelope' => ['default' => $default, 'used' => $used, 'source' => $manual ? 'manual' : 'grow_transform_baseline'],
            'ranking' => $ranking, 'splits' => ['rgt' => $rgt, 'driver' => $driver, 'regulatory' => $regulatory], 'pipeline' => $pipeline, 'costs' => $costs,
            'benefits' => $benefits, 'atRisk' => $atRisk, 'bubble' => $bubble, 'capabilities' => $caps,
        ];
    }

    /** Change-the-bank budget of the year: the current baseline amount of grow and transform lines. */
    public function defaultEnvelope(int $orgId, int $fiscalYearId): string
    {
        $ids = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('rgt', ['grow', 'transform'])
            ->whereIn('budget_id', Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->where('status', 'baseline')->select('id'))->pluck('id')->all();

        return $this->financials->allocated($ids);
    }

    /** @param list<int>|null $costCentreIds @return array<string,mixed> */
    public function summary(int $orgId, ?array $costCentreIds = null): array
    {
        $all = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('stage', Investment::OPEN_STAGES)
            ->when($costCentreIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $costCentreIds ?: [0]))->get();
        $fin = $this->financials->forInvestments($all);
        $byStage = [];
        foreach (Investment::OPEN_STAGES as $stage) {
            $g = $all->where('stage', $stage);
            $g->isNotEmpty() && $byStage[] = ['stage' => $stage, 'count' => $g->count(), 'bac' => $g->reduce(fn ($s, $i) => bcadd($s, (string) ($i->bac ?? '0'), 2), '0.00')];
        }
        $baselined = $all->filter(fn ($i) => $i->bac !== null);
        $rgt = [];
        foreach (Investment::RGT as $k) {
            $rgt[$k] = $all->where('rgt', $k)->reduce(fn ($s, $i) => bcadd($s, (string) ($i->bac ?? $i->planned_cost ?? '0'), 2), '0.00');
        }
        $total = array_reduce($rgt, fn ($s, $v) => bcadd($s, $v, 2), '0.00');
        $regBac = $all->where('is_regulatory', true)->reduce(fn ($s, $i) => bcadd($s, (string) ($i->bac ?? $i->planned_cost ?? '0'), 2), '0.00');
        $pcts = array_filter($this->benefits->pctsFor($all), fn ($v) => $v !== null);
        $today = FinanceClock::today();
        $reviews = ValueReview::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('investment_id', $all->pluck('id'))->whereIn('status', ['due', 'overdue'])->get();

        return [
            'by_stage' => $byStage,
            'benefits_realised_pct' => $pcts ? bcadd(bcdiv(array_reduce($pcts, fn ($s, $v) => bcadd($s, $v, 4), '0'), (string) count($pcts), 6), '0.005', 2) : null,
            'at_risk' => $all->filter(fn ($i) => in_array($fin[$i->id]['health'] ?? null, ['at_risk', 'off_track'], true) || $i->is_late)->count(),
            'reviews_due' => $reviews->where('status', 'due')->count(), 'reviews_overdue' => $reviews->where('status', 'overdue')->count(),
            'bac_total' => $baselined->reduce(fn ($s, $i) => bcadd($s, (string) $i->bac, 2), '0.00'),
            'ac_total' => $baselined->reduce(fn ($s, $i) => bcadd($s, $fin[$i->id]['ac'], 2), '0.00'),
            'eac_total' => $baselined->reduce(fn ($s, $i) => bcadd($s, (string) ($fin[$i->id]['eac'] ?? $i->bac), 2), '0.00'),
            'rgt_split' => $rgt, 'regulatory_share_pct' => $this->share($regBac, $total), 'as_of' => $today->toDateString(),
        ];
    }

    private function share(string $part, string $total): ?string
    {
        return bccomp($total, '0', 2) > 0 ? bcadd(bcdiv(bcmul($part, '100', 6), $total, 6), '0.005', 2) : null;
    }
}
