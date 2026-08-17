<?php

namespace App\Http\Controllers\Ea;

use App\Http\Controllers\Controller;
use App\Models\Ea\Capability;
use App\Models\Ea\Channel;
use App\Models\Ea\Rail;
use App\Models\Ea\Site;
use App\Models\LegalEntity;
use App\Services\Ea\ConcentrationService;
use App\Services\Ea\FxExposureService;
use App\Services\Ea\ResidencyService;
use App\Services\Ea\SiteResilienceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * WedgeController — the five Africa-first lenses (§6.3 A2–A7).
 *
 * They share a controller because they share a shape: each reads the
 * architecture graph through one regulatory or market lens and produces a
 * register plus a headline. None of them writes.
 */
class WedgeController extends Controller
{
    /** A2 — Data Residency & Localisation Control Tower. */
    public function residency(Request $request)
    {
        Gate::authorize('viewAny', Capability::class);

        $service = new ResidencyService();

        return Inertia::render('Ea/Residency', [
            'summary' => $service->summary(),
            'gapRegister' => $service->gapRegister(),
            'crossBorderFlows' => $service->crossBorderFlows(),
            'readiness' => $service->sovereignReadiness(),
            'entities' => $this->entityOptions(),
        ]);
    }

    /** A3 — FX Exposure lens. §6.3: "the artefact that sells to the CFO". */
    public function fx(Request $request)
    {
        Gate::authorize('viewAny', Capability::class);

        $service = new FxExposureService();

        $scenarios = $request->filled('rate')
            ? [(float) $request->query('rate')]
            : [];

        return Inertia::render('Ea/FxExposure', [
            'dashboard' => $service->dashboard(),
            'stressTest' => $service->stressTest(),
            'customScenario' => $scenarios ? $service->stressTest($scenarios) : null,
            'renewals' => $service->renewalCalendar(),
            'candidates' => $service->localisationCandidates(),
            'rates' => $service->rates(),
        ]);
    }

    /** A4 — Vendor & Integrator Concentration, deepened. */
    public function concentration()
    {
        Gate::authorize('viewAny', Capability::class);

        $service = new ConcentrationService();

        return Inertia::render('Ea/Concentration', [
            'summary' => $service->summary(),
            'byCapability' => $service->byCapability(),
            'byRole' => $service->byRole(),
            'spofRegister' => $service->spofRegister(),
            'roles' => ConcentrationService::ROLES,
        ]);
    }

    /** A5 — Power & Site Resilience. */
    public function sites()
    {
        Gate::authorize('viewAny', Capability::class);

        $service = new SiteResilienceService();

        return Inertia::render('Ea/Sites', [
            'overview' => $service->overview(),
            'sites' => Site::orderBy('country')->orderBy('city')->orderBy('name')->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => $s->name,
                    'operator' => $s->operator,
                    'city' => $s->city,
                    'country' => $s->country,
                    'tia942_tier' => $s->tia942_tier,
                    'generator_autonomy_hours' => $s->generator_autonomy_hours,
                    'ups_autonomy_minutes' => $s->ups_autonomy_minutes,
                    'autonomy_hours' => $s->outageAutonomyHours(),
                    'flood_risk' => $s->flood_risk,
                    'grid_reliability_band' => $s->grid_reliability_band,
                    'grid_zone' => $s->grid_zone,
                    'connectivity' => $s->connectivity_providers,
                    'resilience_score' => $s->resilienceScore(),
                    'resilience_band' => $s->resilienceBand(),
                    'is_sovereign' => $s->is_sovereign,
                ]),
            'alerts' => $service->concentrationAlerts(),
            'postureAgainstBia' => $service->postureAgainstBia(),
            'singleSiteDependencies' => $service->singleSiteDependencies(),
        ]);
    }

    /** A7 — Channels & Rails. */
    public function channels()
    {
        Gate::authorize('viewAny', Capability::class);

        $channels = Channel::with(['telco:id,name', 'aggregator:id,name', 'legalEntity:id,name'])
            ->orderBy('channel_type')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'channel_type' => $c->channel_type,
                'type_label' => $c->typeLabel(),
                'criticality' => $c->criticality,
                'shortcode' => $c->shortcode,
                'telco' => $c->telco?->name,
                'aggregator' => $c->aggregator?->name,
                'legal_entity' => $c->legalEntity?->name,
                'third_party_can_suspend' => $c->third_party_can_suspend,
                'suspension_risk_notes' => $c->suspension_risk_notes,
                'is_exposed' => $c->isSuspensionExposed(),
                'status' => $c->status,
            ]);

        $rails = Rail::with('operatorVendor:id,name')->orderBy('rail_type')->orderBy('code')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'rail_type' => $r->rail_type,
                'type_label' => $r->typeLabel(),
                'operator' => $r->operator,
                'is_mandatory' => $r->is_mandatory,
                'conditions' => $r->conditionSummary(),
                'log_retention_years' => $r->log_retention_years,
                'interface_count' => $r->interfaces()->count(),
                'status' => $r->status,
            ]);

        return Inertia::render('Ea/Channels', [
            'channels' => $channels,
            'rails' => $rails,
            'summary' => [
                'channels' => $channels->count(),
                'exposed_channels' => $channels->where('is_exposed', true)->count(),
                'rails' => $rails->where('rail_type', 'rail')->count(),
                'switches' => $rails->where('rail_type', 'switch')->count(),
                'schemes' => $rails->where('rail_type', 'scheme')->count(),
            ],
        ]);
    }

    /** A6 — Legal entities and the group roll-up. */
    public function entities()
    {
        Gate::authorize('viewAny', Capability::class);

        $entities = LegalEntity::with('parent:id,name')->orderBy('code')->get();
        $residency = new ResidencyService();
        $gaps = collect($residency->gapRegister());

        return Inertia::render('Ea/LegalEntities', [
            'entities' => $entities->map(fn ($e) => [
                'id' => $e->id,
                'code' => $e->code,
                'name' => $e->name,
                'jurisdiction' => $e->jurisdiction,
                'jurisdiction_name' => $e->jurisdiction_name,
                'regulators' => $e->effectiveRegulators(),
                'licence_class' => $e->licence_class,
                'licence_label' => LegalEntity::LICENCE_CLASSES[$e->licence_class]['label'] ?? null,
                'parent' => $e->parent?->name,
                'is_group_parent' => $e->is_group_parent,
                'capital_base_ngn' => $e->capital_base_ngn,
                'meets_capital_threshold' => $e->meetsCapitalThreshold(),
                'itsb_category' => $e->itsbCategory(),
                'itsb_target_level' => $e->itsbTargetLevel(),
                'instances' => $e->instances()->count(),
                'applications' => $e->applications()->count(),
                'localisation_gaps' => $gaps->where('legal_entity', $e->name)->count(),
            ]),
            'summary' => [
                'entities' => $entities->count(),
                'jurisdictions' => $entities->pluck('jurisdiction')->unique()->count(),
                'regulators' => $entities->flatMap(fn ($e) => $e->effectiveRegulators())->unique()->values(),
                'category_one' => $entities->filter(fn ($e) => $e->itsbCategory() === 'one')->count(),
                'category_two' => $entities->filter(fn ($e) => $e->itsbCategory() === 'two')->count(),
            ],
        ]);
    }

    private function entityOptions(): array
    {
        return LegalEntity::orderBy('code')->get(['id', 'code', 'name', 'jurisdiction'])
            ->map(fn ($e) => [
                'value' => $e->id,
                'label' => "{$e->name} ({$e->jurisdiction})",
            ])->all();
    }
}
