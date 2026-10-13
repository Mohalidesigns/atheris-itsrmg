<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\IncidentResponseProcedure;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Ea\CrossModulePanels;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class IncidentController extends Controller
{
    private const SORTABLE = ['created_at', 'incident_id_code', 'title', 'type', 'severity', 'status', 'detected_at'];

    /** First time an incident reaches a status, the matching response timestamp is stamped. */
    private const TIMESTAMP_FOR_STATUS = [
        'triaged' => 'responded_at',
        'investigating' => 'responded_at',
        'containing' => 'contained_at',
        'eradicating' => 'contained_at',
        'recovering' => 'contained_at',
        'resolved' => 'resolved_at',
        'post_incident_review' => 'resolved_at',
        'closed' => 'closed_at',
    ];

    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $query = Incident::where('organization_id', $orgId)
            ->with(['assignee:id,name', 'leadInvestigator:id,name']);

        if ($request->filled('status')) {
            $request->status === 'active'
                ? $query->whereIn('status', Incident::ACTIVE_STATUSES)
                : $query->where('status', $request->status);
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
                $q->whereLike('title', "%{$search}%")
                    ->orWhereLike('incident_id_code', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%");
            });
        }

        $sortField = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'created_at';
        $sortDir = $request->get('direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDir)->orderBy('id');

        $incidents = $query->paginate(15)->withQueryString();

        return Inertia::render('SecurityOps/Incidents/Index', [
            'incidents' => $incidents,
            'filters' => $request->only(['status', 'severity', 'type', 'search', 'sort', 'direction']),
            'statuses' => Incident::STATUSES,
            'types' => Incident::TYPES,
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
                ->select('id', 'title', 'category', 'incident_types')
                ->orderBy('title')
                ->get(),
            'types' => Incident::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(auth()->user()->organization_id));

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['incident_id_code'] = Incident::generateNextCode(auth()->user()->organization_id);
        $validated['status'] = 'detected';
        $validated['detected_at'] = $validated['detected_at'] ?? now();

        $incident = Incident::create($validated);

        // Create initial event
        IncidentEvent::create([
            'incident_id' => $incident->id,
            'event_type' => 'status_change',
            'description' => 'Incident detected and logged.'
                .($incident->procedure ? " Following procedure \"{$incident->procedure->title}\"." : ''),
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
            'procedure',
            'notifications' => fn ($q) => $q->orderBy('deadline_at'),
        ]);

        return Inertia::render('SecurityOps/Incidents/Show', [
            'incident' => $incident,
            'statuses' => Incident::STATUSES,
            'templates' => NotificationTemplate::orderBy('regulator_code')->get(['id', 'regulator_code', 'template_key', 'title']),
            // Procedures written for this incident type, offered when none is being followed yet.
            'suggestedProcedures' => $incident->response_procedure_id ? [] : IncidentResponseProcedure::where('is_active', true)
                ->get(['id', 'title', 'category', 'incident_types'])
                ->filter(fn ($p) => in_array($incident->type, $p->incident_types ?? [], true))
                ->values(),
            // ATH-EAR-002 §7.4 (contract I-13) — blast radius on Incident
            // detail. §7.3 frames it against the CBN 30-minute response
            // window: the responder must not have to open the EA module and
            // run an analysis to learn what else this breaks.
            'eaImpact' => (new CrossModulePanels)->forIncident($incident),
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
            'procedures' => IncidentResponseProcedure::where('is_active', true)->orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function update(Request $request, Incident $incident)
    {
        $validated = $request->validate($this->rules(auth()->user()->organization_id) + [
            'status' => ['nullable', Rule::in(Incident::STATUSES)],
            'affected_users_count' => 'nullable|integer|min:0',
            'root_cause' => 'nullable|string',
            'lessons_learned' => 'nullable|string',
        ]);

        if (($validated['status'] ?? null) === 'closed' && blank($validated['root_cause'] ?? $incident->root_cause)) {
            return back()->withErrors(['root_cause' => 'Record the root cause before closing the incident.'])->withInput();
        }
        if (! empty($validated['status'])) {
            $validated += $this->timestampsFor($incident, $validated['status']);
        }

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
            'status' => ['required', Rule::in(Incident::STATUSES)],
        ]);

        $oldStatus = $incident->status;
        $newStatus = $validated['status'];
        $order = array_flip(Incident::STATUSES);

        // Forward through the lifecycle; the only way back is reopening into investigation.
        $reopening = $newStatus === 'investigating' && in_array($oldStatus, ['resolved', 'post_incident_review', 'closed'], true);
        if ($newStatus === $oldStatus || (! $reopening && ($order[$newStatus] ?? 0) < ($order[$oldStatus] ?? 0))) {
            return back()->with('error', "Cannot move the incident from '{$oldStatus}' to '{$newStatus}'.");
        }
        if ($newStatus === 'closed' && blank($incident->root_cause)) {
            return back()->with('error', 'Record the root cause (Edit) before closing the incident.');
        }

        $updateData = ['status' => $newStatus] + $this->timestampsFor($incident, $newStatus);
        if ($reopening) {
            $updateData += ['resolved_at' => null, 'closed_at' => null];
        }
        $incident->update($updateData);

        IncidentEvent::create([
            'incident_id' => $incident->id,
            'event_type' => 'status_change',
            'description' => ($reopening ? 'Reopened' : 'Status changed')." from '{$oldStatus}' to '{$newStatus}'.",
            'performed_by' => auth()->id(),
            'occurred_at' => now(),
            'metadata' => ['old_status' => $oldStatus, 'new_status' => $newStatus],
        ]);

        return back()->with('success', 'Incident status updated successfully.');
    }

    /** Follow a response procedure: recorded on the incident and in its timeline. */
    public function assignProcedure(Request $request, Incident $incident)
    {
        $validated = $request->validate([
            'response_procedure_id' => ['required', Rule::exists('incident_response_procedures', 'id')
                ->where('organization_id', $request->user()->organization_id)],
        ]);
        $incident->update($validated);
        IncidentEvent::create([
            'incident_id' => $incident->id,
            'event_type' => 'note',
            'description' => "Response procedure \"{$incident->procedure->title}\" adopted.",
            'performed_by' => auth()->id(),
            'occurred_at' => now(),
        ]);

        return back()->with('success', 'Response procedure linked.');
    }

    /** Timestamps to stamp the first time an incident reaches a status (earlier stages included). */
    private function timestampsFor(Incident $incident, string $status): array
    {
        $order = array_flip(Incident::STATUSES);
        $stamps = [];
        foreach (self::TIMESTAMP_FOR_STATUS as $stage => $field) {
            if (($order[$stage] ?? 99) <= ($order[$status] ?? -1) && is_null($incident->{$field}) && ! isset($stamps[$field])) {
                $stamps[$field] = now();
            }
        }

        return $stamps;
    }

    private function rules(int $orgId): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['nullable', Rule::in(Incident::TYPES)],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)],
            'source' => 'nullable|string|max:255',
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'lead_investigator_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'response_procedure_id' => ['nullable', Rule::exists('incident_response_procedures', 'id')->where('organization_id', $orgId)],
            'detected_at' => 'nullable|date',
            'affected_systems' => 'nullable|array',
            'tags' => 'nullable|array',
        ];
    }
}
