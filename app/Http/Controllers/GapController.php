<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\Gap;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Compliance gap register — gaps raised automatically from assessment results and logged manually
 * (e.g. ISMS internal-audit non-conformities), tracked through remediation to closure.
 */
class GapController extends Controller
{
    public function index(Request $request)
    {
        $query = Gap::with([
            'requirement:id,framework_id,requirement_code,title',
            'requirement.framework:id,short_name',
            'assignee:id,name',
            'assessment:id,title',
            'control:id,control_code,title',
        ])->withCount('evidence');

        if ($request->input('state', 'open') === 'open') {
            $query->open();
        } elseif ($request->state === 'resolved') {
            $query->whereIn('status', Gap::RESOLVED_STATUSES);
        }
        foreach (['severity', 'status'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        if ($request->filled('assignee')) {
            $request->assignee === 'none' ? $query->whereNull('assigned_to') : $query->where('assigned_to', $request->assignee);
        }
        if ($request->boolean('overdue')) {
            $query->open()->whereNotNull('due_date')->whereDate('due_date', '<', today());
        }
        if ($request->filled('framework')) {
            $query->whereHas('requirement', fn ($q) => $q->where('framework_id', $request->framework));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('title', 'like', "%{$s}%")->orWhere('gap_code', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%"));
        }

        $gaps = $query->orderBy('priority')->orderByRaw('due_date is null')->orderBy('due_date')
            ->paginate(20)->withQueryString();

        $open = Gap::open();
        $stats = [
            'open' => (clone $open)->count(),
            'critical' => (clone $open)->where('severity', 'critical')->count(),
            'high' => (clone $open)->where('severity', 'high')->count(),
            'overdue' => (clone $open)->whereNotNull('due_date')->whereDate('due_date', '<', today())->count(),
            'unassigned' => (clone $open)->whereNull('assigned_to')->count(),
            'resolved' => Gap::whereIn('status', Gap::RESOLVED_STATUSES)->count(),
        ];
        $bySeverity = Gap::open()->select('severity', DB::raw('count(*) as c'))->groupBy('severity')->pluck('c', 'severity');

        $user = auth()->user();
        $orgId = (int) $user->organization_id;

        return Inertia::render('Compliance/GapAnalysis/Index', [
            'gaps' => $gaps,
            'stats' => $stats,
            'bySeverity' => $bySeverity,
            'filters' => $request->only(['state', 'severity', 'status', 'assignee', 'overdue', 'framework', 'search']) + ['state' => 'open'],
            'statuses' => Gap::STATUSES,
            'severities' => Gap::SEVERITIES,
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
            'frameworks' => ControlFramework::where('is_active', true)->orderBy('sort_order')->get(['id', 'short_name']),
            'controls' => Control::orderBy('control_code')->get(['id', 'control_code', 'title']),
            'can' => [
                'create' => $user->can('create gap-analysis'),
                'edit' => $user->can('edit gap-analysis'),
                'delete' => $user->can('delete gap-analysis'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $orgId = (int) auth()->user()->organization_id;
        $validated = $this->validated($request, $orgId);
        $validated['organization_id'] = $orgId;
        $validated['gap_code'] = Gap::generateNextCode($orgId);
        $validated['status'] = $validated['status'] ?? 'identified';
        $validated['priority'] = $validated['priority'] ?? Gap::SEVERITY_PRIORITY[$validated['severity']];

        $gap = Gap::create($validated);

        return back()->with('success', "Gap {$gap->gap_code} logged.");
    }

    public function update(Request $request, Gap $gap)
    {
        $orgId = (int) auth()->user()->organization_id;
        $validated = $this->validated($request, $orgId, $gap);

        $resolving = in_array($validated['status'], Gap::RESOLVED_STATUSES, true);
        $validated['completed_at'] = $resolving ? ($gap->completed_at ?? today()) : null;

        $gap->update($validated);

        return back()->with('success', "Gap {$gap->gap_code} updated.");
    }

    public function destroy(Gap $gap)
    {
        $code = $gap->gap_code;
        $gap->delete();

        return back()->with('success', "Gap {$code} deleted.");
    }

    private function validated(Request $request, int $orgId, ?Gap $gap = null): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'severity' => ['required', Rule::in(Gap::SEVERITIES)],
            'status' => [$gap ? 'required' : 'nullable', Rule::in(Gap::STATUSES)],
            'priority' => 'nullable|integer|between:1,5',
            'requirement_id' => 'nullable|exists:framework_requirements,id',
            'assessment_id' => ['nullable', Rule::exists('compliance_assessments', 'id')->where('organization_id', $orgId)],
            'control_id' => ['nullable', Rule::exists('controls', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'due_date' => 'nullable|date',
            'remediation_plan' => [
                // A plan is what moves a gap past "identified".
                Rule::requiredIf(in_array($request->status, ['remediation_planned', 'in_progress'], true)),
                'nullable', 'string', 'max:5000',
            ],
            'notes' => [
                // Accepting a gap is a risk decision — record why.
                Rule::requiredIf($request->status === 'accepted'),
                'nullable', 'string', 'max:5000',
            ],
        ], [
            'remediation_plan.required' => 'Document the remediation plan before moving the gap into remediation.',
            'notes.required' => 'Record the risk-acceptance rationale (and approver) in the notes.',
        ]);
    }
}
