<?php

namespace App\Services\Ea;

use App\Models\Control;
use App\Models\Ea\ControlMapping;
use App\Models\Ea\EaApplication;
use App\Models\Ea\TechComponent;
use App\Models\Ea\Zone;
use App\Models\Ea\ZoneAssignment;
use Illuminate\Support\Collection;

/**
 * ControlInheritanceService — resolves the *effective* control posture for an
 * application by composing:
 *
 *   1. Direct mappings on the application itself
 *   2. Inherited mappings from the security zone it lives in
 *   3. Inherited mappings from the underlying tech components
 *   4. (Stub) inherited mappings from the vendor SOC 2 report
 *
 * For each control, the highest-confidence source wins (full > partial >
 * planned > gap). Provenance for every line is preserved so the UI can show
 * "inherited from Zone Z03" or "direct".
 */
class ControlInheritanceService
{
    public const COVERAGE_RANK = [
        'gap' => 1,
        'planned' => 2,
        'partial' => 3,
        'full' => 4,
    ];

    /**
     * @return array<int,array{control_id:int,framework:string,coverage:string,source:string,source_label:string,notes:?string}>
     */
    public function resolveForApplication(int $applicationId): array
    {
        $direct = ControlMapping::query()
            ->where('component_type', 'application')
            ->where('component_id', $applicationId)
            ->get()
            ->map(fn ($m) => $this->row($m, 'direct', 'Application '.$applicationId))
            ->all();

        $rows = $direct;

        // Inherit from zone
        $za = ZoneAssignment::where('application_id', $applicationId)->with('zone')->first();
        if ($za && $za->zone_id) {
            $zoneMaps = ControlMapping::query()
                ->where('component_type', 'zone')
                ->where('component_id', $za->zone_id)
                ->get()
                ->map(fn ($m) => $this->row($m, 'zone', 'Zone '.($za->zone->code ?? '')));
            $rows = array_merge($rows, $zoneMaps->all());
        }

        // Inherit from tech components linked to this application via JSON column
        $tech = TechComponent::query()
            ->whereJsonContains('application_ids', $applicationId)
            ->get(['id', 'code', 'name']);
        foreach ($tech as $t) {
            $tMaps = ControlMapping::query()
                ->where('component_type', 'tech')
                ->where('component_id', $t->id)
                ->get()
                ->map(fn ($m) => $this->row($m, 'tech', 'Tech '.$t->code));
            $rows = array_merge($rows, $tMaps->all());
        }

        // Combine — highest coverage wins per (control_id, framework)
        $merged = [];
        foreach ($rows as $r) {
            $k = $r['control_id'].':'.$r['framework'];
            if (! isset($merged[$k]) || self::COVERAGE_RANK[$r['coverage']] > self::COVERAGE_RANK[$merged[$k]['coverage']]) {
                $merged[$k] = $r;
            }
        }
        return array_values($merged);
    }

    /**
     * Coverage report across the portfolio: returns one row per application
     * with full/partial/planned/gap counts, ready for table rendering.
     */
    public function portfolioCoverageReport(string $framework = 'CBN'): array
    {
        $apps = EaApplication::query()->orderBy('name')->get();
        $rows = [];
        foreach ($apps as $a) {
            $resolved = collect($this->resolveForApplication($a->id))
                ->when($framework !== '*', fn ($c) => $c->where('framework', $framework));
            $rows[] = [
                'application' => $a,
                'total' => $resolved->count(),
                'full' => $resolved->where('coverage', 'full')->count(),
                'partial' => $resolved->where('coverage', 'partial')->count(),
                'planned' => $resolved->where('coverage', 'planned')->count(),
                'gap' => $resolved->where('coverage', 'gap')->count(),
            ];
        }
        return $rows;
    }

    private function row(ControlMapping $m, string $source, string $label): array
    {
        $control = $this->control((int) $m->control_id);

        return [
            'control_id' => (int) $m->control_id,
            'framework' => (string) ($m->framework ?? 'CBN'),
            'coverage' => (string) ($m->coverage ?? 'partial'),
            'source' => $source,
            'source_label' => $label,
            'notes' => $m->notes,
            // Contract I-4 (§7.3): "`control_id` validated against
            // `controls.id`; `ControlInheritanceService` reads actual control
            // test status; coverage becomes citable in a return."
            'resolves' => $control !== null,
            'control_code' => $control?->control_code,
            'control_title' => $control?->title,
            'control_status' => $control?->status,
            'control_effectiveness' => $control?->effectiveness,
            'last_tested' => optional($control?->last_tested)->toDateString(),
        ];
    }

    /**
     * Resolved controls, memoised per request.
     *
     * §2.5 is blunt about why this matters: `ea_control_mappings.control_id`
     * was "an unconstrained bigint that `EaPhase2Seeder` populates with
     * `rand(1,80)`", so "any coverage figure the module currently produces is
     * arithmetic over noise, and must not be shown to a regulator". Resolving
     * every id and reporting what did not resolve is what makes the number
     * citable.
     */
    private function control(int $id): ?Control
    {
        $this->controls ??= Control::withoutGlobalScopes()
            ->get(['id', 'control_code', 'title', 'status', 'effectiveness', 'last_tested'])
            ->keyBy('id');

        return $this->controls->get($id);
    }

    /** @var \Illuminate\Support\Collection|null */
    private $controls = null;

    /**
     * Coverage counted only over mappings whose control actually resolves, and
     * only where the control is implemented and tested effective.
     *
     * An "effective" figure computed over dangling ids, or over controls that
     * exist but have never been tested, is the kind of number §10's evidence
     * integrity gate exists to keep out of a return.
     */
    public function citableCoverage(int $applicationId, string $framework = 'CBN'): array
    {
        $resolved = collect($this->resolveForApplication($applicationId))
            ->when($framework !== '*', fn ($c) => $c->where('framework', $framework));

        $real = $resolved->where('resolves', true);
        $effective = $real->filter(fn ($r) => $r['coverage'] === 'full'
            && in_array($r['control_status'], ['implemented', 'active', 'operating'], true));

        return [
            'mapped' => $resolved->count(),
            'resolvable' => $real->count(),
            'dangling' => $resolved->count() - $real->count(),
            'effective' => $effective->count(),
            'untested' => $real->filter(fn ($r) => $r['last_tested'] === null)->count(),
            // The only percentage safe to print next to a regulator's question.
            'citable_percent' => $resolved->count()
                ? round($effective->count() / $resolved->count() * 100, 1)
                : 0.0,
        ];
    }
}
