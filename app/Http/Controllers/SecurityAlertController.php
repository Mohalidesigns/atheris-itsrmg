<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\SecurityAlert;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SecurityAlertController extends Controller
{
    public function index(Request $request)
    {
        $query = SecurityAlert::with(['incident:id,title,incident_id_code', 'acknowledgedBy:id,name']);

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('alert_id_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'received_at');
        $sortDir = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDir);

        $alerts = $query->paginate(20)->withQueryString();

        return Inertia::render('SecurityOps/SecurityAlerts/Index', [
            'alerts' => $alerts,
            'filters' => $request->only(['severity', 'status', 'source', 'search', 'sort', 'direction']),
            'severities' => SecurityAlert::SEVERITIES,
            'statuses' => SecurityAlert::STATUSES,
        ]);
    }

    public function create()
    {
        return Inertia::render('SecurityOps/SecurityAlerts/Create', [
            'nextCode' => SecurityAlert::generateNextCode(auth()->user()->organization_id),
            'severities' => SecurityAlert::SEVERITIES,
            'statuses' => SecurityAlert::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'source' => 'nullable|string|max:255',
            'severity' => 'required|in:' . implode(',', SecurityAlert::SEVERITIES),
            'status' => 'nullable|in:' . implode(',', SecurityAlert::STATUSES),
            'received_at' => 'nullable|date',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['alert_id_code'] = SecurityAlert::generateNextCode(auth()->user()->organization_id);
        $validated['received_at'] = $validated['received_at'] ?? now();

        $alert = SecurityAlert::create($validated);

        return redirect()->route('security-alerts.show', $alert)
            ->with('success', "Alert {$alert->alert_id_code} logged successfully.");
    }

    public function show(SecurityAlert $securityAlert)
    {
        $securityAlert->load([
            'incident:id,title,incident_id_code,severity,status',
            'acknowledgedBy:id,name',
        ]);

        return Inertia::render('SecurityOps/SecurityAlerts/Show', [
            'alert' => $securityAlert,
            'statuses' => SecurityAlert::STATUSES,
        ]);
    }

    public function edit(SecurityAlert $securityAlert)
    {
        return Inertia::render('SecurityOps/SecurityAlerts/Edit', [
            'alert' => $securityAlert->load('incident:id,title,incident_id_code'),
            'severities' => SecurityAlert::SEVERITIES,
            'statuses' => SecurityAlert::STATUSES,
        ]);
    }

    public function update(Request $request, SecurityAlert $securityAlert)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'source' => 'nullable|string|max:255',
            'severity' => 'required|in:' . implode(',', SecurityAlert::SEVERITIES),
            'status' => 'required|in:' . implode(',', SecurityAlert::STATUSES),
            'received_at' => 'nullable|date',
        ]);

        // Stamp acknowledgement the first time an alert moves out of "new"
        if ($validated['status'] !== 'new' && is_null($securityAlert->acknowledged_at)) {
            $validated['acknowledged_at'] = now();
            $validated['acknowledged_by'] = auth()->id();
        }

        $securityAlert->update($validated);

        return redirect()->route('security-alerts.show', $securityAlert)
            ->with('success', 'Alert updated successfully.');
    }

    public function destroy(SecurityAlert $securityAlert)
    {
        $securityAlert->delete();

        return redirect()->route('security-alerts.index')
            ->with('success', "Alert {$securityAlert->alert_id_code} deleted.");
    }

    /**
     * Promote an alert into a full incident and link the two records.
     */
    public function promote(SecurityAlert $securityAlert)
    {
        if ($securityAlert->incident_id) {
            return back()->with('error', 'This alert is already linked to an incident.');
        }

        $orgId = auth()->user()->organization_id;

        $incident = Incident::create([
            'organization_id' => $orgId,
            'incident_id_code' => Incident::generateNextCode($orgId),
            'title' => $securityAlert->title,
            'description' => trim("Promoted from security alert {$securityAlert->alert_id_code}.\n\n" . ($securityAlert->description ?? '')),
            'severity' => in_array($securityAlert->severity, ['critical', 'high', 'medium', 'low']) ? $securityAlert->severity : 'medium',
            'status' => 'detected',
            'source' => $securityAlert->source,
            'detected_at' => $securityAlert->received_at ?? now(),
        ]);

        IncidentEvent::create([
            'incident_id' => $incident->id,
            'event_type' => 'status_change',
            'description' => "Incident created by promoting security alert {$securityAlert->alert_id_code}.",
            'performed_by' => auth()->id(),
            'occurred_at' => now(),
        ]);

        $securityAlert->update([
            'incident_id' => $incident->id,
            'status' => 'investigating',
            'acknowledged_at' => $securityAlert->acknowledged_at ?? now(),
            'acknowledged_by' => $securityAlert->acknowledged_by ?? auth()->id(),
        ]);

        return redirect()->route('incidents.show', $incident)
            ->with('success', "Alert promoted to incident {$incident->incident_id_code}.");
    }
}
