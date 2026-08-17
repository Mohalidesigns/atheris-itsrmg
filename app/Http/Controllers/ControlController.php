<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlController extends Controller
{
    public function index(Request $request)
    {
        $query = Control::with(['owner:id,name', 'parent:id,control_code,title'])
            ->withCount(['frameworkRequirements', 'risks', 'evidence']);

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('domain')) $query->where('domain', $request->domain);
        if ($request->filled('type')) $query->where('type', $request->type);
        if ($request->filled('effectiveness')) $query->where('effectiveness', $request->effectiveness);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('title', 'like', "%{$s}%")
                ->orWhere('control_code', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"));
        }

        $controls = $query->orderBy('sort_order')->orderBy('control_code')
            ->paginate(20)->withQueryString();

        $domains = Control::where('organization_id', auth()->user()->organization_id)
            ->whereNotNull('domain')->distinct()->pluck('domain');

        return Inertia::render('Compliance/Controls/Index', [
            'controls' => $controls,
            'filters' => $request->only(['status', 'domain', 'type', 'effectiveness', 'search']),
            'domains' => $domains,
        ]);
    }

    public function create()
    {
        return Inertia::render('Compliance/Controls/Create', [
            'users' => User::where('organization_id', auth()->user()->organization_id)->select('id', 'name')->get(),
            'parentControls' => Control::select('id', 'control_code', 'title')->get(),
            'nextCode' => Control::generateNextCode(auth()->user()->organization_id),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'domain' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'type' => 'nullable|in:preventive,detective,corrective,deterrent',
            'nature' => 'nullable|in:technical,administrative,physical',
            'frequency' => 'nullable|in:continuous,daily,weekly,monthly,quarterly,annual',
            'owner_id' => 'nullable|exists:users,id',
            'parent_id' => 'nullable|exists:controls,id',
            'is_key_control' => 'boolean',
            'implementation_notes' => 'nullable|string',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['control_code'] = Control::generateNextCode(auth()->user()->organization_id, $validated['domain'] ?? null);

        $control = Control::create($validated);

        return redirect()->route('controls.show', $control)->with('success', "Control {$control->control_code} created.");
    }

    public function show(Control $control)
    {
        $control->load([
            'owner:id,name,email',
            'parent:id,control_code,title',
            'children:id,control_code,title,status,effectiveness',
            'frameworkRequirements' => fn ($q) => $q->with('framework:id,short_name,slug'),
            'risks:id,risk_id_code,title,inherent_rating',
            'evidence' => fn ($q) => $q->with('uploader:id,name')->latest(),
        ]);

        return Inertia::render('Compliance/Controls/Show', ['control' => $control]);
    }

    public function edit(Control $control)
    {
        return Inertia::render('Compliance/Controls/Edit', [
            'control' => $control,
            'users' => User::where('organization_id', auth()->user()->organization_id)->select('id', 'name')->get(),
            'parentControls' => Control::where('id', '!=', $control->id)->select('id', 'control_code', 'title')->get(),
        ]);
    }

    public function update(Request $request, Control $control)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'domain' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'type' => 'nullable|in:preventive,detective,corrective,deterrent',
            'nature' => 'nullable|in:technical,administrative,physical',
            'frequency' => 'nullable|in:continuous,daily,weekly,monthly,quarterly,annual',
            'owner_id' => 'nullable|exists:users,id',
            'parent_id' => 'nullable|exists:controls,id',
            'status' => 'nullable|in:draft,active,inactive,deprecated',
            'effectiveness' => 'nullable|in:effective,partially_effective,ineffective,not_assessed',
            'is_key_control' => 'boolean',
            'implementation_notes' => 'nullable|string',
            'next_review_date' => 'nullable|date',
        ]);

        $control->update($validated);

        return redirect()->route('controls.show', $control)->with('success', 'Control updated.');
    }

    public function destroy(Control $control)
    {
        $control->delete();
        return redirect()->route('controls.index')->with('success', "Control {$control->control_code} archived.");
    }
}
