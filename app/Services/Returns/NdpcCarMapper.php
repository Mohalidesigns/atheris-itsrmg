<?php

namespace App\Services\Returns;

use App\Models\Ea\DpiaAssessment;
use App\Models\Ea\LogicalEntity;
use App\Models\LegalEntity;
use App\Models\Vendor;
use App\Services\Ea\ResidencyService;

/**
 * NdpcCarMapper — the NDPC Compliance Audit Return extract (A1).
 *
 * §6.1: "Every Nigerian commercial bank is an **Ultra-High Level** Data
 * Controller of Major Importance… Compliance Audit Returns are due **31 March**
 * annually, filed **through a licensed DPCO**, with fees ₦100k–₦1m and
 * non-filing penalties of **up to 2% of annual gross revenue or ₦10m, whichever
 * is higher**."
 *
 * The insight that makes this an EA artefact: "**Two of the five CAR audit
 * domains — cross-border transfer activities and third-party data processor
 * arrangements — are architecture questions dressed as privacy questions.** A
 * repository holding *system → data category → hosting location → processor →
 * transfer basis* answers both mechanically."
 *
 * §6.3 A1 specifies the output: "processing inventory from logical entities;
 * cross-border transfer register from data flows with transfer basis;
 * third-party processor arrangements from the vendor↔application graph; DPIA
 * register. Formatted for DPCO filing."
 *
 * ⚠️ §6.1 notes the 2025 cycle was extended to 30 May 2026 and that
 * "extensions appear to be a pattern", so the due date is configurable.
 */
class NdpcCarMapper extends ReturnMapper
{
    public static function name(): string
    {
        return 'NDPC Compliance Audit Return extract';
    }

    public static function regulator(): string
    {
        return 'NDPC (filed via a licensed DPCO)';
    }

    public static function description(): string
    {
        return 'Produces the processing inventory, cross-border transfer register, processor '
            .'arrangements and DPIA register the CAR requires, from the architecture graph.';
    }

    public static function cadence(): string
    {
        return 'annual';
    }

    public static function dueDateFor(string $period): ?string
    {
        $year = (int) substr($period, 0, 4);
        $md = config('ea.returns.ndpc_car_due', '03-31');

        return "{$year}-{$md}";
    }

    public function compile(?LegalEntity $entity, string $period): array
    {
        $sections = [];
        $citations = [];

        $sections[] = $this->processingInventory($citations);
        $sections[] = $this->crossBorderRegister($citations);
        $sections[] = $this->processorArrangements($citations);
        $sections[] = $this->dpiaRegister($citations);

        $completeness = (int) round(collect($sections)->avg('completeness'));
        $residency = new ResidencyService();
        $flows = collect($residency->crossBorderFlows());

        return [
            'sections' => $sections,
            'summary' => [
                'data_entities' => LogicalEntity::count(),
                'pii_entities' => LogicalEntity::where('pii_flag', true)->count(),
                'classified_entities' => LogicalEntity::whereNotNull('ndpa_classification')->count(),
                'cross_border_flows' => $flows->count(),
                'flows_without_basis' => $flows->where('status', 'no_basis')->count(),
                'flows_awaiting_approval' => $flows->where('status', 'awaiting_approval')->count(),
                'processors' => Vendor::whereIn('id', LogicalEntity::whereNotNull('processor_vendor_id')
                    ->pluck('processor_vendor_id')->unique())->count(),
                'dpias' => DpiaAssessment::count(),
                'completeness' => $completeness,
            ],
            'citations' => $citations,
            'completeness' => $completeness,
            'evidence_confidence' => $this->confidenceFrom($citations),
        ];
    }

    /** CAR domain: record of processing activities. */
    private function processingInventory(array &$citations): array
    {
        $entities = LogicalEntity::with('domain:id,name')->get();
        $pii = $entities->where('pii_flag', true);
        $classified = $entities->whereNotNull('ndpa_classification');
        $withRetention = $entities->whereNotNull('retention_period_months');
        $withBasis = $entities->whereNotNull('lawful_basis');

        foreach ($pii->take(200) as $logicalEntity) {
            $citations[] = [
                'question_ref' => 'CAR-PROCESSING',
                'entity_type' => LogicalEntity::class,
                'entity_id' => $logicalEntity->id,
                'label' => "{$logicalEntity->code} — {$logicalEntity->name}",
            ];
        }

        $piiTotal = max(1, $pii->count());

        return $this->section(
            'CAR-PROCESSING',
            'Record of processing activities',
            'NDPA / GAID 2025: a Data Controller of Major Importance must maintain a record of processing '
                .'activities including data categories, lawful basis and retention periods.',
            [
                'data_entities' => $entities->count(),
                'personal_data_entities' => $pii->count(),
                'with_ndpa_classification' => $classified->count(),
                'with_lawful_basis' => $withBasis->count(),
                'with_retention_period' => $withRetention->count(),
                'by_classification' => $entities->groupBy(fn ($e) => $e->ndpa_classification ?: 'unclassified')->map->count(),
                'by_domain' => $entities->groupBy(fn ($e) => $e->domain?->name ?: 'Unassigned')->map->count(),
            ],
            (int) round(($classified->count() + $withBasis->count() + $withRetention->count()) / ($piiTotal * 3) * 100),
            $pii->count() > $withBasis->count()
                ? ($pii->count() - $withBasis->count()).' personal-data entit(ies) have no lawful basis '
                    .'recorded. The CAR cannot be filed complete without one per processing activity.'
                : null,
        );
    }

    /**
     * CAR domain: cross-border transfer activities.
     *
     * §6.1: NDPA/GAID requires "Commission approval for SCCs/BCRs, with
     * consent-based transfer permissible **only where connected to jural or
     * fiduciary obligations** — materially stricter than GDPR practice."
     */
    private function crossBorderRegister(array &$citations): array
    {
        $flows = collect((new ResidencyService())->crossBorderFlows());

        foreach ($flows->take(200) as $flow) {
            $citations[] = [
                'question_ref' => 'CAR-CROSSBORDER',
                'entity_type' => \App\Models\Ea\DataFlow::class,
                'entity_id' => $flow['id'],
                'label' => $flow['name'],
                'note' => $flow['transfer_basis_label'],
            ];
        }

        $documented = $flows->where('status', 'documented')->count();
        $total = max(1, $flows->count());

        return $this->section(
            'CAR-CROSSBORDER',
            'Cross-border transfer register',
            'NDPA / GAID 2025: every transfer of personal data outside Nigeria requires a lawful basis; '
                .'standard contractual clauses and binding corporate rules require Commission approval. '
                .'Consent is valid only where connected to a jural or fiduciary obligation.',
            [
                'cross_border_flows' => $flows->count(),
                'documented' => $documented,
                'no_basis' => $flows->where('status', 'no_basis')->count(),
                'awaiting_commission_approval' => $flows->where('status', 'awaiting_approval')->count(),
                'prohibited_payment_data' => $flows->where('status', 'prohibited')->count(),
                'register' => $flows->take(100)->map(fn ($f) => [
                    'flow' => $f['name'],
                    'source_country' => $f['source_country'],
                    'destination_country' => $f['destination_country'],
                    'basis' => $f['transfer_basis_label'],
                    'approval_reference' => $f['approval_reference'],
                    'status' => $f['status'],
                ])->values(),
            ],
            (int) round($documented / $total * 100),
            $flows->where('status', 'prohibited')->count() > 0
                ? $flows->where('status', 'prohibited')->count().' flow(s) carry Nigerian payment '
                    .'transaction data across a border, which the 15 June 2026 directive prohibits outright. '
                    .'These are a compliance breach, not a documentation gap.'
                : ($flows->where('status', 'no_basis')->count() > 0
                    ? $flows->where('status', 'no_basis')->count().' flow(s) have no recorded transfer basis.'
                    : null),
        );
    }

    /** CAR domain: third-party data processor arrangements. */
    private function processorArrangements(array &$citations): array
    {
        $processorIds = LogicalEntity::whereNotNull('processor_vendor_id')
            ->pluck('processor_vendor_id')->unique();

        $processors = Vendor::whereIn('id', $processorIds)->get();

        foreach ($processors as $processor) {
            $citations[] = [
                'question_ref' => 'CAR-PROCESSORS',
                'entity_type' => Vendor::class,
                'entity_id' => $processor->id,
                'label' => $processor->name,
            ];
        }

        $entitiesWithProcessor = LogicalEntity::whereNotNull('processor_vendor_id')->count();
        $piiEntities = max(1, LogicalEntity::where('pii_flag', true)->count());
        $assessed = $processors->whereNotNull('last_assessed')->count();

        return $this->section(
            'CAR-PROCESSORS',
            'Third-party data processor arrangements',
            'NDPA / GAID 2025: processors must be identified, contracted and assessed. This is also '
                .'RBCF App. II §1.4 — a record of all third-party providers with connections documented.',
            [
                'processors' => $processors->count(),
                'processors_assessed' => $assessed,
                'data_entities_with_processor' => $entitiesWithProcessor,
                'offshore_processors' => $processors->filter(fn ($v) => $v->origin_country && $v->origin_country !== 'NG')->count(),
                'register' => $processors->map(fn ($v) => [
                    'processor' => $v->name,
                    'origin_country' => $v->origin_country,
                    'role' => $v->role,
                    'last_assessed' => optional($v->last_assessed)->toDateString(),
                    'contract_end' => optional($v->contract_end)->toDateString(),
                ])->values(),
            ],
            (int) round(min(1, $entitiesWithProcessor / $piiEntities) * 100),
            $entitiesWithProcessor < LogicalEntity::where('pii_flag', true)->count()
                ? 'Not every personal-data entity names its processor, so the arrangement register is '
                    .'incomplete.'
                : null,
        );
    }

    private function dpiaRegister(array &$citations): array
    {
        $dpias = DpiaAssessment::all();

        foreach ($dpias as $dpia) {
            $citations[] = [
                'question_ref' => 'CAR-DPIA',
                'entity_type' => DpiaAssessment::class,
                'entity_id' => $dpia->id,
                'label' => $dpia->subject,
            ];
        }

        $approved = $dpias->where('status', 'approved')->count();
        $highRisk = $dpias->where('risk_band', 'high')->count();

        return $this->section(
            'CAR-DPIA',
            'Data protection impact assessment register',
            'NDPA / GAID 2025 requires a DPIA where processing is likely to result in high risk to data '
                .'subjects.',
            [
                'dpias' => $dpias->count(),
                'approved' => $approved,
                'in_review' => $dpias->where('status', 'in_review')->count(),
                'high_risk' => $highRisk,
                'register' => $dpias->take(100)->map(fn ($d) => [
                    'code' => $d->code,
                    'subject' => $d->subject,
                    'risk_band' => $d->risk_band,
                    'status' => $d->status,
                ])->values(),
            ],
            $dpias->count() ? (int) round($approved / max(1, $dpias->count()) * 100) : 0,
            $dpias->isEmpty()
                ? 'No DPIAs are recorded. For an Ultra-High Level controller this will be questioned.'
                : null,
        );
    }
}
