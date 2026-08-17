<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorAssessment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VendorAssessmentController extends Controller
{
    public function index(Request $request)
    {
        $query = VendorAssessment::with([
            'vendor:id,name,vendor_code,risk_level',
            'assessor:id,name',
        ]);

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('assessment_type')) {
            $query->where('assessment_type', $request->assessment_type);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('findings', 'like', "%{$search}%");
            });
        }

        $assessments = $query->latest('assessment_date')->paginate(20)->withQueryString();

        return Inertia::render('Vendors/Assessments/Index', [
            'assessments' => $assessments,
            'vendors' => Vendor::select('id', 'name', 'vendor_code')->orderBy('name')->get(),
            'filters' => $request->only(['vendor_id', 'status', 'assessment_type', 'search']),
            'statuses' => VendorAssessment::STATUSES,
            'assessmentTypes' => VendorAssessment::ASSESSMENT_TYPES,
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Vendors/Assessments/Create', [
            'vendors' => Vendor::select('id', 'name', 'vendor_code')->orderBy('name')->get(),
            'preselectedVendorId' => $request->integer('vendor_id') ?: null,
            'statuses' => VendorAssessment::STATUSES,
            'assessmentTypes' => VendorAssessment::ASSESSMENT_TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['assessed_by'] = auth()->id();
        $validated['assessment_date'] = $validated['assessment_date'] ?? now()->toDateString();

        $assessment = VendorAssessment::create($validated);

        return redirect()->route('vendor-assessments.show', $assessment)
            ->with('success', 'Vendor assessment created successfully.');
    }

    public function show(VendorAssessment $vendorAssessment)
    {
        $vendorAssessment->load([
            'vendor:id,name,vendor_code,risk_level,status,category',
            'assessor:id,name,email,job_title',
        ]);

        return Inertia::render('Vendors/Assessments/Show', [
            'assessment' => $vendorAssessment,
        ]);
    }

    public function edit(VendorAssessment $vendorAssessment)
    {
        return Inertia::render('Vendors/Assessments/Edit', [
            'assessment' => $vendorAssessment->load('vendor:id,name,vendor_code'),
            'vendors' => Vendor::select('id', 'name', 'vendor_code')->orderBy('name')->get(),
            'statuses' => VendorAssessment::STATUSES,
            'assessmentTypes' => VendorAssessment::ASSESSMENT_TYPES,
        ]);
    }

    public function update(Request $request, VendorAssessment $vendorAssessment)
    {
        $vendorAssessment->update($this->validated($request));

        return redirect()->route('vendor-assessments.show', $vendorAssessment)
            ->with('success', 'Vendor assessment updated successfully.');
    }

    public function destroy(VendorAssessment $vendorAssessment)
    {
        $vendorAssessment->delete();

        return redirect()->route('vendor-assessments.index')
            ->with('success', 'Vendor assessment deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'title' => 'required|string|max:255',
            'assessment_type' => 'required|in:' . implode(',', VendorAssessment::ASSESSMENT_TYPES),
            'status' => 'nullable|in:' . implode(',', VendorAssessment::STATUSES),
            'overall_score' => 'nullable|numeric|min:0|max:100',
            'assessment_date' => 'nullable|date',
            'findings' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'next_review_date' => 'nullable|date',
        ]);
    }
}
