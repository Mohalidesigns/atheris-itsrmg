<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Ea\BusinessService;
use App\Models\Ea\Capability;
use App\Models\Ea\Relationship;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Business Services overview and dependency graph (plan gap D1.3). Reads the bank's canonical EA
 * business architecture: capabilities → services → supporting assets, with the `serving` edges
 * between services. Replaces the PlatformController stubs, whose graph read edges the platform
 * seeder had typed "service" (never matched) and was centred on a hard-coded "Kano Heritage Core".
 */
class BusinessServiceMapController extends Controller
{
    private const SERVICE = BusinessService::class;

    public function index()
    {
        $services = $this->services();
        $edges = $this->edges($services->pluck('id'));

        $capabilityIds = $services->pluck('capability_id')->filter()->unique();
        $capabilities = Capability::query()->whereIn('id', $capabilityIds)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($c) => $c->only(['id', 'code', 'name']) + ['services_count' => $services->where('capability_id', $c->id)->count()]);

        $rows = $services->map(fn ($s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'criticality' => $s->criticality,
            'capability' => $s->capability?->name, 'owner' => $s->owner?->name,
            'rto_minutes' => $s->rto_minutes, 'rpo_minutes' => $s->rpo_minutes,
            'processes_count' => $s->processes_count, 'assets_count' => $s->assets_count,
            'depends_on' => $edges->where('target_id', $s->id)->count(),
            'dependents' => $edges->where('source_id', $s->id)->count(),
        ]);

        return Inertia::render('BusinessServices/Index', [
            'services' => $rows->values(),
            'capabilities' => $capabilities->values(),
            'totals' => [
                'services' => $rows->count(),
                'critical' => $rows->where('criticality', 'critical')->count(),
                'unowned' => $rows->whereNull('owner')->count(),
                'without_assets' => $rows->where('assets_count', 0)->count(),
                'dependencies' => $edges->count(),
            ],
        ]);
    }

    public function graph(Request $request)
    {
        $criticality = in_array($request->input('criticality'), ['critical', 'high', 'medium', 'low'], true) ? $request->input('criticality') : null;
        $showAssets = $request->boolean('assets', true);

        $services = $this->services()->when($criticality, fn ($c) => $c->where('criticality', $criticality))->values();
        $ids = $services->pluck('id');
        $edges = $this->edges($ids)->filter(fn ($e) => $ids->contains($e->source_id) && $ids->contains($e->target_id))->values();

        $capabilities = Capability::query()->whereIn('id', $services->pluck('capability_id')->filter()->unique())->orderBy('code')->get(['id', 'code', 'name']);

        $assets = $showAssets
            ? Asset::query()->whereHas('businessServices', fn ($q) => $q->whereIn('ea_business_services.id', $ids))
                ->with(['businessServices' => fn ($q) => $q->select('ea_business_services.id')])
                ->get(['id', 'asset_id_code', 'name', 'criticality', 'criticality_inherited'])
            : collect();

        return Inertia::render('BusinessServices/Graph', [
            'capabilities' => $capabilities,
            'services' => $services->map(fn ($s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'criticality' => $s->criticality,
                'capability_id' => $s->capability_id, 'rto_minutes' => $s->rto_minutes, 'assets_count' => $s->assets_count,
            ])->values(),
            'dependencies' => $edges->map(fn ($e) => ['id' => $e->id, 'provider_id' => (int) $e->source_id, 'consumer_id' => (int) $e->target_id])->values(),
            'assets' => $assets->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->asset_id_code, 'name' => $a->name, 'criticality' => $a->effective_criticality,
                'service_ids' => $a->businessServices->pluck('id')->intersect($ids)->values(),
            ])->values(),
            'filters' => ['criticality' => $criticality, 'assets' => $showAssets],
            'orgName' => $request->user()->organization?->name,
        ]);
    }

    private function services()
    {
        return BusinessService::query()
            ->where('organization_id', auth()->user()->organization_id)
            ->with(['capability:id,name', 'owner:id,name'])
            ->withCount(['processes', 'assets'])
            ->orderBy('code')
            ->get();
    }

    /** `serving` edges between the given services: source = provider, target = consumer. */
    private function edges($serviceIds)
    {
        return Relationship::query()
            ->where('relation_type', 'serving')
            ->where('source_type', self::SERVICE)->where('target_type', self::SERVICE)
            ->whereIn('target_id', $serviceIds)
            ->get(['id', 'source_id', 'target_id'])
            ->map(function ($e) {
                $e->source_id = (int) $e->source_id;
                $e->target_id = (int) $e->target_id;

                return $e;
            });
    }
}
