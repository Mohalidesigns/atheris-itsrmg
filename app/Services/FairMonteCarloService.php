<?php

namespace App\Services;

use App\Models\FairScenario;

/**
 * FAIR Monte Carlo over a scenario's calibrated ranges.
 *
 * Per iteration (one simulated year):
 *   λ      ~ Triangular(frequency.min, frequency.most, frequency.max)   loss events / year
 *   events ~ Poisson(λ)
 *   loss   = Σ_events Triangular(magnitude.min, most, max) × (1 − control effectiveness)
 *
 * The annual-loss distribution gives mean / median / P95 / P99 ALE and a 20-bin histogram.
 */
class FairMonteCarloService
{
    public const HISTOGRAM_BINS = 20;

    public function simulate(FairScenario $scenario, ?int $seed = null): array
    {
        $freq = $this->range($scenario->frequency_distribution, 0.1, 0.5, 2);
        $mag = $this->range($scenario->magnitude_distribution, 10_000_000, 100_000_000, 1_000_000_000);
        $controls = min(100, max(0, (float) ($scenario->control_effectiveness['percent'] ?? 0))) / 100;
        $iterations = max(1000, min(100_000, (int) ($scenario->iterations ?: 10_000)));

        mt_srand($seed ?? random_int(1, PHP_INT_MAX));

        $annual = [];
        $eventLosses = 0.0;
        $eventCount = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $events = $this->poisson($this->triangular(...$freq));
            $year = 0.0;
            for ($e = 0; $e < $events; $e++) {
                $loss = $this->triangular(...$mag) * (1 - $controls);
                $year += $loss;
                $eventLosses += $loss;
                $eventCount++;
            }
            $annual[] = $year;
        }
        mt_srand();

        sort($annual);
        $p99 = $this->percentile($annual, 0.99);

        return [
            'ale_mean_ngn' => round(array_sum($annual) / $iterations, 2),
            'ale_median_ngn' => round($this->percentile($annual, 0.50), 2),
            'ale_p95_ngn' => round($this->percentile($annual, 0.95), 2),
            'ale_p99_ngn' => round($p99, 2),
            // Mean loss of a single event after controls — the register's SLE.
            'sle_ngn' => round($eventCount ? $eventLosses / $eventCount : 0, 2),
            'histogram' => $this->histogram($annual, $p99),
            'iterations' => $iterations,
        ];
    }

    /** [min, most likely, max] with sane ordering and defaults. */
    private function range(?array $d, float $min, float $most, float $max): array
    {
        $values = [(float) ($d['min'] ?? $min), (float) ($d['most'] ?? $most), (float) ($d['max'] ?? $max)];
        sort($values);

        return $values;
    }

    private function uniform(): float
    {
        return (mt_rand() + 0.5) / (mt_getrandmax() + 1.0);
    }

    private function triangular(float $a, float $c, float $b): float
    {
        if ($b <= $a) {
            return $a;
        }
        $u = $this->uniform();
        $f = ($c - $a) / ($b - $a);

        return $u < $f
            ? $a + sqrt($u * ($b - $a) * ($c - $a))
            : $b - sqrt((1 - $u) * ($b - $a) * ($b - $c));
    }

    /** Knuth for small rates, normal approximation above 30 events/year. */
    private function poisson(float $lambda): int
    {
        if ($lambda <= 0) {
            return 0;
        }
        if ($lambda > 30) {
            $z = sqrt(-2 * log($this->uniform())) * cos(2 * M_PI * $this->uniform());

            return max(0, (int) round($lambda + sqrt($lambda) * $z));
        }
        $l = exp(-$lambda);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= $this->uniform();
        } while ($p > $l);

        return $k - 1;
    }

    private function percentile(array $sorted, float $q): float
    {
        $idx = ($q * (count($sorted) - 1));
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);

        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($idx - $lo);
    }

    /** Counts per bin over [0, P99]; the tail beyond P99 folds into the last bin. */
    private function histogram(array $values, float $upper): array
    {
        $bins = array_fill(0, self::HISTOGRAM_BINS, 0);
        $width = $upper > 0 ? $upper / self::HISTOGRAM_BINS : 1;
        foreach ($values as $v) {
            $bins[min(self::HISTOGRAM_BINS - 1, (int) floor($v / $width))]++;
        }

        return $bins;
    }
}
