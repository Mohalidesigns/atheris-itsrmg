<?php

namespace App\Http\Controllers\Change;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeCabBoard;
use App\Models\Change\ChangeFreezeWindow;
use App\Models\Change\ChangeRequest;
use App\Models\Change\ChangeStandardTemplate;
use App\Models\User;
use App\Services\Change\ChangeApprovalRouter;
use App\Services\Change\ChangeMetrics;
use App\Services\Change\ChangeWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Change Management: the change register, raising a change, the change page with its approval
 * tracker, the My Approvals inbox, the forward schedule of change and the CSV export
 * (ATH-CHG-001 §7, §8).
 */
class ChangeRequestController extends Controller
{
    private const SORTS = ['created_at', 'scheduled_start_at', 'risk_level', 'priority', 'status', 'change_code', 'title'];

    public function __construct(private ChangeWorkflow $workflow, private ChangeApprovalRouter $router) {}

    /* ================================ Register ================================ */

    public function index(Request $r, ChangeMetrics $metrics)
    {
        $filters = $r->only(['status', 'type', 'risk', 'category', 'q', 'view', 'sort', 'dir', 'mine']);
        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'created_at';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $me = $r->user()->id;

        $query = ChangeRequest::query()->with(['requester:id,name', 'owner:id,name', 'implementer:id,name'])
            ->withCount(['approvals as approvals_pending' => fn ($q) => $q->where('status', 'pending')])
            ->when($filters['status'] ?? null, function ($q, $status) {
                match ($status) {
                    'open' => $q->whereIn('status', ChangeRequest::OPEN_STATUSES),
                    'upcoming' => $q->upcoming(),
                    default => $q->where('status', $status),
                };
            })
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('change_type', $t))
            ->when($filters['risk'] ?? null, fn ($q, $l) => $q->where('risk_level', $l))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(filled($filters['mine'] ?? null), fn ($q) => $q->where(fn ($w) => $w
                ->where('requester_id', $me)->orWhere('change_owner_id', $me)->orWhere('implementer_id', $me)->orWhere('change_manager_id', $me)))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereLike('title', "%{$term}%")->orWhereLike('change_code', "%{$term}%")->orWhereLike('description', "%{$term}%")));

        $query = match ($sort) {
            'risk_level' => $query->orderByRaw("case risk_level when 'critical' then 1 when 'high' then 2 when 'medium' then 3 else 4 end ".$dir),
            'priority' => $query->orderByRaw("case priority when 'critical' then 1 when 'high' then 2 when 'medium' then 3 else 4 end ".$dir),
            default => $query->orderBy($sort, $dir),
        };
        $rows = $query->orderByDesc('id')->get();

        // Current stage name per change, in one query.
        $current = ChangeApproval::whereIn('change_request_id', $rows->pluck('id'))->where('status', 'pending')
            ->orderBy('sequence')->orderBy('id')->get(['change_request_id', 'stage_name'])
            ->groupBy('change_request_id')->map(fn ($g) => $g->first()->stage_name);

        $changes = $rows->map(fn (ChangeRequest $c) => [
            'id' => $c->id,
            'change_code' => $c->change_code,
            'title' => $c->title,
            'change_type' => $c->change_type,
            'category' => $c->category,
            'priority' => $c->priority,
            'risk_level' => $c->risk_level,
            'status' => $c->status,
            'requester' => $c->requester?->name,
            'owner' => $c->owner?->name,
            'implementer' => $c->implementer?->name,
            'scheduled_start_at' => $c->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $c->scheduled_end_at?->toIso8601String(),
            'current_stage' => in_array($c->status, ['in_approval'], true) || $c->started_before_approval ? ($current[$c->id] ?? null) : null,
            'approvals_pending' => (int) $c->approvals_pending,
            'cab_required' => $c->cab_required,
            'pir_required' => $c->pir_required,
            'created_at' => $c->created_at?->toIso8601String(),
        ]);

        return Inertia::render('Changes/Index', [
            'changes' => $changes,
            'counts' => ChangeRequest::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'stats' => $metrics->summary(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $r)
    {
        $orgId = $r->user()->organization_id;

        return Inertia::render('Changes/Create', [
            'templates' => ChangeStandardTemplate::eligible()->orderBy('name')
                ->get(['id', 'name', 'category', 'description', 'risk_level', 'expected_downtime_minutes', 'implementation_plan', 'backout_plan', 'usage_count']),
            'assets' => $this->assets(),
            'owners' => $this->owners(),
            'sourceRecords' => Inertia::optional(fn () => $this->sourceRecords()),
            'freezeWindows' => ChangeFreezeWindow::activeOrUpcoming()->limit(10)->get(),
            'defaults' => ['change_type' => 'normal', 'priority' => 'medium', 'impact' => 3, 'likelihood' => 3],
            'routingPreview' => $this->router->matrix($orgId),
            'categories' => ChangeRequest::CATEGORIES,
            'sources' => collect(ChangeRequest::SOURCES)->map(fn ($s) => $s['label']),
        ]);
    }

    public function store(Request $r)
    {
        $data = $this->validated($r, true);
        $submit = (bool) ($data['submit'] ?? false);
        unset($data['submit']);

        $change = $this->workflow->create($data, $r->user());
        if ($submit) {
            try {
                $message = $this->workflow->transition($change, 'submit', $r->user(), $data);
            } catch (ValidationException $e) {
                return redirect()->route('changes.show', $change)
                    ->with('warning', "{$change->change_code} saved as a draft but not submitted: ".collect($e->errors())->flatten()->first());
            }

            return redirect()->route('changes.show', $change)->with('success', $message);
        }

        return redirect()->route('changes.show', $change)->with('success', "{$change->change_code} saved as a draft.");
    }

    public function show(Request $r, ChangeRequest $change)
    {
        $user = $r->user();
        $change->load([
            'requester:id,name,job_title', 'owner:id,name,job_title', 'implementer:id,name,job_title', 'changeManager:id,name,job_title',
            'approvedBy:id,name', 'pirBy:id,name', 'template:id,name,category,risk_level',
            'approvals' => fn ($q) => $q->with(['assignee:id,name,job_title', 'decidedBy:id,name', 'board:id,name,type,quorum,chair_id', 'votes.user:id,name,job_title']),
            'tasks' => fn ($q) => $q->with('assignee:id,name'),
            'events' => fn ($q) => $q->with('actor:id,name'),
            'cabItems.meeting:id,title,scheduled_at,status,board_id',
        ]);

        $current = $this->router->currentApproval($change);
        $boardMembers = [];
        $tally = null;
        if ($current && $current->approver_type === 'board') {
            $board = ChangeCabBoard::with('members.user:id,name,job_title')->find($current->board_id);
            $votes = $current->votes->keyBy('user_id');
            $boardMembers = $board?->members->map(fn ($m) => [
                'id' => $m->id, 'user' => $m->user, 'role' => $m->role, 'represents' => $m->represents, 'is_voting' => $m->is_voting,
                'vote' => $votes[$m->user_id]->vote ?? null, 'comment' => $votes[$m->user_id]->comment ?? null,
            ])->values() ?? [];
            $tally = $this->router->evaluateBoard($current);
        }

        return Inertia::render('Changes/Show', [
            'change' => $change,
            'source' => $change->sourceSummary(),
            'affectedAssets' => $change->affectedAssets(),
            'conflicts' => $this->workflow->conflictsFor($change),
            'freeze' => $this->workflow->freezeFor($change),
            'currentApproval' => $current?->id,
            'canWork' => $this->canWork($change, $user),
            'canDecide' => $current ? $this->router->canDecide($current, $user) : false,
            'canVote' => $current ? $this->router->canVote($current, $user) : false,
            'myVote' => $current ? $current->votes->firstWhere('user_id', $user->id)?->vote : null,
            'canApprove' => $user->can('approve changes'),
            'canStart' => $this->canStart($change, $user),
            'canReview' => $change->status === 'review' && $user->can('approve changes') && $user->id !== $change->implementer_id,
            'owners' => $this->owners(),
            'assets' => $this->assets(),
            'boardsMembers' => $boardMembers,
            'voteTally' => $tally,
            'transitions' => ChangeWorkflow::TRANSITIONS,
            'categories' => ChangeRequest::CATEGORIES,
            'sources' => collect(ChangeRequest::SOURCES)->map(fn ($s) => $s['label']),
        ]);
    }

    public function update(Request $r, ChangeRequest $change)
    {
        abort_unless($this->canWork($change, $r->user()), 403);
        if (in_array($change->status, ['closed', 'cancelled'], true)) {
            return back()->with('error', 'A '.$change->status.' change is locked. Raise a new change instead.');
        }
        $data = $this->validated($r, false);
        unset($data['submit'], $data['change_type'], $data['standard_template_id']);

        $current = fn ($k) => $change->{$k} instanceof \DateTimeInterface ? $change->{$k}->toIso8601String() : (is_array($change->{$k}) ? json_encode($change->{$k}) : $change->{$k});
        $incoming = fn ($k) => is_array($data[$k] ?? null) ? json_encode($data[$k]) : ($data[$k] ?? null);
        $changed = collect($data)->keys()->filter(fn ($k) => (string) $current($k) !== (string) $incoming($k));

        foreach (['change_owner_id' => 'Change owner', 'implementer_id' => 'Implementer', 'change_manager_id' => 'Change manager'] as $field => $label) {
            if ($changed->contains($field)) {
                $this->workflow->log($change, 'assigned', "{$label} set to ".(User::find($data[$field])?->name ?? 'nobody').'.', $r->user());
            }
        }
        $change->fill($data);
        if ($change->status === 'draft') {
            $this->workflow->rescore($change, $r->user());
        }
        $change->save();

        $fields = $changed->reject(fn ($k) => in_array($k, ['change_owner_id', 'implementer_id', 'change_manager_id'], true));
        if ($fields->isNotEmpty()) {
            $this->workflow->log($change, 'updated', 'Updated '.$fields->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ').'.', $r->user());
        }

        return back()->with('success', 'Change updated.');
    }

    /* =============================== Transitions =============================== */

    public function transition(Request $r, ChangeRequest $change)
    {
        abort_unless($this->canWork($change, $r->user()) || $r->user()->can('approve changes'), 403);
        $data = $r->validate([
            'action' => ['required', Rule::in(array_keys(ChangeWorkflow::TRANSITIONS))],
            'note' => 'nullable|string|max:4000',
            'decision' => 'nullable|string',
            'vote' => 'nullable|string',
            'comment' => 'nullable|string|max:2000',
            'freeze_override_reason' => 'nullable|string|max:2000',
            'emergency_start_reason' => 'nullable|string|max:2000',
            'scheduled_start_at' => 'nullable|date',
            'scheduled_end_at' => 'nullable|date',
            'pir_outcome' => 'nullable|string',
            'pir_summary' => 'nullable|string|max:5000',
        ]);

        return back()->with('success', $this->workflow->transition($change, $data['action'], $r->user(), $data));
    }

    public function decide(Request $r, ChangeRequest $change)
    {
        $data = $r->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => 'nullable|string|max:4000',
        ]);

        return back()->with('success', $this->workflow->decide($change, $r->user(), $data['decision'], trim((string) ($data['note'] ?? ''))));
    }

    public function vote(Request $r, ChangeRequest $change)
    {
        $data = $r->validate([
            'vote' => ['required', Rule::in(['approve', 'reject', 'abstain', 'defer'])],
            'comment' => 'nullable|string|max:2000',
        ]);

        return back()->with('success', $this->workflow->vote($change, $r->user(), $data['vote'], trim((string) ($data['comment'] ?? ''))));
    }

    public function schedule(Request $r, ChangeRequest $change)
    {
        abort_unless($this->canWork($change, $r->user()) || $r->user()->can('approve changes'), 403);
        $data = $r->validate([
            'scheduled_start_at' => 'required|date',
            'scheduled_end_at' => 'nullable|date',
            'freeze_override_reason' => 'nullable|string|max:2000',
        ]);
        $result = $this->workflow->schedule($change, $r->user(), $data);
        $response = back()->with('success', $result['message']);
        if ($result['conflicts']) {
            $response->with('warning', 'Conflicts with '.collect($result['conflicts'])->pluck('change_code')->implode(', ').'. Review the schedule card.');
        }

        return $response;
    }

    public function comment(Request $r, ChangeRequest $change)
    {
        $data = $r->validate(['note' => 'required|string|max:4000']);
        $this->workflow->log($change, 'comment', $data['note'], $r->user());

        return back()->with('success', 'Comment added.');
    }

    /* ============================== My Approvals ============================== */

    public function approvals(Request $r)
    {
        $user = $r->user();
        $pending = $this->router->pendingFor($user)->map(function (ChangeApproval $a) use ($user) {
            $c = $a->change;

            return [
                'approval_id' => $a->id,
                'change' => [
                    'id' => $c->id, 'change_code' => $c->change_code, 'title' => $c->title, 'change_type' => $c->change_type,
                    'risk_level' => $c->risk_level, 'requester' => $c->requester?->name,
                    'scheduled_start_at' => $c->scheduled_start_at?->toIso8601String(), 'status' => $c->status,
                ],
                'stage_name' => $a->stage_name,
                'stage_key' => $a->stage_key,
                'due_at' => $a->due_at?->toIso8601String(),
                'overdue' => $a->isOverdue(),
                'is_board' => $a->approver_type === 'board',
                'can_decide' => $this->router->canDecide($a, $user),
                'my_vote' => $a->approver_type === 'board' ? $a->votes()->where('user_id', $user->id)->value('vote') : null,
            ];
        });

        $history = ChangeApproval::where('decided_by_id', $user->id)->whereNotNull('decided_at')
            ->with('change:id,change_code,title,change_type,risk_level,status')
            ->orderByDesc('decided_at')->limit(20)->get()
            ->map(fn (ChangeApproval $a) => [
                'approval_id' => $a->id,
                'change' => [
                    'id' => $a->change?->id, 'change_code' => $a->change?->change_code, 'title' => $a->change?->title,
                    'change_type' => $a->change?->change_type, 'risk_level' => $a->change?->risk_level, 'status' => $a->change?->status,
                ],
                'stage_name' => $a->stage_name,
                'stage_key' => $a->stage_key,
                'status' => $a->status,
                'decided_at' => $a->decided_at?->toIso8601String(),
                'decision_note' => $a->decision_note,
            ]);

        return Inertia::render('Changes/Approvals', ['approvals' => $pending->values(), 'history' => $history]);
    }

    /* ================================ Calendar ================================ */

    public function calendar(Request $r)
    {
        $from = $r->filled('from') ? Carbon::parse($r->input('from'))->startOfDay() : now()->startOfMonth();
        $to = $r->filled('to') ? Carbon::parse($r->input('to'))->endOfDay() : $from->copy()->endOfMonth();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $rows = ChangeRequest::inWindow($from, $to)->whereNotIn('status', ['draft', 'rejected', 'cancelled'])
            ->orderBy('scheduled_start_at')->get();
        $conflictIds = $rows->filter(fn (ChangeRequest $c) => in_array($c->status, ChangeRequest::ACTIVE_WINDOW_STATUSES, true) && $this->workflow->conflictsFor($c))
            ->pluck('id')->values();

        return Inertia::render('Changes/Calendar', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'changes' => $rows->map(fn (ChangeRequest $c) => [
                'id' => $c->id, 'change_code' => $c->change_code, 'title' => $c->title, 'change_type' => $c->change_type,
                'risk_level' => $c->risk_level, 'status' => $c->status, 'category' => $c->category,
                'scheduled_start_at' => $c->scheduled_start_at?->toIso8601String(), 'scheduled_end_at' => $c->scheduled_end_at?->toIso8601String(),
                'outage_required' => $c->outage_required,
            ]),
            'freezeWindows' => ChangeFreezeWindow::where('starts_at', '<=', $to)->where('ends_at', '>=', $from)->orderBy('starts_at')->get(),
            'conflictIds' => $conflictIds,
        ]);
    }

    /* ================================= Export ================================= */

    public function export(Request $r)
    {
        $orgSlug = Str::slug((string) ($r->user()->organization?->name ?? 'bank'));
        $rows = ChangeRequest::with(['requester:id,name', 'owner:id,name', 'implementer:id,name', 'changeManager:id,name', 'approvedBy:id,name'])
            ->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Code', 'Title', 'Type', 'Category', 'Priority', 'Impact', 'Likelihood', 'Risk score', 'Risk level', 'Status', 'Requester', 'Change owner', 'Implementer', 'Change manager', 'CAB required', 'Scheduled start', 'Scheduled end', 'Actual start', 'Actual end', 'Submitted', 'Approved', 'Approved by', 'PIR required', 'PIR outcome', 'Closure code', 'Closed', 'Started before approval', 'Freeze override reason', 'Source type', 'Source reference', 'Created']);
            foreach ($rows as $c) {
                fputcsv($out, [
                    $c->change_code, $c->title, $c->change_type, $c->category, $c->priority, $c->impact, $c->likelihood, $c->risk_score, $c->risk_level, $c->status,
                    $c->requester?->name, $c->owner?->name, $c->implementer?->name, $c->changeManager?->name, $c->cab_required ? 'yes' : 'no',
                    $c->scheduled_start_at?->toDateTimeString(), $c->scheduled_end_at?->toDateTimeString(), $c->actual_start_at?->toDateTimeString(), $c->actual_end_at?->toDateTimeString(),
                    $c->submitted_at?->toDateTimeString(), $c->approved_at?->toDateTimeString(), $c->approvedBy?->name, $c->pir_required ? 'yes' : 'no', $c->pir_outcome, $c->closure_code,
                    $c->closed_at?->toDateTimeString(), $c->started_before_approval ? 'yes' : 'no', $c->freeze_override_reason, $c->source_type, $c->source_reference, $c->created_at?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, "change-register-{$orgSlug}-".now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /* ================================ Helpers ================================ */

    /** Requester, owner, implementer and change manager work their own change; edit changes works any. */
    public static function canWork(ChangeRequest $change, User $user): bool
    {
        return $user->can('edit changes')
            || in_array($user->id, [$change->requester_id, $change->change_owner_id, $change->implementer_id, $change->change_manager_id], true);
    }

    private function canStart(ChangeRequest $change, User $user): bool
    {
        if ($change->status === 'scheduled' || ($change->status === 'approved' && $change->change_type === 'emergency')) {
            return $user->can('edit changes') || in_array($user->id, [$change->implementer_id, $change->change_manager_id], true);
        }

        return $change->status === 'in_approval' && $change->change_type === 'emergency' && $user->can('approve changes');
    }

    private function validated(Request $r, bool $creating): array
    {
        $orgId = $r->user()->organization_id;
        $inOrg = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $orgId);

        $data = $r->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
            'justification' => 'nullable|string|max:5000',
            'change_type' => [$creating ? 'required' : 'sometimes', Rule::in(ChangeRequest::TYPES)],
            'category' => ['required', Rule::in(ChangeRequest::CATEGORIES)],
            'priority' => ['required', Rule::in(ChangeRequest::PRIORITIES)],
            'impact' => 'required|integer|min:1|max:5',
            'likelihood' => 'required|integer|min:1|max:5',
            'impact_assessment' => 'nullable|string|max:10000',
            'implementation_plan' => 'nullable|string|max:10000',
            'backout_plan' => 'nullable|string|max:10000',
            'test_plan' => 'nullable|string|max:10000',
            'communication_plan' => 'nullable|string|max:10000',
            'outage_required' => 'nullable|boolean',
            'expected_downtime_minutes' => 'nullable|integer|min:0|max:100000',
            'affected_asset_ids' => 'nullable|array|max:50',
            'affected_asset_ids.*' => ['integer', Rule::exists('assets', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'affected_services' => 'nullable|array|max:20',
            'affected_services.*' => 'string|max:120',
            'source_type' => ['nullable', Rule::in(array_keys(ChangeRequest::SOURCES))],
            'source_id' => 'nullable|integer',
            'source_reference' => 'nullable|string|max:255',
            'standard_template_id' => ['nullable', $inOrg('change_standard_templates')],
            'change_owner_id' => ['nullable', $inOrg('users')],
            'implementer_id' => ['nullable', $inOrg('users')],
            'change_manager_id' => ['nullable', $inOrg('users')],
            'scheduled_start_at' => 'nullable|date',
            'scheduled_end_at' => 'nullable|date|after:scheduled_start_at',
            'freeze_override_reason' => 'nullable|string|max:2000',
            'submit' => 'nullable|boolean',
        ]);

        $type = $data['source_type'] ?? null;
        $model = $type ? (ChangeRequest::SOURCES[$type]['model'] ?? null) : null;
        if (! empty($data['source_id']) && (! $model || ! $model::find($data['source_id']))) {
            throw ValidationException::withMessages(['source_id' => 'The source record was not found in this bank.']);
        }
        if (($data['change_type'] ?? null) === 'standard' && $creating && empty($data['standard_template_id'])) {
            throw ValidationException::withMessages(['standard_template_id' => 'Pick the standard change template.']);
        }
        $data['outage_required'] = (bool) ($data['outage_required'] ?? false);
        if (isset($data['affected_asset_ids'])) {
            $data['affected_asset_ids'] = array_values(array_unique(array_map('intval', $data['affected_asset_ids'])));
        }
        if (isset($data['affected_services'])) {
            $data['affected_services'] = array_values(array_filter(array_map('trim', $data['affected_services'])));
        }

        return $data;
    }

    private function owners()
    {
        return User::where('organization_id', request()->user()->organization_id)->orderBy('name')->get(['id', 'name', 'job_title']);
    }

    private function assets()
    {
        return Asset::orderBy('name')->limit(500)->get(['id', 'name', 'asset_id_code', 'criticality', 'asset_type']);
    }

    /** Pickable source records per type, for the raise-change form. */
    private function sourceRecords(): array
    {
        return collect(ChangeRequest::SOURCES)->filter(fn ($s) => $s['model'])
            ->map(fn ($s) => $s['model']::query()->orderByDesc('id')->limit(300)->get(['id', $s['code'], 'title'])
                ->map(fn ($m) => ['id' => $m->id, 'code' => $m->{$s['code']}, 'title' => Str::limit((string) $m->title, 80)]))
            ->all();
    }
}
