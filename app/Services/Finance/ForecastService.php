<?php

namespace App\Services\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Forecast;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Estimate at completion (FIN-04.02, plan P4-7, P4-8). The live EAC is computed from the ledger on demand; fin_forecasts only
 * holds manual overrides and the snapshots taken when a period closes (C117). Periods that have not ended yet contribute no actuals.
 */
class ForecastService
{
    use LogsFinanceEvents;

    public function __construct(private readonly FinanceSettings $settings, private readonly BudgetAvailability $availability, private readonly BudgetPhasing $phasing) {}

    private function monitoring(): MonitoringService
    {
        return app(MonitoringService::class); // resolved lazily: MonitoringService depends on this class
    }

    /** @return array<string,mixed> */
    public function eac(BudgetLine $line, ?Period $asOf = null): array
    {
        $fy = (int) Budget::withoutGlobalScopes()->whereKey($line->budget_id)->value('fiscal_year_id');

        return $this->eacForLines(collect([$line]), $fy, $asOf)[(int) $line->id];
    }

    /**
     * @param  Collection<int, BudgetLine>  $lines
     * @return array<int, array<string,mixed>> line_id => eac()
     */
    public function eacForLines(Collection $lines, int $fiscalYearId, ?Period $asOf = null): array
    {
        if ($lines->isEmpty()) {
            return [];
        }
        $orgId = (int) $lines->first()->organization_id;
        $mon = $this->monitoring();
        $periods = $mon->periodsOf($orgId, $fiscalYearId);
        $asOf ??= $mon->asOfPeriod($orgId, $fiscalYearId);
        $regular = $periods->where('is_adjustment', false);
        $total = $regular->count();
        $elapsed = $asOf ? $regular->filter(fn ($p) => $p->sequence <= $asOf->sequence)->count() : 0;
        $figures = $mon->lineFigures($lines, $fiscalYearId);
        $avail = $this->availability->forLines($lines);
        $method = $this->settings->for($orgId)->eac_method ?: 'remaining_budget';
        $keys = $mon->lineage($orgId, $lines);
        $overrides = Forecast::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->activeOverride()
            ->whereIn('line_key', array_map(fn ($c) => (int) end($c), $keys))->with('setBy:id,name')->orderBy('id')->get()->keyBy('line_key');

        $out = [];
        foreach ($lines as $l) {
            $fg = $figures[$l->id];
            $budget = $fg['budget_fy'];
            $ytd = '0.00';
            $later = '0.00';
            foreach ($fg['by_period'] as $pid => $v) {
                if ($asOf && $periods[$pid]->sequence <= $asOf->sequence) {
                    $ytd = bcadd($ytd, $v['actual'], 2);
                } elseif ($asOf) {
                    $later = bcadd($later, $v['budget'], 2);
                } else {
                    $later = bcadd($later, $v['budget'], 2);
                }
            }
            // An estimate at completion can never sit below what is already booked in the year plus what is committed and not yet spent.
            $floor = bcadd($fg['actual_fy'], (string) ($avail[$l->id]['committed'] ?? '0'), 2);
            $remaining = $elapsed > 0 ? bcadd($ytd, $later, 2) : $budget;
            $runRate = $elapsed > 0 && $total > 0 ? $this->phasing->round(bcmul(bcdiv($ytd, (string) $elapsed, 8), (string) $total, 8), 2) : $budget;
            bccomp($remaining, $floor, 2) < 0 && $remaining = $floor;
            bccomp($runRate, $floor, 2) < 0 && $runRate = $floor;
            $manual = $overrides->get((int) end($keys[$l->id]));
            $chosen = $manual ? (string) $manual->base_amount : ($method === 'run_rate' ? $runRate : $remaining);
            bccomp($chosen, $floor, 2) < 0 && $chosen = $floor;
            $variance = bcsub($chosen, $budget, 2);
            $pct = bccomp($budget, '0', 2) !== 0 ? $this->phasing->round(bcmul(bcdiv($variance, $budget, 8), '100', 8), 2) : null;
            $burn = $elapsed > 0 ? $this->phasing->round(bcdiv($ytd, (string) $elapsed, 8), 2) : '0.00';
            $available = (string) ($avail[$l->id]['available'] ?? '0.00');
            $months = null;
            $exhaustion = null;
            if (bccomp($burn, '0', 2) > 0) {
                $months = bccomp($available, '0', 2) > 0 ? $this->phasing->round(bcdiv($available, $burn, 8), 2) : '0.00';
                $exhaustion = $asOf ? $asOf->ends_on->copy()->addDays((int) round((float) $months * 30.4375))->toDateString() : null;
            }
            $out[(int) $l->id] = [
                'method' => $manual ? 'manual' : $method, 'eac_remaining_budget' => $remaining, 'eac_run_rate' => $runRate,
                'manual' => $manual ? ['amount' => (string) $manual->base_amount, 'reason' => $manual->reason, 'set_by' => $manual->setBy?->name, 'set_at' => $manual->created_at?->toIso8601String()] : null,
                'eac' => bcadd($chosen, '0', 2), 'eac_floor' => $floor, 'baseline' => bcadd((string) $l->base_annual_amount, '0', 2), 'budget' => $budget, 'actual_ytd' => $ytd,
                'variance' => $variance, 'variance_pct' => $pct, 'periods_elapsed' => $elapsed, 'periods_total' => $total,
                'burn_monthly' => $burn, 'months_remaining' => $months, 'exhaustion_on' => $exhaustion,
            ];
        }

        return $out;
    }

    /** A manual EAC for a baseline line, with a reason; it replaces the computed figure until cleared (plan P4-7). */
    public function override(BudgetLine $line, string $baseAmount, string $reason, User $by): Forecast
    {
        $this->assertBaseline($line);
        $min = (int) config('finance.monitoring.override_reason_min', 10);
        $reason = trim($reason);
        mb_strlen($reason) >= $min || $this->fail("Give the reason for the override (at least {$min} characters).", 'reason');
        $v = trim($baseAmount);
        preg_match(FinanceSettings::DOA_AMOUNT, $v) || $this->fail('The forecast must be a plain number of at least 0 with up to 2 decimals.', 'amount');
        $v = bcadd($v, '0', 2);
        (int) $by->organization_id === (int) $line->organization_id || $this->fail('This line belongs to another bank.');
        $floor = $this->eac($line)['eac_floor'];
        bccomp($v, $floor, 2) < 0 && $this->fail("The forecast cannot be below the {$floor} already booked as actuals plus open commitments.", 'amount');
        $mon = $this->monitoring();
        $key = (int) end($mon->lineage((int) $line->organization_id, collect([$line]))[(int) $line->id]);
        $fy = (int) Budget::withoutGlobalScopes()->whereKey($line->budget_id)->value('fiscal_year_id');
        $asOf = $mon->asOfPeriod((int) $line->organization_id, $fy);
        $rate = (string) $line->fx_rate;

        return DB::transaction(function () use ($line, $v, $reason, $by, $key, $fy, $asOf, $rate) {
            Forecast::withoutGlobalScopes()->where('organization_id', $line->organization_id)->where('fiscal_year_id', $fy)->where('line_key', $key)
                ->activeOverride()->update(['cleared_at' => now(), 'cleared_by_id' => $by->id]);
            $f = Forecast::withoutGlobalScopes()->create([
                'organization_id' => $line->organization_id, 'created_by_id' => $by->id, 'fiscal_year_id' => $fy, 'budget_line_id' => $line->id, 'line_key' => $key,
                'period_id' => $asOf?->id, 'method' => 'manual', 'amount' => bccomp($rate, '0', 8) > 0 ? $this->phasing->round(bcdiv($v, $rate, 12), 2) : $v,
                'base_amount' => $v, 'reason' => $reason, 'set_by_id' => $by->id, 'is_snapshot' => false,
            ]);
            $this->audit($f, $by, 'forecast_override', "Manual forecast set on line #{$line->line_no}", ['reason' => $reason]);

            return $f;
        });
    }

    public function clear(BudgetLine $line, User $by, string $reason): void
    {
        $reason = trim($reason);
        $reason === '' && $this->fail('Give the reason for clearing the override.', 'reason');
        (int) $by->organization_id === (int) $line->organization_id || $this->fail('This line belongs to another bank.');
        $mon = $this->monitoring();
        $key = (int) end($mon->lineage((int) $line->organization_id, collect([$line]))[(int) $line->id]);
        $fy = (int) Budget::withoutGlobalScopes()->whereKey($line->budget_id)->value('fiscal_year_id');
        $rows = Forecast::withoutGlobalScopes()->where('organization_id', $line->organization_id)->where('fiscal_year_id', $fy)->where('line_key', $key)->activeOverride()->get();
        $rows->isEmpty() && $this->fail('This line has no manual forecast to clear.');
        foreach ($rows as $f) {
            $f->forceFill(['cleared_at' => now(), 'cleared_by_id' => $by->id])->save();
            $this->audit($f, $by, 'forecast_cleared', "Manual forecast cleared on line #{$line->line_no}", ['reason' => $reason]);
        }
    }

    /**
     * Stores one snapshot row per baseline line, as of the period being closed, at the EAC then in force (an active manual override is snapshotted
     * as `manual`). An earlier snapshot of the same period is kept as history, marked superseded (domain M5).
     */
    public function snapshot(Period $p, User $by, ?PeriodClose $close = null): int
    {
        $orgId = (int) $p->organization_id;
        $mon = $this->monitoring();
        $lines = $mon->baselineLines($orgId, (int) $p->fiscal_year_id);
        $eac = $this->eacForLines($lines, (int) $p->fiscal_year_id, $p);
        $keys = $mon->lineage($orgId, $lines);

        return DB::transaction(function () use ($p, $by, $orgId, $lines, $eac, $keys, $close) {
            Forecast::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)->where('is_snapshot', true)->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);
            $n = 0;
            foreach ($lines as $l) {
                $base = $eac[$l->id]['eac'];
                $rate = (string) $l->fx_rate;
                Forecast::withoutGlobalScopes()->create([
                    'organization_id' => $orgId, 'created_by_id' => $by->id, 'fiscal_year_id' => $p->fiscal_year_id, 'budget_line_id' => $l->id, 'line_key' => (int) end($keys[$l->id]),
                    'period_id' => $p->id, 'period_close_id' => $close?->id, 'method' => $eac[$l->id]['method'], 'amount' => bccomp($rate, '0', 8) > 0 ? $this->phasing->round(bcdiv($base, $rate, 12), 2) : $base,
                    'base_amount' => $base, 'reason' => $eac[$l->id]['manual']['reason'] ?? null, 'set_by_id' => $by->id, 'is_snapshot' => true,
                ]);
                $n++;
            }

            return $n;
        });
    }

    private function assertBaseline(BudgetLine $line): void
    {
        $b = Budget::withoutGlobalScopes()->whereKey($line->budget_id)->first(['status']);
        $b?->status === 'baseline' || $this->fail('Only a line of the current baseline can carry a forecast override.');
    }
}
