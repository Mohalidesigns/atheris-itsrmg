<?php

namespace App\Http\Controllers\Change;

use App\Http\Controllers\Controller;
use App\Models\Change\ChangeRequest;
use App\Models\Change\ChangeTask;
use App\Services\Change\ChangeWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The implementation checklist on a change: pre-implementation, implementation, validation, back-out, communication. */
class ChangeTaskController extends Controller
{
    public function __construct(private ChangeWorkflow $workflow) {}

    public function store(Request $r, ChangeRequest $change)
    {
        abort_unless(ChangeRequestController::canWork($change, $r->user()), 403);
        if (in_array($change->status, ['closed', 'cancelled'], true)) {
            return back()->with('error', 'A '.$change->status.' change is locked.');
        }
        $data = $this->validated($r);
        $data['sequence'] = $data['sequence'] ?? ((int) $change->tasks()->max('sequence') + 1);
        $task = $change->tasks()->create($data + ['organization_id' => $change->organization_id, 'status' => 'pending']);
        $this->workflow->log($change, 'task', "Task added: {$task->title}".($task->assignee ? " ({$task->assignee->name})" : '').'.', $r->user(), ['task_id' => $task->id]);

        return back()->with('success', 'Task added.');
    }

    public function update(Request $r, ChangeRequest $change, ChangeTask $task)
    {
        abort_unless($task->change_request_id === $change->id, 404);
        abort_unless(ChangeRequestController::canWork($change, $r->user()) || $task->assignee_id === $r->user()->id, 403);
        $data = $this->validated($r, false);
        $before = $task->status;

        if (($data['status'] ?? $before) !== $before) {
            if (in_array($data['status'], ['done', 'skipped'], true)) {
                $data['completed_at'] = now();
                $data['completed_by_id'] = $r->user()->id;
            } else {
                $data['completed_at'] = null;
                $data['completed_by_id'] = null;
            }
        }
        $task->update($data);
        if ($task->status !== $before) {
            $this->workflow->log($change, 'task', "Task \"{$task->title}\" ".str_replace('_', ' ', $task->status).'.', $r->user(), ['task_id' => $task->id]);
        }

        return back()->with('success', 'Task updated.');
    }

    private function validated(Request $r, bool $creating = true): array
    {
        $orgId = $r->user()->organization_id;

        return $r->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(ChangeTask::TYPES)],
            'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'due_at' => 'nullable|date',
            'sequence' => 'nullable|integer|min:1|max:255',
            'status' => ['sometimes', Rule::in(ChangeTask::STATUSES)],
            'notes' => 'nullable|string|max:4000',
        ]);
    }
}
