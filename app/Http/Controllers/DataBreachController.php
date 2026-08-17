<?php

namespace App\Http\Controllers;

use App\Models\DataBreach;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DataBreachController extends Controller
{
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $query = DataBreach::where('organization_id', $orgId)
            ->with(['incident:id,title,incident_id_code', 'assignee:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('breach_type')) {
            $query->where('breach_type', $request->breach_type);
        }
        if ($request->filled('ndpa_notification_required')) {
            $query->where('ndpa_notification_required', $request->boolean('ndpa_notification_required'));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('breach_id_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDir);

        $breaches = $query->paginate(15)->withQueryString();

        return Inertia::render('SecurityOps/Breaches/Index', [
            'breaches' => $breaches,
            'filters' => $request->only(['status', 'breach_type', 'ndpa_notification_required', 'search', 'sort', 'direction']),
        ]);
    }

    public function create()
    {
        $orgId = auth()->user()->organization_id;

        return Inertia::render('SecurityOps/Breaches/Create', [
            'users' => User::where('organization_id', $orgId)
                ->select('id', 'name')->get(),
            'incidents' => Incident::where('organization_id', $orgId)
                ->select('id', 'title', 'incident_id_code')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'incident_id' => 'nullable|exists:incidents,id',
            'breach_type' => 'nullable|in:confidentiality,integrity,availability',
            'data_types_affected' => 'nullable|array',
            'records_affected' => 'nullable|integer|min:0',
            'status' => 'nullable|in:identified,investigating,contained,notified,resolved,closed',
            'ndpa_notification_required' => 'nullable|boolean',
            'regulatory_body_notified' => 'nullable|boolean',
            'individuals_notified' => 'nullable|boolean',
            'root_cause' => 'nullable|string',
            'remedial_actions' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['breach_id_code'] = DataBreach::generateNextCode(auth()->user()->organization_id);

        // If NDPA notification is required, set 72-hour deadline from now (discovery time)
        if (! empty($validated['ndpa_notification_required'])) {
            $validated['ndpa_notification_deadline'] = now()->addHours(72);
        }

        $breach = DataBreach::create($validated);

        // If linked to an incident, mark it as a data breach
        if ($breach->incident_id) {
            Incident::where('id', $breach->incident_id)->update(['is_data_breach' => true]);
        }

        return redirect()->route('data-breaches.show', $breach)
            ->with('success', "Data Breach {$breach->breach_id_code} created successfully.");
    }

    public function show(DataBreach $breach)
    {
        $breach->load([
            'incident:id,title,incident_id_code,severity,status',
            'assignee:id,name,email,job_title',
        ]);

        return Inertia::render('SecurityOps/Breaches/Show', [
            'breach' => $breach,
        ]);
    }

    public function edit(DataBreach $breach)
    {
        $orgId = auth()->user()->organization_id;

        return Inertia::render('SecurityOps/Breaches/Edit', [
            'breach' => $breach->load(['incident:id,title,incident_id_code', 'assignee:id,name']),
            'users' => User::where('organization_id', $orgId)
                ->select('id', 'name')->get(),
            'incidents' => Incident::where('organization_id', $orgId)
                ->select('id', 'title', 'incident_id_code')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function update(Request $request, DataBreach $breach)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'incident_id' => 'nullable|exists:incidents,id',
            'breach_type' => 'nullable|in:confidentiality,integrity,availability',
            'data_types_affected' => 'nullable|array',
            'records_affected' => 'nullable|integer|min:0',
            'status' => 'nullable|in:identified,investigating,contained,notified,resolved,closed',
            'ndpa_notification_required' => 'nullable|boolean',
            'regulatory_body_notified' => 'nullable|boolean',
            'individuals_notified' => 'nullable|boolean',
            'root_cause' => 'nullable|string',
            'remedial_actions' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        // Set the 72-hour NDPA deadline if the requirement was just switched on
        if (! empty($validated['ndpa_notification_required']) && ! $breach->ndpa_notification_deadline) {
            $validated['ndpa_notification_deadline'] = now()->addHours(72);
        }

        $breach->update($validated);

        if ($breach->incident_id) {
            Incident::where('id', $breach->incident_id)->update(['is_data_breach' => true]);
        }

        return redirect()->route('data-breaches.show', $breach)
            ->with('success', 'Data breach updated successfully.');
    }

    public function destroy(DataBreach $breach)
    {
        $breach->delete();

        return redirect()->route('data-breaches.index')
            ->with('success', "Data Breach {$breach->breach_id_code} deleted.");
    }
}
