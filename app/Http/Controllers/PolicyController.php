<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\PolicyVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyController extends Controller
{
    public function index(Request $request)
    {
        $query = Policy::with(['owner:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('policy_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDir);

        $policies = $query->paginate(15)->withQueryString();

        return Inertia::render('Policies/Index', [
            'policies' => $policies,
            'filters' => $request->only(['status', 'category', 'search', 'sort', 'direction']),
            'statuses' => Policy::STATUSES,
            'categories' => Policy::CATEGORIES,
        ]);
    }

    public function create()
    {
        return Inertia::render('Policies/Create', [
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'categories' => Policy::CATEGORIES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'category' => 'nullable|in:' . implode(',', array_keys(Policy::CATEGORIES)),
            'owner_id' => 'nullable|exists:users,id',
            'is_mandatory' => 'boolean',
            'effective_date' => 'nullable|date',
            'review_date' => 'nullable|date',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['policy_code'] = Policy::generateNextCode(auth()->user()->organization_id);

        $policy = Policy::create($validated);

        // Create initial version
        if ($validated['content'] ?? null) {
            PolicyVersion::create([
                'policy_id' => $policy->id,
                'version_number' => '1.0',
                'content' => $validated['content'],
                'change_summary' => 'Initial version',
                'created_by' => auth()->id(),
            ]);
        }

        return redirect()->route('policies.show', $policy)
            ->with('success', "Policy {$policy->policy_code} created successfully.");
    }

    public function show(Policy $policy)
    {
        $policy->load([
            'owner:id,name,email',
            'approver:id,name',
            'versions' => fn ($q) => $q->with('creator:id,name')->latest(),
            'attestations' => fn ($q) => $q->with('user:id,name,email')->latest(),
        ]);

        $userAttestation = $policy->attestations
            ->where('user_id', auth()->id())
            ->first();

        return Inertia::render('Policies/Show', [
            'policy' => $policy,
            'userAttestation' => $userAttestation,
            'categories' => Policy::CATEGORIES,
        ]);
    }

    public function edit(Policy $policy)
    {
        return Inertia::render('Policies/Edit', [
            'policy' => $policy->load('owner:id,name'),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'categories' => Policy::CATEGORIES,
        ]);
    }

    public function update(Request $request, Policy $policy)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'category' => 'nullable|in:' . implode(',', array_keys(Policy::CATEGORIES)),
            'owner_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:' . implode(',', Policy::STATUSES),
            'is_mandatory' => 'boolean',
            'effective_date' => 'nullable|date',
            'review_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
        ]);

        $contentChanged = isset($validated['content'])
            && $validated['content'] !== $policy->content;

        if ($contentChanged) {
            // Increment version
            $currentVersion = $policy->version_number;
            $parts = explode('.', $currentVersion);
            $major = (int) ($parts[0] ?? 1);
            $minor = (int) ($parts[1] ?? 0);
            $newVersion = $major . '.' . ($minor + 1);

            $validated['version_number'] = $newVersion;

            PolicyVersion::create([
                'policy_id' => $policy->id,
                'version_number' => $newVersion,
                'content' => $validated['content'],
                'change_summary' => $request->input('change_summary', 'Content updated'),
                'created_by' => auth()->id(),
            ]);
        }

        $policy->update($validated);

        return redirect()->route('policies.show', $policy)
            ->with('success', 'Policy updated successfully.');
    }

    public function destroy(Policy $policy)
    {
        $policy->delete();

        return redirect()->route('policies.index')
            ->with('success', "Policy {$policy->policy_code} archived.");
    }

    public function publish(Policy $policy)
    {
        $policy->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return back()->with('success', 'Policy published successfully.');
    }

    public function attest(Request $request, Policy $policy)
    {
        PolicyAttestation::updateOrCreate(
            [
                'organization_id' => auth()->user()->organization_id,
                'policy_id' => $policy->id,
                'user_id' => auth()->id(),
            ],
            [
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
                'notes' => $request->input('notes'),
            ]
        );

        return back()->with('success', 'Policy acknowledged successfully.');
    }
}
