<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $query = Asset::with(['owner:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('asset_type')) {
            $query->where('asset_type', $request->asset_type);
        }
        if ($request->filled('criticality')) {
            $query->where('criticality', $request->criticality);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('asset_id_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('hostname', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDir);

        $assets = $query->paginate(20)->withQueryString();

        return Inertia::render('Assets/Index', [
            'assets' => $assets,
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'filters' => $request->only(['status', 'asset_type', 'criticality', 'search', 'sort', 'direction']),
            'assetTypes' => Asset::ASSET_TYPES,
            'criticalities' => Asset::CRITICALITIES,
            'statuses' => Asset::STATUSES,
        ]);
    }

    public function create()
    {
        return Inertia::render('Assets/Create', [
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'nextCode' => Asset::generateNextCode(auth()->user()->organization_id),
            'assetTypes' => Asset::ASSET_TYPES,
            'criticalities' => Asset::CRITICALITIES,
            'statuses' => Asset::STATUSES,
            'dataClassifications' => Asset::DATA_CLASSIFICATIONS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'asset_type' => 'required|in:' . implode(',', Asset::ASSET_TYPES),
            'category' => 'nullable|string|max:255',
            'criticality' => 'nullable|in:' . implode(',', Asset::CRITICALITIES),
            'status' => 'nullable|in:' . implode(',', Asset::STATUSES),
            'owner_id' => 'nullable|exists:users,id',
            'department' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:45',
            'hostname' => 'nullable|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:100',
            'license_type' => 'nullable|string|max:100',
            'data_classification' => 'nullable|in:' . implode(',', Asset::DATA_CLASSIFICATIONS),
            'purchase_date' => 'nullable|date',
            'end_of_life' => 'nullable|date',
            'tags' => 'nullable|array',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['asset_id_code'] = Asset::generateNextCode(auth()->user()->organization_id);

        $asset = Asset::create($validated);

        return redirect()->route('assets.show', $asset)
            ->with('success', "Asset {$asset->asset_id_code} created successfully.");
    }

    public function show(Asset $asset)
    {
        $asset->load([
            'owner:id,name,email,job_title',
            'risks:id,title,risk_id_code,status,inherent_rating,inherent_score',
            'vulnerabilities:id,title,vuln_id_code,severity,status',
        ]);

        return Inertia::render('Assets/Show', [
            'asset' => $asset,
            // ATH-EAR-002 §7.4 (contract I-3) — "EA panel on Asset detail
            // (capability, criticality, TIME, residency)". EA is master for
            // the logical application, Assets for the physical instance;
            // this is the logical half of that link.
            'ea' => (new \App\Services\Ea\CrossModulePanels())->forAsset($asset->id),
        ]);
    }

    public function edit(Asset $asset)
    {
        return Inertia::render('Assets/Edit', [
            'asset' => $asset->load(['owner:id,name']),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'assetTypes' => Asset::ASSET_TYPES,
            'criticalities' => Asset::CRITICALITIES,
            'statuses' => Asset::STATUSES,
            'dataClassifications' => Asset::DATA_CLASSIFICATIONS,
        ]);
    }

    public function update(Request $request, Asset $asset)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'asset_type' => 'required|in:' . implode(',', Asset::ASSET_TYPES),
            'category' => 'nullable|string|max:255',
            'criticality' => 'nullable|in:' . implode(',', Asset::CRITICALITIES),
            'status' => 'nullable|in:' . implode(',', Asset::STATUSES),
            'owner_id' => 'nullable|exists:users,id',
            'department' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:45',
            'hostname' => 'nullable|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:100',
            'license_type' => 'nullable|string|max:100',
            'data_classification' => 'nullable|in:' . implode(',', Asset::DATA_CLASSIFICATIONS),
            'purchase_date' => 'nullable|date',
            'end_of_life' => 'nullable|date',
            'tags' => 'nullable|array',
        ]);

        $asset->update($validated);

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Asset updated successfully.');
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();

        return redirect()->route('assets.index')
            ->with('success', "Asset {$asset->asset_id_code} archived.");
    }
}
