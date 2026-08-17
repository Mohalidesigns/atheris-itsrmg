<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $query = Vendor::withCount('assessments');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->risk_level);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('vendor_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('contact_name', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDir);

        $vendors = $query->paginate(20)->withQueryString();

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors,
            'filters' => $request->only(['status', 'risk_level', 'search', 'sort', 'direction']),
            'riskLevels' => Vendor::RISK_LEVELS,
            'statuses' => Vendor::STATUSES,
        ]);
    }

    public function create()
    {
        return Inertia::render('Vendors/Create', [
            'nextCode' => Vendor::generateNextCode(auth()->user()->organization_id),
            'riskLevels' => Vendor::RISK_LEVELS,
            'statuses' => Vendor::STATUSES,
            'dataAccessLevels' => Vendor::DATA_ACCESS_LEVELS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'risk_level' => 'nullable|in:' . implode(',', Vendor::RISK_LEVELS),
            'status' => 'nullable|in:' . implode(',', Vendor::STATUSES),
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'country' => 'nullable|string|max:100',
            'services_provided' => 'nullable|string',
            'contract_start' => 'nullable|date',
            'contract_end' => 'nullable|date',
            'contract_value' => 'nullable|numeric|min:0',
            'contract_currency' => 'nullable|string|max:10',
            'data_access_level' => 'nullable|in:' . implode(',', Vendor::DATA_ACCESS_LEVELS),
            'sla_details' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['vendor_code'] = Vendor::generateNextCode(auth()->user()->organization_id);

        $vendor = Vendor::create($validated);

        return redirect()->route('vendors.show', $vendor)
            ->with('success', "Vendor {$vendor->vendor_code} created successfully.");
    }

    public function show(Vendor $vendor)
    {
        $vendor->load([
            'assessments' => fn ($q) => $q->with('assessor:id,name')->latest()->limit(20),
        ]);

        return Inertia::render('Vendors/Show', [
            'vendor' => $vendor,
        ]);
    }

    public function edit(Vendor $vendor)
    {
        return Inertia::render('Vendors/Edit', [
            'vendor' => $vendor,
            'riskLevels' => Vendor::RISK_LEVELS,
            'statuses' => Vendor::STATUSES,
            'dataAccessLevels' => Vendor::DATA_ACCESS_LEVELS,
        ]);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'risk_level' => 'nullable|in:' . implode(',', Vendor::RISK_LEVELS),
            'status' => 'nullable|in:' . implode(',', Vendor::STATUSES),
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'country' => 'nullable|string|max:100',
            'services_provided' => 'nullable|string',
            'contract_start' => 'nullable|date',
            'contract_end' => 'nullable|date',
            'contract_value' => 'nullable|numeric|min:0',
            'contract_currency' => 'nullable|string|max:10',
            'data_access_level' => 'nullable|in:' . implode(',', Vendor::DATA_ACCESS_LEVELS),
            'sla_details' => 'nullable|string',
            'next_review_date' => 'nullable|date',
            'tags' => 'nullable|array',
        ]);

        $vendor->update($validated);

        return redirect()->route('vendors.show', $vendor)
            ->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Vendor $vendor)
    {
        if ($vendor->assessments()->exists()) {
            return back()->with('error', "Vendor {$vendor->vendor_code} has assessments on record and cannot be deleted. Mark the vendor as terminated instead.");
        }

        $vendor->delete();

        return redirect()->route('vendors.index')
            ->with('success', "Vendor {$vendor->vendor_code} deleted.");
    }
}
