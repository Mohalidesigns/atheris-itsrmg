<?php

namespace App\Http\Controllers\Workflows;

use App\Http\Controllers\Controller;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Approvals\ApprovalWorkflowStage;
use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeApprovalStage;
use App\Models\Change\ChangeCabBoard;
use App\Models\Change\ChangeRequest;
use App\Models\User;
use App\Modules\CBNCSAT\Models\CsatApprovalStage;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Services\CsatWorkflowService;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Approvals\ApprovalProcesses;
use App\Services\Change\ChangeApprovalRouter;
use App\Services\Change\ChangePolicySeeder;
use App\Services\Finance\FinancePolicySeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Workflow Studio (ATH-VAL-016): one place to configure, for every process in the platform that
 * needs an approval, who may start it and which stages approve it, in what order and under what
 * conditions. Replaces a read-only list of demo workflows that no process ran.
 */
class WorkflowStudioController extends Controller
{
    public function __construct(private ApprovalEngine $engine) {}

    public function index(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $configs = ApprovalWorkflow::query()->withCount(['stages as active_stages' => fn ($q) => $q->where('is_active', true)])->get()->keyBy('process');
        $open = ApprovalRequest::query()->where('status', 'pending')->selectRaw('process, count(*) as n')->groupBy('process')->pluck('n', 'process');

        $processes = collect(ApprovalProcesses::PROCESSES)->map(function ($def, $key) use ($configs, $open, $orgId) {
            $cfg = $configs->get($key);
            [$stages, $pending] = match ($def['engine']) {
                'change' => [ChangeApprovalStage::where('is_active', true)->count(), ChangeApproval::where('status', 'pending')->distinct()->count('change_request_id')],
                'csat' => [CsatApprovalStage::where('organization_id', $orgId)->count() ?: count(CsatWorkflowService::DEFAULT_STAGES),
                    CsatAssessment::where('organization_id', $orgId)->where('status', 'pending_approval')->count()],
                default => [$cfg && $cfg->is_active ? $cfg->active_stages : 0, (int) ($open[$key] ?? 0)],
            };

            return [
                'key' => $key, 'module' => $def['module'], 'label' => $def['label'], 'description' => $def['description'], 'engine' => $def['engine'],
                'stages' => $stages, 'pending' => $pending, 'configured' => $def['engine'] !== 'generic' || ($cfg && $cfg->is_active && $cfg->active_stages > 0),
                'initiators' => $cfg?->initiator_roles ?? [], 'default' => $def['default'] ?? null,
            ];
        })->values();

        return Inertia::render('Workflows/Index', [
            'processes' => $processes->groupBy('module'),
            'summary' => [
                'processes' => $processes->count(),
                'configured' => $processes->where('configured', true)->count(),
                'pending' => $processes->sum('pending'),
            ],
        ]);
    }

    public function show(Request $request, string $process)
    {
        $def = $this->definition($process);
        $orgId = (int) $request->user()->organization_id;
        if (str_starts_with($process, 'finance.')) {
            FinancePolicySeeder::ensureDefaults($orgId);
        }
        $common = [
            'process' => ['key' => $process] + collect($def)->except(['model', 'conditions'])->all()
                + ['conditions' => collect(ApprovalProcesses::conditions($process, $orgId))->map(fn ($c, $k) => ['field' => $k, 'label' => $c['label'], 'values' => $c['values']])->values()],
            'workflow' => ApprovalWorkflow::query()->where('process', $process)->with('editor:id,name')->first(),
            'roles' => Role::orderBy('name')->pluck('name'),
            'rolesWithPermission' => Role::whereHas('permissions', fn ($q) => $q->where('name', $def['permission']))->orderBy('name')->pluck('name')->push('Super Admin')->unique()->values(),
            'permissions' => Permission::where('name', 'like', 'approve %')->orderBy('name')->pluck('name'),
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'job_title']),
            'approverTypes' => ApprovalProcesses::APPROVER_TYPES,
            'can' => ['edit' => $request->user()->can('edit workflows')],
        ];

        return Inertia::render('Workflows/Process', $common + match ($def['engine']) {
            'change' => $this->changeProps($request, $orgId),
            'csat' => ['csatStages' => $this->csatStages($orgId)],
            default => ['stages' => ApprovalWorkflowStage::query()->whereHas('workflow', fn ($q) => $q->where('process', $process))
                ->orderBy('sequence')->orderBy('id')->get()->map(fn ($s) => $s->toArray() + ['who' => $this->engine->describe($s)])],
        } + ['recent' => $this->recent($process)]);
    }

    /** Initiators, active flag and notes for a process. */
    public function update(Request $request, string $process)
    {
        $this->definition($process);
        $data = $request->validate([
            'initiator_roles' => 'nullable|array', 'initiator_roles.*' => ['string', Rule::exists('roles', 'name')],
            'is_active' => 'boolean', 'notes' => 'nullable|string|max:2000',
        ]);
        $this->workflowFor($request, $process)->update([
            'initiator_roles' => array_values($data['initiator_roles'] ?? []) ?: null,
            'is_active' => $data['is_active'] ?? true, 'notes' => $data['notes'] ?? null, 'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Workflow settings saved. They apply to approvals started from now on.');
    }

    public function stageStore(Request $request, string $process)
    {
        $def = $this->definition($process);
        abort_unless($def['engine'] === 'generic', 404);
        $wf = $this->workflowFor($request, $process);
        $data = $this->validatedStage($request, $def, $process);
        $wf->stages()->create($data + ['organization_id' => $wf->organization_id,
            'sequence' => (int) ($data['sequence'] ?? (($wf->stages()->max('sequence') ?? 0) + 10))]);
        $wf->update(['updated_by' => $request->user()->id]);

        return back()->with('success', "Stage \"{$data['name']}\" added.");
    }

    public function stageUpdate(Request $request, $stage)
    {
        $stage = ApprovalWorkflowStage::findOrFail($stage);
        $stage->update($this->validatedStage($request, ApprovalProcesses::get($stage->workflow->process), $stage->workflow->process));
        $stage->workflow->update(['updated_by' => $request->user()->id]);

        return back()->with('success', "Stage \"{$stage->name}\" saved.");
    }

    public function stageDestroy(Request $request, $stage)
    {
        $stage = ApprovalWorkflowStage::findOrFail($stage);
        $stage->delete();

        return back()->with('success', 'Stage removed. Approvals already under way keep their steps.');
    }

    /** Move a stage up or down by swapping sequence with its neighbour. */
    public function stageMove(Request $request, $stage)
    {
        $stage = ApprovalWorkflowStage::findOrFail($stage);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $siblings = $stage->workflow->stages()->get()->values();
        $i = $siblings->search(fn ($s) => $s->id === $stage->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if (isset($siblings[$j])) {
            DB::transaction(function () use ($siblings, $i, $j) {
                $siblings->each(fn ($s, $k) => $s->update(['sequence' => ($k + 1) * 10]));
                [$a, $b] = [$siblings[$i], $siblings[$j]];
                [$sa, $sb] = [$a->fresh()->sequence, $b->fresh()->sequence];
                $a->update(['sequence' => $sb]);
                $b->update(['sequence' => $sa]);
            });
        }

        return back();
    }

    /** CSAT sign-off stages: name and the permission each stage needs (CsatWorkflowService). */
    public function csatStages(int $orgId): array
    {
        $configured = CsatApprovalStage::where('organization_id', $orgId)->orderBy('stage_number')->get(['stage_number', 'stage_name', 'role_required']);

        return $configured->isNotEmpty()
            ? $configured->map(fn ($s) => ['name' => $s->stage_name, 'permission' => $s->role_required])->all()
            : collect(CsatWorkflowService::DEFAULT_STAGES)->map(fn ($s) => ['name' => $s['name'], 'permission' => $s['permission']])->values()->all();
    }

    public function csatUpdate(Request $request)
    {
        $data = $request->validate([
            'stages' => 'required|array|min:2|max:8',
            'stages.*.name' => 'required|string|max:120',
            'stages.*.permission' => ['required', Rule::exists('permissions', 'name')],
        ]);
        abort_if(CsatAssessment::where('organization_id', $request->user()->organization_id)->where('status', 'pending_approval')->exists(), 422,
            'An assessment is awaiting sign-off; change the stages after it is decided.');
        $orgId = (int) $request->user()->organization_id;
        DB::transaction(function () use ($data, $orgId) {
            CsatApprovalStage::where('organization_id', $orgId)->delete();
            foreach (array_values($data['stages']) as $i => $s) {
                CsatApprovalStage::create(['organization_id' => $orgId, 'stage_number' => $i + 1, 'stage_name' => $s['name'],
                    'role_required' => $s['permission'], 'is_mandatory' => true]);
            }
        });

        return back()->with('success', 'CSAT sign-off stages saved. Stage 1 is the preparer\'s submission.');
    }

    /** Every approval awaiting a decision, across processes, with who can act now. */
    public function inbox(Request $request)
    {
        $user = $request->user();
        $rows = ApprovalRequest::query()->where('status', 'pending')->with(['steps.decisions', 'initiator:id,name', 'subject'])->latest('id')->get()
            ->map(function (ApprovalRequest $r) use ($user) {
                $step = $r->currentStep();
                $def = ApprovalProcesses::PROCESSES[$r->process] ?? [];

                return [
                    'id' => $r->id, 'process' => $r->process, 'process_label' => $def['label'] ?? $r->process, 'module' => $def['module'] ?? '',
                    'subject' => $r->subject_label, 'url' => isset($def['route']) ? $this->recordUrl($def['route'], $r) : null,
                    'initiator' => $r->initiator?->name, 'started' => $r->created_at,
                    'step' => $step?->name, 'who' => $step ? $this->engine->describe($step) : null, 'due_at' => $step?->due_at,
                    'overdue' => $step?->due_at?->isPast() ?? false,
                    'progress' => $r->steps->where('status', 'approved')->count().'/'.$r->steps->count(),
                    'mine' => $step && $this->engine->canDecide($step, $r, $r->subject, $user) && $user->can($def['permission'] ?? ''),
                ];
            });
        $changes = ChangeApproval::query()->where('status', 'pending')->with('change:id,change_code,title,status,requester_id')->orderBy('sequence')->get()
            ->groupBy('change_request_id')->map(fn ($g) => $g->first())->filter(fn ($a) => $a->change && $a->change->status === 'in_approval')
            ->map(fn ($a) => [
                'id' => 'chg-'.$a->id, 'process' => 'change.approval', 'process_label' => 'Change approval', 'module' => 'Change Management',
                'subject' => "{$a->change->change_code} {$a->change->title}", 'url' => route('changes.show', $a->change_request_id),
                'initiator' => null, 'started' => $a->created_at, 'step' => $a->stage_name, 'who' => $this->changeWho($a),
                'due_at' => $a->due_at, 'overdue' => $a->due_at?->isPast() ?? false, 'progress' => null,
                'mine' => app(ChangeApprovalRouter::class)->canDecide($a, $user),
            ])->values();

        $all = $rows->concat($changes)->sortBy(fn ($r) => [$r['mine'] ? 0 : 1, $r['due_at'] ?? now()->addYears(5)])->values();
        if ($request->boolean('mine')) {
            $all = $all->where('mine', true)->values();
        }

        return Inertia::render('Workflows/Inbox', [
            'items' => $all,
            'summary' => ['pending' => $rows->count() + $changes->count(), 'mine' => $rows->concat($changes)->where('mine', true)->count(),
                'overdue' => $rows->concat($changes)->where('overdue', true)->count()],
            'filters' => $request->only('mine'),
        ]);
    }

    /** JSON for a record page's approval trail. */
    public function trail(Request $request)
    {
        $data = $request->validate(['process' => ['required', Rule::in(array_keys(ApprovalProcesses::generic()))], 'id' => 'required|integer']);
        $def = ApprovalProcesses::get($data['process']);
        $subject = $def['model']::findOrFail($data['id']);
        abort_unless($request->user()->can(str_replace('approve ', 'view ', $def['permission'])), 403);

        return response()->json($this->engine->trail($data['process'], $subject, $request->user()));
    }

    private function changeProps(Request $request, int $orgId): array
    {
        ChangePolicySeeder::ensureDefaults($orgId);

        return [
            'changeStages' => ChangeApprovalStage::orderBy('sequence')->orderBy('id')->get(),
            'boards' => ChangeCabBoard::orderBy('type')->get(['id', 'name', 'type', 'quorum']),
            'changeTypes' => ChangeRequest::TYPES, 'riskLevels' => ChangeRequest::RISK_LEVELS,
            'changeKeys' => ChangeApprovalStage::KEYS, 'changeApproverTypes' => ChangeApprovalStage::APPROVER_TYPES,
            'matrix' => app(ChangeApprovalRouter::class)->matrix($orgId),
            'canChange' => $request->user()->can('approve changes'),
        ];
    }

    private function changeWho(ChangeApproval $a): string
    {
        return match ($a->approver_type) {
            'board' => ChangeCabBoard::find((int) $a->board_id ?: (int) $a->approver_ref)?->name ?? 'the board',
            'job_title' => "anyone whose job title includes \"{$a->approver_ref}\"",
            'role' => "anyone with the role {$a->approver_ref}",
            'user', 'owner' => User::find((int) ($a->assignee_id ?: $a->approver_ref))?->name ?? 'a named person',
            default => (string) $a->approver_type,
        };
    }

    private function recent(string $process): array
    {
        return ApprovalRequest::query()->where('process', $process)->with('initiator:id,name')->latest('id')->limit(10)
            ->get(['id', 'process', 'subject_type', 'subject_id', 'subject_label', 'status', 'initiated_by', 'created_at', 'completed_at'])->toArray();
    }

    private function recordUrl(string $route, ApprovalRequest $r): ?string
    {
        $params = app('router')->getRoutes()->getByName($route)?->parameterNames() ?? [];

        return $params ? route($route, $r->subject_id) : route($route);
    }

    private function definition(string $process): array
    {
        abort_unless(isset(ApprovalProcesses::PROCESSES[$process]), 404);

        return ApprovalProcesses::PROCESSES[$process];
    }

    private function workflowFor(Request $request, string $process): ApprovalWorkflow
    {
        return ApprovalWorkflow::query()->firstOrCreate(['process' => $process, 'organization_id' => $request->user()->organization_id],
            ['is_active' => true, 'updated_by' => $request->user()->id]);
    }

    private function validatedStage(Request $request, array $def, string $process): array
    {
        $orgId = (int) $request->user()->organization_id;
        $conditions = ApprovalProcesses::conditions($process, $orgId);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'sequence' => 'nullable|integer|min:1|max:999',
            'approver_type' => ['required', Rule::in(array_keys(ApprovalProcesses::APPROVER_TYPES))],
            'approver_ref' => 'nullable|string|max:255',
            'min_approvals' => 'required|integer|min:1|max:10',
            'sla_hours' => 'required|integer|min:1|max:2160',
            'condition_field' => ['nullable', Rule::in(array_keys($conditions))],
            'condition_values' => 'nullable|array',
            'condition_values.*' => 'string|max:64',
            'is_active' => 'boolean',
        ]);
        $ref = $data['approver_ref'] ?? null;
        $fail = fn (string $m) => throw ValidationException::withMessages(['approver_ref' => $m]);
        match ($data['approver_type']) {
            'role' => Role::where('name', $ref)->exists() || $fail('Pick a role.'),
            'user' => User::where('organization_id', $orgId)->where('id', (int) $ref)->exists() || $fail('Pick a person in this bank.'),
            'job_title' => filled($ref) || $fail('Give the job title.'),
            'permission' => Permission::where('name', $ref)->exists() || $fail('Pick a permission.'),
            'owner' => isset($def['owner']) || $fail('This process has no record owner; pick another approver.'),
        };
        if ($data['approver_type'] === 'owner') {
            $data['approver_ref'] = null;
        }
        if (! empty($data['condition_field'])) {
            $allowed = array_map('strval', array_keys($conditions[$data['condition_field']]['values']));
            $values = array_values(array_intersect($data['condition_values'] ?? [], $allowed));
            $values || throw ValidationException::withMessages(['condition_values' => 'Pick the values this stage applies to.']);
            $data['condition_values'] = $values;
        } else {
            $data['condition_field'] = null;
            $data['condition_values'] = null;
        }
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }
}
