<?php

namespace App\Notifications\Change;

use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeRequest;
use App\Notifications\BaseNotification;

/** Sent to the eligible approvers (board → voting members) when a stage becomes current. */
class ChangeApprovalRequestedNotification extends BaseNotification
{
    protected string $type = 'changes.approval_requested';

    protected string $module = 'changes';

    public function __construct(public ChangeRequest $change, public ChangeApproval $approval)
    {
        $this->actionUrl = route('changes.show', $change->id);
    }

    protected function getSubject(): string
    {
        return "Approval needed: {$this->change->change_code} — {$this->approval->stage_name}";
    }

    protected function getMessage(): string
    {
        $due = $this->approval->due_at?->copy()->timezone('Africa/Lagos')->format('j M Y H:i');

        return "{$this->change->change_code} \"{$this->change->title}\" ({$this->change->change_type}, {$this->change->risk_level} risk) is waiting on {$this->approval->stage_name}".($due ? ", due {$due}." : '.');
    }
}
