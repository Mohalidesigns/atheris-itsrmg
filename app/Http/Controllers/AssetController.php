<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Change\ChangeRequest;
use App\Models\Ea\BusinessService;
use App\Models\User;
use App\Services\Assets\AssetCriticality;
use App\Services\Assets\AssetMetrics;
use App\Services\Ea\CrossModulePanels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
    public function __construct(private AssetCriticality $criticality) {}

    public function index(Request $request)
    {
        $query = $this->filtered($request)->with(['owner:id,name', 'custodian:id,name'])->withCount('businessServices');

        $sortField = in_array($request->get('sort'), Asset::SORTABLE, true) ? $request->get('sort') : 'asset_id_code';
        $sortDir = $request->get('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDir)->orderBy('id');

        return Inertia::render('Assets/Index', [
            'assets' => $query->paginate(20)->withQueryString(),
            'metrics' => app(AssetMetrics::class)->summary(),
            'filters' => $request->only(['status', 'asset_type', 'criticality', 'source', 'flag', 'search', 'sort', 'direction']),
            'assetTypes' => Asset::ASSET_TYPES,
            'criticalities' => Asset::CRITICALITIES,
            'statuses' => Asset::STATUSES,
            'sources' => Asset::SOURCES,
        ]);
    }

    /** CSV export of the filtered register (`export assets`). */
    public function export(Request $request): StreamedResponse
    {
        $assets = $this->filtered($request)->with(['owner:id,name,email', 'custodian:id,name', 'businessServices:id,name'])->orderBy('asset_id_code')->get();

        return response()->streamDownload(function () use ($assets) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['asset_id', 'name', 'asset_type', 'criticality', 'inherited_criticality', 'effective_criticality', 'status', 'source',
                'owner', 'owner_email', 'custodian', 'department', 'location', 'hostname', 'ip_address', 'vendor', 'version',
                'data_classification', 'end_of_life', 'last_seen_at', 'business_services']);
            foreach ($assets as $a) {
                fputcsv($out, [$a->asset_id_code, $a->name, $a->asset_type, $a->criticality, $a->criticality_inherited, $a->effective_criticality,
                    $a->status, $a->source, $a->owner?->name, $a->owner?->email, $a->custodian?->name, $a->department, $a->location,
                    $a->hostname, $a->ip_address, $a->vendor, $a->version, $a->data_classification, $a->end_of_life?->toDateString(),
                    $a->last_seen_at?->toDateTimeString(), $a->businessServices->pluck('name')->implode('; ')]);
            }
            fclose($out);
        }, 'asset-register-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function create()
    {
        return Inertia::render('Assets/Create', $this->formOptions() + [
            'nextCode' => Asset::generateNextCode(auth()->user()->organization_id),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $serviceIds = $validated['business_service_ids'] ?? [];
        unset($validated['business_service_ids']);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['asset_id_code'] = Asset::generateNextCode(auth()->user()->organization_id);
        $validated['source'] = 'manual';

        $asset = DB::transaction(function () use ($validated, $serviceIds) {
            $asset = Asset::create($validated);
            $this->syncServices($asset, $serviceIds);

            return $asset;
        });

        return redirect()->route('assets.show', $asset)
            ->with('success', "Asset {$asset->asset_id_code} created successfully.");
    }

    public function show(Asset $asset)
    {
        $asset->load([
            'owner:id,name,email,job_title',
            'custodian:id,name,email,job_title',
            'reviewer:id,name',
            'risks:id,title,risk_id_code,status,inherent_rating,inherent_score',
            'vulnerabilities:id,title,vuln_id_code,severity,status,cve_id',
            'businessServices' => fn ($q) => $q->select('ea_business_services.id', 'code', 'name', 'criticality', 'rto_minutes', 'rpo_minutes'),
        ]);

        $changes = ChangeRequest::query()
            ->where(fn ($q) => $q->whereJsonContains('affected_asset_ids', $asset->id))
            ->orderByDesc('created_at')->limit(10)
            ->get(['id', 'change_code', 'title', 'status', 'risk_level', 'scheduled_start_at']);

        $trail = Activity::query()
            ->where('subject_type', Asset::class)->where('subject_id', $asset->id)
            ->with('causer:id,name')->latest()->limit(25)
            ->get(['id', 'description', 'event', 'attribute_changes', 'causer_id', 'causer_type', 'created_at']);

        return Inertia::render('Assets/Show', [
            'asset' => $asset,
            'changes' => $changes,
            'trail' => $trail,
            'services' => BusinessService::query()->where('organization_id', $asset->organization_id)->orderBy('name')->get(['id', 'code', 'name', 'criticality']),
            'users' => User::where('organization_id', $asset->organization_id)->orderBy('name')->get(['id', 'name']),
            'criticalities' => Asset::CRITICALITIES,
            'can' => [
                'edit' => request()->user()->can('edit assets'),
                'delete' => request()->user()->can('delete assets'),
            ],
            // ATH-EAR-002 §7.4 (contract I-3) — "EA panel on Asset detail
            // (capability, criticality, TIME, residency)". EA is master for
            // the logical application, Assets for the physical instance;
            // this is the logical half of that link.
            'ea' => (new CrossModulePanels)->forAsset($asset->id),
        ]);
    }

    public function edit(Asset $asset)
    {
        return Inertia::render('Assets/Edit', $this->formOptions() + [
            'asset' => $asset->load(['owner:id,name']),
            'linkedServiceIds' => $asset->businessServices()->pluck('ea_business_services.id'),
        ]);
    }

    public function update(Request $request, Asset $asset)
    {
        $validated = $this->validated($request, $asset);
        $serviceIds = $validated['business_service_ids'] ?? null;
        unset($validated['business_service_ids']);

        DB::transaction(function () use ($asset, $validated, $serviceIds) {
            $asset->update($validated);
            if ($serviceIds !== null) {
                $this->syncServices($asset, $serviceIds);
            }
        });

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Asset updated successfully.');
    }

    /** Link or unlink the business services this asset supports (Show page panel). */
    public function services(Request $request, Asset $asset)
    {
        $validated = $request->validate([
            'business_service_ids' => 'present|array',
            'business_service_ids.*' => ['integer', $this->serviceRule()],
        ]);

        $this->syncServices($asset, $validated['business_service_ids']);

        return back()->with('success', 'Business services updated; inherited criticality recalculated.');
    }

    /**
     * Accept a discovered asset into the governed register, or reject it as not the bank's.
     * Accepting needs an owner, because an unowned asset is exactly what the queue exists to stop.
     */
    public function review(Request $request, Asset $asset)
    {
        abort_unless($asset->status === 'under_review', 422, 'Only assets under review can be accepted or rejected.');

        $validated = $request->validate([
            'decision' => 'required|in:accept,reject',
            'owner_id' => ['required_if:decision,accept', 'nullable', 'integer', $this->userRule()],
            'criticality' => ['nullable', Rule::in(Asset::CRITICALITIES)],
            'reason' => 'required_if:decision,reject|nullable|string|max:500',
        ]);

        if ($validated['decision'] === 'accept') {
            $asset->update([
                'status' => 'active',
                'owner_id' => $validated['owner_id'],
                'criticality' => $validated['criticality'] ?? $asset->criticality,
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
            ]);

            return back()->with('success', "{$asset->asset_id_code} accepted into the register.");
        }

        $asset->update([
            'status' => 'decommissioned',
            'description' => trim(($asset->description ? $asset->description."\n\n" : '').'Rejected at discovery review: '.$validated['reason']),
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        return back()->with('success', "{$asset->asset_id_code} rejected and retired.");
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();

        return redirect()->route('assets.index')
            ->with('success', "Asset {$asset->asset_id_code} archived.");
    }

    /** Filters shared by the index and the CSV export. */
    private function filtered(Request $request)
    {
        $query = Asset::query();

        // Retired assets stay out of the working register unless asked for by status.
        if (! $request->filled('status')) {
            $query->where('status', '!=', 'decommissioned');
        }

        foreach (['status', 'asset_type', 'source'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->input($column));
            }
        }
        if ($request->filled('criticality')) {
            // Effective criticality: the owner's rating or the inherited one, whichever is higher.
            $c = $request->input('criticality');
            $higher = array_keys(array_filter(Asset::CRITICALITY_RANK, fn ($r) => $r > (Asset::CRITICALITY_RANK[$c] ?? 0)));
            $query->where(fn ($q) => $q->where(fn ($q) => $q->where('criticality', $c)->where(fn ($q) => $q->whereNull('criticality_inherited')->orWhereNotIn('criticality_inherited', $higher)))
                ->orWhere(fn ($q) => $q->where('criticality_inherited', $c)->whereNotIn('criticality', $higher)));
        }

        $today = now()->toDateString();
        match ($request->input('flag')) {
            'unowned' => $query->whereNull('owner_id'),
            'unclassified' => $query->whereNull('data_classification'),
            'eol_passed' => $query->whereDate('end_of_life', '<', $today),
            'eol_soon' => $query->whereDate('end_of_life', '>=', $today)->whereDate('end_of_life', '<=', now()->addDays(AssetMetrics::EOL_WARNING_DAYS)->toDateString()),
            'unmapped' => $query->whereDoesntHave('businessServices'),
            'stale' => $query->whereNotIn('source', ['manual', 'csv'])->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(AssetMetrics::STALE_DAYS))),
            default => null,
        };
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereLike('name', "%{$search}%")
                    ->orWhereLike('asset_id_code', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%")
                    ->orWhereLike('hostname', "%{$search}%")
                    ->orWhereLike('ip_address', "%{$search}%");
            });
        }

        return $query;
    }

    private function syncServices(Asset $asset, array $serviceIds): void
    {
        $asset->businessServices()->sync(collect($serviceIds)->unique()->mapWithKeys(fn ($id) => [
            (int) $id => ['organization_id' => $asset->organization_id, 'role' => 'supports'],
        ])->all());

        $this->criticality->recompute([$asset->id]);
    }

    private function formOptions(): array
    {
        return [
            'users' => User::where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            'services' => BusinessService::query()->where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(['id', 'code', 'name', 'criticality']),
            'assetTypes' => Asset::ASSET_TYPES,
            'criticalities' => Asset::CRITICALITIES,
            'statuses' => Asset::STATUSES,
            'dataClassifications' => Asset::DATA_CLASSIFICATIONS,
        ];
    }

    private function validated(Request $request, ?Asset $asset = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'asset_type' => ['required', Rule::in(Asset::ASSET_TYPES)],
            'category' => 'nullable|string|max:255',
            'criticality' => ['required', Rule::in(Asset::CRITICALITIES)],
            'status' => ['nullable', Rule::in(Asset::STATUSES)],
            'owner_id' => ['nullable', 'integer', $this->userRule()],
            'custodian_id' => ['nullable', 'integer', $this->userRule()],
            'department' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'ip_address' => 'nullable|ip',
            'hostname' => 'nullable|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:100',
            'license_type' => 'nullable|string|max:100',
            'data_classification' => ['nullable', Rule::in(Asset::DATA_CLASSIFICATIONS)],
            'purchase_date' => 'nullable|date',
            'end_of_life' => 'nullable|date|after_or_equal:purchase_date',
            'tags' => 'nullable|array',
            'business_service_ids' => 'sometimes|array',
            'business_service_ids.*' => ['integer', $this->serviceRule()],
        ]);
    }

    /** A user of the signed-in user's bank. */
    private function userRule()
    {
        return Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id);
    }

    /** A business service of the signed-in user's bank (strict: platform-shared rows are not linkable). */
    private function serviceRule()
    {
        return Rule::exists('ea_business_services', 'id')->where('organization_id', auth()->user()->organization_id);
    }
}
