<?php

namespace App\Services\Ea;

use App\Models\Ea\Principle;
use App\Models\Ea\Standard;
use Illuminate\Support\Str;

/**
 * PrincipleImpactEngine — given the text of an ARB submission (subject,
 * summary, blast radius) the engine matches against known principles and
 * standards via keyword scoring. The output drives the ARB submission wizard's
 * "impacted principles / standards" tab.
 */
class PrincipleImpactEngine
{
    public function analyse(string $subject, string $summary, array $blastRadius = []): array
    {
        $haystack = strtolower($subject.' '.$summary.' '.implode(' ', array_map(fn ($n) => is_array($n) ? ($n['name'] ?? '') : (string) $n, $blastRadius)));

        $principles = Principle::query()->get();
        $standards = Standard::query()->get();

        $impactedPrinciples = $principles
            ->map(fn ($p) => [
                'principle' => $p,
                'score' => $this->score($p->name.' '.$p->statement.' '.$p->rationale.' '.$p->implications, $haystack),
            ])
            ->filter(fn ($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->take(10)
            ->values();

        $impactedStandards = $standards
            ->map(fn ($s) => [
                'standard' => $s,
                'score' => $this->score($s->name.' '.$s->description.' '.$s->category, $haystack),
            ])
            ->filter(fn ($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->take(10)
            ->values();

        return [
            'impacted_principles' => $impactedPrinciples,
            'impacted_standards' => $impactedStandards,
            'risk_band' => $this->bandFor(
                $impactedPrinciples->count() + $impactedStandards->count() + count($blastRadius)
            ),
        ];
    }

    private function score(string $candidate, string $haystack): int
    {
        $words = collect(explode(' ', strtolower($candidate)))
            ->filter(fn ($w) => Str::length($w) >= 5)
            ->take(12);
        $score = 0;
        foreach ($words as $w) {
            if (Str::contains($haystack, $w)) {
                $score += 1;
            }
        }
        return $score;
    }

    private function bandFor(int $total): string
    {
        return match (true) {
            $total >= 8 => 'high',
            $total >= 4 => 'medium',
            default => 'low',
        };
    }
}
