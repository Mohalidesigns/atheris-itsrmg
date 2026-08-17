<?php

namespace App\Http\Controllers;

use App\Models\BusinessCapability;
use App\Models\BusinessService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Business Assets — the register of business services and the
 * capabilities they support (business_services / business_capabilities).
 */
class BusinessAssetController extends Controller
{
    public const CRITICALITIES = ['critical', 'high', 'medium', 'low'];

    public function index(Request $request)
    {
        $query = BusinessService::with('capability:id,name')->withCount('processes');

        if ($request->filled('criticality')) {
            $query->where('criticality', $request->criticality);
        }
        if ($request->filled('capability_id')) {
            $query->where('capability_id', $request->capability_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $services = $query->orderBy('name')->paginate(20)->withQueryString();

        return Inertia::render('Assets/BusinessAssets/Index', [
            'services' => $services,
            'capabilities' => BusinessCapability::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['criticality', 'capability_id', 'search']),
            'criticalities' => self::CRITICALITIES,
        ]);
    }

    public function create()
    {
        return Inertia::render('Assets/BusinessAssets/Create', [
            'capabilities' => BusinessCapability::orderBy('name')->get(['id', 'name']),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'criticalities' => self::CRITICALITIES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $validated['organization_id'] = auth()->user()->organization_id;

        $service = BusinessService::create($validated);

        return redirect()->route('business-assets.show', $service)
            ->with('success', 'Business service created successfully.');
    }

    public function show(BusinessService $businessAsset)
    {
        $businessAsset->load(['capability:id,name,description', 'processes']);

        $owner = $businessAsset->owner_id
            ? User::select('id', 'name', 'email')->find($businessAsset->owner_id)
            : null;

        return Inertia::render('Assets/BusinessAssets/Show', [
            'service' => $businessAsset,
            'owner' => $owner,
        ]);
    }

    public function edit(BusinessService $businessAsset)
    {
        return Inertia::render('Assets/BusinessAssets/Edit', [
            'service' => $businessAsset,
            'capabilities' => BusinessCapability::orderBy('name')->get(['id', 'name']),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'criticalities' => self::CRITICALITIES,
        ]);
    }

    public function update(Request $request, BusinessService $businessAsset)
    {
        $businessAsset->update($this->validated($request));

        return redirect()->route('business-assets.show', $businessAsset)
            ->with('success', 'Business service updated successfully.');
    }

    public function destroy(BusinessService $businessAsset)
    {
        if ($businessAsset->processes()->exists()) {
            return back()->with('error', 'This service has business processes attached and cannot be deleted.');
        }

        $businessAsset->delete();

        return redirect()->route('business-assets.index')
            ->with('success', 'Business service deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'capability_id' => 'nullable|exists:business_capabilities,id',
            'owner_id' => 'nullable|exists:users,id',
            'criticality' => 'required|in:' . implode(',', self::CRITICALITIES),
            'recovery_time_objective_min' => 'nullable|integer|min:0',
            'recovery_point_objective_min' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
        ]);
    }
}
