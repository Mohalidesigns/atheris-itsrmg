<?php

namespace App\Notifications\Change;

use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeRequest;
use App\Notifications\BaseNotification;

/** Sent by changes:sweep to the current approvers and the change manager when a decision is overdue. */
class ChangeApprovalOverdueNotification extends BaseNotification
{
    protected string $type = 'changes.approval_overdue';

    protected string $module = 'changes';

    public function __construct(public ChangeRequest $change, public ChangeApproval $approval)
    {
        $this->actionUrl = route('changes.show', $change->id);
    }

    protected function getSubject(): string
    {
        return "Overdue approval: {$this->change->change_code} — {$this->approval->stage_name}";
    }

    protected function getMessage(): string
    {
        $hours = $this->approval->due_at ? (int) $this->approval->due_at->diffInHours(now()) : 0;

        return "{$this->change->change_code} \"{$this->change->title}\" has been waiting on {$this->approval->stage_name} for {$hours} h past its due time.";
    }
}
