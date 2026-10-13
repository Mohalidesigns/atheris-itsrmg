<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Services\Vendors\VendorTiering;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class VendorAssessmentController extends Controller
{
    public function __construct(private VendorTiering $tiering) {}

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
                $q->whereLike('title', "%{$search}%")
                    ->orWhereLike('findings', "%{$search}%");
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
        if ($assessment->status === 'completed') {
            $this->tiering->recordCompletion($assessment);
        }

        return redirect()->route('vendor-assessments.show', $assessment)
            ->with('success', 'Vendor assessment created successfully.');
    }

    public function show(VendorAssessment $vendorAssessment)
    {
        $vendorAssessment->load([
            'vendor:id,name,vendor_code,risk_level,status,category,tier,last_assessed,next_review_date',
            'assessor:id,name,email,job_title',
            'breachEvent:id,vendor_id,headline,event_type,severity,discovered_at',
        ]);

        return Inertia::render('Vendors/Assessments/Show', [
            'assessment' => $vendorAssessment,
            'impliedResidual' => VendorAssessment::residualFor($vendorAssessment->overall_score !== null ? (float) $vendorAssessment->overall_score : null),
            'can' => ['edit' => request()->user()->can('edit vendor-assessments')],
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
        $wasCompleted = $vendorAssessment->status === 'completed';
        $vendorAssessment->update($this->validated($request));
        if (! $wasCompleted && $vendorAssessment->status === 'completed') {
            $this->tiering->recordCompletion($vendorAssessment);
        }

        return redirect()->route('vendor-assessments.show', $vendorAssessment)
            ->with('success', 'Vendor assessment updated successfully.');
    }

    /** Close the assessment: it needs a score and findings, and it resets the vendor's review clock. */
    public function complete(Request $request, VendorAssessment $vendorAssessment)
    {
        abort_if($vendorAssessment->status === 'completed', 422, 'This assessment is already complete.');

        $data = $request->validate([
            'overall_score' => 'required|numeric|min:0|max:100',
            'findings' => 'required|string|min:10',
            'recommendations' => 'nullable|string',
        ]);

        $vendorAssessment->update($data + [
            'status' => 'completed',
            'assessment_date' => $vendorAssessment->assessment_date ?? now()->toDateString(),
        ]);
        $this->tiering->recordCompletion($vendorAssessment);

        $vendor = $vendorAssessment->vendor()->first();

        return back()->with('success', 'Assessment completed: residual risk '.$vendorAssessment->residual_rating
            .'. Next review of '.$vendor->name.' due '.$vendor->next_review_date->format('d M Y').'.');
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
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('organization_id', auth()->user()->organization_id)],
            'title' => 'required|string|max:255',
            'assessment_type' => ['required', Rule::in(VendorAssessment::ASSESSMENT_TYPES)],
            'status' => ['nullable', Rule::in(VendorAssessment::STATUSES)],
            // Completing needs the evidence of the work: a score and findings.
            'overall_score' => 'nullable|required_if:status,completed|numeric|min:0|max:100',
            'assessment_date' => 'nullable|date',
            'findings' => 'nullable|required_if:status,completed|string',
            'recommendations' => 'nullable|string',
            'next_review_date' => 'nullable|date',
        ]);
    }
}
