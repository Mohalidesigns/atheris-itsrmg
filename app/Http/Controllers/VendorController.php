<?php

namespace App\Http\Controllers;

use App\Models\SharedVendorDirectory;
use App\Models\TprmBreachEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Vendors\VendorMetrics;
use App\Services\Vendors\VendorTiering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class VendorController extends Controller
{
    public function __construct(private VendorTiering $tiering) {}

    public function index(Request $request)
    {
        $query = Vendor::withCount('assessments')->with(['owner:id,name', 'latestRating']);

        foreach (['status', 'risk_level', 'tier'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->input($column));
            }
        }
        if (! $request->filled('status')) {
            // Terminated relationships stay on record but out of the working register.
            $query->where('status', '!=', 'terminated');
        }

        $today = now()->toDateString();
        match ($request->input('flag')) {
            'review_overdue' => $query->whereDate('next_review_date', '<', $today),
            'contract_expiring' => $query->whereDate('contract_end', '>=', $today)
                ->whereDate('contract_end', '<=', now()->addDays(config('tprm.contract_warning_days'))->toDateString()),
            'missing_dpa' => $query->where('processes_personal_data', true)->whereNull('dpa_signed_at'),
            'material_not_notified' => $query->where('material_outsourcing', true)->whereNull('regulator_notified_at'),
            'unowned' => $query->whereNull('owner_id'),
            default => null,
        };

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereLike('name', "%{$search}%")
                    ->orWhereLike('vendor_code', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%")
                    ->orWhereLike('contact_name', "%{$search}%");
            });
        }

        $sortField = in_array($request->get('sort'), Vendor::SORTABLE, true) ? $request->get('sort') : 'tier';
        $sortDir = $request->get('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDir)->orderBy('name');

        return Inertia::render('Vendors/Index', [
            'vendors' => $query->paginate(20)->withQueryString(),
            'metrics' => app(VendorMetrics::class)->summary(),
            'filters' => $request->only(['status', 'risk_level', 'tier', 'flag', 'search', 'sort', 'direction']),
            'riskLevels' => Vendor::RISK_LEVELS,
            'statuses' => Vendor::STATUSES,
            'tiers' => Vendor::TIERS,
        ]);
    }

    public function create(Request $request)
    {
        $entry = $request->filled('directory') ? SharedVendorDirectory::find($request->integer('directory')) : null;

        return Inertia::render('Vendors/Create', $this->formOptions() + [
            'nextCode' => Vendor::generateNextCode(auth()->user()->organization_id),
            // "Add to my register" from the Shared Directory pre-fills the form from the entry.
            'prefill' => $entry ? [
                'name' => $entry->legal_name, 'category' => $entry->category, 'country' => $entry->country,
                'description' => $entry->description, 'shared_directory_id' => $entry->id,
                'contact_name' => $entry->contacts['name'] ?? null, 'contact_email' => $entry->contacts['email'] ?? null,
                'website' => $entry->contacts['website'] ?? null,
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['vendor_code'] = Vendor::generateNextCode(auth()->user()->organization_id);

        $vendor = Vendor::create($validated);
        $this->tiering->apply($vendor);

        return redirect()->route('vendors.show', $vendor)
            ->with('success', "Vendor {$vendor->vendor_code} created as Tier {$vendor->tier}.");
    }

    public function show(Vendor $vendor)
    {
        $vendor->load([
            'owner:id,name,email,job_title',
            'directoryEntry',
            'assessments' => fn ($q) => $q->with('assessor:id,name')->latest('assessment_date')->limit(20),
            'ratings' => fn ($q) => $q->orderBy('captured_at')->limit(36),
            'breachEvents' => fn ($q) => $q->with(['reviewer:id,name', 'reassessment:id,breach_event_id,title,status'])->latest('discovered_at')->limit(20),
        ]);

        $applications = DB::table('ea_applications_ext')->where('organization_id', $vendor->organization_id)
            ->where('vendor_id', $vendor->id)->orderBy('name')->get(['id', 'code', 'name', 'criticality']);

        return Inertia::render('Vendors/Show', [
            'vendor' => $vendor,
            'tiering' => $this->tiering->evaluate($vendor),
            'applications' => $applications,
            'reviewMonths' => config('tprm.review_months'),
            'can' => [
                'edit' => request()->user()->can('edit vendors'),
                'delete' => request()->user()->can('delete vendors'),
                'assess' => request()->user()->can('create vendor-assessments'),
            ],
            'breachStatuses' => TprmBreachEvent::STATUSES,
        ]);
    }

    public function edit(Vendor $vendor)
    {
        return Inertia::render('Vendors/Edit', $this->formOptions() + ['vendor' => $vendor]);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $vendor->update($this->validated($request, $vendor));
        $this->tiering->apply($vendor->refresh());

        return redirect()->route('vendors.show', $vendor)
            ->with('success', "Vendor updated (Tier {$vendor->tier}).");
    }

    public function destroy(Vendor $vendor)
    {
        if ($vendor->assessments()->exists()) {
            return back()->with('error', "Vendor {$vendor->vendor_code} has assessments on record and cannot be deleted. Mark the vendor as terminated instead.");
        }
        if (DB::table('ea_applications_ext')->where('vendor_id', $vendor->id)->exists()) {
            return back()->with('error', "Vendor {$vendor->vendor_code} supplies applications in the architecture repository and cannot be deleted. Mark it as terminated instead.");
        }

        $vendor->delete();

        return redirect()->route('vendors.index')
            ->with('success', "Vendor {$vendor->vendor_code} deleted.");
    }

    private function formOptions(): array
    {
        return [
            'users' => User::where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            'directory' => SharedVendorDirectory::orderBy('legal_name')->get(['id', 'legal_name', 'category']),
            'riskLevels' => Vendor::RISK_LEVELS,
            'statuses' => Vendor::STATUSES,
            'dataAccessLevels' => Vendor::DATA_ACCESS_LEVELS,
        ];
    }

    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'risk_level' => ['nullable', Rule::in(Vendor::RISK_LEVELS)],
            'status' => ['nullable', Rule::in(Vendor::STATUSES)],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id)],
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'domain' => ['nullable', 'string', 'max:255', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i'],
            'country' => 'nullable|string|max:100',
            'services_provided' => 'nullable|string',
            'contract_start' => 'nullable|date',
            'contract_end' => 'nullable|date|after_or_equal:contract_start',
            'contract_value' => 'nullable|numeric|min:0',
            'contract_currency' => 'nullable|string|max:10',
            'data_access_level' => ['nullable', Rule::in(Vendor::DATA_ACCESS_LEVELS)],
            'processes_personal_data' => 'boolean',
            'dpa_signed_at' => 'nullable|date',
            'material_outsourcing' => 'boolean',
            'regulator_notified_at' => 'nullable|date',
            'shared_directory_id' => 'nullable|integer|exists:shared_vendor_directory,id',
            'sla_details' => 'nullable|string',
            'next_review_date' => 'nullable|date',
            'tags' => 'nullable|array',
        ], ['domain.regex' => 'Enter a bare domain such as interswitchgroup.com (no https:// or path).']);
    }
}
