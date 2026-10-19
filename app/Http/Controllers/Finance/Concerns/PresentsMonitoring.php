<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\FinanceSettings;
use Inertia\Inertia;

/**
 * Props shaping for the monitoring, period-close, dashboard and report pages (plan §7). Without `view it-finance-amounts` every
 * amount, variance % and EAC figure is nulled server-side (plan P4-11); severity, status, counts and dates stay. Self-contained on
 * purpose: do not combine with the other Presents* traits, whose method names collide.
 */
trait PresentsMonitoring
{
    private const MON_MONEY_KEYS = [
        'budget', 'committed', 'actual', 'available', 'forecast', 'eac', 'eac_floor', 'eac_remaining_budget', 'eac_run_rate', 'baseline', 'variance', 'variance_pct', 'period_variance',
        'budget_ytd', 'actual_ytd', 'variance_ytd', 'budget_total', 'forecast_fy', 'forecast_variance_pct', 'unbudgeted_amount', 'actual_unallocated', 'burn_monthly', 'monthly', 'months_remaining',
        'exhaustion_on', 'amount', 'base_amount', 'remaining_base', 'ledger_actual', 'matrix_actual', 'difference', 'cumulative_budget', 'cumulative_actual', 'abs_floor',
        'capex', 'opex', 'run', 'grow', 'transform', 'cloud_mtd', 'budget_amount', 'actual_amount', 'variance_amount', 'eac_total',
    ];

    private const MON_MONEY_LISTS = ['by_period'];

    protected function renderMonitoring(string $component, array $props)
    {
        $user = request()->user();
        $can = $user->can('view it-finance-amounts');
        $props['baseCurrency'] ??= app(FinanceSettings::class)->baseCurrency((int) $user->organization_id);
        $props['can'] = array_merge((array) ($props['can'] ?? []), ['viewAmounts' => $can]);
        if (! $can) {
            $props = $this->maskMonitoring(json_decode(json_encode($props), true));
        }

        return Inertia::render($component, $props);
    }

    protected function maskMonitoring(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::MON_MONEY_LISTS, true)) {
                $data[$k] = [];
            } elseif (is_string($k) && in_array($k, self::MON_MONEY_KEYS, true) && ! is_array($v)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->maskMonitoring($v);
            }
        }

        return $data;
    }

    /** @return list<array<string,mixed>> */
    protected function fiscalYearsMini(int $orgId): array
    {
        return FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderByDesc('starts_on')->get()
            ->map(fn ($f) => ['id' => $f->id, 'year_label' => $f->year_label, 'status' => $f->status, 'is_current' => $f->is_current])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    protected function periodsMini(int $orgId, ?int $fiscalYearId): array
    {
        if (! $fiscalYearId) {
            return [];
        }

        return Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->orderBy('sequence')->get()
            ->map(fn (Period $p) => ['id' => $p->id, 'name' => $p->name, 'sequence' => $p->sequence, 'status' => $p->status, 'ends_on' => $p->ends_on->toDateString()])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    protected function visibleCostCentresMini(User $user): array
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);

        return CostCentre::withoutGlobalScopes()->where('organization_id', $user->organization_id)->whereNull('deleted_at')->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('code')->get()
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all();
    }

    protected function userMini(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name] : null;
    }
}
