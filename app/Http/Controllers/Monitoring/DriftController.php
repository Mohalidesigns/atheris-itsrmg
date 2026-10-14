<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ConfigDriftEvent;
use App\Models\User;
use App\Services\Monitoring\DriftService;
use App\Services\Monitoring\MonitoringMetrics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Configuration-drift register (ATH-VAL-013). The previous page generated 30 random events on
 * every request; drift is now detected against the bank's own assets, reconciled with approved
 * changes, and worked to remediation, risk acceptance or authorisation.
 */
class DriftController extends Controller
{
    private const SORTABLE = ['drift_code', 'detected_at', 'severity', 'status'];

    public function index(Request $request)
    {
        $sort = in_array($request->sort, self::SORTABLE, true) ? $request->sort : 'detected_at';
        $events = ConfigDriftEvent::query()->with(['asset:id,name', 'owner:id,name', 'change:id,change_code'])
            ->when($request->status, fn ($q, $s) => $s === 'unresolved' ? $q->whereIn('status', ConfigDriftEvent::OPEN_STATUSES) : $q->where('status', $s))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($request->source, fn ($q, $s) => $q->where('source', $s))
            ->orderBy($sort, $request->direction === 'asc' ? 'asc' : 'desc')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        $orgId = (int) $request->user()->organization_id;

        return Inertia::render('Monitoring/Drift', [
            'events' => $events,
            'buckets' => ConfigDriftEvent::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'metrics' => app(MonitoringMetrics::class)->summary($orgId),
            'sources' => ConfigDriftEvent::SOURCES,
            'scanSources' => array_keys(DriftService::CATALOGUE),
            'assets' => Asset::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('status', 'severity', 'source', 'sort', 'direction'),
            'can' => ['scan' => $request->user()->can('edit monitoring'), 'create' => $request->user()->can('create monitoring')],
        ]);
    }

    public function show(Request $request, ConfigDriftEvent $drift, DriftService $service)
    {
        $drift->load(['asset:id,name,asset_id_code,owner_id', 'owner:id,name', 'resolver:id,name', 'change:id,change_code,title,status', 'issue:id,issue_code,status']);
        $orgId = (int) $request->user()->organization_id;

        return Inertia::render('Monitoring/DriftShow', [
            'drift' => $drift,
            'sourceLabel' => ConfigDriftEvent::SOURCES[$drift->source] ?? $drift->source,
            'changes' => $drift->asset_id ? $service->changesFor($orgId, $drift->asset_id)->map(fn ($c) => ['id' => $c->id, 'change_code' => $c->change_code, 'title' => $c->title, 'status' => $c->status, 'scheduled_start_at' => $c->scheduled_start_at])->values() : [],
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
            'can' => ['edit' => $request->user()->can('edit monitoring'), 'approve' => $request->user()->can('approve monitoring')],
        ]);
    }

    public function scan(Request $request, DriftService $service)
    {
        $data = $request->validate(['source' => ['required', Rule::in(array_keys(DriftService::CATALOGUE))]]);
        [$found, $new, $authorised] = $service->scan($data['source'], $request->user());

        return back()->with('success', ConfigDriftEvent::SOURCES[$data['source']]." scan (sandbox): {$found} deviation(s), {$new} new, {$authorised} covered by an approved change.");
    }

    /** Record drift found by hand (an audit or an engineer's report). */
    public function store(Request $request, DriftService $service)
    {
        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'asset_id' => ['required', Rule::exists('assets', 'id')->where('organization_id', $orgId)],
            'title' => 'required|string|max:255',
            'severity' => ['required', Rule::in(ConfigDriftEvent::SEVERITIES)],
            'baseline_value' => 'nullable|string|max:255',
            'observed_value' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'detected_at' => 'nullable|date|before_or_equal:now',
        ]);
        $asset = Asset::findOrFail($data['asset_id']);
        $event = $service->record($orgId, [
            'asset' => $asset, 'source' => 'manual', 'title' => $data['title'], 'severity' => $data['severity'],
            'baseline' => $data['baseline_value'] ?? null, 'observed' => $data['observed_value'],
            'description' => $data['description'] ?? null, 'detected_at' => $data['detected_at'] ?? now(),
        ], $request->user());

        return $event
            ? redirect()->route('drift.show', $event)->with('success', "{$event->drift_code} recorded".($event->status === 'authorised' ? ' and matched to an approved change.' : '.'))
            : back()->withErrors(['title' => 'An open drift event already records this deviation on this asset.']);
    }

    public function transition(Request $request, ConfigDriftEvent $drift, DriftService $service)
    {
        $orgId = (int) $request->user()->organization_id;
        $action = $request->validate(['action' => ['required', Rule::in(['investigate', 'remediate', 'accept', 'authorise', 'reopen', 'raise_issue'])]])['action'];
        $input = $request->validate(match ($action) {
            'investigate' => ['owner_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)]],
            'remediate' => ['note' => 'required|string|min:10'],
            'accept' => ['note' => 'required|string|min:10', 'accepted_until' => 'required|date|after:today|before_or_equal:'.now()->addYear()->toDateString()],
            'authorise' => ['change_request_id' => 'required|integer', 'note' => 'nullable|string'],
            default => ['note' => 'nullable|string'],
        });
        abort_if($action === 'accept' && ! $request->user()->can('approve monitoring'), 403);

        $service->transition($drift, $action, $request->user(), $input);

        return back()->with('success', match ($action) {
            'investigate' => "{$drift->drift_code} is under investigation.",
            'remediate' => "{$drift->drift_code} marked remediated.",
            'accept' => "{$drift->drift_code} risk accepted until ".$drift->fresh()->accepted_until->format('d M Y').'.',
            'authorise' => "{$drift->drift_code} linked to an approved change.",
            'reopen' => "{$drift->drift_code} reopened.",
            'raise_issue' => "Issue raised for {$drift->drift_code}.",
        });
    }
}
