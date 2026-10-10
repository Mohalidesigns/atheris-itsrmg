<?php

namespace App\Services\ReturnsCentre;

use App\Models\Control;
use App\Models\DataBreach;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\ReturnRun;
use App\Models\ReturnTemplate;
use App\Models\Risk;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use Illuminate\Support\Carbon;

/**
 * Returns Centre auto-population (spec §6.4.3: "per-return wizard with auto-populated fields and
 * manual overrides").
 *
 * Each template field names a `source` resolved against the bank's own register for the run's
 * period. Every query filters on organization_id explicitly, so the figures are right whether or
 * not a tenant scope is active (queue, console, seeder).
 *
 * This is the generic template runner. Returns compiled from the architecture graph, with
 * evidence citations, live in App\Services\Returns (Architecture Returns).
 */
class ReturnRunPopulator
{
    /**
     * Field definitions by return_code. `cadence` sets the period format: monthly 2026-09,
     * quarterly 2026-Q3, annual 2026.
     */
    public const CATALOGUE = [
        'cbn_crms_it' => [
            'cadence' => 'monthly',
            'fields' => [
                ['key' => 'institution', 'label' => 'Reporting institution', 'type' => 'text', 'source' => 'institution'],
                ['key' => 'period', 'label' => 'Reporting period', 'type' => 'text', 'source' => 'period'],
                ['key' => 'it_risks_active', 'label' => 'Active IT risks', 'type' => 'number', 'source' => 'risks_active'],
                ['key' => 'it_risks_high', 'label' => 'High / critical residual IT risks', 'type' => 'number', 'source' => 'risks_high'],
                ['key' => 'risks_above_appetite', 'label' => 'Risks above appetite', 'type' => 'number', 'source' => 'risks_above_appetite'],
                ['key' => 'critical_vulns_open', 'label' => 'Open critical vulnerabilities', 'type' => 'number', 'source' => 'vulns_critical_open'],
                ['key' => 'incidents_in_period', 'label' => 'Cyber incidents recorded in period', 'type' => 'number', 'source' => 'incidents_in_period'],
                ['key' => 'key_controls_ineffective', 'label' => 'Key controls rated ineffective', 'type' => 'number', 'source' => 'key_controls_ineffective'],
            ],
        ],
        'cbn_cyber_sa' => [
            'cadence' => 'annual',
            'fields' => [
                ['key' => 'institution', 'label' => 'Reporting institution', 'type' => 'text', 'source' => 'institution'],
                ['key' => 'period', 'label' => 'Assessment year', 'type' => 'text', 'source' => 'period'],
                ['key' => 'csat_status', 'label' => 'CBN CSAT assessment status', 'type' => 'text', 'source' => 'csat_status'],
                ['key' => 'overall_maturity', 'label' => 'Overall maturity level', 'type' => 'text', 'source' => 'csat_maturity'],
                ['key' => 'inherent_risk', 'label' => 'Composite inherent risk level', 'type' => 'text', 'source' => 'csat_inherent_risk'],
                ['key' => 'critical_vulns_open', 'label' => 'Open critical vulnerabilities', 'type' => 'number', 'source' => 'vulns_critical_open'],
                ['key' => 'incidents_in_period', 'label' => 'Cyber incidents in the year', 'type' => 'number', 'source' => 'incidents_in_period'],
            ],
        ],
        'ndpc_audit' => [
            'cadence' => 'annual',
            'fields' => [
                ['key' => 'institution', 'label' => 'Data controller', 'type' => 'text', 'source' => 'institution'],
                ['key' => 'period', 'label' => 'Audit year', 'type' => 'text', 'source' => 'period'],
                ['key' => 'breaches_in_period', 'label' => 'Personal data breaches in the year', 'type' => 'number', 'source' => 'breaches_in_period'],
                ['key' => 'breaches_notified', 'label' => 'Breaches notified to NDPC', 'type' => 'number', 'source' => 'breaches_notified'],
                ['key' => 'records_affected', 'label' => 'Data subject records affected', 'type' => 'number', 'source' => 'breach_records_affected'],
                ['key' => 'policies_in_force', 'label' => 'Approved policies in force', 'type' => 'number', 'source' => 'policies_in_force'],
                ['key' => 'dpo_name', 'label' => 'Data Protection Compliance Officer', 'type' => 'text', 'source' => null],
            ],
        ],
        'ndic_outsource' => [
            'cadence' => 'quarterly',
            'fields' => [
                ['key' => 'institution', 'label' => 'Reporting institution', 'type' => 'text', 'source' => 'institution'],
                ['key' => 'period', 'label' => 'Quarter', 'type' => 'text', 'source' => 'period'],
                ['key' => 'active_providers', 'label' => 'Active outsourced service providers', 'type' => 'number', 'source' => 'vendors_active'],
                ['key' => 'critical_providers', 'label' => 'Critical / high-risk providers', 'type' => 'number', 'source' => 'vendors_critical'],
                ['key' => 'assessed_12m', 'label' => 'Providers assessed in the last 12 months', 'type' => 'number', 'source' => 'vendors_assessed_12m'],
                ['key' => 'contracts_expiring', 'label' => 'Contracts expiring within 90 days', 'type' => 'number', 'source' => 'vendors_expiring_90d'],
            ],
        ],
    ];

    public static function definition(ReturnTemplate $template): array
    {
        if (isset(self::CATALOGUE[$template->return_code])) {
            return self::CATALOGUE[$template->return_code];
        }

        // A template configured outside the catalogue: its stored schema, entered manually.
        return ['cadence' => 'monthly', 'fields' => collect($template->schema['fields'] ?? [])
            ->map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'] ?? $f['key'], 'type' => $f['type'] ?? 'text', 'source' => $f['source'] ?? null])
            ->all()];
    }

    public static function cadence(ReturnTemplate $template): string
    {
        return self::definition($template)['cadence'];
    }

    /** The current reporting period for a cadence. */
    public static function currentPeriod(string $cadence, ?Carbon $at = null): string
    {
        $at ??= now();

        return match ($cadence) {
            'annual' => (string) $at->year,
            'quarterly' => $at->year.'-Q'.$at->quarter,
            default => $at->format('Y-m'),
        };
    }

    public static function periodPattern(string $cadence): string
    {
        return match ($cadence) {
            'annual' => '/^\d{4}$/',
            'quarterly' => '/^\d{4}-Q[1-4]$/',
            default => '/^\d{4}-(0[1-9]|1[0-2])$/',
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function periodRange(string $period): array
    {
        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m)) {
            $start = Carbon::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1)->startOfDay();

            return [$start, $start->copy()->addMonths(3)->subSecond()];
        }
        if (preg_match('/^\d{4}$/', $period)) {
            return [Carbon::create((int) $period, 1, 1)->startOfDay(), Carbon::create((int) $period, 12, 31)->endOfDay()];
        }
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * Auto-populate every sourced field. Returns the `data` payload: values keyed by field, plus the
     * overrides already on the run so a refresh never discards a reviewer's manual entry.
     */
    public function populate(ReturnRun $run): array
    {
        $template = $run->template;
        $definition = self::definition($template);
        $orgId = (int) $run->organization_id;
        $values = [];

        foreach ($definition['fields'] as $field) {
            $values[$field['key']] = $field['source'] ? $this->resolve($field['source'], $orgId, $run->period) : null;
        }

        return [
            'auto_populated' => true,
            'populated_at' => now()->toIso8601String(),
            'values' => $values,
            'overrides' => $run->data['overrides'] ?? [],
        ];
    }

    /** Final figure for each field: an override where one exists, otherwise the computed value. */
    public static function effectiveValues(ReturnRun $run): array
    {
        $values = $run->data['values'] ?? [];
        foreach ($run->data['overrides'] ?? [] as $key => $override) {
            $values[$key] = $override['value'] ?? null;
        }

        return $values;
    }

    public function resolve(string $source, int $orgId, string $period): string|int|null
    {
        [$from, $to] = self::periodRange($period);

        return match ($source) {
            'institution' => Organization::find($orgId)?->name,
            'period' => $period,
            'risks_active' => Risk::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->active()->count(),
            'risks_high' => Risk::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->active()
                ->whereIn('residual_rating', ['high', 'critical'])->count(),
            'risks_above_appetite' => Risk::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->active()
                ->where('risk_appetite', 'above')->count(),
            'vulns_critical_open' => Vulnerability::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
                ->where('severity', 'critical')->whereIn('status', ['open', 'in_progress'])->count(),
            'incidents_in_period' => Incident::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
                ->whereBetween('detected_at', [$from, $to])->count(),
            'key_controls_ineffective' => Control::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
                ->where('is_key_control', true)->where('effectiveness', 'ineffective')->count(),
            'csat_status', 'csat_maturity', 'csat_inherent_risk' => $this->csat($source, $orgId, $period),
            // Breaches belong to the period in which they were discovered, not when they were typed in.
            'breaches_in_period' => DataBreach::withoutGlobalScopes()->where('organization_id', $orgId)
                ->whereBetween('discovered_at', [$from, $to])->count(),
            'breaches_notified' => DataBreach::withoutGlobalScopes()->where('organization_id', $orgId)
                ->whereBetween('discovered_at', [$from, $to])->whereNotNull('ndpa_notified_at')->count(),
            'breach_records_affected' => (int) DataBreach::withoutGlobalScopes()->where('organization_id', $orgId)
                ->whereBetween('discovered_at', [$from, $to])->sum('records_affected'),
            'policies_in_force' => Policy::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
                ->whereIn('status', ['approved', 'active'])->count(),
            'vendors_active' => Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'active')->count(),
            'vendors_critical' => Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'active')
                ->whereIn('risk_level', ['critical', 'high'])->count(),
            'vendors_assessed_12m' => Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'active')
                ->where('last_assessed', '>=', $to->copy()->subYear())->count(),
            'vendors_expiring_90d' => Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'active')
                ->whereBetween('contract_end', [$to->copy()->startOfDay(), $to->copy()->addDays(90)])->count(),
            default => null,
        };
    }

    private function csat(string $source, int $orgId, string $period): ?string
    {
        $year = (int) substr($period, 0, 4);
        $assessment = CsatAssessment::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
            ->where('assessment_year', '<=', $year)->orderByDesc('assessment_year')->orderByDesc('id')->first();

        if (! $assessment) {
            return null;
        }

        $value = match ($source) {
            'csat_status' => $assessment->status,
            'csat_maturity' => $assessment->overall_maturity_level,
            'csat_inherent_risk' => $assessment->composite_risk_level,
        };

        return $value ? str_replace('_', ' ', (string) $value) : null;
    }
}
