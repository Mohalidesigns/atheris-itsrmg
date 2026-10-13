<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetSyncJob;
use App\Models\User;
use App\Services\Assets\AssetDiscoveryService;
use App\Services\Assets\AssetMetrics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asset Discovery (plan gap D1.1): connector runs, CSV import, and the review queue of assets a
 * connector found that nobody has accepted into the register yet. Replaces the PlatformController
 * stub, which wrote every run to organization 1 with random totals.
 */
class AssetDiscoveryController extends Controller
{
    public function __construct(private AssetDiscoveryService $discovery) {}

    public function index()
    {
        $jobs = AssetSyncJob::query()->with('runner:id,name')->orderByDesc('finished_at')->orderByDesc('id')->limit(50)->get();

        $connectors = collect(AssetDiscoveryService::CONNECTORS)->map(function ($c, $key) use ($jobs) {
            $last = $jobs->firstWhere('source', $key);

            return [
                'key' => $key,
                'label' => $c['label'],
                'mode' => 'sandbox',
                'assets' => Asset::query()->where('source', $key)->where('status', '!=', 'decommissioned')->count(),
                'last_run' => $last?->finished_at,
                'last_summary' => $last?->output_summary,
            ];
        })->values();

        return Inertia::render('Assets/Discovery', [
            'jobs' => $jobs,
            'connectors' => $connectors,
            'metrics' => app(AssetMetrics::class)->summary(),
            'queue' => Asset::query()->where('status', 'under_review')
                ->orderByDesc('last_seen_at')->orderByDesc('id')
                ->get(['id', 'asset_id_code', 'name', 'asset_type', 'category', 'criticality', 'source', 'external_id', 'hostname', 'ip_address', 'vendor', 'version', 'last_seen_at', 'owner_id']),
            'users' => User::where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            'criticalities' => Asset::CRITICALITIES,
            'csvColumns' => AssetDiscoveryService::CSV_COLUMNS,
            'can' => [
                'sync' => request()->user()->can('create assets'),
                'review' => request()->user()->can('edit assets'),
            ],
        ]);
    }

    public function sync(Request $request)
    {
        $validated = $request->validate(['source' => ['required', Rule::in(array_keys(AssetDiscoveryService::CONNECTORS))]]);

        $job = $this->discovery->sync($validated['source'], $request->user());

        return redirect()->route('asset-discovery.index')->with('success', $job->output_summary);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);

        $job = $this->discovery->importCsv($request->file('file'), $request->user());

        return redirect()->route('asset-discovery.index')
            ->with($job->records_skipped ? 'warning' : 'success', $job->output_summary);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, AssetDiscoveryService::CSV_COLUMNS);
            fputcsv($out, ['Branch file server — Ikeja', 'hardware', 'medium', 'active', auth()->user()->email, 'IT Infrastructure', 'Ikeja branch',
                'fs-ikj-01', '10.40.1.20', 'Dell', 'PowerEdge R650', 'internal', now()->addYears(4)->toDateString(), '', 'Branch file and print server']);
            fclose($out);
        }, 'asset-import-template.csv', ['Content-Type' => 'text/csv']);
    }
}
