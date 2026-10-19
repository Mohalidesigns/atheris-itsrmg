<?php

namespace App\Services\Finance;

use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudAnomaly;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cloud cost anomaly detection (plan P6-4). A day is anomalous when its cost beats the mean of the previous seven days, or the same
 * weekday a week before, by the percentage threshold and the absolute floor. It runs on billing-currency cost, so an FX move is no anomaly.
 */
class CloudAnomalyService
{
    use LogsFinanceEvents;

    public const TRANSITIONS = [
        'acknowledge' => ['from' => ['open'], 'to' => 'acknowledged'],
        'resolve' => ['from' => ['open', 'acknowledged'], 'to' => 'resolved'],
        'dismiss' => ['from' => ['open', 'acknowledged'], 'to' => 'false_positive'],
    ];

    public function __construct(private readonly FinanceSettings $settings, private readonly CurrencyService $currency) {}

    /** @return array{pct:string,abs_floor:string,baseline_days:int,min_baseline_days:int,lookback_days:int} */
    public function thresholds(int $orgId): array
    {
        return $this->settings->cloudAnomalyThresholds($orgId);
    }

    /**
     * Pure test of one day. `$dailyCosts` maps Y-m-d to the day's cost in one currency; the floor is in that currency too.
     *
     * @param  array<string,string>  $dailyCosts
     * @param  array{pct:string|int|float,abs_floor:string|int|float,baseline_days?:int,min_baseline_days?:int}  $thresholds
     * @return array{method:string,baseline:string,observed:string,deviation_pct:string}|null
     */
    public static function evaluate(array $dailyCosts, string $day, array $thresholds): ?array
    {
        if (! array_key_exists($day, $dailyCosts)) {
            return null;
        }
        $observed = (string) $dailyCosts[$day];
        $span = (int) ($thresholds['baseline_days'] ?? 7);
        $min = (int) ($thresholds['min_baseline_days'] ?? 5);
        $d = CarbonImmutable::parse($day);

        $found = [];
        $sum = '0';
        $n = 0;
        for ($i = 1; $i <= $span; $i++) {
            $k = $d->subDays($i)->toDateString();
            if (array_key_exists($k, $dailyCosts)) {
                $sum = bcadd($sum, (string) $dailyCosts[$k], 6);
                $n++;
            }
        }
        $candidates = [];
        $n >= $min && $candidates['rolling_7d'] = bcdiv($sum, (string) $n, 6);
        $wow = $d->subDays(7)->toDateString();
        array_key_exists($wow, $dailyCosts) && $candidates['week_over_week'] = (string) $dailyCosts[$wow];

        foreach ($candidates as $method => $baseline) {
            if (bccomp($baseline, '0', 6) <= 0) {
                continue;
            }
            $rise = bcsub($observed, $baseline, 6);
            $pct = bcmul(bcdiv($rise, $baseline, 8), '100', 4);
            if (bccomp($pct, (string) $thresholds['pct'], 4) >= 0 && bccomp($rise, (string) $thresholds['abs_floor'], 6) >= 0) {
                $found[$method] = ['method' => $method, 'baseline' => bcadd($baseline, '0', 2), 'observed' => bcadd($observed, '0', 2), 'deviation_pct' => bcadd($pct, '0', 2), '_pct' => $pct];
            }
        }
        if (! $found) {
            return null;
        }
        uasort($found, fn ($a, $b) => bccomp($b['_pct'], $a['_pct'], 4));
        $best = array_values($found)[0];
        unset($best['_pct']);

        return $best;
    }

    /** Tests the last lookback days of one account; returns the anomalies newly created by this run. @return Collection<int,CloudAnomaly> */
    public function detect(CloudAccount $a, ?CarbonInterface $asOf = null): Collection
    {
        $asOf = ($asOf ?? FinanceClock::today())->startOfDay();
        $orgId = (int) $a->organization_id;
        $t = $this->thresholds($orgId);
        $from = $asOf->subDays($t['lookback_days'] + $t['baseline_days'])->toDateString();
        $to = $asOf->subDay()->toDateString();
        $base = $this->settings->baseCurrency($orgId);
        $new = collect();

        $series = ['account' => ['*' => []], 'service' => [], 'application' => []];
        $baseSeries = ['account' => ['*' => []], 'service' => [], 'application' => []];
        $rows = DB::table('fin_cloud_cost_lines')->where('cloud_account_id', $a->id)->whereDate('usage_date', '>=', $from)->whereDate('usage_date', '<=', $to)
            ->selectRaw('usage_date as d, service, ea_application_id as app, SUM(cost) as cost, SUM(base_cost) as base_cost')->groupBy('usage_date', 'service', 'ea_application_id')->get();
        foreach ($rows as $r) {
            $d = substr((string) $r->d, 0, 10);
            $targets = [['account', '*'], ['service', (string) $r->service]];
            $r->app !== null && $targets[] = ['application', (string) $r->app];
            foreach ($targets as [$scope, $ref]) {
                $series[$scope][$ref][$d] = bcadd($series[$scope][$ref][$d] ?? '0', (string) $r->cost, 6);
                $baseSeries[$scope][$ref][$d] = bcadd($baseSeries[$scope][$ref][$d] ?? '0', (string) $r->base_cost, 2);
            }
        }

        for ($i = 1; $i <= $t['lookback_days']; $i++) {
            $day = $asOf->subDays($i)->toDateString();
            $rate = $a->currency === $base ? '1' : ($this->currency->quote($orgId, $a->currency, $base, CarbonImmutable::parse($day), 'spot')['rate'] ?? null);
            if ($rate === null || bccomp($rate, '0', 8) <= 0) {
                continue;
            }
            $floor = bcdiv((string) $t['abs_floor'], $rate, 6); // the floor is in base currency; the test runs in billing currency
            foreach ($series as $scope => $refs) {
                foreach ($refs as $ref => $daily) {
                    $hit = self::evaluate($daily, $day, ['pct' => $t['pct'], 'abs_floor' => $floor, 'baseline_days' => $t['baseline_days'], 'min_baseline_days' => $t['min_baseline_days']]);
                    if (! $hit) {
                        continue;
                    }
                    $obsBase = $baseSeries[$scope][$ref][$day] ?? bcmul($hit['observed'], $rate, 2);
                    $baseBase = bcadd(bcmul($hit['baseline'], $rate, 8), '0.005', 2);
                    $row = [
                        'method' => $hit['method'], 'baseline_cost' => $hit['baseline'], 'observed_cost' => $hit['observed'], 'baseline_base_cost' => $baseBase,
                        'observed_base_cost' => bcadd((string) $obsBase, '0', 2), 'deviation_pct' => $hit['deviation_pct'],
                    ];
                    $existing = CloudAnomaly::withoutGlobalScopes()->where('organization_id', $orgId)->where('cloud_account_id', $a->id)->whereDate('detected_on', $day)
                        ->where('scope', $scope)->where('scope_ref', $ref)->first();
                    if ($existing) {
                        $existing->status === 'open' && $existing->forceFill($row)->save();

                        continue;
                    }
                    $x = CloudAnomaly::withoutGlobalScopes()->create($row + [
                        'organization_id' => $orgId, 'cloud_account_id' => $a->id, 'detected_on' => $day, 'scope' => $scope, 'scope_ref' => $ref, 'status' => 'open', 'owner_id' => $a->owner_id,
                    ]);
                    $this->audit($x, null, 'detected', "Cloud cost anomaly on {$a->name} ({$scope} {$ref}) on {$day}: +{$hit['deviation_pct']} %", ['method' => $hit['method']]);
                    $new->push($x);
                }
            }
        }

        return $new;
    }

    /** @return Collection<int,CloudAnomaly> */
    public function detectForOrg(int $orgId, ?CarbonInterface $asOf = null): Collection
    {
        $new = collect();
        foreach (CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->get() as $a) {
            $new = $new->concat($this->detect($a, $asOf));
        }

        return $new->values();
    }

    public function acknowledge(CloudAnomaly $x, ?string $note, User $by): CloudAnomaly
    {
        $this->guard($x, 'acknowledge', $by);
        $x->forceFill(['status' => 'acknowledged', 'acknowledged_by_id' => $by->id, 'acknowledged_at' => now()] + ($note !== null && trim($note) !== '' ? ['note' => trim($note)] : []))->save();
        $this->audit($x, $by, 'acknowledged', "Anomaly {$x->label} acknowledged");

        return $x->refresh();
    }

    public function resolve(CloudAnomaly $x, string $note, User $by): CloudAnomaly
    {
        $this->guard($x, 'resolve', $by);
        $this->noteOf($note, 'Say what caused it and what was done');
        $x->forceFill(['status' => 'resolved', 'resolved_by_id' => $by->id, 'resolved_at' => now(), 'note' => trim($note)])->save();
        $this->audit($x, $by, 'resolved', "Anomaly {$x->label} resolved", ['note' => trim($note)]);

        return $x->refresh();
    }

    public function dismiss(CloudAnomaly $x, string $reason, User $by): CloudAnomaly
    {
        $this->guard($x, 'dismiss', $by);
        $this->noteOf($reason, 'Say why this is not a real anomaly');
        $x->forceFill(['status' => 'false_positive', 'resolved_by_id' => $by->id, 'resolved_at' => now(), 'note' => trim($reason)])->save();
        $this->audit($x, $by, 'dismissed', "Anomaly {$x->label} dismissed as a false positive", ['reason' => trim($reason)]);

        return $x->refresh();
    }

    private function guard(CloudAnomaly $x, string $action, User $by): void
    {
        (int) $x->organization_id === (int) $by->organization_id || $this->fail('That anomaly belongs to another bank.');
        in_array($x->status, self::TRANSITIONS[$action]['from'], true) || $this->fail("A {$x->status} anomaly cannot be {$action}d.");
    }

    private function noteOf(string $note, string $hint): void
    {
        mb_strlen(trim($note)) >= 10 || $this->fail("{$hint} (at least 10 characters).", 'note');
    }
}
