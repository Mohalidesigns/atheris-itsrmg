<?php

namespace App\Http\Controllers;

use App\Models\ComplianceResult;
use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\Evidence;
use App\Models\FrameworkRequirement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ControlController extends Controller
{
    private const SORTABLE = ['control_code', 'title', 'domain', 'effectiveness', 'status', 'next_review_date', 'framework_requirements_count'];

    public function index(Request $request)
    {
        $query = Control::with(['owner:id,name', 'parent:id,control_code,title'])
            ->withCount(['frameworkRequirements', 'risks', 'evidence']);

        foreach (['status', 'domain', 'type'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        if ($request->filled('effectiveness')) {
            $request->effectiveness === 'not_assessed'
                ? $query->where(fn ($q) => $q->whereNull('effectiveness')->orWhere('effectiveness', 'not_assessed'))
                : $query->where('effectiveness', $request->effectiveness);
        }
        if ($request->boolean('key')) {
            $query->where('is_key_control', true);
        }
        if ($request->boolean('unmapped')) {
            $query->doesntHave('frameworkRequirements');
        }
        if ($request->boolean('review_due')) {
            $query->whereNotNull('next_review_date')->whereDate('next_review_date', '<=', today()->addDays(30));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('title', 'like', "%{$s}%")
                ->orWhere('control_code', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"));
        }

        $sort = in_array($request->sort, self::SORTABLE, true) ? $request->sort : null;
        $dir = $request->direction === 'desc' ? 'desc' : 'asc';
        $sort ? $query->orderBy($sort, $dir) : $query->orderBy('sort_order')->orderBy('control_code');

        $controls = $query->paginate(20)->withQueryString();

        $domains = Control::whereNotNull('domain')->distinct()->orderBy('domain')->pluck('domain');

        return Inertia::render('Compliance/Controls/Index', [
            'controls' => $controls,
            'filters' => $request->only(['status', 'domain', 'type', 'effectiveness', 'search', 'key', 'unmapped', 'review_due', 'sort', 'direction']),
            'domains' => $domains,
            'options' => $this->options(),
            'summary' => [
                'total' => Control::count(),
                'key' => Control::where('is_key_control', true)->count(),
                'unmapped' => Control::doesntHave('frameworkRequirements')->count(),
                'review_due' => Control::whereNotNull('next_review_date')->whereDate('next_review_date', '<=', today()->addDays(30))->count(),
            ],
            'can' => ['create' => auth()->user()->can('create controls')],
        ]);
    }

    public function create()
    {
        return Inertia::render('Compliance/Controls/Create', [
            'users' => $this->users(),
            'parentControls' => Control::orderBy('control_code')->get(['id', 'control_code', 'title']),
            'domains' => Control::whereNotNull('domain')->distinct()->orderBy('domain')->pluck('domain'),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request)
    {
        $orgId = (int) auth()->user()->organization_id;
        $validated = $this->validated($request, $orgId);

        $validated['organization_id'] = $orgId;
        $validated['control_code'] = Control::generateNextCode($orgId, $validated['domain'] ?? null);
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['effectiveness'] = $validated['effectiveness'] ?? 'not_assessed';

        $control = Control::create($validated);

        return redirect()->route('controls.show', $control)->with('success', "Control {$control->control_code} created.");
    }

    public function show(Control $control)
    {
        $control->load([
            'owner:id,name,email',
            'parent:id,control_code,title',
            'children:id,parent_id,control_code,title,status,effectiveness',
            'frameworkRequirements' => fn ($q) => $q->with('framework:id,short_name,slug')->orderBy('framework_id')->orderBy('sort_order'),
            'risks:risks.id,risks.risk_id_code,risks.title,risks.inherent_rating,risks.residual_rating,risks.status',
            'evidence' => fn ($q) => $q->with(['uploader:id,name', 'reviewer:id,name'])->latest(),
        ]);

        // Where this control has been tested in compliance assessments.
        $testResults = ComplianceResult::where('control_id', $control->id)
            ->whereHas('assessment')
            ->with(['assessment:id,title,status,end_date', 'requirement:id,requirement_code,title'])
            ->latest('assessed_at')->limit(20)->get();

        $user = auth()->user();

        return Inertia::render('Compliance/Controls/Show', [
            'control' => $control,
            'testResults' => $testResults,
            'frameworks' => ControlFramework::where('is_active', true)->orderBy('sort_order')->get(['id', 'short_name', 'name']),
            'coverageOptions' => Control::COVERAGE,
            'evidenceTypes' => Evidence::TYPES,
            'can' => [
                'edit' => $user->can('edit controls'),
                'delete' => $user->can('delete controls'),
                'evidence' => $user->can('create evidence'),
                'reviewEvidence' => $user->can('approve evidence'),
            ],
        ]);
    }

    public function edit(Control $control)
    {
        return Inertia::render('Compliance/Controls/Edit', [
            'control' => $control,
            'users' => $this->users(),
            'parentControls' => Control::where('id', '!=', $control->id)->orderBy('control_code')->get(['id', 'control_code', 'title']),
            'domains' => Control::whereNotNull('domain')->distinct()->orderBy('domain')->pluck('domain'),
            'options' => $this->options(),
        ]);
    }

    public function update(Request $request, Control $control)
    {
        $validated = $this->validated($request, (int) auth()->user()->organization_id, $control);

        $control->update($validated);

        return redirect()->route('controls.show', $control)->with('success', 'Control updated.');
    }

    public function destroy(Control $control)
    {
        $control->delete();

        return redirect()->route('controls.index')->with('success', "Control {$control->control_code} archived.");
    }

    /** Map the control to a framework requirement (or update the coverage of an existing mapping). */
    public function storeMapping(Request $request, Control $control)
    {
        $validated = $request->validate([
            'requirement_id' => ['required', Rule::exists('framework_requirements', 'id')],
            'coverage' => ['required', Rule::in(Control::COVERAGE)],
            'notes' => 'nullable|string|max:1000',
        ]);

        if (FrameworkRequirement::whereKey($validated['requirement_id'])->has('children')->exists()) {
            throw ValidationException::withMessages(['requirement_id' => 'Map to a specific requirement, not a domain heading.']);
        }

        $control->frameworkRequirements()->syncWithoutDetaching([
            $validated['requirement_id'] => ['coverage' => $validated['coverage'], 'notes' => $validated['notes'] ?? null],
        ]);

        return back()->with('success', 'Framework mapping saved.');
    }

    public function destroyMapping(Control $control, FrameworkRequirement $requirement)
    {
        $control->frameworkRequirements()->detach($requirement->id);

        return back()->with('success', "Mapping to {$requirement->requirement_code} removed.");
    }

    /** Assessable requirements of a framework for the mapping picker. */
    public function requirementOptions(ControlFramework $framework)
    {
        return FrameworkRequirement::where('framework_id', $framework->id)
            ->whereDoesntHave('children')->orderBy('parent_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'requirement_code', 'title']);
    }

    private function validated(Request $request, int $orgId, ?Control $control = null): array
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'domain' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'type' => ['nullable', Rule::in(Control::TYPES)],
            'nature' => ['nullable', Rule::in(Control::NATURES)],
            'frequency' => ['nullable', Rule::in(Control::FREQUENCIES)],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'parent_id' => [
                'nullable',
                Rule::exists('controls', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                function ($attr, $value, $fail) use ($control) {
                    if ($control && $value && $this->wouldCycle($control, (int) $value)) {
                        $fail('A control cannot sit under itself or one of its own sub-controls.');
                    }
                },
            ],
            'status' => ['nullable', Rule::in(Control::STATUSES)],
            'effectiveness' => ['nullable', Rule::in(Control::EFFECTIVENESS)],
            'is_key_control' => 'boolean',
            'implementation_notes' => 'nullable|string',
            'last_tested' => 'nullable|date|before_or_equal:today',
            'next_review_date' => 'nullable|date',
        ]);

        if (array_key_exists('effectiveness', $validated) && $validated['effectiveness'] === null) {
            $validated['effectiveness'] = 'not_assessed';
        }

        return $validated;
    }

    private function wouldCycle(Control $control, int $parentId): bool
    {
        $seen = [];
        for ($id = $parentId; $id && ! isset($seen[$id]); $id = Control::whereKey($id)->value('parent_id')) {
            if ($id === $control->id) {
                return true;
            }
            $seen[$id] = true;
        }

        return false;
    }

    private function users()
    {
        return User::where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(['id', 'name']);
    }

    private function options(): array
    {
        return [
            'statuses' => Control::STATUSES,
            'effectiveness' => Control::EFFECTIVENESS,
            'types' => Control::TYPES,
            'natures' => Control::NATURES,
            'frequencies' => Control::FREQUENCIES,
        ];
    }
}
