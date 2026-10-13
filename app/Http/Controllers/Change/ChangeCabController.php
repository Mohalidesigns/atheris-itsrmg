<?php

namespace App\Http\Controllers\Change;

use App\Http\Controllers\Controller;
use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeCabBoard;
use App\Models\Change\ChangeCabMeeting;
use App\Models\Change\ChangeCabMeetingItem;
use App\Models\Change\ChangeCabMember;
use App\Models\Change\ChangeRequest;
use App\Models\User;
use App\Services\Change\ChangeApprovalRouter;
use App\Services\Change\ChangePolicySeeder;
use App\Services\Change\ChangeWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** CAB & ECAB: boards, seats, meetings and agenda decisions (ATH-CHG-001 §2.4, §7). */
class ChangeCabController extends Controller
{
    public function __construct(private ChangeWorkflow $workflow, private ChangeApprovalRouter $router) {}

    public function index(Request $r)
    {
        $orgId = $r->user()->organization_id;
        ChangePolicySeeder::ensureBoards($orgId);

        $pendingByBoard = $this->agendaCandidates()->groupBy('board_id')->map->count();

        $boards = ChangeCabBoard::with(['chair:id,name,job_title', 'members.user:id,name,job_title'])->orderBy('type')->orderBy('id')->get()
            ->map(fn (ChangeCabBoard $b) => [
                'id' => $b->id, 'name' => $b->name, 'type' => $b->type, 'quorum' => $b->quorum, 'meeting_cadence' => $b->meeting_cadence,
                'description' => $b->description, 'is_active' => $b->is_active, 'chair' => $b->chair,
                'members' => $b->members->map(fn ($m) => ['id' => $m->id, 'user' => $m->user, 'role' => $m->role, 'represents' => $m->represents, 'is_voting' => $m->is_voting]),
                'pending_count' => (int) ($pendingByBoard[$b->id] ?? 0),
            ]);

        $meetings = ChangeCabMeeting::with('board:id,name,type')->withCount('items')
            ->where(fn ($q) => $q->where('scheduled_at', '>=', now()->subDays(60))->orWhere('status', 'scheduled'))
            ->orderByRaw("case status when 'scheduled' then 0 else 1 end")->orderByDesc('scheduled_at')->limit(30)->get()
            ->map(fn (ChangeCabMeeting $m) => [
                'id' => $m->id, 'title' => $m->title, 'scheduled_at' => $m->scheduled_at?->toIso8601String(), 'status' => $m->status,
                'board' => $m->board, 'items_count' => $m->items_count,
            ]);

        return Inertia::render('Changes/Cab/Index', [
            'boards' => $boards,
            'meetings' => $meetings,
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'job_title']),
            'agendaCandidates' => $this->agendaCandidates()->map(fn (ChangeApproval $a) => $this->candidate($a))->values(),
            'represents' => ChangeCabMember::REPRESENTS,
            'canManage' => $r->user()->can('approve changes'),
        ]);
    }

    public function boardsStore(Request $r)
    {
        $data = $this->validatedBoard($r);
        $board = ChangeCabBoard::create($data + ['organization_id' => $r->user()->organization_id]);
        if ($board->chair_id) {
            $board->members()->firstOrCreate(['user_id' => $board->chair_id], ['role' => 'chair', 'represents' => 'IT Operations', 'is_voting' => true]);
        }

        return back()->with('success', "{$board->name} created.");
    }

    public function boardsUpdate(Request $r, ChangeCabBoard $board)
    {
        $board->update($this->validatedBoard($r));
        if ($board->chair_id) {
            $board->members()->where('role', 'chair')->where('user_id', '!=', $board->chair_id)->update(['role' => 'member']);
            $member = $board->members()->firstOrCreate(['user_id' => $board->chair_id], ['represents' => 'IT Operations', 'is_voting' => true]);
            $member->update(['role' => 'chair']);
        }

        return back()->with('success', "{$board->name} saved.");
    }

    public function membersStore(Request $r, ChangeCabBoard $board)
    {
        $data = $r->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $r->user()->organization_id)],
            'role' => ['required', Rule::in(ChangeCabMember::ROLES)],
            'represents' => ['nullable', 'string', 'max:64'],
            'is_voting' => 'nullable|boolean',
        ]);
        $data['is_voting'] = (bool) ($data['is_voting'] ?? true);
        $member = $board->members()->updateOrCreate(['user_id' => $data['user_id']], $data);
        if ($member->role === 'chair') {
            $board->members()->where('id', '!=', $member->id)->where('role', 'chair')->update(['role' => 'member']);
            $board->update(['chair_id' => $member->user_id]);
        }

        return back()->with('success', 'Member added to '.$board->name.'.');
    }

    public function membersDestroy(Request $r, ChangeCabMember $member)
    {
        $board = $member->board;
        abort_unless($board, 404);
        if ($board->chair_id === $member->user_id) {
            $board->update(['chair_id' => null]);
        }
        $member->delete();

        return back()->with('success', 'Member removed.');
    }

    public function meetingsStore(Request $r)
    {
        $data = $r->validate([
            'board_id' => ['required', Rule::exists('change_cab_boards', 'id')->where('organization_id', $r->user()->organization_id)],
            'title' => 'required|string|max:255',
            'scheduled_at' => 'required|date',
            'chaired_by_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $r->user()->organization_id)],
            'change_ids' => 'nullable|array|max:40',
            'change_ids.*' => 'integer',
        ]);
        $board = ChangeCabBoard::findOrFail($data['board_id']);
        $meeting = ChangeCabMeeting::create([
            'organization_id' => $r->user()->organization_id, 'board_id' => $board->id, 'title' => $data['title'],
            'scheduled_at' => $data['scheduled_at'], 'status' => 'scheduled', 'chaired_by_id' => $data['chaired_by_id'] ?? $board->chair_id,
        ]);
        $this->addItems($meeting, $data['change_ids'] ?? [], $r->user());

        return redirect()->route('changes.cab.meetings.show', $meeting)->with('success', "{$meeting->title} scheduled.");
    }

    public function meetingsShow(Request $r, ChangeCabMeeting $meeting)
    {
        $meeting->load(['board:id,name,type,quorum,chair_id', 'chairedBy:id,name,job_title',
            'items.change' => fn ($q) => $q->with('requester:id,name')]);

        $items = $meeting->items->map(function (ChangeCabMeetingItem $item) {
            $c = $item->change;
            $current = $c ? $this->router->currentApproval($c) : null;

            return [
                'id' => $item->id, 'sequence' => $item->sequence, 'decision' => $item->decision, 'notes' => $item->notes,
                'change' => $c ? [
                    'id' => $c->id, 'change_code' => $c->change_code, 'title' => $c->title, 'change_type' => $c->change_type,
                    'risk_level' => $c->risk_level, 'status' => $c->status, 'requester' => $c->requester?->name,
                    'scheduled_start_at' => $c->scheduled_start_at?->toIso8601String(),
                    'votes_tally' => $current && $current->approver_type === 'board' ? $this->router->evaluateBoard($current) : null,
                    'current_stage' => $current?->stage_name,
                ] : null,
            ];
        });

        return Inertia::render('Changes/Cab/Meeting', [
            'meeting' => [
                'id' => $meeting->id, 'title' => $meeting->title, 'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
                'status' => $meeting->status, 'minutes' => $meeting->minutes, 'board' => $meeting->board, 'chaired_by' => $meeting->chairedBy,
                'items' => $items,
            ],
            'candidates' => $this->agendaCandidates()->where('board_id', $meeting->board_id)
                ->reject(fn (ChangeApproval $a) => $meeting->items->contains('change_request_id', $a->change_request_id))
                ->map(fn (ChangeApproval $a) => $this->candidate($a))->values(),
            'canManage' => $r->user()->can('approve changes'),
            'decisions' => ChangeCabMeetingItem::DECISIONS,
        ]);
    }

    public function meetingsUpdate(Request $r, ChangeCabMeeting $meeting)
    {
        $data = $r->validate([
            'title' => 'sometimes|string|max:255',
            'scheduled_at' => 'sometimes|date',
            'status' => ['sometimes', Rule::in(ChangeCabMeeting::STATUSES)],
            'minutes' => 'nullable|string|max:20000',
            'chaired_by_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $r->user()->organization_id)],
        ]);
        $meeting->update($data);

        return back()->with('success', 'Meeting saved.');
    }

    public function itemsStore(Request $r, ChangeCabMeeting $meeting)
    {
        $data = $r->validate(['change_ids' => 'required|array|min:1|max:40', 'change_ids.*' => 'integer']);
        $added = $this->addItems($meeting, $data['change_ids'], $r->user());

        return back()->with('success', $added.' change(s) added to the agenda.');
    }

    public function itemsUpdate(Request $r, ChangeCabMeetingItem $item)
    {
        $meeting = $item->meeting;
        abort_unless($meeting, 404);
        $data = $r->validate([
            'decision' => ['sometimes', Rule::in(ChangeCabMeetingItem::DECISIONS)],
            'notes' => 'nullable|string|max:4000',
            'sequence' => 'nullable|integer|min:1|max:255',
        ]);
        $before = $item->decision;
        $item->update($data);

        $message = 'Agenda item saved.';
        if (($data['decision'] ?? $before) !== $before && in_array($item->decision, ['approved', 'rejected'], true)) {
            $applied = $this->workflow->applyMeetingDecision($item->fresh(), $r->user());
            $message = $applied ?? 'Decision minuted; the change has no open board stage for this board, so nothing was applied to its approvals.';
        }

        return back()->with('success', $message);
    }

    /* -------------------------------- helpers -------------------------------- */

    private function validatedBoard(Request $r): array
    {
        return $r->validate([
            'name' => 'required|string|max:120',
            'type' => ['required', Rule::in(ChangeCabBoard::TYPES)],
            'chair_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $r->user()->organization_id)],
            'quorum' => 'required|integer|min:1|max:20',
            'meeting_cadence' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);
    }

    /** Pending board approvals that are the current stage of a change in approval. */
    private function agendaCandidates()
    {
        return ChangeApproval::where('status', 'pending')->where('approver_type', 'board')
            ->whereHas('change', fn ($q) => $q->where(fn ($o) => $o->where('status', 'in_approval')->orWhere(fn ($w) => $w->where('started_before_approval', true)->whereNull('approved_at'))))
            ->with(['change' => fn ($q) => $q->with('requester:id,name')])
            ->orderBy('due_at')->get()
            ->filter(fn (ChangeApproval $a) => $this->router->isCurrent($a, $a->change))->values();
    }

    private function candidate(ChangeApproval $a): array
    {
        $c = $a->change;

        return [
            'approval_id' => $a->id, 'board_id' => $a->board_id, 'id' => $c->id, 'change_code' => $c->change_code, 'title' => $c->title,
            'change_type' => $c->change_type, 'risk_level' => $c->risk_level, 'status' => $c->status, 'requester' => $c->requester?->name,
            'scheduled_start_at' => $c->scheduled_start_at?->toIso8601String(), 'due_at' => $a->due_at?->toIso8601String(),
            'votes_tally' => $this->router->evaluateBoard($a),
        ];
    }

    private function addItems(ChangeCabMeeting $meeting, array $changeIds, User $actor): int
    {
        $added = 0;
        $next = (int) $meeting->items()->max('sequence');
        foreach (ChangeRequest::whereIn('id', $changeIds)->orderBy('scheduled_start_at')->get() as $change) {
            $item = $meeting->items()->firstOrCreate(['change_request_id' => $change->id], ['sequence' => ++$next, 'decision' => 'pending']);
            if ($item->wasRecentlyCreated) {
                $added++;
                $this->workflow->log($change, 'updated', "Added to the agenda of {$meeting->title} (".$meeting->scheduled_at?->format('j M Y').').', $actor, ['meeting_id' => $meeting->id]);
            }
        }

        return $added;
    }
}
