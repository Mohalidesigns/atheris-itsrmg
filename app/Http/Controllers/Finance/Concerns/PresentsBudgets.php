<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Models\Asset;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\Driver;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Period;
use App\Models\LegalEntity;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/** Shapes shared by the budgeting pages (props contract, plan §6). Decimals stay strings. */
trait PresentsBudgets
{
    protected function userMini(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name] : null;
    }

    /** @return array<string,mixed> */
    protected function budgetSummary(Budget $b, ?array $adjustmentsByBudget = null): array
    {
        $adj = $adjustmentsByBudget !== null ? ($adjustmentsByBudget[$b->id] ?? '0.00') : $this->adjustmentsByBudget([$b->id])[$b->id] ?? '0.00';
        $b->loadMissing(['fiscalYear:id,year_label', 'cycle', 'costCentre:id,code,name', 'legalEntity:id,code,name', 'owner:id,name']);

        return [
            'id' => $b->id, 'budget_code' => $b->budget_code, 'version_no' => $b->version_no, 'version_label' => $b->version_label, 'title' => $b->title,
            'status' => $b->status, 'mode' => $b->mode,
            'fiscal_year' => $b->fiscalYear ? ['id' => $b->fiscalYear->id, 'year_label' => $b->fiscalYear->year_label] : null,
            'cycle' => $b->cycle ? ['id' => $b->cycle->id, 'cycle_code' => $b->cycle->cycle_code, 'name' => $b->cycle->name, 'type' => $b->cycle->type, 'status' => $b->cycle->status] : null,
            'cost_centre' => $b->costCentre ? ['id' => $b->costCentre->id, 'code' => $b->costCentre->code, 'name' => $b->costCentre->name] : null,
            'legal_entity' => $b->legalEntity ? ['id' => $b->legalEntity->id, 'code' => $b->legalEntity->code, 'name' => $b->legalEntity->name] : null,
            'owner' => $this->userMini($b->owner),
            'total_capex' => (string) $b->total_capex, 'total_opex' => (string) $b->total_opex, 'total_base_amount' => (string) $b->total_base_amount,
            'adjustments_base' => $adj, 'current_base_amount' => bcadd((string) $b->total_base_amount, $adj, 2),
            'amount_band' => $b->amount_band, 'amount_band_label' => $b->amount_band_label,
            'submitted_at' => $b->submitted_at?->toIso8601String(), 'approved_at' => $b->approved_at?->toIso8601String(), 'baselined_at' => $b->baselined_at?->toIso8601String(),
            'is_locked' => $b->is_locked, 'updated_at' => $b->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Sum of applied adjustments (base currency) per budget, in one grouped query.
     *
     * @param  array<int, int>  $budgetIds
     * @return array<int, string> budget_id => adjustments_base
     */
    protected function adjustmentsByBudget(array $budgetIds): array
    {
        if (! $budgetIds) {
            return [];
        }
        $rows = BudgetAdjustment::query()->join('fin_budget_lines as l', 'l.id', '=', 'fin_budget_adjustments.budget_line_id')
            ->whereIn('l.budget_id', $budgetIds)->selectRaw('l.budget_id as budget_id, sum(fin_budget_adjustments.base_amount) as total')->groupBy('l.budget_id')->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->budget_id => bcadd((string) $r->total, '0', 2)])->all();
    }

    /** @return Collection<int, Period> */
    protected function periodsOf(Budget $b): Collection
    {
        return Period::query()->where('fiscal_year_id', $b->fiscal_year_id)->where('is_adjustment', false)->orderBy('sequence')->get();
    }

    /** @return array<int, array<string,mixed>> */
    protected function periodsMini(Collection $periods): array
    {
        return $periods->map(fn (Period $p) => ['id' => $p->id, 'sequence' => $p->sequence, 'name' => $p->name,
            'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(), 'status' => $p->status])->values()->all();
    }

    /**
     * @param  array<int>  $overIds
     * @return array<int, array<string,mixed>>
     */
    protected function linesPayload(Budget $b, array $overIds = []): array
    {
        $lines = $b->lines()->get();
        $ids = $lines->pluck('id');
        $periods = BudgetLinePeriod::query()->whereIn('budget_line_id', $ids)->get()->groupBy('budget_line_id');
        $adj = BudgetAdjustment::query()->whereIn('budget_line_id', $ids)->selectRaw('budget_line_id, sum(base_amount) as total')->groupBy('budget_line_id')->pluck('total', 'budget_line_id');
        $adjPeriods = BudgetAdjustment::query()->whereIn('budget_line_id', $ids)->selectRaw('budget_line_id, period_id, sum(base_amount) as total')
            ->groupBy('budget_line_id', 'period_id')->get()->groupBy('budget_line_id');
        $cats = Category::query()->whereIn('id', $lines->pluck('cost_pool_id')->merge($lines->pluck('tower_id'))->merge($lines->pluck('category_id'))->filter()->unique())->get()->keyBy('id');
        $gls = GlAccount::query()->whereIn('id', $lines->pluck('gl_account_id')->filter()->unique())->get()->keyBy('id');
        $vendors = Vendor::query()->whereIn('id', $lines->pluck('vendor_id')->filter()->unique())->get()->keyBy('id');
        $label = fn ($c) => $c ? $c->code.' '.$c->name : null;

        return $lines->map(function (BudgetLine $l) use ($periods, $adj, $adjPeriods, $cats, $gls, $vendors, $overIds, $label) {
            $rows = $periods->get($l->id, collect());
            $adjBase = bcadd((string) ($adj[$l->id] ?? '0'), '0', 2);
            $adjByPeriod = ($adjPeriods->get($l->id) ?? collect())->mapWithKeys(fn ($r) => [$r->period_id => (string) $r->total]);
            $py = $l->py_budget === null ? null : (string) $l->py_budget;

            return [
                'id' => $l->id, 'line_no' => $l->line_no, 'source_line_id' => $l->source_line_id, 'origin' => $l->origin, 'description' => $l->description,
                'cost_pool_id' => $l->cost_pool_id, 'tower_id' => $l->tower_id, 'category_id' => $l->category_id, 'gl_account_id' => $l->gl_account_id,
                'cost_centre_id' => $l->cost_centre_id, 'legal_entity_id' => $l->legal_entity_id,
                'is_capex' => $l->is_capex, 'is_recurring' => $l->is_recurring, 'priority' => $l->priority, 'rgt' => $l->rgt,
                'vendor_id' => $l->vendor_id, 'contract_id' => $l->contract_id, 'asset_id' => $l->asset_id, 'ea_application_id' => $l->ea_application_id,
                'business_service_id' => $l->business_service_id, 'investment_id' => $l->investment_id, 'risk_id' => $l->risk_id, 'driver_id' => $l->driver_id,
                'driver_rate' => $l->driver_rate === null ? null : (string) $l->driver_rate, 'quantity' => (string) $l->quantity,
                'unit_cost' => $l->unit_cost === null ? null : (string) $l->unit_cost,
                'currency' => $l->currency, 'fx_rate' => (string) $l->fx_rate, 'fx_rate_type' => $l->fx_rate_type, 'fx_rate_date' => $l->fx_rate_date?->toDateString(),
                'annual_amount' => (string) $l->annual_amount, 'base_annual_amount' => (string) $l->base_annual_amount, 'phasing_method' => $l->phasing_method,
                'amounts' => $rows->mapWithKeys(fn ($p) => [$p->period_id => (string) $p->amount])->all(),
                'base_amounts' => $rows->mapWithKeys(fn ($p) => [$p->period_id => (string) $p->base_amount])->all(),
                'current_base_amounts' => $rows->mapWithKeys(fn ($p) => [$p->period_id => bcadd((string) $p->base_amount, (string) ($adjByPeriod[$p->period_id] ?? '0'), 2)])->all(),
                'justification' => $l->justification,
                'variance_amber_pct' => $l->variance_amber_pct === null ? null : (string) $l->variance_amber_pct,
                'variance_red_pct' => $l->variance_red_pct === null ? null : (string) $l->variance_red_pct,
                'py_budget' => $py, 'py_actual' => $l->py_actual === null ? null : (string) $l->py_actual,
                'yoy_pct' => $py === null || bccomp($py, '0', 2) === 0 ? null : bcmul(bcdiv(bcsub((string) $l->base_annual_amount, $py, 6), $py, 8), '100', 2),
                'adjustments_base' => $adjBase, 'current_base_amount' => bcadd((string) $l->base_annual_amount, $adjBase, 2),
                'over_ceiling' => in_array($l->id, $overIds, true), 'sort_order' => $l->sort_order,
                'labels' => [
                    'cost_pool' => $label($cats[$l->cost_pool_id] ?? null), 'tower' => $label($cats[$l->tower_id] ?? null), 'category' => $label($cats[$l->category_id] ?? null),
                    'gl' => ($g = $gls[$l->gl_account_id] ?? null) ? $g->code.' '.$g->name : null, 'vendor' => $vendors[$l->vendor_id]->name ?? null,
                ],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string,mixed>>  $lines
     * @return array<string,mixed>
     */
    protected function totalsPayload(array $lines): array
    {
        $sum = fn (array $vals) => array_reduce($vals, fn ($c, $v) => bcadd($c, (string) $v, 2), '0');
        $byPeriod = [];
        $byPeriodOriginal = [];
        $byPriority = [];
        $byRgt = [];
        $py = '0';
        $hasPy = false;
        foreach ($lines as $l) {
            foreach ($l['base_amounts'] as $pid => $v) {
                $byPeriodOriginal[$pid] = bcadd($byPeriodOriginal[$pid] ?? '0', $v, 2);
            }
            foreach ($l['current_base_amounts'] as $pid => $v) {
                $byPeriod[$pid] = bcadd($byPeriod[$pid] ?? '0', $v, 2);
            }
            $byPriority[$l['priority']] = bcadd($byPriority[$l['priority']] ?? '0', $l['current_base_amount'], 2);
            $byRgt[$l['rgt']] = bcadd($byRgt[$l['rgt']] ?? '0', $l['current_base_amount'], 2);
            if ($l['py_budget'] !== null) {
                $hasPy = true;
                $py = bcadd($py, $l['py_budget'], 2);
            }
        }
        $capex = $sum(array_column(array_filter($lines, fn ($l) => $l['is_capex']), 'base_annual_amount'));
        $opex = $sum(array_column(array_filter($lines, fn ($l) => ! $l['is_capex']), 'base_annual_amount'));
        $base = bcadd($capex, $opex, 2);
        $capexCurrent = $sum(array_column(array_filter($lines, fn ($l) => $l['is_capex']), 'current_base_amount'));
        $opexCurrent = $sum(array_column(array_filter($lines, fn ($l) => ! $l['is_capex']), 'current_base_amount'));

        return [
            'capex' => $capex, 'opex' => $opex, 'base' => $base, 'py_budget' => $hasPy ? $py : null,
            'yoy_pct' => $hasPy && bccomp($py, '0', 2) !== 0 ? bcmul(bcdiv(bcsub($base, $py, 6), $py, 8), '100', 2) : null,
            'adjustments_base' => $sum(array_column($lines, 'adjustments_base')), 'current_base' => $sum(array_column($lines, 'current_base_amount')),
            'capex_current' => $capexCurrent, 'opex_current' => $opexCurrent, 'by_period_original' => $byPeriodOriginal,
            'by_period' => $byPeriod, 'by_priority' => $byPriority, 'by_rgt' => $byRgt,
        ];
    }

    /** @return array<string,mixed> */
    protected function dimensionsPayload(int $orgId, string $baseCurrency): array
    {
        $cats = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get();
        $pick = fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name];

        return [
            'costPools' => $cats->where('kind', 'cost_pool')->map(fn ($c) => $pick($c) + ['default_capex' => $c->default_capex, 'default_gl_account_id' => $c->default_gl_account_id])->values()->all(),
            'towers' => $cats->where('kind', 'tower')->map($pick)->values()->all(),
            'subCategories' => $cats->where('kind', 'sub_category')->map(fn ($c) => $pick($c) + ['parent_id' => $c->parent_id])->values()->all(),
            'glAccounts' => GlAccount::query()->where('is_active', true)->orderBy('code')->get()->map(fn ($g) => ['id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'type' => $g->type])->values()->all(),
            'vendors' => Vendor::query()->where('organization_id', $orgId)->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values()->all(),
            'assets' => Asset::query()->where('organization_id', $orgId)->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values()->all(),
            'eaApplications' => EaApplication::query()->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values()->all(),
            'businessServices' => BusinessService::query()->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values()->all(),
            'risks' => Risk::query()->where('organization_id', $orgId)->orderBy('title')->limit(500)->get(['id', 'title'])->map(fn ($v) => ['id' => $v->id, 'title' => $v->title])->values()->all(),
            'drivers' => Driver::query()->where('is_active', true)->orderBy('code')->get()->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'unit' => $d->unit])->values()->all(),
            'currencies' => Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get()->map(fn ($c) => ['code' => $c->code, 'decimals' => $c->decimals, 'is_base' => $c->is_base])->values()->all(),
            'legalEntities' => LegalEntity::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->name])->values()->all(),
            'contracts' => Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', array_merge(['draft'], Contract::LIVE))
                ->orderBy('contract_code')->get(['id', 'contract_code', 'title'])->map(fn ($c) => ['id' => $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title])->values()->all(),
            'investments' => [],
            'priorities' => BudgetLine::PRIORITIES, 'rgt' => BudgetLine::RGT, 'phasingMethods' => BudgetLine::PHASING, 'fxRateTypes' => FxRate::RATE_TYPES,
        ];
    }

    /** Cost centres the user may plan for. */
    protected function visibleCostCentres(User $user, bool $withOwner = false): array
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);

        return CostCentre::query()->where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('code')->get()
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name] + ($withOwner ? ['owner_id' => $c->owner_id, 'legal_entity_id' => $c->legal_entity_id] : []))->values()->all();
    }

    protected function fiscalYearsPayload(): array
    {
        return FiscalYear::query()->orderByDesc('starts_on')->get()->map(fn ($f) => ['id' => $f->id, 'year_label' => $f->year_label, 'status' => $f->status, 'is_current' => $f->is_current])->values()->all();
    }

    protected function usersPayload(int $orgId): array
    {
        return User::query()->where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'job_title'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title])->values()->all();
    }

    private const MONEY_KEYS = [
        'total_capex', 'total_opex', 'total_base_amount', 'annual_amount', 'base_annual_amount', 'unit_cost', 'py_budget', 'py_actual', 'yoy_pct',
        'adjustments_base', 'current_base_amount', 'capex', 'opex', 'base', 'current_base', 'capex_ceiling', 'opex_ceiling', 'capex_requested',
        'opex_requested', 'capex_pct', 'opex_pct', 'amount', 'base_amount', 'total_base', 'requested_base', 'ceiling_total', 'total_baseline_base',
        'total_in_flight_base', 'applied_base', 'capex_delta', 'opex_delta', 'base_delta',
        'total_baseline_adjustments_base', 'total_baseline_current_base', 'capex_current', 'opex_current',
        'original_delta', 'revision_delta', 'current_delta', 'original_base', 'amount_band', 'amount_band_label', 'driver_rate',
    ];

    private const MONEY_MAPS = ['amounts', 'base_amounts', 'by_period', 'by_priority', 'by_rgt', 'available_by_period', 'current_base_amounts', 'by_period_original', 'adjustments'];

    /** Renders a budget page; without `view it-finance-amounts` every amount is removed server-side. */
    protected function renderMasked(string $component, array $props)
    {
        $user = request()->user();
        $can = $user->can('view it-finance-amounts');
        $props['can'] = array_merge((array) ($props['can'] ?? []), ['viewAmounts' => $can]);
        if (! $can) {
            $props = $this->maskMoney(json_decode(json_encode($props), true));
        }

        return Inertia::render($component, $props);
    }

    protected function maskMoney(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::MONEY_MAPS, true)) {
                $data[$k] = [];
            } elseif ($k === 'band' && is_array($v)) { // the DoA band snapshot on an approval request reveals the amount range
                $data[$k] = ['label' => null, 'min' => null, 'max' => null];
            } elseif (is_string($k) && in_array($k, self::MONEY_KEYS, true) && ! is_array($v)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->maskMoney($v);
            }
        }

        return $data;
    }
}
