<?php

namespace App\Http\Controllers;

use App\Models\BcpPlan;
use App\Models\BcpTest;
use App\Models\BiaRecord;
use App\Models\DrExercise;
use App\Models\DrRunbook;
use App\Models\Ea\BusinessService;
use App\Models\User;
use App\Services\Continuity\ContinuityMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Business Continuity (ATH-VAL-012): BCP/DR/crisis plans with segregated approval and recorded
 * tests, a BIA tied to the EA business services whose recovery objectives it sets, and DR runbooks
 * and exercises measured against their RTO. All reads go through tenant-scoped models; the DR and
 * test pages used to query their tables for every bank.
 */
class BcpController extends Controller
{
    public function planIndex(Request $request)
    {
        $query = BcpPlan::with(['owner:id,name', 'approver:id,name'])->withCount(['tests', 'services']);

        foreach (['plan_type', 'status'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->input($column));
            }
        }
        match ($request->input('flag')) {
            'test_overdue' => $query->where(fn ($q) => $q->whereNull('last_tested')->orWhereDate('last_tested', '<', now()->subMonths(ContinuityMetrics::TEST_MONTHS))),
            'review_overdue' => $query->whereDate('next_review_date', '<', today()),
            default => null,
        };
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->whereLike('title', "%{$search}%")->orWhereLike('plan_code', "%{$search}%"));
        }

        return Inertia::render('Bcp/Plans/Index', [
            'plans' => $query->orderBy('plan_code')->paginate(15)->withQueryString(),
            'metrics' => app(ContinuityMetrics::class)->summary(),
            'filters' => $request->only(['plan_type', 'status', 'flag', 'search']),
            'planTypes' => BcpPlan::PLAN_TYPES,
            'statuses' => BcpPlan::STATUSES,
            'testMonths' => ContinuityMetrics::TEST_MONTHS,
        ]);
    }

    public function planCreate()
    {
        return Inertia::render('Bcp/Plans/Create', $this->planOptions() + [
            'nextCode' => BcpPlan::generateNextCode(auth()->user()->organization_id),
        ]);
    }

    public function planStore(Request $request)
    {
        $validated = $this->planValidated($request);
        $services = $validated['service_ids'] ?? [];
        unset($validated['service_ids']);
        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['plan_code'] = BcpPlan::generateNextCode(auth()->user()->organization_id);
        $validated['status'] = 'draft';

        $plan = DB::transaction(function () use ($validated, $services) {
            $plan = BcpPlan::create($validated);
            $this->syncServices($plan, $services);

            return $plan;
        });

        return redirect()->route('bcp.plans.show', $plan)->with('success', "Plan {$plan->plan_code} created as a draft.");
    }

    public function planShow(BcpPlan $bcpPlan)
    {
        $bcpPlan->load([
            'owner:id,name,email', 'approver:id,name',
            'tests' => fn ($q) => $q->with('conductor:id,name')->orderByDesc('scheduled_date'),
            'services' => fn ($q) => $q->select('ea_business_services.id', 'code', 'name', 'criticality', 'rto_minutes', 'rpo_minutes'),
        ]);
        $user = request()->user();

        return Inertia::render('Bcp/Plans/Show', [
            'plan' => $bcpPlan,
            'testOverdue' => ! $bcpPlan->last_tested || $bcpPlan->last_tested->lt(now()->subMonths(ContinuityMetrics::TEST_MONTHS)),
            'testTypes' => BcpTest::TEST_TYPES,
            'users' => User::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'edit' => $user->can('edit bcp-plans'),
                'approve' => $user->can('approve bcp-plans'),
            ],
        ]);
    }

    public function planEdit(BcpPlan $bcpPlan)
    {
        return Inertia::render('Bcp/Plans/Create', $this->planOptions() + [
            'plan' => $bcpPlan,
            'linkedServiceIds' => $bcpPlan->services()->pluck('ea_business_services.id'),
        ]);
    }

    public function planUpdate(Request $request, BcpPlan $bcpPlan)
    {
        $validated = $this->planValidated($request);
        $services = $validated['service_ids'] ?? null;
        unset($validated['service_ids']);

        // A material change to an approved plan needs approving again.
        $material = collect(['scope', 'objectives', 'rto_hours', 'rpo_hours'])->contains(fn ($k) => array_key_exists($k, $validated) && $validated[$k] != $bcpPlan->{$k});
        if ($material && $bcpPlan->status === 'active') {
            $validated += ['status' => 'under_review', 'approved_by' => null, 'approved_at' => null];
        }

        DB::transaction(function () use ($bcpPlan, $validated, $services) {
            $bcpPlan->update($validated);
            if ($services !== null) {
                $this->syncServices($bcpPlan, $services);
            }
        });

        return redirect()->route('bcp.plans.show', $bcpPlan)->with('success', $material && ($validated['status'] ?? null) === 'under_review'
            ? 'Plan updated and sent back for approval.' : 'Plan updated.');
    }

    /** Approve a plan into force. The plan owner cannot approve their own plan. */
    public function planApprove(Request $request, BcpPlan $bcpPlan)
    {
        abort_unless(in_array($bcpPlan->status, ['draft', 'under_review'], true), 422, 'Only a draft or a plan under review can be approved.');
        if ($request->user()->id === (int) $bcpPlan->owner_id) {
            throw ValidationException::withMessages(['plan' => 'The plan owner cannot approve their own plan.']);
        }
        if (blank($bcpPlan->scope) || $bcpPlan->rto_hours === null) {
            throw ValidationException::withMessages(['plan' => 'A plan needs its scope and recovery time objective before it can be approved.']);
        }

        $bcpPlan->update([
            'status' => 'active', 'approved_by' => $request->user()->id, 'approved_at' => now(),
            'next_review_date' => $bcpPlan->next_review_date && $bcpPlan->next_review_date->isFuture() ? $bcpPlan->next_review_date : today()->addYear(),
        ]);

        return back()->with('success', "{$bcpPlan->plan_code} approved and in force.");
    }

    /** Record a completed test or exercise of the plan; it sets the plan's last-tested date. */
    public function planTest(Request $request, BcpPlan $bcpPlan)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'test_type' => ['required', Rule::in(BcpTest::TEST_TYPES)],
            'completed_date' => 'required|date|before_or_equal:today',
            'pass_fail' => ['required', Rule::in(BcpTest::PASS_FAIL)],
            'rto_achieved_hours' => 'nullable|numeric|min:0',
            'results' => 'required|string|min:10',
            'findings' => 'required_unless:pass_fail,pass|nullable|string',
            'recommendations' => 'nullable|string',
        ], ['findings.required_unless' => 'Record what went wrong when a test does not pass.']);

        $results = $data['results'].(isset($data['rto_achieved_hours']) ? "\nRecovered in {$data['rto_achieved_hours']}h against an RTO of {$bcpPlan->rto_hours}h." : '');
        DB::transaction(function () use ($bcpPlan, $data, $results, $request) {
            BcpTest::create([
                'organization_id' => $bcpPlan->organization_id, 'plan_id' => $bcpPlan->id, 'title' => $data['title'],
                'test_type' => $data['test_type'], 'status' => 'completed',
                'scheduled_date' => $data['completed_date'], 'completed_date' => $data['completed_date'],
                'conducted_by' => $request->user()->id, 'results' => $results, 'findings' => $data['findings'] ?? null,
                'recommendations' => $data['recommendations'] ?? null, 'pass_fail' => $data['pass_fail'],
            ]);
            if (! $bcpPlan->last_tested || $bcpPlan->last_tested->lt($data['completed_date'])) {
                $bcpPlan->update(['last_tested' => $data['completed_date'], 'next_test_date' => Carbon::parse($data['completed_date'])->addMonths(ContinuityMetrics::TEST_MONTHS)]);
            }
        });

        return back()->with('success', 'Test recorded.');
    }

    public function drPlanIndex()
    {
        $drPlans = BcpPlan::where('plan_type', 'dr')->with('owner:id,name')->orderBy('title')->get();
        $runbooks = DrRunbook::query()->withCount('exercises')->orderBy('name')->get();
        $exercises = DrExercise::query()->with('runbook:id,name')->orderByDesc('scheduled_at')->limit(20)->get();
        $metrics = app(ContinuityMetrics::class)->summary();

        return Inertia::render('Bcp/DrPlans/Index', [
            'drPlans' => $drPlans,
            'runbooks' => $runbooks,
            'exercises' => $exercises->map(fn ($e) => $e->toArray() + ['runbook_name' => $e->runbook?->name]),
            'kpis' => [
                'dr_plans' => $drPlans->count(),
                'runbooks' => $runbooks->count(),
                'exercises_ytd' => $exercises->filter(fn ($e) => $e->scheduled_at?->year === now()->year)->count(),
                'avg_rto_hours' => (int) round($drPlans->avg('rto_hours') ?? 0),
                'avg_rpo_hours' => (int) round($drPlans->avg('rpo_hours') ?? 0),
                'within_rto' => $metrics['exercises_12m'] ? (int) round($metrics['exercises_within_rto'] / $metrics['exercises_12m'] * 100) : null,
            ],
        ]);
    }

    public function testIndex()
    {
        $tests = BcpTest::query()->with(['plan:id,plan_code,title', 'conductor:id,name'])->orderByDesc('scheduled_date')->get();

        return Inertia::render('Bcp/Tests/Index', [
            'plans' => BcpPlan::with('owner:id,name')->orderBy('title')->get(),
            'tests' => $tests->map(fn ($t) => $t->toArray() + ['plan_title' => $t->plan?->title, 'conductor_name' => $t->conductor?->name]),
            'kpis' => [
                'total' => $tests->count(),
                'passed' => $tests->where('pass_fail', 'pass')->count(),
                'passed_with_issues' => $tests->where('pass_fail', 'partial')->count(),
                'failed' => $tests->where('pass_fail', 'fail')->count(),
                'pass_rate' => $tests->whereNotNull('pass_fail')->count()
                    ? (int) round($tests->whereIn('pass_fail', ['pass', 'partial'])->count() / $tests->whereNotNull('pass_fail')->count() * 100) : 0,
            ],
        ]);
    }

    public function biaIndex(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $query = BiaRecord::with(['owner:id,name', 'approver:id,name', 'service:id,code,name,criticality,rto_minutes,rpo_minutes']);

        if ($request->filled('criticality')) {
            $query->where('criticality', $request->criticality);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->whereLike('process_name', "%{$search}%")->orWhereLike('department', "%{$search}%"));
        }

        $records = $query->orderBy('process_name')->paginate(15)->withQueryString();
        $records->getCollection()->transform(fn ($r) => $r->setAttribute('service_mismatch', $r->service
            && $r->rto_hours !== null && (int) round($r->rto_hours * 60) !== (int) $r->service->rto_minutes));

        $assessed = BiaRecord::query()->whereNotNull('business_service_id')->pluck('business_service_id')->flip();

        return Inertia::render('Bcp/Bia/Index', [
            'records' => $records,
            'filters' => $request->only(['criticality', 'search']),
            'criticalities' => BiaRecord::CRITICALITIES,
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
            'services' => BusinessService::query()->where('organization_id', $orgId)->orderBy('name')->get(['id', 'code', 'name', 'criticality', 'rto_minutes']),
            'unassessed' => BusinessService::query()->where('organization_id', $orgId)->whereIn('criticality', ['critical', 'high'])
                ->get(['id', 'code', 'name', 'criticality'])->reject(fn ($s) => isset($assessed[$s->id]))->values(),
            'can' => ['create' => request()->user()->can('create bcp-plans'), 'approve' => request()->user()->can('approve bcp-plans')],
        ]);
    }

    public function biaStore(Request $request)
    {
        $validated = $this->biaValidated($request);
        $validated['organization_id'] = auth()->user()->organization_id;

        BiaRecord::create($validated);

        return redirect()->back()->with('success', 'BIA record created.');
    }

    public function biaUpdate(Request $request, BiaRecord $biaRecord)
    {
        $validated = $this->biaValidated($request);
        // Changed objectives need approving and applying again.
        if (($validated['rto_hours'] ?? null) != $biaRecord->rto_hours || ($validated['rpo_hours'] ?? null) != $biaRecord->rpo_hours) {
            $validated += ['approved_by' => null, 'approved_at' => null, 'applied_at' => null];
        }
        $biaRecord->update($validated);

        return back()->with('success', 'BIA record updated.');
    }

    /**
     * Approve a BIA and write its recovery objectives onto the business service it assessed, so
     * EA, Asset Management and the DR plans all read one RTO/RPO. The BIA owner cannot approve it.
     */
    public function biaApply(Request $request, BiaRecord $biaRecord)
    {
        if (! $biaRecord->business_service_id) {
            throw ValidationException::withMessages(['bia' => 'Link the BIA to the business service it assesses first.']);
        }
        if ($request->user()->id === (int) $biaRecord->owner_id) {
            throw ValidationException::withMessages(['bia' => 'The process owner cannot approve their own BIA.']);
        }
        if ($biaRecord->rto_hours === null || $biaRecord->rpo_hours === null) {
            throw ValidationException::withMessages(['bia' => 'The BIA needs both an RTO and an RPO.']);
        }

        DB::transaction(function () use ($biaRecord, $request) {
            BusinessService::query()->where('id', $biaRecord->business_service_id)->update([
                'rto_minutes' => (int) round($biaRecord->rto_hours * 60),
                'rpo_minutes' => (int) round($biaRecord->rpo_hours * 60),
                'updated_at' => now(),
            ]);
            $biaRecord->update(['approved_by' => $request->user()->id, 'approved_at' => now(), 'applied_at' => now()]);
        });

        return back()->with('success', "BIA approved; {$biaRecord->service?->name} now has RTO {$biaRecord->rto_hours}h and RPO {$biaRecord->rpo_hours}h.");
    }

    private function syncServices(BcpPlan $plan, array $serviceIds): void
    {
        $plan->services()->sync(collect($serviceIds)->unique()->mapWithKeys(fn ($id) => [(int) $id => ['organization_id' => $plan->organization_id]])->all());
    }

    private function planOptions(): array
    {
        $orgId = auth()->user()->organization_id;

        return [
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
            'services' => BusinessService::query()->where('organization_id', $orgId)->orderBy('name')->get(['id', 'code', 'name', 'criticality']),
            'planTypes' => BcpPlan::PLAN_TYPES,
        ];
    }

    private function planValidated(Request $request): array
    {
        $orgId = auth()->user()->organization_id;

        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'plan_type' => ['required', Rule::in(BcpPlan::PLAN_TYPES)],
            'scope' => 'nullable|string',
            'objectives' => 'nullable|string',
            'rto_hours' => 'nullable|integer|min:0',
            'rpo_hours' => 'nullable|integer|min:0',
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'next_review_date' => 'nullable|date',
            'service_ids' => 'sometimes|array',
            'service_ids.*' => ['integer', Rule::exists('ea_business_services', 'id')->where('organization_id', $orgId)],
        ]);
    }

    private function biaValidated(Request $request): array
    {
        $orgId = auth()->user()->organization_id;

        return $request->validate([
            'process_name' => 'required|string|max:255',
            'business_service_id' => ['nullable', 'integer', Rule::exists('ea_business_services', 'id')->where('organization_id', $orgId)],
            'department' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'criticality' => ['required', Rule::in(BiaRecord::CRITICALITIES)],
            'rto_hours' => 'nullable|integer|min:0',
            'rpo_hours' => 'nullable|integer|min:0',
            'mtpd_hours' => 'nullable|integer|min:0|gte:rto_hours',
            'financial_impact_per_hour' => 'nullable|numeric|min:0',
            'dependencies' => 'nullable|string',
            'recovery_strategy' => 'nullable|string',
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
        ], ['mtpd_hours.gte' => 'The maximum tolerable period of disruption cannot be shorter than the RTO.']);
    }
}
