<?php

namespace App\Services;

class FairQuantificationService
{
    /**
     * Calculate Annual Loss Expectancy using FAIR model.
     *
     * LEF = TEF × Vulnerability
     * ALE = LEF × (PLM + SLM)
     */
    public function calculate(
        float $threatEventFrequency,
        float $vulnerability,
        float $primaryLossMagnitude,
        float $secondaryLossMagnitude = 0,
        string $currency = 'NGN'
    ): array {
        $lossEventFrequency = $threatEventFrequency * $vulnerability;
        $totalLossMagnitude = $primaryLossMagnitude + $secondaryLossMagnitude;
        $annualLossExpectancy = $lossEventFrequency * $totalLossMagnitude;
        $singleLossExpectancy = $totalLossMagnitude;

        return [
            'tef' => round($threatEventFrequency, 4),
            'vulnerability' => round($vulnerability, 4),
            'lef' => round($lossEventFrequency, 4),
            'plm' => round($primaryLossMagnitude, 2),
            'slm' => round($secondaryLossMagnitude, 2),
            'total_loss_magnitude' => round($totalLossMagnitude, 2),
            'sle' => round($singleLossExpectancy, 2),
            'ale' => round($annualLossExpectancy, 2),
            'currency' => $currency,
            'formatted_ale' => $this->formatCurrency($annualLossExpectancy, $currency),
            'formatted_sle' => $this->formatCurrency($singleLossExpectancy, $currency),
            'risk_band' => $this->getRiskBand($annualLossExpectancy, $currency),
        ];
    }

    public function formatCurrency(float $amount, string $currency = 'NGN'): string
    {
        $symbols = [
            'NGN' => "\u{20A6}",
            'USD' => '$',
            'GBP' => "\u{00A3}",
            'EUR' => "\u{20AC}",
            'GHS' => "GH\u{20B5}",
            'KES' => 'KSh',
            'ZAR' => 'R',
        ];

        $symbol = $symbols[$currency] ?? $currency.' ';

        if ($amount >= 1_000_000_000) {
            return $symbol.number_format($amount / 1_000_000_000, 1).'B';
        }
        if ($amount >= 1_000_000) {
            return $symbol.number_format($amount / 1_000_000, 1).'M';
        }
        if ($amount >= 1_000) {
            return $symbol.number_format($amount / 1_000, 1).'K';
        }

        return $symbol.number_format($amount, 2);
    }

    public function getRiskBand(float $ale, string $currency = 'NGN'): string
    {
        // Thresholds calibrated for NGN (adjust for other currencies)
        $multiplier = $currency === 'NGN' ? 1 : 1500; // rough NGN/USD rate

        $thresholds = [
            'critical' => 500_000_000 / $multiplier,
            'high' => 100_000_000 / $multiplier,
            'medium' => 10_000_000 / $multiplier,
            'low' => 1_000_000 / $multiplier,
        ];

        if ($ale >= $thresholds['critical']) {
            return 'critical';
        }
        if ($ale >= $thresholds['high']) {
            return 'high';
        }
        if ($ale >= $thresholds['medium']) {
            return 'medium';
        }
        if ($ale >= $thresholds['low']) {
            return 'low';
        }

        return 'very_low';
    }
}
