<?php

namespace App\Services\Returns;

use App\Models\Ea\EaApplication;
use App\Models\LegalEntity;
use App\Services\Ea\ResidencyService;
use App\Services\Ea\SiteResilienceService;

/**
 * LocalisationGapMapper — the payment data localisation gap report (A1/A2).
 *
 * §6.1 Finding 3: the circular of **15 June 2026** requires all institutions
 * facilitating Nigerian payments to store and manage domestically generated
 * transaction data **within Nigeria**, with compliance by **1 January 2027**.
 * "It affects **primary and disaster recovery infrastructure**, forces revision
 * of outsourcing and cloud contracts, and prohibits cross-border transfer of
 * Nigerian payment transaction data."
 *
 * §11 wedge play 2: "Between now and 1 January 2027, 'which systems hold
 * Nigerian payment data and where do they and their DR sites physically run' is
 * a board-level question with a legal deadline. It is an EA query and nothing
 * else in the market answers it."
 *
 * ⚠️ §12.1 R3 flags the open question: whether any hyperscaler operates an
 * in-country Nigerian region. The sovereign-readiness section therefore ranks
 * the in-country sites actually recorded rather than assuming a cloud target.
 */
class LocalisationGapMapper extends ReturnMapper
{
    public static function name(): string
    {
        return 'Payment data localisation gap report';
    }

    public static function regulator(): string
    {
        return 'CBN';
    }

    public static function description(): string
    {
        return 'Every system holding Nigerian payment transaction data whose primary or DR site sits '
            .'outside Nigeria, with days to the 1 January 2027 deadline and the remediation owner.';
    }

    public static function cadence(): string
    {
        return 'quarterly';
    }

    public function compile(?LegalEntity $entity, string $period): array
    {
        $residency = new ResidencyService();
        $citations = [];

        $summary = $residency->summary();
        $register = $residency->gapRegister();
        $flows = $residency->crossBorderFlows();
        $readiness = $residency->sovereignReadiness();

        $sections = [
            $this->gapSection($register, $summary, $citations),
            $this->drSection($register, $citations),
            $this->flowSection($flows, $citations),
            $this->readinessSection($readiness),
        ];

        $completeness = (int) round(collect($sections)->avg('completeness'));

        return [
            'sections' => $sections,
            'summary' => array_merge($summary, [
                'completeness' => $completeness,
            ]),
            'citations' => $citations,
            'completeness' => $completeness,
            'evidence_confidence' => $this->confidenceFrom($citations),
        ];
    }

    private function gapSection(array $register, array $summary, array &$citations): array
    {
        foreach (array_slice($register, 0, 200) as $row) {
            $citations[] = [
                'question_ref' => 'LOC-GAP',
                'entity_type' => EaApplication::class,
                'entity_id' => $row['application_id'],
                'label' => $row['label'],
                'note' => $row['reason'],
            ];
        }

        $paymentSystems = max(1, $summary['payment_data_systems']);
        $compliant = $paymentSystems - $summary['localisation_gaps'];

        return $this->section(
            'LOC-GAP',
            'Localisation gap register',
            'Circular of 15 June 2026: Nigerian payment transaction data must be stored and managed within '
                .'Nigeria by 1 January 2027. The requirement covers primary and disaster recovery '
                .'infrastructure.',
            [
                'deadline' => $summary['deadline'],
                'days_remaining' => $summary['days_to_deadline'],
                'systems_holding_payment_data' => $summary['payment_data_systems'],
                'compliant' => max(0, $compliant),
                'non_compliant' => $summary['localisation_gaps'],
                'critical_non_compliant' => $summary['critical_gaps'],
                'unknown_residency' => $summary['unknown_residency'],
                'register' => array_slice($register, 0, 100),
            ],
            (int) round($compliant / $paymentSystems * 100),
            $summary['unknown_residency'] > 0
                ? $summary['unknown_residency'].' system(s) have no recorded hosting country, so this '
                    .'figure is a floor, not a complete position. Residency must be recorded before the '
                    .'report can be relied on.'
                : null,
        );
    }

    /**
     * The DR half, called out separately because it is the trap.
     *
     * A bank that has repatriated its primary systems and left DR offshore is
     * non-compliant and will usually believe it is not.
     */
    private function drSection(array $register, array &$citations): array
    {
        $drOnly = array_values(array_filter(
            $register,
            fn ($r) => $r['hosting_country'] === 'NG'
        ));

        foreach (array_slice($drOnly, 0, 100) as $row) {
            $citations[] = [
                'question_ref' => 'LOC-DR',
                'entity_type' => EaApplication::class,
                'entity_id' => $row['application_id'],
                'label' => $row['label'],
                'note' => 'DR outside Nigeria: '.($row['dr_country'] ?: 'unknown'),
            ];
        }

        return $this->section(
            'LOC-DR',
            'Disaster recovery residency',
            'The directive covers primary **and** disaster recovery infrastructure. A compliant primary '
                .'with an offshore DR site remains non-compliant.',
            [
                'primary_compliant_dr_offshore' => count($drOnly),
                'register' => array_slice($drOnly, 0, 50),
            ],
            count($drOnly) === 0 ? 100 : 0,
            count($drOnly) > 0
                ? count($drOnly).' system(s) run a compliant primary in Nigeria but replicate to a site '
                    .'outside it. This is the most commonly missed half of the requirement.'
                : null,
        );
    }

    private function flowSection(array $flows, array &$citations): array
    {
        $prohibited = array_values(array_filter($flows, fn ($f) => $f['status'] === 'prohibited'));

        foreach (array_slice($prohibited, 0, 100) as $flow) {
            $citations[] = [
                'question_ref' => 'LOC-FLOWS',
                'entity_type' => \App\Models\Ea\DataFlow::class,
                'entity_id' => $flow['id'],
                'label' => $flow['name'],
            ];
        }

        return $this->section(
            'LOC-FLOWS',
            'Prohibited cross-border payment data flows',
            'The directive prohibits cross-border transfer of Nigerian payment transaction data outright — '
                .'no transfer basis cures it.',
            [
                'cross_border_flows' => count($flows),
                'prohibited' => count($prohibited),
                'register' => array_slice($prohibited, 0, 50),
            ],
            count($prohibited) === 0 ? 100 : 0,
            count($prohibited) > 0
                ? count($prohibited).' flow(s) move Nigerian payment transaction data across a national '
                    .'boundary. Unlike a missing transfer basis, this cannot be remediated with paperwork.'
                : null,
        );
    }

    private function readinessSection(array $readiness): array
    {
        $sites = $readiness['in_country_sites'];
        $resilience = (new SiteResilienceService())->overview();

        return $this->section(
            'LOC-READINESS',
            'Sovereign hosting readiness',
            'Which in-country sites can carry the workloads that must move, by tier, power autonomy and '
                .'current load.',
            [
                'in_country_sites' => count($sites),
                'tier_iii_plus' => $readiness['tier_iii_plus_capacity'],
                'workloads_to_relocate' => $readiness['workloads_to_relocate'],
                'critical_to_relocate' => $readiness['critical_to_relocate'],
                'lagos_concentration_percent' => $resilience['lagos_share'],
                'candidates' => array_slice($sites, 0, 20),
            ],
            count($sites) > 0 ? 80 : 0,
            $resilience['lagos_share'] > 60
                ? $resilience['lagos_share'].'% of recorded sites are in Lagos. Repatriating workloads '
                    .'into a single metropolitan area satisfies the directive while concentrating '
                    .'geographic risk — both facts belong in front of the board together.'
                : (count($sites) === 0
                    ? 'No in-country sites are recorded, so no relocation target can be assessed.'
                    : null),
        );
    }
}
