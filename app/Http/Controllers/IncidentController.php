<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\IncidentResponseProcedure;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $query = Incident::where('organization_id', $orgId)
            ->with(['assignee:id,name', 'leadInvestigator:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('incident_id_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDir);

        $incidents = $query->paginate(15)->withQueryString();

        return Inertia::render('SecurityOps/Incidents/Index', [
            'incidents' => $incidents,
            'filters' => $request->only(['status', 'severity', 'type', 'search', 'sort', 'direction']),
        ]);
    }

    public function create()
    {
        $orgId = auth()->user()->organization_id;

        return Inertia::render('SecurityOps/Incidents/Create', [
            'users' => User::where('organization_id', $orgId)
                ->select('id', 'name')->get(),
            'procedures' => IncidentResponseProcedure::where('organization_id', $orgId)
                ->where('is_active', true)
                ->select('id', 'title', 'category')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:malware,phishing,data_leak,unauthorized_access,dos,insider_threat,other',
            'severity' => 'required|in:critical,high,medium,low',
            'source' => 'nullable|string|max:255',
            'assigned_to' => 'nullable|exists:users,id',
            'lead_investigator_id' => 'nullable|exists:users,id',
            'detected_at' => 'nullable|date',
            'affected_systems' => 'nullable|array',
            'tags' => 'nullable|array',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['incident_id_code'] = Incident::generateNextCode(auth()->user()->organization_id);
        $validated['status'] = 'detected';
        $validated['detected_at'] = $validated['detected_at'] ?? now();

        $incident = Incident::create($validated);

        // Create initial event
        IncidentEvent::create([
            'incident_id' => $incident->id,
            'event_type' => 'status_change',
            'description' => 'Incident detected and logged.',
            'performed_by' => auth()->id(),
            'occurred_at' => now(),
        ]);

        return redirect()->route('incidents.show', $incident)
            ->with('success', "Incident {$incident->incident_id_code} created successfully.");
    }

    public function show(Incident $incident)
    {
        $incident->load([
            'events' => fn ($q) => $q->with('performer:id,name')->orderByDesc('occurred_at'),
            'assignee:id,name,email,job_title',
            'leadInvestigator:id,name,email,job_title',
            'alerts' => fn ($q) => $q->latest(),
            'breach',
        ]);

        return Inertia::render('SecurityOps/Incidents/Show', [
            'incident' => $incident,
            // ATH-EAR-002 §7.4 (contract I-13) — blast radius on Incident
            // detail. §7.3 frames it against the CBN 30-minute response
            // window: the responder must not have to open the EA module and
            // run an analysis to learn what else this breaks.
            'eaImpact' => (new \App\Services\Ea\CrossModulePanels())->forIncident($incident),
        ]);
    }

    public function edit(Incident $incident)
    {
        $orgId = auth()->user()->organization_id;

        return Inertia::render('SecurityOps/Incidents/Edit', [
            'incident' => $incident->load(['assignee:id,name', 'leadInvestigator:id,name']),
            'users' => User::where('organization_id', $orgId)
                ->select('id', 'name')->get(),
            'statuses' => Incident::STATUSES,
            'types' => Incident::TYPES,
        ]);
    }

    public function update(Request $request, Incident $incident)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:' . implode(',', Incident::TYPES),
            'severity' => 'required|in:critical,high,medium,low',
            'status' => 'nullable|in:' . implode(',', Incident::STATUSES),
            'source' => 'nullable|string|max:255',
            'assigned_to' => 'nullable|exists:users,id',
            'lead_investigator_id' => 'nullable|exists:users,id',
            'detected_at' => 'nullable|date',
            'affected_systems' => 'nullable|array',
            'affected_users_count' => 'nullable|integer|min:0',
            'root_cause' => 'nullable|string',
            'lessons_learned' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $oldStatus = $incident->status;
        $incident->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            IncidentEvent::create([
                'incident_id' => $incident->id,
                'event_type' => 'status_change',
                'description' => "Status changed from '{$oldStatus}' to '{$validated['status']}' (via edit).",
                'performed_by' => auth()->id(),
                'occurred_at' => now(),
                'metadata' => ['old_status' => $oldStatus, 'new_status' => $validated['status']],
            ]);
        }

        return redirect()->route('incidents.show', $incident)
            ->with('success', 'Incident updated successfully.');
    }

    public function destroy(Incident $incident)
    {
        $incident->delete();

        return redirect()->route('incidents.index')
            ->with('success', "Incident {$incident->incident_id_code} deleted.");
    }

    public function addEvent(Request $request, Incident $incident)
    {
        $validated = $request->validate([
            'event_type' => 'required|in:note,status_change,assignment,escalation,evidence,communication',
            'description' => 'required|string',
            'metadata' => 'nullable|array',
        ]);

        IncidentEvent::create([
            'incident_id' => $incident->id,
            'event_type' => $validated['event_type'],
            'description' => $validated['description'],
            'performed_by' => auth()->id(),
            'occurred_at' => now(),
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return back()->with('success', 'Event added successfully.');
    }

    public function updateStatus(Request $request, Incident $incident)
    {
        $validated = $request->validate([
            'status' => 'required|in:detected,triaged,investigating,containing,eradicating,recovering,closed',
        ]);

        $oldStatus = $incident->status;
        $newStatus = $validated['status'];

        // Set appropriate timestamps based on the new status
        $timestampMap = [
            'triaged' => 'responded_at',
            'investigating' => 'responded_at',
            'containing' => 'contained_at',
            'eradicating' => 'contained_at',
            'recovering' => 'resolved_at',
            'closed' => 'closed_at',
        ];

        $updateData = ['status' => $newStatus];

        if (isset($timestampMap[$newStatus])) {
            $field = $timestampMap[$newStatus];
            if (is_null($incident->{$field})) {
                $updateData[$field] = now();
            }
        }

        $incident->update($updateData);

        // Create status change event
        IncidentEvent::create([
            'incident_id' => $incident->id,
            'event_type' => 'status_change',
            'description' => "Status changed from '{$oldStatus}' to '{$newStatus}'.",
            'performed_by' => auth()->id(),
            'occurred_at' => now(),
            'metadata' => [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
        ]);

        return back()->with('success', 'Incident status updated successfully.');
    }
}
