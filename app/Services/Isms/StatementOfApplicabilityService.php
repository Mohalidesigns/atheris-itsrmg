<?php

namespace App\Services\Isms;

use App\Models\ControlFramework;
use App\Models\SoaVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Statement of Applicability, read together with its evidence (ATH-VAL-010).
 *
 * For each Annex A control the SoA says whether it applies, why, and how far it is implemented.
 * That claim is set beside what the bank can show: the controls mapped to the requirement and
 * their tested effectiveness, and the result of the latest completed ISO 27001 assessment. Where
 * the claim and the evidence disagree the entry is flagged, because that is what an auditor finds.
 */
class StatementOfApplicabilityService
{
    public const FRAMEWORK_SLUG = 'iso-27001-2022';

    public function frameworkId(): ?int
    {
        return ControlFramework::where('slug', self::FRAMEWORK_SLUG)->value('id');
    }

    /** Every Annex A leaf requirement with the bank's SoA entry, evidence and any contradiction. */
    public function rows(int $orgId): Collection
    {
        $fwId = $this->frameworkId();

        $requirements = DB::table('framework_requirements as r')
            ->leftJoin('statements_of_applicability as s', fn ($j) => $j->on('s.requirement_id', '=', 'r.id')->where('s.organization_id', $orgId))
            ->leftJoin('controls as c', 'c.id', '=', 's.control_id')
            ->leftJoin('users as u', 'u.id', '=', 's.updated_by')
            ->where('r.framework_id', $fwId)->where('r.level', '>', 0)
            ->orderBy('r.requirement_code')
            ->get(['r.id as requirement_id', 'r.requirement_code', 'r.title', 'r.description', 's.id as soa_id', 's.is_applicable', 's.justification',
                's.implementation_status', 's.control_id', 's.updated_at', 'u.name as updated_by_name',
                'c.control_code as primary_control_code', 'c.title as primary_control_title', 'c.effectiveness as primary_effectiveness']);

        $mapped = DB::table('control_framework_mappings as m')->join('controls as c', 'c.id', '=', 'm.control_id')
            ->where('c.organization_id', $orgId)->whereNull('c.deleted_at')
            ->whereIn('m.requirement_id', $requirements->pluck('requirement_id'))
            ->get(['m.requirement_id', 'c.id', 'c.control_code', 'c.title', 'c.effectiveness'])
            ->groupBy('requirement_id');

        $assessment = DB::table('compliance_assessments')->where('organization_id', $orgId)->where('framework_id', $fwId)
            ->where('status', 'completed')->orderByDesc('end_date')->orderByDesc('id')->first(['id', 'title', 'end_date']);
        $results = $assessment
            ? DB::table('compliance_results')->where('assessment_id', $assessment->id)->pluck('status', 'requirement_id')
            : collect();

        return $requirements->map(function ($r) use ($mapped, $results) {
            $controls = collect($mapped[$r->requirement_id] ?? []);
            if ($r->control_id && ! $controls->contains('id', $r->control_id)) {
                $controls->prepend((object) ['id' => $r->control_id, 'control_code' => $r->primary_control_code, 'title' => $r->primary_control_title, 'effectiveness' => $r->primary_effectiveness]);
            }
            $result = $results[$r->requirement_id] ?? null;

            return (array) $r + [
                'is_applicable' => $r->is_applicable === null ? null : (bool) $r->is_applicable,
                'controls' => $controls->values(),
                'assessment_result' => $result,
                'flags' => $this->flags($r, $controls, $result),
            ];
        })->sortBy('requirement_code', SORT_NATURAL)->values(); // A.5.2 before A.5.10
    }

    /** Where the SoA's claim and the bank's evidence disagree. */
    public function flags(object $r, Collection $controls, ?string $result): array
    {
        $flags = [];
        if ($r->soa_id === null) {
            return ['No SoA decision recorded.'];
        }
        $applicable = (bool) $r->is_applicable;
        if (blank($r->justification)) {
            $flags[] = $applicable ? 'No reason given for including it.' : 'Excluded without a justification.';
        }
        if (! $applicable) {
            return $flags;
        }
        if ($controls->isEmpty()) {
            $flags[] = 'Applicable but no control implements it.';
        }
        if ($r->implementation_status === 'implemented') {
            if ($controls->contains('effectiveness', 'ineffective')) {
                $flags[] = 'Marked implemented, but a control implementing it tested ineffective.';
            }
            if ($result === 'non_compliant') {
                $flags[] = 'Marked implemented, but the latest ISO assessment found it non-compliant.';
            }
        }
        if (in_array($r->implementation_status, ['not_started', 'planned'], true) && $result === 'compliant') {
            $flags[] = 'Assessed compliant, but the SoA still says '.str_replace('_', ' ', $r->implementation_status).'.';
        }

        return $flags;
    }

    public function summary(Collection $rows): array
    {
        $applicable = $rows->where('is_applicable', true);

        return [
            'total' => $rows->count(),
            'applicable' => $applicable->count(),
            'excluded' => $rows->where('is_applicable', false)->count(),
            'pending' => $rows->whereNull('soa_id')->count(),
            'implemented' => $applicable->where('implementation_status', 'implemented')->count(),
            'partial' => $applicable->where('implementation_status', 'partial')->count(),
            'flagged' => $rows->filter(fn ($r) => $r['flags'])->count(),
        ];
    }

    /** The current SoA, frozen for a version. */
    public function snapshot(Collection $rows): array
    {
        return $rows->map(fn ($r) => [
            'code' => $r['requirement_code'], 'title' => $r['title'], 'applicable' => $r['is_applicable'],
            'justification' => $r['justification'], 'implementation' => $r['implementation_status'],
            'controls' => collect($r['controls'])->pluck('control_code')->values()->all(),
        ])->values()->all();
    }

    /** Entries changed since the last approved version (code => what changed). */
    public function changesSinceApproved(int $orgId, Collection $rows): array
    {
        $approved = SoaVersion::query()->where('organization_id', $orgId)->where('status', 'approved')->orderByDesc('version_no')->first();
        if (! $approved) {
            return [];
        }
        $before = collect($approved->snapshot)->keyBy('code');
        $changes = [];
        foreach ($this->snapshot($rows) as $now) {
            $was = $before[$now['code']] ?? null;
            if (! $was) {
                $changes[$now['code']] = 'new';

                continue;
            }
            $diff = array_keys(array_filter([
                'applicability' => $was['applicable'] !== $now['applicable'],
                'implementation' => $was['implementation'] !== $now['implementation'],
                'justification' => $was['justification'] !== $now['justification'],
            ]));
            if ($diff) {
                $changes[$now['code']] = implode(', ', $diff);
            }
        }

        return $changes;
    }
}
