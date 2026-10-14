<?php

namespace App\Services\Ea\Maturity;

use Illuminate\Support\Facades\DB;

/**
 * What the bank's own EA repository shows for each practice area of the CBN maturity framework
 * (ATH-VAL-015).
 *
 * Each practice area gets a few measured indicators (counts against an expected minimum, or the
 * share of records that are complete), scored 0-100. A domain's documentation score is the average
 * of its practices, and Cmmi::indicativeLevel() turns it into the level the repository supports on
 * its own. The assessment shows this beside each self-rating, the assessor can cite the indicators
 * as evidence, and a self-rating far above it is flagged for the approver.
 *
 * Only the bank's own records count; platform-shared reference data (NULL organisation) counts only
 * where the practice is about using shared reference content (glossary, hosting sites).
 */
class DomainEvidence
{
    private int $org;

    /** @return array<string, array{score:int, indicators:array}> practice code => evidence */
    public function practices(int $orgId): array
    {
        $this->org = $orgId;
        $out = [];
        foreach ($this->indicatorsByPractice() as $code => $indicators) {
            $indicators = array_values(array_filter($indicators));
            $out[$code] = [
                'score' => $indicators ? (int) round(collect($indicators)->avg('score')) : 0,
                'indicators' => $indicators,
            ];
        }

        return $out;
    }

    /** @return array<string, array{score:int, level:int}> domain code => documentation score and indicative level */
    public function domains(int $orgId): array
    {
        return collect($this->practices($orgId))
            ->groupBy(fn ($p, $code) => explode('-', $code)[0], true)
            ->map(function ($practices) {
                $score = (int) round($practices->avg('score'));

                return ['score' => $score, 'level' => Cmmi::indicativeLevel($score)];
            })->all();
    }

    private function indicatorsByPractice(): array
    {
        $apps = $this->own('ea_applications_ext');
        $appCount = (clone $apps)->count();
        $interfaces = $this->own('ea_interfaces');
        $flows = $this->own('ea_data_flows');
        $crossBorder = (clone $flows)->where('cross_border', true);
        $tech = $this->own('ea_tech_components_ext');
        $mappings = $this->own('ea_control_mappings');
        $mappedControls = DB::table('ea_control_mappings as m')->join('controls as c', 'c.id', '=', 'm.control_id')
            ->where('m.organization_id', $this->org)->where('c.organization_id', $this->org)->whereNull('c.deleted_at')
            ->distinct()->pluck('c.effectiveness', 'c.id');
        $ccm = DB::table('ccm_tenant_tests')->where('organization_id', $this->org)->where('status', 'enabled')->whereNotNull('last_status')->pluck('last_status');
        $criticalApps = (clone $apps)->whereIn('criticality', ['critical', 'tier1', 'high'])->pluck('id');
        $threatened = $this->own('ea_threat_models')->where('subject_type', 'like', '%EaApplication')->pluck('subject_id')->unique();

        return [
            'BA-1' => [
                $this->count('capabilities', 'Business capabilities modelled', $this->own('ea_capabilities')->count(), 20, 'ea.capabilities'),
                $this->share('capabilities_owned', 'Capabilities with an owner', $this->own('ea_capabilities'), fn ($q) => $q->whereNotNull('owner_role'), 'ea.capabilities'),
            ],
            'BA-2' => [
                $this->count('value_streams', 'Value streams', $this->own('ea_value_streams')->count(), 3, 'ea.value-streams'),
                $this->count('processes', 'Processes in the inventory', $this->own('ea_processes')->count(), 20, 'ea.processes'),
                $this->share('processes_linked', 'Processes linked to a capability', $this->own('ea_processes'), fn ($q) => $q->whereNotNull('capability_id'), 'ea.processes'),
            ],
            'BA-3' => [
                $this->count('services', 'Business services catalogued', $this->own('ea_business_services')->count(), 5, null),
                $this->share('services_owned', 'Business services with an owner', $this->own('ea_business_services'), fn ($q) => $q->whereNotNull('owner_id'), null),
                $this->share('services_rto', 'Business services with an RTO', $this->own('ea_business_services'), fn ($q) => $q->whereNotNull('rto_minutes'), 'bcp.bia'),
            ],
            'BA-4' => [
                $this->count('goals', 'Strategic goals in the motivation layer', $this->own('ea_goals')->count(), 3, 'ea.motivation'),
                $this->listed('initiatives_linked', 'Initiatives linked to capabilities', 'ea_initiatives', 'linked_capabilities', 'ea.initiatives'),
            ],
            'BA-5' => [
                $this->count('arb_decided', 'ARB decisions in the last 12 months', $this->own('ea_arb_submissions')->whereNotNull('decided_at')->where('decided_at', '>=', now()->subYear())->count(), 6, 'ea.arb'),
            ],
            'AA-1' => [
                $this->count('applications', 'Applications in the portfolio', $appCount, 15, 'ea.applications'),
                $this->share('apps_owned', 'Applications with an owner', $this->own('ea_applications_ext'), fn ($q) => $q->whereNotNull('owner_role'), 'ea.applications'),
                $this->share('apps_lifecycle', 'Applications with a lifecycle stage', $this->own('ea_applications_ext'), fn ($q) => $q->whereNotNull('lifecycle'), 'ea.applications'),
            ],
            'AA-2' => [
                $this->share('apps_time', 'Applications with a TIME score', $this->own('ea_applications_ext'), fn ($q) => $q->whereNotNull('time_score'), 'ea.applications'),
                $this->share('apps_cost', 'Applications with a known annual cost', $this->own('ea_applications_ext'), fn ($q) => $q->where(fn ($w) => $w->whereNotNull('tco_annual_ngn')->orWhereNotNull('annual_cost_ngn')), 'ea.cost-model'),
            ],
            'AA-3' => [
                $this->count('interfaces', 'Interfaces in the connection catalogue', (clone $interfaces)->count(), 10, 'ea.interfaces'),
                $this->count('apis', 'APIs registered', $this->own('ea_apis')->count(), 3, 'ea.apis'),
                $this->share('interfaces_reviewed', 'Interfaces reviewed in the last 12 months', $this->own('ea_interfaces'), fn ($q) => $q->where('last_reviewed_at', '>=', now()->subYear()), 'ea.interfaces'),
            ],
            'AA-4' => [
                $this->count('arb_submissions', 'Solution designs submitted to the ARB', $this->own('ea_arb_submissions')->count(), 5, 'ea.arb'),
                $this->share('arb_decided_share', 'Submissions with a recorded decision', $this->own('ea_arb_submissions'), fn ($q) => $q->whereNotNull('decided_at'), 'ea.arb'),
            ],
            'AA-5' => [
                $this->listed('apps_capabilities', 'Applications mapped to capabilities', 'ea_applications_ext', 'capability_ids', 'ea.capabilities'),
            ],
            'DA-1' => [
                $this->count('info_domains', 'Information domains', $this->own('ea_info_domains')->count(), 4, 'ea.information-domains'),
                $this->share('domains_owned', 'Information domains with an owner', $this->own('ea_info_domains'), fn ($q) => $q->whereNotNull('owner_role'), 'ea.information-domains'),
                $this->count('entities', 'Logical data entities', $this->own('ea_logical_entities')->count(), 10, 'ea.logical-entities'),
            ],
            'DA-2' => [
                $this->share('entities_classified', 'Entities with a classification', $this->own('ea_logical_entities'), fn ($q) => $q->whereNotNull('classification'), 'ea.logical-entities'),
                $this->share('pii_basis', 'Personal-data entities with a lawful basis', $this->own('ea_logical_entities')->where('pii_flag', true), fn ($q) => $q->whereNotNull('lawful_basis'), 'ea.logical-entities'),
            ],
            'DA-3' => [
                $this->count('flows', 'Data flows documented', (clone $flows)->count(), 5, 'ea.data-flows'),
                $this->share('flows_basis', 'Cross-border flows with a transfer basis', $crossBorder, fn ($q) => $q->whereNotNull('transfer_basis'), 'ea.residency'),
            ],
            'DA-4' => [
                $this->count('glossary', 'Business glossary terms', DB::table('ea_glossary_terms')->where(fn ($q) => $q->where('organization_id', $this->org)->orWhereNull('organization_id'))->count(), 10, 'ea.glossary'),
            ],
            'DA-5' => [
                $this->count('dpias', 'DPIAs on changing processing', $this->own('ea_dpia_assessments')->count(), 1, 'ea.dpia'),
            ],
            'TA-1' => [
                $this->count('standards', 'Technology standards', $this->own('ea_standards')->count(), 5, 'ea.standards'),
                $this->share('standards_radar', 'Standards with a radar position', $this->own('ea_standards'), fn ($q) => $q->whereNotNull('radar_status'), 'ea.technology-radar'),
            ],
            'TA-2' => [
                $this->count('components', 'Technology components inventoried', (clone $tech)->count(), 15, 'ea.technology-radar'),
                $this->share('components_eol', 'Components with an end-of-life date', $this->own('ea_tech_components_ext'), fn ($q) => $q->whereNotNull('eol_date'), 'ea.technology-radar'),
            ],
            'TA-3' => [
                $this->count('sites', 'Hosting sites documented', DB::table('ea_sites')->where(fn ($q) => $q->where('organization_id', $this->org)->orWhereNull('organization_id'))->count(), 2, 'ea.sites'),
                $this->share('apps_hosted', 'Applications with a hosting site', $this->own('ea_applications_ext'), fn ($q) => $q->whereNotNull('hosting_site_id'), 'ea.sites'),
            ],
            'TA-4' => [
                $this->share('components_supported', 'Components still within support', $this->own('ea_tech_components_ext')->whereNotNull('eol_date'), fn ($q) => $q->where('eol_date', '>=', today()->toDateString()), 'ea.technology-radar'),
                $this->count('initiatives', 'Initiatives on the roadmap', $this->own('ea_initiatives')->whereIn('status', ['approved', 'in_flight', 'proposed'])->count(), 3, 'ea.roadmap'),
            ],
            'TA-5' => [
                $this->share('apps_hosting_country', 'Applications with a hosting country', $this->own('ea_applications_ext'), fn ($q) => $q->whereNotNull('hosting_country'), 'ea.residency'),
                $this->share('apps_vendor', 'Applications with a vendor of record', $this->own('ea_applications_ext'), fn ($q) => $q->whereNotNull('vendor_id'), 'ea.concentration'),
            ],
            'SA-1' => [
                $this->count('zones', 'Security zones defined', $this->own('ea_zones')->count(), 3, 'ea.security-zones'),
                $this->ratio('apps_zoned', 'Applications assigned to a zone', $this->own('ea_zone_assignments')->distinct()->count('application_id'), $appCount, 'ea.security-zones'),
            ],
            'SA-2' => [
                $this->ratio('apps_mapped', 'Applications with mapped controls', (clone $mappings)->where('component_type', 'application')->distinct()->count('component_id'), $appCount, 'ea.control-mappings'),
                $this->share('mappings_covered', 'Mappings with full or partial coverage', $this->own('ea_control_mappings'), fn ($q) => $q->whereIn('coverage', ['full', 'partial']), 'ea.control-mappings'),
            ],
            'SA-3' => [
                $this->ratio('critical_threat_modelled', 'Critical applications threat-modelled', $criticalApps->intersect($threatened)->count(), $criticalApps->count(), 'ea.threats'),
            ],
            'SA-4' => [
                $this->ratio('controls_effective', 'Mapped controls rated effective', $mappedControls->filter(fn ($e) => $e === 'effective')->count(), $mappedControls->count(), 'ea.security-assurance'),
                $this->ratio('ccm_passing', 'CCM tests passing', $ccm->filter(fn ($s) => $s === 'pass')->count(), $ccm->count(), 'ccm.index'),
            ],
            'SA-5' => [
                $this->count('principles', 'Architecture principles', $this->own('ea_principles')->count(), 5, 'ea.principles'),
                $this->share('exceptions_expiring', 'Architecture exceptions with an expiry', $this->own('ea_exceptions'), fn ($q) => $q->whereNotNull('expires_at'), 'ea.exceptions'),
            ],
        ];
    }

    private function own(string $table)
    {
        return DB::table($table)->where('organization_id', $this->org);
    }

    private function count(string $key, string $label, int $n, int $expected, ?string $route): array
    {
        return ['key' => $key, 'label' => $label, 'value' => (string) $n, 'score' => (int) min(100, round(100 * $n / max(1, $expected))), 'route' => $route];
    }

    private function ratio(string $key, string $label, int $n, int $of, ?string $route): ?array
    {
        if ($of === 0) {
            return ['key' => $key, 'label' => $label, 'value' => 'none in scope', 'score' => 0, 'route' => $route];
        }
        $pct = (int) round(100 * $n / $of);

        return ['key' => $key, 'label' => $label, 'value' => "{$n} of {$of} ({$pct}%)", 'score' => $pct, 'route' => $route];
    }

    private function share(string $key, string $label, $query, callable $filter, ?string $route): ?array
    {
        $of = (clone $query)->count();

        return $this->ratio($key, $label, $filter(clone $query)->count(), $of, $route);
    }

    /** Share of rows whose JSON list column is non-empty (checked in PHP: JSON comparison differs by driver). */
    private function listed(string $key, string $label, string $table, string $column, ?string $route): ?array
    {
        $values = $this->own($table)->pluck($column);

        return $this->ratio($key, $label, $values->filter(fn ($v) => ! empty(is_string($v) ? json_decode($v, true) : $v))->count(), $values->count(), $route);
    }
}
