<?php

namespace App\Services\Ea;

use App\Models\Ea\EaApplication;
use App\Models\Ea\TechComponent;
use Illuminate\Support\Collection;

/**
 * FxExposureService — ATH-EAR-002 A3, §8.3: "Currency aggregation, stress test,
 * renewal calendar, localisation candidates."
 *
 * §6.3 A3 market condition: "Tier-1 banks spend **at least $10m annually on
 * core banking licences and support alone**, dollar-priced; naira devaluation
 * nearly doubled these costs and directly drove Sterling Bank's 2024 migration
 * to the indigenous **SeaBaaS** platform. Six major banks spent **₦268.7bn
 * (~$171.5m) on IT in 2024, up 74.5% year on year**."
 *
 * "Why this matters commercially. This is the artefact that sells the module to
 * the **CFO**, not the CISO or the CIO. It is generated entirely from EA data
 * and **no global EA tool has a currency dimension at all**."
 */
class FxExposureService
{
    /**
     * Reference rates. Configurable because they move constantly — a rate
     * compiled into code is a wrong number with a deployment cycle attached.
     */
    public function rates(): array
    {
        return config('ea.fx.rates', [
            'NGN' => 1.0,
            'USD' => 1550.0,
            'EUR' => 1680.0,
            'GBP' => 1960.0,
            'ZAR' => 85.0,
            'GHS' => 105.0,
        ]);
    }

    public function rateFor(?string $currency): float
    {
        return (float) ($this->rates()[strtoupper((string) $currency)] ?? 1.0);
    }

    /**
     * Convert to naira, or return null when the currency is not one we hold a
     * rate for.
     *
     * Null rather than a silent 1:1 fallback: Phase 4's cost roll-ups (B17) and
     * the impact summary both total this figure, and a dollar amount added to a
     * naira total as though it were naira understates the exposure by ~1,550×.
     * The callers report `unknown_currency` counts instead.
     */
    public function toNgn(?float $amount, ?string $currency): ?float
    {
        if ($amount === null) {
            return null;
        }

        $code = strtoupper((string) $currency);
        if ($code === '' || ! array_key_exists($code, $this->rates())) {
            return null;
        }

        return round($amount * $this->rateFor($code), 2);
    }

    /**
     * Everything with a cost, normalised to naira.
     *
     * Falls back to the legacy `annual_cost_ngn` column when no currency is
     * recorded — which is itself the finding: a dollar-priced core banking
     * licence booked in naira hides the exposure entirely.
     */
    private function costed(): Collection
    {
        return $this->cache ??= EaApplication::query()
            ->where(fn ($q) => $q->whereNotNull('annual_cost')->orWhereNotNull('annual_cost_ngn'))
            ->get()
            ->map(function ($a) {
                $currency = $a->cost_currency ? strtoupper($a->cost_currency) : null;
                $native = $a->annual_cost !== null ? (float) $a->annual_cost : null;

                // No currency recorded → treat the legacy naira column as naira,
                // but flag it so the dashboard can say how much of the estate
                // has an unknown currency exposure.
                if ($native === null || $currency === null) {
                    return [
                        'id' => $a->id,
                        'code' => $a->code,
                        'name' => $a->name,
                        'criticality' => $a->criticality,
                        'currency' => $currency ?? 'NGN',
                        'currency_known' => $currency !== null,
                        'native_cost' => $native ?? (float) $a->annual_cost_ngn,
                        'ngn_cost' => (float) ($a->annual_cost_ngn ?? 0),
                        'contract_end_date' => $a->contract_end_date,
                        'renewal_notice_days' => $a->renewal_notice_days,
                        'vendor_id' => $a->vendor_id,
                        'is_indigenous' => (bool) $a->is_indigenous,
                        'capability_ids' => $a->capability_ids ?? [],
                    ];
                }

                return [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                    'criticality' => $a->criticality,
                    'currency' => $currency,
                    'currency_known' => true,
                    'native_cost' => $native,
                    'ngn_cost' => $native * $this->rateFor($currency),
                    'contract_end_date' => $a->contract_end_date,
                    'renewal_notice_days' => $a->renewal_notice_days,
                    'vendor_id' => $a->vendor_id,
                    'is_indigenous' => (bool) $a->is_indigenous,
                    'capability_ids' => $a->capability_ids ?? [],
                ];
            });
    }

    private ?Collection $cache = null;

    /**
     * Report 1 — the FX exposure dashboard.
     *
     * §6.3 A3: "total annual application cost split by currency; naira
     * equivalent at current and stressed rates; exposure by business
     * capability; exposure by vendor."
     */
    public function dashboard(): array
    {
        $costed = $this->costed();
        $foreign = $costed->where('currency', '!=', 'NGN');

        $byCurrency = $costed->groupBy('currency')->map(fn ($rows, $currency) => [
            'currency' => $currency,
            'systems' => $rows->count(),
            'native_total' => round($rows->sum('native_cost'), 2),
            'ngn_total' => round($rows->sum('ngn_cost'), 2),
            'rate' => $this->rateFor($currency),
        ])->sortByDesc('ngn_total')->values();

        $total = $costed->sum('ngn_cost');
        $foreignTotal = $foreign->sum('ngn_cost');

        return [
            'total_ngn' => round($total, 2),
            'foreign_ngn' => round($foreignTotal, 2),
            'foreign_share' => $total > 0 ? round($foreignTotal / $total * 100, 1) : 0.0,
            'systems_costed' => $costed->count(),
            'systems_foreign' => $foreign->count(),
            // The honest caveat: how much of the estate has no currency at all.
            'unknown_currency' => $costed->where('currency_known', false)->count(),
            'by_currency' => $byCurrency->all(),
            'by_capability' => $this->byCapability($costed),
            'by_vendor' => $this->byVendor($costed),
            'indigenous_share' => $costed->count()
                ? round($costed->where('is_indigenous', true)->count() / $costed->count() * 100, 1)
                : 0.0,
        ];
    }

    private function byCapability(Collection $costed): array
    {
        $capabilities = \App\Models\Ea\Capability::get(['id', 'code', 'name'])->keyBy('id');
        $totals = [];

        foreach ($costed as $row) {
            foreach ($row['capability_ids'] as $capabilityId) {
                $totals[$capabilityId] ??= ['ngn' => 0.0, 'foreign_ngn' => 0.0, 'systems' => 0];
                $totals[$capabilityId]['ngn'] += $row['ngn_cost'];
                $totals[$capabilityId]['systems']++;
                if ($row['currency'] !== 'NGN') {
                    $totals[$capabilityId]['foreign_ngn'] += $row['ngn_cost'];
                }
            }
        }

        return collect($totals)
            ->map(fn ($t, $id) => [
                'capability' => $capabilities->get($id)?->name ?? "Capability #{$id}",
                'systems' => $t['systems'],
                'ngn_total' => round($t['ngn'], 2),
                'foreign_ngn' => round($t['foreign_ngn'], 2),
                'foreign_share' => $t['ngn'] > 0 ? round($t['foreign_ngn'] / $t['ngn'] * 100, 1) : 0.0,
            ])
            ->sortByDesc('foreign_ngn')->take(15)->values()->all();
    }

    private function byVendor(Collection $costed): array
    {
        $vendors = \App\Models\Vendor::get(['id', 'name'])->keyBy('id');

        return $costed->whereNotNull('vendor_id')
            ->groupBy('vendor_id')
            ->map(fn ($rows, $vendorId) => [
                'vendor' => $vendors->get($vendorId)?->name ?? "Vendor #{$vendorId}",
                'systems' => $rows->count(),
                'ngn_total' => round($rows->sum('ngn_cost'), 2),
                'foreign_ngn' => round($rows->where('currency', '!=', 'NGN')->sum('ngn_cost'), 2),
            ])
            ->sortByDesc('foreign_ngn')->take(15)->values()->all();
    }

    /**
     * Report 2 — the devaluation stress test.
     *
     * §6.3 A3: "'at ₦X/$ our application cost base is ₦Y' with a slider."
     *
     * The naira went from roughly ₦460/$ to over ₦1,500/$ across 2023–24, so
     * the default scenarios span a range this market has actually lived
     * through rather than a theoretical ±10%.
     */
    public function stressTest(array $scenarios = []): array
    {
        $scenarios = $scenarios ?: config('ea.fx.stress_scenarios', [1200, 1550, 1800, 2200, 2600]);
        $costed = $this->costed();

        $usdDenominated = $costed->where('currency', 'USD');
        $baseline = $this->rateFor('USD');
        $nonUsd = $costed->where('currency', '!=', 'USD')->sum('ngn_cost');

        return [
            'baseline_rate' => $baseline,
            'usd_native_total' => round($usdDenominated->sum('native_cost'), 2),
            'scenarios' => collect($scenarios)->map(function ($rate) use ($usdDenominated, $nonUsd, $baseline) {
                $usdNgn = $usdDenominated->sum('native_cost') * $rate;
                $total = $usdNgn + $nonUsd;
                $baselineTotal = $usdDenominated->sum('native_cost') * $baseline + $nonUsd;

                return [
                    'rate' => $rate,
                    'usd_cost_ngn' => round($usdNgn, 2),
                    'total_ngn' => round($total, 2),
                    'delta_ngn' => round($total - $baselineTotal, 2),
                    'delta_percent' => $baselineTotal > 0
                        ? round(($total - $baselineTotal) / $baselineTotal * 100, 1)
                        : 0.0,
                ];
            })->all(),
        ];
    }

    /**
     * Report 3 — the renewal calendar.
     *
     * §6.3 A3: "contracts expiring in the next 18 months, sorted by FX
     * exposure." A renewal is the only moment the price is negotiable, and the
     * notice period is when the decision actually has to be made.
     */
    public function renewalCalendar(int $months = 18): array
    {
        $horizon = now()->addMonths($months);

        return $this->costed()
            ->filter(fn ($r) => $r['contract_end_date'] !== null
                && $r['contract_end_date'] <= $horizon)
            ->map(function ($r) {
                $end = $r['contract_end_date'];
                $noticeDays = $r['renewal_notice_days'] ?? 90;
                $decisionBy = $end->copy()->subDays($noticeDays);

                return [
                    'application_id' => $r['id'],
                    'label' => "{$r['code']} — {$r['name']}",
                    'criticality' => $r['criticality'],
                    'currency' => $r['currency'],
                    'native_cost' => $r['native_cost'],
                    'ngn_cost' => round($r['ngn_cost'], 2),
                    'contract_end' => $end->toDateString(),
                    'notice_days' => $noticeDays,
                    'decision_by' => $decisionBy->toDateString(),
                    'days_to_decision' => (int) round(now()->diffInDays($decisionBy, false)),
                    'is_foreign_currency' => $r['currency'] !== 'NGN',
                ];
            })
            ->sortBy('days_to_decision')
            ->values()->all();
    }

    /**
     * Report 4 — localisation candidates.
     *
     * §6.3 A3: "dollar-denominated applications ranked by cost, criticality and
     * availability of an indigenous or naira-priced alternative."
     *
     * "Localisation" here is currency localisation — moving off a dollar-priced
     * platform — which is a different question from A2's data localisation.
     * The Sterling Bank / SeaBaaS migration is the worked example.
     */
    public function localisationCandidates(): array
    {
        return $this->costed()
            ->where('currency', '!=', 'NGN')
            ->where('is_indigenous', false)
            ->map(function ($r) {
                $vendor = $r['vendor_id'] ? \App\Models\Vendor::find($r['vendor_id']) : null;

                // Cost dominates, but a critical system is harder to move and a
                // vendor with in-market alternatives is easier.
                $costWeight = min(50, $r['ngn_cost'] / 20_000_000);
                $criticalityWeight = match ($r['criticality']) {
                    'critical' => 5, 'high' => 15, 'medium' => 25, default => 30,
                };
                $alternativesWeight = min(20, ($vendor?->in_market_alternatives ?? 0) * 5);

                return [
                    'application_id' => $r['id'],
                    'label' => "{$r['code']} — {$r['name']}",
                    'criticality' => $r['criticality'],
                    'currency' => $r['currency'],
                    'native_cost' => $r['native_cost'],
                    'ngn_cost' => round($r['ngn_cost'], 2),
                    'vendor' => $vendor?->name,
                    'vendor_origin' => $vendor?->origin_country,
                    'in_market_alternatives' => $vendor?->in_market_alternatives,
                    'substitutability_score' => $vendor?->substitutability_score,
                    'switching_notes' => $vendor?->switching_notes,
                    'candidate_score' => (int) round($costWeight + $criticalityWeight + $alternativesWeight),
                ];
            })
            ->sortByDesc('candidate_score')
            ->values()->all();
    }
}
