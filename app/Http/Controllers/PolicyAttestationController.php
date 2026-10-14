<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Policy attestations. Requests are created when a mandatory policy is published
 * (PolicyWorkflow::launchCampaign) and completed through policies.attest; this controller is the
 * register for compliance staff, the waiver action, and every member of staff's own inbox.
 */
class PolicyAttestationController extends Controller
{
    public function index(Request $request)
    {
        $query = PolicyAttestation::with([
            'policy:id,title,policy_code,status,version_number',
            'user:id,name,email,department',
        ]);

        if ($request->filled('policy_id')) {
            $query->where('policy_id', $request->policy_id);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('department')) {
            $query->whereHas('user', fn ($q) => $q->where('department', $request->input('department')));
        }
        match ($request->input('status')) {
            'overdue' => $query->overdue(),
            'pending' => $query->where('status', 'pending')->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '>=', today())),
            'completed', 'waived' => $query->where('status', $request->input('status')),
            default => null,
        };

        $base = PolicyAttestation::query()->whereHas('policy', fn ($q) => $q->where('status', 'active'));
        $requested = (clone $base)->whereIn('status', ['pending', 'completed'])->count();
        $completed = (clone $base)->where('status', 'completed')->count();

        return Inertia::render('Policies/Attestations/Index', [
            'attestations' => $query->orderByRaw("case status when 'pending' then 0 else 1 end")->orderBy('due_date')->orderByDesc('id')->paginate(20)->withQueryString(),
            'stats' => [
                'requested' => $requested,
                'completed' => $completed,
                'pending' => $requested - $completed,
                'overdue' => (clone $base)->overdue()->count(),
                'waived' => (clone $base)->where('status', 'waived')->count(),
                'rate' => $requested ? (int) round($completed / $requested * 100) : null,
            ],
            'policies' => Policy::query()->where('status', 'active')->orderBy('policy_code')->get(['id', 'policy_code', 'title']),
            'users' => User::where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            'departments' => User::where('organization_id', auth()->user()->organization_id)->whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
            'filters' => $request->only(['policy_id', 'user_id', 'status', 'department']),
            'statuses' => [...PolicyAttestation::STATUSES, 'overdue'],
            'canWaive' => $request->user()->can('approve policy-attestations'),
        ]);
    }

    public function show(PolicyAttestation $policyAttestation)
    {
        $policyAttestation->load([
            'policy:id,title,policy_code,status,version_number',
            'user:id,name,email,department,job_title',
        ]);

        return Inertia::render('Policies/Attestations/Show', [
            'attestation' => $policyAttestation,
            'overdue' => $policyAttestation->isOverdue(),
            'canWaive' => request()->user()->can('approve policy-attestations'),
        ]);
    }

    /** Excuse a member of staff from an attestation (long leave, exit, out of scope), with a reason. */
    public function waive(Request $request, PolicyAttestation $policyAttestation)
    {
        abort_unless($policyAttestation->status === 'pending', 422, 'Only a pending attestation can be waived.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500', Rule::notIn([''])]]);

        $policyAttestation->update(['status' => 'waived', 'notes' => 'Waived by '.$request->user()->name.': '.$data['reason']]);

        return back()->with('success', 'Attestation waived.');
    }

    /** Every signed-in member of staff: the policies they have been asked to attest to. */
    public function mine(Request $request)
    {
        $mine = PolicyAttestation::query()->where('user_id', $request->user()->id)
            ->whereHas('policy', fn ($q) => $q->where('status', 'active'))
            ->with('policy:id,policy_code,title,description,content,version_number,effective_date,category')
            ->orderByRaw("case status when 'pending' then 0 else 1 end")->orderBy('due_date')->get();

        return Inertia::render('Policies/Attestations/Mine', [
            'pending' => $mine->where('status', 'pending')->values(),
            'done' => $mine->where('status', '!=', 'pending')->take(30)->values(),
        ]);
    }
}
