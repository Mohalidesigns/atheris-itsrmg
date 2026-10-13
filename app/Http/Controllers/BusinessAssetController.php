<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BusinessService;
use App\Models\Ea\BusinessService as EaBusinessService;
use App\Models\Ea\Capability;
use App\Models\Ea\Relationship;
use App\Models\User;
use App\Services\Assets\AssetCriticality;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Business Assets — the bank's register of business services: the capability each realises, its
 * owner and recovery objectives, the physical assets that support it, and the services it depends
 * on. Business architecture is canonical in EA (ATH-EAR-002 D1): services are rows of
 * `ea_business_services`, capabilities of `ea_capabilities`, dependencies are `serving` edges in
 * `ea_relationships` (provider → consumer). Supporting assets use the GRC pivot
 * `asset_business_service`, because an asset is not an ArchiMate element.
 */
class BusinessAssetController extends Controller
{
    public const CRITICALITIES = ['critical', 'high', 'medium', 'low'];

    private const SERVICE = EaBusinessService::class;

    public function __construct(private AssetCriticality $criticality) {}

    public function index(Request $request)
    {
        $query = BusinessService::with(['capability:id,code,name', 'owner:id,name'])->withCount(['processes', 'assets']);

        if ($request->filled('criticality')) {
            $query->where('criticality', $request->criticality);
        }
        if ($request->filled('capability_id')) {
            $query->where('capability_id', $request->capability_id);
        }
        if ($request->input('flag') === 'unowned') {
            $query->whereNull('owner_id');
        }
        if ($request->input('flag') === 'no_assets') {
            $query->whereDoesntHave('assets');
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereLike('name', "%{$search}%")
                    ->orWhereLike('code', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%");
            });
        }

        return Inertia::render('Assets/BusinessAssets/Index', [
            'services' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'capabilities' => $this->capabilities(),
            'filters' => $request->only(['criticality', 'capability_id', 'flag', 'search']),
            'criticalities' => self::CRITICALITIES,
        ]);
    }

    public function create()
    {
        return Inertia::render('Assets/BusinessAssets/Create', $this->formOptions());
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $validated['organization_id'] = auth()->user()->organization_id;

        $service = BusinessService::create($validated);

        return redirect()->route('business-assets.show', $service)
            ->with('success', "Business service {$service->code} created successfully.");
    }

    public function show(BusinessService $businessAsset)
    {
        $this->ownBank($businessAsset);

        $businessAsset->load([
            'capability:id,code,name,description',
            'processes:id,service_id,code,name,criticality',
            'owner:id,name,email,job_title',
            'assets' => fn ($q) => $q->select('assets.id', 'asset_id_code', 'name', 'asset_type', 'criticality', 'criticality_inherited', 'status', 'owner_id')
                ->withCount(['vulnerabilities as open_vulns_count' => fn ($v) => $v->whereIn('vulnerabilities.status', ['open', 'in_progress'])]),
        ]);

        $edges = Relationship::query()
            ->where('relation_type', 'serving')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('target_type', self::SERVICE)->where('target_id', $businessAsset->id))
                ->orWhere(fn ($q) => $q->where('source_type', self::SERVICE)->where('source_id', $businessAsset->id)))
            ->get(['id', 'source_type', 'source_id', 'target_type', 'target_id']);

        $names = EaBusinessService::query()->whereIn('id', $edges->pluck('source_id')->merge($edges->pluck('target_id')))
            ->get(['id', 'code', 'name', 'criticality', 'rto_minutes'])->keyBy('id');

        $describe = fn ($edge, $otherId) => ['edge_id' => $edge->id] + ($names[$otherId]?->only(['id', 'code', 'name', 'criticality', 'rto_minutes']) ?? ['id' => $otherId, 'name' => 'Unknown service']);

        // Upstream: services this one depends on (they serve it). Downstream: services it serves.
        $upstream = $edges->filter(fn ($e) => $e->target_type === self::SERVICE && (int) $e->target_id === $businessAsset->id && $e->source_type === self::SERVICE)
            ->map(fn ($e) => $describe($e, (int) $e->source_id))->values();
        $downstream = $edges->filter(fn ($e) => $e->source_type === self::SERVICE && (int) $e->source_id === $businessAsset->id && $e->target_type === self::SERVICE)
            ->map(fn ($e) => $describe($e, (int) $e->target_id))->values();

        // A dependency recovering slower than this service cannot meet this service's RTO.
        $rtoConflicts = $upstream->filter(fn ($u) => $businessAsset->rto_minutes !== null && ($u['rto_minutes'] ?? null) !== null
            && $u['rto_minutes'] > $businessAsset->rto_minutes)->pluck('name')->values();

        return Inertia::render('Assets/BusinessAssets/Show', [
            'service' => $businessAsset,
            'owner' => $businessAsset->owner,
            'upstream' => $upstream,
            'downstream' => $downstream,
            'rtoConflicts' => $rtoConflicts,
            'allServices' => EaBusinessService::query()->where('id', '!=', $businessAsset->id)->orderBy('name')->get(['id', 'code', 'name']),
            'allAssets' => Asset::query()->where('status', '!=', 'decommissioned')->orderBy('asset_id_code')->get(['id', 'asset_id_code', 'name', 'criticality']),
            'can' => [
                'edit' => request()->user()->can('edit assets'),
                'delete' => request()->user()->can('delete assets'),
            ],
        ]);
    }

    public function edit(BusinessService $businessAsset)
    {
        $this->ownBank($businessAsset);

        return Inertia::render('Assets/BusinessAssets/Edit', $this->formOptions() + ['service' => $businessAsset]);
    }

    public function update(Request $request, BusinessService $businessAsset)
    {
        $this->ownBank($businessAsset);

        $businessAsset->update($this->validated($request));
        $this->criticality->recomputeForService($businessAsset->id);

        return redirect()->route('business-assets.show', $businessAsset)
            ->with('success', 'Business service updated successfully.');
    }

    public function destroy(BusinessService $businessAsset)
    {
        $this->ownBank($businessAsset);

        if ($businessAsset->processes()->exists()) {
            return back()->with('error', 'This service has business processes attached and cannot be deleted.');
        }

        $assetIds = DB::table('asset_business_service')->where('business_service_id', $businessAsset->id)->pluck('asset_id')->all();

        DB::transaction(function () use ($businessAsset) {
            Relationship::query()->where(fn ($q) => $q->where('source_type', self::SERVICE)->where('source_id', $businessAsset->id))
                ->orWhere(fn ($q) => $q->where('target_type', self::SERVICE)->where('target_id', $businessAsset->id))
                ->delete();
            $businessAsset->delete();
        });

        if ($assetIds) {
            $this->criticality->recompute($assetIds);
        }

        return redirect()->route('business-assets.index')
            ->with('success', 'Business service deleted.');
    }

    /** Replace the set of physical assets supporting this service. */
    public function assets(Request $request, BusinessService $businessAsset)
    {
        $this->ownBank($businessAsset);

        $validated = $request->validate([
            'asset_ids' => 'present|array',
            'asset_ids.*' => ['integer', Rule::exists('assets', 'id')->where('organization_id', auth()->user()->organization_id)->whereNull('deleted_at')],
        ]);

        $before = DB::table('asset_business_service')->where('business_service_id', $businessAsset->id)->pluck('asset_id')->all();

        $businessAsset->assets()->sync(collect($validated['asset_ids'])->unique()->mapWithKeys(fn ($id) => [
            (int) $id => ['organization_id' => $businessAsset->organization_id, 'role' => 'supports'],
        ])->all());

        $this->criticality->recompute(array_values(array_unique(array_merge($before, $validated['asset_ids']))));

        return back()->with('success', 'Supporting assets updated; inherited criticality recalculated.');
    }

    /** Record that this service depends on another (the provider serves this service). */
    public function storeDependency(Request $request, BusinessService $businessAsset)
    {
        $this->ownBank($businessAsset);

        $validated = $request->validate([
            'provider_id' => ['required', 'integer',
                Rule::exists('ea_business_services', 'id')->where('organization_id', auth()->user()->organization_id)],
        ]);
        $provider = (int) $validated['provider_id'];
        abort_if($provider === $businessAsset->id, 422, 'A service cannot depend on itself.');

        if ($this->reaches($businessAsset->id, $provider)) {
            return back()->with('error', 'That dependency would create a cycle: the chosen service already depends on this one.');
        }

        Relationship::firstOrCreate([
            'organization_id' => $businessAsset->organization_id,
            'source_type' => self::SERVICE, 'source_id' => $provider,
            'target_type' => self::SERVICE, 'target_id' => $businessAsset->id,
            'relation_type' => 'serving',
        ]);

        return back()->with('success', 'Dependency recorded.');
    }

    public function destroyDependency(BusinessService $businessAsset, int $relationship)
    {
        $this->ownBank($businessAsset);

        $edge = Relationship::query()->where('id', $relationship)
            ->where('organization_id', $businessAsset->organization_id)
            ->where('relation_type', 'serving')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('target_type', self::SERVICE)->where('target_id', $businessAsset->id))
                ->orWhere(fn ($q) => $q->where('source_type', self::SERVICE)->where('source_id', $businessAsset->id)))
            ->firstOrFail();

        $edge->delete();

        return back()->with('success', 'Dependency removed.');
    }

    /** True when `$from` already serves (directly or transitively) `$to` — adding to→from would loop. */
    private function reaches(int $from, int $to): bool
    {
        $edges = Relationship::query()->where('relation_type', 'serving')
            ->where('source_type', self::SERVICE)->where('target_type', self::SERVICE)
            ->get(['source_id', 'target_id'])->groupBy('source_id');

        $queue = [$from];
        $seen = [];
        while ($queue) {
            $node = array_shift($queue);
            if ($node === $to) {
                return true;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($edges[$node] ?? [] as $edge) {
                $queue[] = (int) $edge->target_id;
            }
        }

        return false;
    }

    /**
     * The tenant scope on EA models also admits platform-shared (NULL organization) rows. A bank may
     * read those, but only its own services can be changed.
     */
    private function ownBank(BusinessService $service): void
    {
        abort_unless((int) $service->organization_id === (int) auth()->user()->organization_id, 404);
    }

    private function capabilities()
    {
        return Capability::query()->where('organization_id', auth()->user()->organization_id)
            ->orderBy('code')->get(['id', 'code', 'name', 'level']);
    }

    private function formOptions(): array
    {
        return [
            'capabilities' => $this->capabilities(),
            'users' => User::where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            'criticalities' => self::CRITICALITIES,
        ];
    }

    private function validated(Request $request): array
    {
        $orgId = auth()->user()->organization_id;

        return $request->validate([
            'name' => 'required|string|max:255',
            'capability_id' => ['nullable', 'integer', Rule::exists('ea_capabilities', 'id')->where('organization_id', $orgId)],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'criticality' => ['required', Rule::in(self::CRITICALITIES)],
            'recovery_time_objective_min' => 'nullable|integer|min:0|max:525600',
            'recovery_point_objective_min' => 'nullable|integer|min:0|max:525600',
            'description' => 'nullable|string',
        ]);
    }
}
