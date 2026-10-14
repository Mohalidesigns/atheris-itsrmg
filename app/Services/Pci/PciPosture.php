<?php

namespace App\Services\Pci;

use App\Models\ComplianceAssessment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The bank's PCI DSS v4.0.1 posture, read from real records (ATH-VAL-011): the latest PCI
 * assessment on the compliance engine, the controls mapped to each sub-requirement and their
 * tested effectiveness, the evidence attached to them, and the CDE scoping of assets.
 */
class PciPosture
{
    public const SLUG = 'pci-dss-4.0.1';

    /** Compliance-engine result → PCI DSS reporting term. */
    public const LABELS = [
        'compliant' => 'In place', 'partially_compliant' => 'Partially in place', 'non_compliant' => 'Not in place',
        'not_applicable' => 'Not applicable', 'not_assessed' => 'Not tested',
    ];

    public const SCOPES = ['cde' => 'Cardholder data environment', 'connected' => 'Connected-to / security-impacting', 'out_of_scope' => 'Out of scope'];

    public function frameworkId(): ?int
    {
        return DB::table('control_frameworks')->where('slug', self::SLUG)->value('id');
    }

    /** The latest completed PCI assessment, or the latest one of any status if none has completed. */
    public function assessment(int $orgId): ?ComplianceAssessment
    {
        $base = fn () => ComplianceAssessment::withoutGlobalScopes()->where('organization_id', $orgId)->where('framework_id', $this->frameworkId());

        return $base()->where('status', 'completed')->orderByDesc('end_date')->orderByDesc('id')->first()
            ?? $base()->orderByDesc('created_at')->first();
    }

    /** Principal requirements with their sub-requirements, results, mapped controls and evidence counts. */
    public function requirements(int $orgId): Collection
    {
        $fwId = $this->frameworkId();
        $all = DB::table('framework_requirements')->where('framework_id', $fwId)->orderBy('sort_order')->orderBy('id')->get();
        $assessment = $this->assessment($orgId);
        $results = $assessment
            ? DB::table('compliance_results')->where('assessment_id', $assessment->id)->get(['id', 'requirement_id', 'status', 'findings'])->keyBy('requirement_id')
            : collect();
        $controls = DB::table('control_framework_mappings as m')->join('controls as c', 'c.id', '=', 'm.control_id')
            ->where('c.organization_id', $orgId)->whereNull('c.deleted_at')->whereIn('m.requirement_id', $all->pluck('id'))
            ->get(['m.id as mapping_id', 'm.requirement_id', 'c.id', 'c.control_code', 'c.title', 'c.effectiveness'])->groupBy('requirement_id');
        $evidence = DB::table('evidence')->where('organization_id', $orgId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('evidenceable_type', 'App\\Models\\ComplianceResult')->whereIn('evidenceable_id', $results->pluck('id')))
                ->orWhere(fn ($q) => $q->where('evidenceable_type', 'App\\Models\\Control')->whereIn('evidenceable_id', $controls->flatten(1)->pluck('id'))))
            ->get(['id', 'evidenceable_type', 'evidenceable_id']);

        return $all->whereNull('parent_id')->values()->map(function ($req) use ($all, $results, $controls, $evidence) {
            $subs = $all->where('parent_id', $req->id)->values()->map(function ($sub) use ($results, $controls, $evidence) {
                $result = $results[$sub->id] ?? null;
                $mapped = collect($controls[$sub->id] ?? []);
                $evidenceCount = $evidence->filter(fn ($e) => ($e->evidenceable_type === 'App\\Models\\ComplianceResult' && $result && $e->evidenceable_id === $result->id)
                    || ($e->evidenceable_type === 'App\\Models\\Control' && $mapped->contains('id', $e->evidenceable_id)))->count();

                return [
                    'id' => $sub->id, 'code' => $sub->requirement_code, 'title' => $sub->title,
                    'status' => $result?->status ?? 'not_assessed', 'findings' => $result?->findings, 'result_id' => $result?->id,
                    'controls' => $mapped->values(), 'evidence' => $evidenceCount,
                ];
            });
            $tested = $subs->whereNotIn('status', ['not_assessed', 'not_applicable']);

            return [
                'id' => $req->id, 'code' => $req->requirement_code, 'title' => $req->title, 'goal' => $req->section,
                'subs' => $subs,
                'in_place' => $subs->where('status', 'compliant')->count(),
                'tested' => $tested->count(),
                'not_in_place' => $subs->where('status', 'non_compliant')->count(),
                'pct' => $tested->count() ? (int) round($subs->where('status', 'compliant')->count() / $tested->count() * 100) : null,
                'unmapped' => $subs->filter(fn ($s) => $s['controls']->isEmpty())->count(),
            ];
        });
    }

    /** CDE scoping: assets by scope, and payment-relevant assets nobody has scoped yet. */
    public function scoping(int $orgId): array
    {
        $assets = DB::table('assets')->where('organization_id', $orgId)->whereNull('deleted_at')->where('status', '!=', 'decommissioned');

        return [
            'cde' => (clone $assets)->where('pci_scope', 'cde')->count(),
            'connected' => (clone $assets)->where('pci_scope', 'connected')->count(),
            'out_of_scope' => (clone $assets)->where('pci_scope', 'out_of_scope')->count(),
            'unscoped' => (clone $assets)->whereNull('pci_scope')->count(),
        ];
    }
}
