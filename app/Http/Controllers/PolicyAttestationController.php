<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Read-only register of policy attestation records.
 *
 * Attestations are created when a policy is published (PolicyController::publish)
 * and acknowledged through the policies.attest action — this controller
 * deliberately does not duplicate that write path.
 */
class PolicyAttestationController extends Controller
{
    public function index(Request $request)
    {
        $query = PolicyAttestation::with([
            'policy:id,title,policy_code,status',
            'user:id,name,email,department',
        ]);

        if ($request->filled('policy_id')) {
            $query->where('policy_id', $request->policy_id);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $attestations = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        $stats = [
            'total' => PolicyAttestation::count(),
            'acknowledged' => PolicyAttestation::where('status', 'acknowledged')->count(),
            'pending' => PolicyAttestation::where('status', 'pending')->count(),
            'overdue' => PolicyAttestation::where('status', 'pending')
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->count(),
        ];

        return Inertia::render('Policies/Attestations/Index', [
            'attestations' => $attestations,
            'stats' => $stats,
            'policies' => Policy::select('id', 'title')->orderBy('title')->get(),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->orderBy('name')->get(),
            'filters' => $request->only(['policy_id', 'user_id', 'status']),
            'statuses' => PolicyAttestation::STATUSES,
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
        ]);
    }
}
