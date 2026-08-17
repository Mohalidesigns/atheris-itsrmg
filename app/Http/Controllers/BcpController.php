<?php

namespace App\Http\Controllers;

use App\Models\BcpPlan;
use App\Models\BiaRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BcpController extends Controller
{
    public function planIndex(Request $request)
    {
        $query = BcpPlan::with(['owner:id,name'])
            ->withCount('tests');

        if ($request->filled('plan_type')) {
            $query->where('plan_type', $request->plan_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('plan_code', 'like', "%{$search}%");
            });
        }

        $plans = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Bcp/Plans/Index', [
            'plans' => $plans,
            'filters' => $request->only(['plan_type', 'status', 'search']),
            'planTypes' => BcpPlan::PLAN_TYPES,
            'statuses' => BcpPlan::STATUSES,
        ]);
    }

    public function planCreate()
    {
        return Inertia::render('Bcp/Plans/Create', [
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'nextCode' => BcpPlan::generateNextCode(auth()->user()->organization_id),
            'planTypes' => BcpPlan::PLAN_TYPES,
        ]);
    }

    public function planStore(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'plan_type' => 'required|in:bcp,dr,crisis',
            'scope' => 'nullable|string',
            'objectives' => 'nullable|string',
            'rto_hours' => 'nullable|integer|min:0',
            'rpo_hours' => 'nullable|integer|min:0',
            'owner_id' => 'nullable|exists:users,id',
            'next_review_date' => 'nullable|date',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['plan_code'] = BcpPlan::generateNextCode(auth()->user()->organization_id);

        $plan = BcpPlan::create($validated);

        return redirect()->route('bcp.plans.show', $plan)
            ->with('success', "Plan {$plan->plan_code} created successfully.");
    }

    public function planShow(BcpPlan $bcpPlan)
    {
        $bcpPlan->load([
            'owner:id,name,email',
            'approver:id,name',
            'tests' => fn ($q) => $q->with('conductor:id,name')->orderByDesc('scheduled_date'),
        ]);

        return Inertia::render('Bcp/Plans/Show', [
            'plan' => $bcpPlan,
        ]);
    }

    public function drPlanIndex()
    {
        $drPlans = BcpPlan::where('plan_type', 'dr')
            ->with('owner:id,name')
            ->orderBy('title')
            ->get();

        // Nigerian DR runbooks (from Phase 7 seed)
        $runbooks = \DB::table('dr_runbooks')->orderBy('name')->get();
        $exercises = \DB::table('dr_exercises as e')
            ->leftJoin('dr_runbooks as r', 'r.id', '=', 'e.runbook_id')
            ->orderByDesc('e.scheduled_at')
            ->select('e.*', 'r.name as runbook_name')
            ->take(20)->get();

        $kpis = [
            'dr_plans' => $drPlans->count(),
            'runbooks' => $runbooks->count(),
            'exercises_ytd' => $exercises->filter(fn ($e) => $e->scheduled_at && substr($e->scheduled_at, 0, 4) === now()->format('Y'))->count(),
            'avg_rto_hours' => (int) round($drPlans->avg('rto_hours') ?? 0),
            'avg_rpo_hours' => (int) round($drPlans->avg('rpo_hours') ?? 0),
        ];

        return Inertia::render('Bcp/DrPlans/Index', compact('drPlans', 'runbooks', 'exercises', 'kpis'));
    }

    public function testIndex()
    {
        $plans = BcpPlan::with('owner:id,name')->orderBy('title')->get();
        $tests = \DB::table('bcp_tests as t')
            ->leftJoin('bcp_plans as p', 'p.id', '=', 't.plan_id')
            ->leftJoin('users as u', 'u.id', '=', 't.conducted_by')
            ->select('t.*', 'p.title as plan_title', 'u.name as conductor_name')
            ->orderByDesc('t.scheduled_date')
            ->get();

        $kpis = [
            'total' => $tests->count(),
            'passed' => $tests->where('pass_fail', 'pass')->count(),
            'passed_with_issues' => $tests->where('pass_fail', 'partial')->count(),
            'failed' => $tests->where('pass_fail', 'fail')->count(),
            'pass_rate' => $tests->count()
                ? (int) round($tests->whereIn('pass_fail', ['pass', 'partial'])->count() / $tests->count() * 100)
                : 0,
        ];

        return Inertia::render('Bcp/Tests/Index', compact('plans', 'tests', 'kpis'));
    }

    public function biaIndex(Request $request)
    {
        $query = BiaRecord::with(['owner:id,name']);

        if ($request->filled('criticality')) {
            $query->where('criticality', $request->criticality);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('process_name', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%");
            });
        }

        $records = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Bcp/Bia/Index', [
            'records' => $records,
            'filters' => $request->only(['criticality', 'search']),
            'criticalities' => BiaRecord::CRITICALITIES,
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
        ]);
    }

    public function biaStore(Request $request)
    {
        $validated = $request->validate([
            'process_name' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'criticality' => 'required|in:critical,high,medium,low',
            'rto_hours' => 'nullable|integer|min:0',
            'rpo_hours' => 'nullable|integer|min:0',
            'mtpd_hours' => 'nullable|integer|min:0',
            'financial_impact_per_hour' => 'nullable|numeric|min:0',
            'dependencies' => 'nullable|string',
            'recovery_strategy' => 'nullable|string',
            'owner_id' => 'nullable|exists:users,id',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;

        BiaRecord::create($validated);

        return redirect()->back()->with('success', 'BIA record created successfully.');
    }
}
