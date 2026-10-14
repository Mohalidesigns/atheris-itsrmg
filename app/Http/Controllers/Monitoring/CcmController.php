<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\CcmTenantTest;
use App\Models\Control;
use App\Models\User;
use App\Services\Monitoring\CcmChecks;
use App\Services\Monitoring\CcmRunner;
use App\Services\Monitoring\MonitoringMetrics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * CCM console (ATH-VAL-013). Replaces the PlatformController stub, which listed every bank's tests
 * and set a random pass / warn / fail on "Run now". A run now measures the check (CcmRunner).
 */
class CcmController extends Controller
{
    public function index(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $tests = CcmTenantTest::query()->with(['test', 'control:id,control_code,title', 'owner:id,name', 'issue:id,issue_code,status'])
            ->when($request->status, fn ($q, $s) => $q->where('last_status', $s))
            ->when($request->boolean('unlinked'), fn ($q) => $q->whereNull('control_id'))
            ->get()->sortBy(fn ($t) => $t->test->code)->values();

        return Inertia::render('Ccm/Index', [
            'tests' => $tests->map(fn ($t) => [
                'id' => $t->id, 'code' => $t->test->code, 'title' => $t->test->title, 'adapter' => $t->test->adapter_key,
                'mode' => CcmChecks::definition($t->test->code)['platform'] ? 'platform' : 'sandbox',
                'framework_refs' => $t->test->framework_refs, 'status' => $t->status, 'last_status' => $t->last_status, 'last_value' => $t->last_value,
                'last_run_at' => $t->last_run_at, 'next_run_at' => $t->next_run_at, 'control' => $t->control, 'owner' => $t->owner,
                'issue' => $t->issue,
            ]),
            'summary' => app(MonitoringMetrics::class)->summary($orgId),
            'filters' => $request->only('status', 'unlinked'),
            'canRun' => $request->user()->can('edit monitoring'),
        ]);
    }

    public function show(Request $request, CcmTenantTest $tenantTest)
    {
        $tenantTest->load(['test', 'control:id,control_code,title,effectiveness,owner_id', 'owner:id,name', 'issue:id,issue_code,status,title']);
        $def = CcmChecks::definition($tenantTest->test->code, $tenantTest->overrides);

        return Inertia::render('Ccm/Show', [
            'test' => $tenantTest,
            'definition' => $def + ['threshold' => CcmChecks::thresholdLabel($def), 'mode' => $def['platform'] ? 'platform' : 'sandbox'],
            'runs' => $tenantTest->runs()->with('trigger:id,name')->orderByDesc('ran_at')->limit(60)->get(),
            'controls' => Control::query()->orderBy('control_code')->get(['id', 'control_code', 'title']),
            'users' => User::where('organization_id', $request->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            'canEdit' => $request->user()->can('edit monitoring'),
        ]);
    }

    public function run(Request $request, CcmTenantTest $tenantTest, CcmRunner $runner)
    {
        abort_if($tenantTest->status === 'disabled', 422, 'This test is disabled.');
        $run = $runner->run($tenantTest, $request->user());

        return back()->with($run->status === 'pass' ? 'success' : 'warning', "{$tenantTest->test->code}: {$run->status}. {$run->output_summary}");
    }

    /** Link the test to a control, set its owner and status, or override its thresholds. */
    public function update(Request $request, CcmTenantTest $tenantTest)
    {
        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'control_id' => ['nullable', Rule::exists('controls', 'id')->where('organization_id', $orgId)],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'status' => ['required', Rule::in(CcmTenantTest::STATUSES)],
            'pass' => 'nullable|numeric|min:0',
            'warn' => 'nullable|numeric|min:0',
        ]);
        $def = CcmChecks::definition($tenantTest->test->code);
        if (isset($data['pass'], $data['warn'])) {
            $ok = $def['better'] === 'higher' ? $data['warn'] <= $data['pass'] : $data['warn'] >= $data['pass'];
            $ok || throw ValidationException::withMessages(['warn' => $def['better'] === 'higher' ? 'The warn limit cannot be above the pass limit.' : 'The warn limit cannot be below the pass limit.']);
        }
        $overrides = array_filter(['pass' => $data['pass'] ?? null, 'warn' => $data['warn'] ?? null], fn ($v) => $v !== null);

        $tenantTest->update([
            'control_id' => $data['control_id'] ?? null, 'owner_id' => $data['owner_id'] ?? null, 'status' => $data['status'],
            'overrides' => $overrides ?: null,
        ]);

        return back()->with('success', 'CCM test updated.');
    }
}
