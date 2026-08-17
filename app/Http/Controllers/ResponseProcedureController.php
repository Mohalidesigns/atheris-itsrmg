<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\IncidentResponseProcedure;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ResponseProcedureController extends Controller
{
    public function index(Request $request)
    {
        $query = IncidentResponseProcedure::query();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $procedures = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return Inertia::render('SecurityOps/ResponseProcedures/Index', [
            'procedures' => $procedures,
            'filters' => $request->only(['category', 'is_active', 'search']),
            'categories' => IncidentResponseProcedure::query()
                ->whereNotNull('category')->distinct()->pluck('category'),
        ]);
    }

    public function create()
    {
        return Inertia::render('SecurityOps/ResponseProcedures/Create', [
            'incidentTypes' => Incident::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $validated['organization_id'] = auth()->user()->organization_id;

        $procedure = IncidentResponseProcedure::create($validated);

        return redirect()->route('response-procedures.show', $procedure)
            ->with('success', 'Response procedure created successfully.');
    }

    public function show(IncidentResponseProcedure $responseProcedure)
    {
        return Inertia::render('SecurityOps/ResponseProcedures/Show', [
            'procedure' => $responseProcedure,
        ]);
    }

    public function edit(IncidentResponseProcedure $responseProcedure)
    {
        return Inertia::render('SecurityOps/ResponseProcedures/Edit', [
            'procedure' => $responseProcedure,
            'incidentTypes' => Incident::TYPES,
        ]);
    }

    public function update(Request $request, IncidentResponseProcedure $responseProcedure)
    {
        $responseProcedure->update($this->validated($request));

        return redirect()->route('response-procedures.show', $responseProcedure)
            ->with('success', 'Response procedure updated successfully.');
    }

    public function destroy(IncidentResponseProcedure $responseProcedure)
    {
        $responseProcedure->delete();

        return redirect()->route('response-procedures.index')
            ->with('success', 'Response procedure deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'incident_types' => 'nullable|array',
            'incident_types.*' => 'string|in:' . implode(',', Incident::TYPES),
            'steps' => 'nullable|array',
            'steps.*' => 'string',
            'escalation_contacts' => 'nullable|array',
            'escalation_contacts.*' => 'string',
            'is_active' => 'nullable|boolean',
            'version' => 'nullable|string|max:50',
            'last_reviewed' => 'nullable|date',
        ]);
    }
}
