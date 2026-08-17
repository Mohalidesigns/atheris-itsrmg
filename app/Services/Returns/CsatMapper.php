<?php

namespace App\Services\Returns;

use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Initiative;
use App\Models\Ea\Plateau;
use App\Models\Ea\TechComponent;
use App\Models\Ea\Zone;
use App\Models\LegalEntity;
use App\Models\Vendor;
use App\Services\Ea\ControlInheritanceService;

/**
 * CsatMapper — the CBN Cybersecurity Self-Assessment pre-fill pack (A1).
 *
 * §6.1 Finding 2 shows why this is an EA artefact rather than a questionnaire:
 * the Risk-Based Cybersecurity Framework "contains clauses that describe an
 * architecture repository almost field by field". Appendix B maps them:
 *
 *   §3.1              inventory of software, hardware and network connections
 *   App. II §1.1(a)   assets on-premises **and in third-party cloud**
 *   App. II §1.1      asset ownership assigned; criticality categorisation
 *   App. II §1.1(i)–(k) approved network topology diagram; catalogue of all
 *                     connections to regulators, switches and third parties
 *                     with **the objective of each connection documented and
 *                     regularly reviewed**
 *   App. II §1.4      third-party register; all third-party connections
 *                     documented; CSP assessed before adoption
 *   §2.4              current profile → target profile → **roadmap**
 *
 * §6.1: "And §2.4 of the framework requires the CSAT to establish a current
 * profile → target profile → roadmap. That is literally the TOGAF ADM
 * gap-analysis pattern. **The regulator has specified the artefact shape.**"
 *
 * ⚠️ §12.1 R2: "CSAT internal structure differs from our assumption, making
 * pre-fill mapping wrong … Obtain the actual CSAT instrument from a
 * design-partner CISO. Highest-value single artefact in this document." The
 * section refs below therefore track the *framework clauses*, which are
 * published, rather than a guessed question numbering from the instrument,
 * which is not.
 */
class CsatMapper extends ReturnMapper
{
    public static function name(): string
    {
        return 'CBN CSAT architecture evidence pack';
    }

    public static function regulator(): string
    {
        return 'CBN';
    }

    public static function description(): string
    {
        return 'Pre-fills the architecture sections of the annual Cybersecurity Self-Assessment '
            .'from the repository, with an evidence citation behind every answer.';
    }

    public static function cadence(): string
    {
        return 'annual';
    }

    public static function dueDateFor(string $period): ?string
    {
        // ⚠️ §6.2 gives 28 February with a verification warning: "the exposure
        // draft said 31 March; confirm against the signed circular".
        $year = (int) substr($period, 0, 4);
        $md = config('ea.returns.csat_due', '02-28');

        return "{$year}-{$md}";
    }

    public function compile(?LegalEntity $entity, string $period): array
    {
        $sections = [];
        $citations = [];

        $sections[] = $this->assetInventory($citations, $entity);
        $sections[] = $this->connectionCatalogue($citations);
        $sections[] = $this->thirdPartyRegister($citations);
        $sections[] = $this->obsolescencePosture($citations);
        $sections[] = $this->securityZones($citations);
        $sections[] = $this->controlCoverage($citations);
        $sections[] = $this->profileAndRoadmap($citations);

        $completeness = (int) round(collect($sections)->avg('completeness'));

        return [
            'sections' => $sections,
            'summary' => [
                'applications' => EaApplication::count(),
                'technology_components' => TechComponent::count(),
                'documented_connections' => EaInterface::whereNotNull('objective')->count(),
                'total_connections' => EaInterface::count(),
                'third_parties' => Vendor::count(),
                'obsolete_components' => TechComponent::where('obsolescence_flag', true)->count(),
                'security_zones' => Zone::count(),
                'completeness' => $completeness,
            ],
            'citations' => $citations,
            'completeness' => $completeness,
            'evidence_confidence' => $this->confidenceFrom($citations),
        ];
    }

    /** RBCF §3.1 and App. II §1.1(a) — "Know Your Environment". */
    private function assetInventory(array &$citations, ?LegalEntity $entity): array
    {
        // Scoping strictly to the entity would silently drop every
        // application not yet assigned to one, and a return that
        // under-reports without saying so is worse than no return at all.
        // Unassigned records are treated as group-level and counted openly.
        $applications = EaApplication::query()
            ->when($entity, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('legal_entity_id', $entity->id)
                ->orWhereNull('legal_entity_id')))
            ->get();

        $unassigned = $entity
            ? $applications->whereNull('legal_entity_id')->count()
            : 0;

        $withOwner = $applications->whereNotNull('owner_role')->count();
        $withCriticality = $applications->whereNotNull('criticality')->count();
        $withHosting = $applications->whereNotNull('hosting_country')->count();

        foreach ($applications->take(200) as $application) {
            $citations[] = [
                'question_ref' => 'RBCF-3.1',
                'entity_type' => EaApplication::class,
                'entity_id' => $application->id,
                'label' => "{$application->code} — {$application->name}",
            ];
        }

        $total = max(1, $applications->count());

        return $this->section(
            'RBCF-3.1',
            'Inventory of authorised software, hardware and network connections',
            'RBCF §3.1 "Know Your Environment"; App. II §1.1(a) requires assets on-premises and in '
                .'third-party cloud infrastructure to be inventoried.',
            [
                'applications' => $applications->count(),
                'technology_components' => TechComponent::count(),
                'with_documented_owner' => $withOwner,
                'with_criticality' => $withCriticality,
                'with_hosting_location' => $withHosting,
                'cloud_hosted' => $applications->whereIn('hosting_model', ['public_cloud', 'saas', 'private_cloud'])->count(),
                'on_premises' => $applications->whereIn('hosting_model', ['on_prem', 'colocation'])->count(),
                'unassigned_to_entity' => $unassigned,
            ],
            (int) round(($withOwner + $withCriticality + $withHosting) / ($total * 3) * 100),
            collect([
                $withHosting < $total
                    ? ($total - $withHosting).' system(s) have no recorded hosting location, so the '
                        .'on-premises versus third-party cloud split is incomplete.'
                    : null,
                $unassigned > 0
                    ? $unassigned.' system(s) are not assigned to a legal entity and have been included '
                        .'as group-level. Assign them to scope this return precisely.'
                    : null,
            ])->filter()->implode(' ') ?: null,
        );
    }

    /**
     * App. II §1.1(i)–(k) — the clause ATH-EAR-002 calls "the single most
     * EA-tool-shaped clause in Nigerian regulation".
     */
    private function connectionCatalogue(array &$citations): array
    {
        $interfaces = EaInterface::all();
        $documented = $interfaces->filter(fn ($i) => filled($i->objective));
        $external = $interfaces->whereIn('counterparty_type', ['regulator', 'switch', 'third_party']);
        $reviewed = $interfaces->filter(fn ($i) => $i->last_reviewed_at !== null);

        foreach ($documented->take(200) as $interface) {
            $citations[] = [
                'question_ref' => 'RBCF-APPII-1.1(j)',
                'entity_type' => EaInterface::class,
                'entity_id' => $interface->id,
                'label' => "{$interface->code} — {$interface->name}",
            ];
        }

        $total = max(1, $interfaces->count());

        return $this->section(
            'RBCF-APPII-1.1(i)-(k)',
            'Catalogue of network connections with documented objectives',
            'App. II §1.1(i)–(k): an approved, up-to-date network topology diagram, and a catalogue of all '
                .'network connections to regulatory authorities, switches and third parties, with the objective '
                .'of each connection documented and regularly reviewed.',
            [
                'total_connections' => $interfaces->count(),
                'objective_documented' => $documented->count(),
                'objective_missing' => $interfaces->count() - $documented->count(),
                'to_regulators' => $interfaces->where('counterparty_type', 'regulator')->count(),
                'to_switches' => $interfaces->where('counterparty_type', 'switch')->count(),
                'to_third_parties' => $interfaces->where('counterparty_type', 'third_party')->count(),
                'external_total' => $external->count(),
                'reviewed' => $reviewed->count(),
                'pii_carrying' => $interfaces->where('pii_carrying', true)->count(),
            ],
            (int) round($documented->count() / $total * 100),
            $documented->count() < $interfaces->count()
                ? ($interfaces->count() - $documented->count()).' connection(s) have no documented objective. '
                    .'This section cannot be filed complete until every row carries one.'
                : null,
        );
    }

    /** App. II §1.4 and §2.3 — third-party register and connections. */
    private function thirdPartyRegister(array &$citations): array
    {
        $vendors = Vendor::all();
        $linked = EaApplication::whereNotNull('vendor_id')->count();
        $connections = EaInterface::whereNotNull('counterparty_vendor_id')->count();

        foreach ($vendors->take(100) as $vendor) {
            $citations[] = [
                'question_ref' => 'RBCF-APPII-1.4',
                'entity_type' => Vendor::class,
                'entity_id' => $vendor->id,
                'label' => $vendor->name,
            ];
        }

        $total = max(1, $vendors->count());
        $assessed = $vendors->whereNotNull('last_assessed')->count();

        return $this->section(
            'RBCF-APPII-1.4',
            'Third-party provider register and connection mapping',
            'App. II §1.4: a record of all third-party providers; all connections to third parties documented; '
                .'CSP controls evaluated before adoption. §2.3 requires a third-party risk framework.',
            [
                'third_parties' => $vendors->count(),
                'assessed' => $assessed,
                'applications_linked_to_a_vendor' => $linked,
                'connections_with_named_counterparty' => $connections,
                'by_role' => $vendors->groupBy(fn ($v) => $v->role ?: 'unclassified')->map->count(),
            ],
            (int) round($assessed / $total * 100),
            $vendors->whereNull('role')->count() > 0
                ? $vendors->whereNull('role')->count().' provider(s) have no role recorded, so services '
                    .'concentration cannot be distinguished from software concentration.'
                : null,
        );
    }

    /** App. II §1.2 — quarterly vulnerability assessment of all IT assets. */
    private function obsolescencePosture(array &$citations): array
    {
        $components = TechComponent::all();
        $obsolete = $components->where('obsolescence_flag', true);

        foreach ($obsolete->take(100) as $component) {
            $citations[] = [
                'question_ref' => 'RBCF-APPII-1.2',
                'entity_type' => TechComponent::class,
                'entity_id' => $component->id,
                'label' => "{$component->code} — {$component->name}",
            ];
        }

        $withEol = $components->whereNotNull('eol_date')->count();
        $total = max(1, $components->count());

        return $this->section(
            'RBCF-APPII-1.2',
            'Technology obsolescence and vulnerability posture',
            'App. II §1.2: vulnerability assessment of all IT assets, presented quarterly to governance bodies.',
            [
                'components' => $components->count(),
                'with_eol_date' => $withEol,
                'past_or_approaching_eol' => $obsolete->count(),
                'unsupported_today' => $components->filter(fn ($c) => $c->eol_date && $c->eol_date->isPast())->count(),
                'mean_tech_debt_score' => round((float) $components->avg('tech_debt_score'), 1),
            ],
            (int) round($withEol / $total * 100),
            $withEol < $components->count()
                ? ($components->count() - $withEol).' component(s) have no end-of-life date, so the '
                    .'obsolescence figure understates the true position.'
                : null,
        );
    }

    private function securityZones(array &$citations): array
    {
        $zones = Zone::withCount('assignments')->get();

        foreach ($zones as $zone) {
            $citations[] = [
                'question_ref' => 'RBCF-APPII-1.1(f)',
                'entity_type' => Zone::class,
                'entity_id' => $zone->id,
                'label' => "{$zone->code} — {$zone->name}",
            ];
        }

        $assigned = $zones->sum('assignments_count');
        $applications = max(1, EaApplication::count());

        return $this->section(
            'RBCF-APPII-1.1(f)',
            'Network segmentation and security zones',
            'App. II §1.1: devices categorised by criticality and sensitivity of data, with documented '
                .'network segmentation.',
            [
                'zones' => $zones->count(),
                'applications_assigned' => $assigned,
                'applications_unassigned' => max(0, EaApplication::count() - $assigned),
                'by_trust_level' => $zones->groupBy('trust_level')->map->count(),
            ],
            (int) round(min(1, $assigned / $applications) * 100),
        );
    }

    private function controlCoverage(array &$citations): array
    {
        $service = new ControlInheritanceService();
        $applications = EaApplication::limit(100)->get(['id', 'code', 'name']);

        $mapped = 0;
        $effective = 0;
        $dangling = 0;

        foreach ($applications as $application) {
            $coverage = $service->citableCoverage($application->id, '*');
            $mapped += $coverage['mapped'];
            $effective += $coverage['effective'];
            $dangling += $coverage['dangling'];

            if ($coverage['mapped'] > 0) {
                $citations[] = [
                    'question_ref' => 'RBCF-2.2',
                    'entity_type' => EaApplication::class,
                    'entity_id' => $application->id,
                    'label' => "{$application->code} — {$application->name}",
                    'note' => "{$coverage['effective']} of {$coverage['mapped']} control mappings effective",
                ];
            }
        }

        return $this->section(
            'RBCF-2.2',
            'Control coverage across the application estate',
            'RBCF §2.2: controls implemented commensurate with the risk profile, evidenced per system.',
            [
                'mappings' => $mapped,
                'effective' => $effective,
                'unresolvable' => $dangling,
                'citable_percent' => $mapped ? round($effective / $mapped * 100, 1) : 0.0,
            ],
            $mapped ? (int) round($effective / $mapped * 100) : 0,
            $dangling > 0
                ? "{$dangling} control mapping(s) do not resolve to a real control and have been excluded "
                    .'from the coverage figure rather than inflating it.'
                : null,
        );
    }

    /**
     * RBCF §2.4 — current profile → target profile → roadmap.
     *
     * §6.1: "That is literally the TOGAF ADM gap-analysis pattern."
     */
    private function profileAndRoadmap(array &$citations): array
    {
        $plateaux = Plateau::orderBy('effective_from')->get();
        $initiatives = Initiative::whereIn('status', ['approved', 'in_flight'])->get();

        foreach ($initiatives->take(50) as $initiative) {
            $citations[] = [
                'question_ref' => 'RBCF-2.4',
                'entity_type' => Initiative::class,
                'entity_id' => $initiative->id,
                'label' => "{$initiative->code} — {$initiative->name}",
            ];
        }

        $hasBaseline = $plateaux->where('plateau_type', 'baseline')->isNotEmpty();
        $hasTarget = $plateaux->where('plateau_type', 'target')->isNotEmpty();

        $completeness = (int) round(
            (($hasBaseline ? 1 : 0) + ($hasTarget ? 1 : 0) + ($initiatives->isNotEmpty() ? 1 : 0)) / 3 * 100
        );

        return $this->section(
            'RBCF-2.4',
            'Current profile, target profile and roadmap',
            'RBCF §2.4 requires the self-assessment to establish a current profile, a target profile and a '
                .'roadmap between them.',
            [
                'baseline_defined' => $hasBaseline,
                'target_defined' => $hasTarget,
                'plateaux' => $plateaux->count(),
                'initiatives_in_flight' => $initiatives->count(),
                'roadmap' => $initiatives->take(25)->map(fn ($i) => [
                    'code' => $i->code,
                    'name' => $i->name,
                    'status' => $i->status,
                    'adm_phase' => $i->adm_phase,
                    'target_end' => optional($i->target_end_date)->toDateString(),
                ])->values(),
            ],
            $completeness,
            ! $hasTarget ? 'No target-state plateau is defined, so the gap analysis this clause requires '
                .'cannot be produced from the repository.' : null,
        );
    }
}
