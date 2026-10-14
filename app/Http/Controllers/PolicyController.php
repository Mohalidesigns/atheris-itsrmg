<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\Policies\PolicyMetrics;
use App\Services\Policies\PolicyWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class PolicyController extends Controller
{
    public function __construct(private PolicyWorkflow $workflow) {}

    public function index(Request $request)
    {
        $query = Policy::with(['owner:id,name'])->withCount([
            'attestations as attested_count' => fn ($q) => $q->where('status', 'completed'),
            'attestations as requested_count' => fn ($q) => $q->whereIn('status', ['pending', 'completed']),
            'controls',
        ]);

        foreach (['status', 'category'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->input($column));
            }
        }
        if (! $request->filled('status')) {
            $query->where('status', '!=', 'retired');
        }
        match ($request->input('flag')) {
            'review_overdue' => $query->where('status', 'active')->whereDate('review_date', '<', today()),
            'unmapped' => $query->where('status', 'active')->whereDoesntHave('controls'),
            default => null,
        };
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereLike('title', "%{$search}%")
                    ->orWhereLike('policy_code', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%");
            });
        }

        $sortField = in_array($request->get('sort'), Policy::SORTABLE, true) ? $request->get('sort') : 'policy_code';
        $sortDir = $request->get('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDir)->orderBy('id');

        return Inertia::render('Policies/Index', [
            'policies' => $query->paginate(15)->withQueryString(),
            'metrics' => app(PolicyMetrics::class)->summary(),
            'filters' => $request->only(['status', 'category', 'flag', 'search', 'sort', 'direction']),
            'statuses' => Policy::STATUSES,
            'categories' => Policy::CATEGORIES,
        ]);
    }

    public function create()
    {
        return Inertia::render('Policies/Create', $this->formOptions() + [
            'nextCode' => Policy::generateNextCode(auth()->user()->organization_id),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $controlIds = $validated['control_ids'] ?? [];
        unset($validated['control_ids']);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['policy_code'] = Policy::generateNextCode(auth()->user()->organization_id);
        $validated['status'] = 'draft';
        $validated['version_number'] = '1.0';

        $policy = DB::transaction(function () use ($validated, $controlIds) {
            $policy = Policy::create($validated);
            if ($validated['content'] ?? null) {
                PolicyVersion::create([
                    'policy_id' => $policy->id, 'version_number' => '1.0', 'content' => $validated['content'],
                    'change_summary' => 'Initial version', 'created_by' => auth()->id(),
                ]);
            }
            $this->syncControls($policy, $controlIds);

            return $policy;
        });

        return redirect()->route('policies.show', $policy)
            ->with('success', "Policy {$policy->policy_code} created as a draft.");
    }

    public function show(Policy $policy)
    {
        $policy->load([
            'owner:id,name,email,job_title',
            'approver:id,name',
            'submitter:id,name',
            'versions' => fn ($q) => $q->with('creator:id,name')->latest('id'),
            'controls' => fn ($q) => $q->select('controls.id', 'control_code', 'title', 'domain', 'effectiveness', 'status'),
            'exceptions' => fn ($q) => $q->with('requester:id,name')->latest()->limit(10),
        ]);

        $attestations = PolicyAttestation::query()->where('policy_id', $policy->id)
            ->where(fn ($q) => $q->where('version_number', $policy->version_number)->orWhereNull('version_number'));
        $summary = [
            'requested' => (clone $attestations)->whereIn('status', ['pending', 'completed'])->count(),
            'completed' => (clone $attestations)->where('status', 'completed')->count(),
            'overdue' => (clone $attestations)->overdue()->count(),
            'waived' => (clone $attestations)->where('status', 'waived')->count(),
        ];
        $user = request()->user();

        return Inertia::render('Policies/Show', [
            'policy' => $policy,
            'attestationSummary' => $summary,
            'recentAttestations' => (clone $attestations)->with('user:id,name,department')->latest('updated_at')->limit(15)->get(),
            'userAttestation' => PolicyAttestation::query()->where('policy_id', $policy->id)->where('user_id', $user->id)->latest('id')->first(),
            'trail' => Activity::query()->where('subject_type', Policy::class)->where('subject_id', $policy->id)
                ->with('causer:id,name')->latest()->limit(20)->get(['id', 'event', 'description', 'attribute_changes', 'causer_id', 'causer_type', 'created_at']),
            'categories' => Policy::CATEGORIES,
            'can' => [
                'edit' => $user->can('edit policies') && $policy->status !== 'retired',
                'approve' => $user->can('approve policies'),
                'delete' => $user->can('delete policies'),
            ],
        ]);
    }

    public function edit(Policy $policy)
    {
        abort_if($policy->status === 'retired', 422, 'A retired policy cannot be edited.');

        return Inertia::render('Policies/Edit', $this->formOptions() + [
            'policy' => $policy->load('owner:id,name'),
            'linkedControlIds' => $policy->controls()->pluck('controls.id'),
        ]);
    }

    /**
     * Status is not editable here: it moves only through the workflow actions. A content change to
     * an approved or active policy is a new version and goes back for approval.
     */
    public function update(Request $request, Policy $policy)
    {
        abort_if($policy->status === 'retired', 422, 'A retired policy cannot be edited.');

        $validated = $this->validated($request);
        $controlIds = $validated['control_ids'] ?? null;
        unset($validated['control_ids']);

        $contentChanged = array_key_exists('content', $validated) && $validated['content'] !== $policy->content;
        $reopened = false;

        DB::transaction(function () use ($policy, $validated, $controlIds, $contentChanged, $request, &$reopened) {
            if ($contentChanged) {
                $validated['version_number'] = $this->workflow->nextVersion($policy);
                PolicyVersion::create([
                    'policy_id' => $policy->id, 'version_number' => $validated['version_number'], 'content' => $validated['content'],
                    'change_summary' => $request->input('change_summary') ?: 'Content updated', 'created_by' => auth()->id(),
                ]);
                if (in_array($policy->status, ['approved', 'active'], true)) {
                    $validated += ['status' => 'in_review', 'submitted_by' => auth()->id(), 'submitted_at' => now(),
                        'approved_by' => null, 'approved_at' => null, 'status_note' => 'Revised: '.($request->input('change_summary') ?: 'content updated')];
                    $reopened = true;
                }
            }
            $policy->update($validated);
            if ($controlIds !== null) {
                $this->syncControls($policy, $controlIds);
            }
        });

        return redirect()->route('policies.show', $policy)->with('success', $reopened
            ? "Version {$policy->version_number} saved and sent back for approval."
            : 'Policy updated successfully.');
    }

    public function destroy(Policy $policy)
    {
        if ($policy->status !== 'draft') {
            return back()->with('error', 'Only a draft can be deleted. Retire a policy that has been in review or in force.');
        }
        $policy->delete();

        return redirect()->route('policies.index')->with('success', "Draft {$policy->policy_code} deleted.");
    }

    /** submit | return | approve | publish | retire (see PolicyWorkflow::TRANSITIONS). */
    public function transition(Request $request, Policy $policy)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(PolicyWorkflow::TRANSITIONS))],
            'note' => 'nullable|string|max:2000',
        ]);

        $needs = in_array($data['action'], ['approve', 'publish', 'retire', 'return'], true) ? 'approve policies' : 'edit policies';
        abort_unless($request->user()->can($needs), 403);

        $this->workflow->apply($policy, $data['action'], $request->user(), $data['note'] ?? null);
        if ($this->workflow->pending) {
            return back()->with('success', "{$policy->policy_code}: {$this->workflow->pending}");
        }

        $asked = $data['action'] === 'publish' && $policy->is_mandatory
            ? ' '.PolicyAttestation::query()->where('policy_id', $policy->id)->where('version_number', $policy->version_number)->where('status', 'pending')->count().' staff asked to attest.'
            : '';

        $done = [
            'submit' => 'submitted for approval', 'return' => 'returned to its author', 'approve' => 'approved',
            'publish' => 'published and now in force', 'retire' => 'retired',
        ][$data['action']];

        return back()->with('success', "{$policy->policy_code} {$done}.{$asked}");
    }

    /** Kept for the existing route name: publishing goes through the workflow. */
    public function publish(Request $request, Policy $policy)
    {
        $request->merge(['action' => 'publish']);

        return $this->transition($request, $policy);
    }

    /** Any member of staff with an attestation request for the policy, or who can view policies. */
    public function attest(Request $request, Policy $policy)
    {
        $asked = PolicyAttestation::query()->where('policy_id', $policy->id)->where('user_id', $request->user()->id)->exists();
        abort_unless($asked || $request->user()->can('view policies'), 403);

        $this->workflow->attest($policy, $request->user(), $request->input('notes'));

        return back()->with('success', "You have attested to {$policy->policy_code} version {$policy->version_number}.");
    }

    private function syncControls(Policy $policy, array $controlIds): void
    {
        $policy->controls()->sync(collect($controlIds)->unique()->mapWithKeys(fn ($id) => [
            (int) $id => ['organization_id' => $policy->organization_id],
        ])->all());
    }

    private function formOptions(): array
    {
        return [
            'users' => User::where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            'controls' => Control::query()->orderBy('control_code')->get(['id', 'control_code', 'title', 'domain']),
            'categories' => Policy::CATEGORIES,
        ];
    }

    private function validated(Request $request): array
    {
        $orgId = auth()->user()->organization_id;

        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'category' => ['nullable', Rule::in(array_keys(Policy::CATEGORIES))],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'is_mandatory' => 'boolean',
            'effective_date' => 'nullable|date',
            'review_date' => 'nullable|date|after_or_equal:effective_date',
            'expiry_date' => 'nullable|date|after_or_equal:effective_date',
            'control_ids' => 'sometimes|array',
            'control_ids.*' => ['integer', Rule::exists('controls', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
        ]);
    }
}
