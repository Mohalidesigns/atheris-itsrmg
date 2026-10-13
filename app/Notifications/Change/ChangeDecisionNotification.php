<?php

namespace App\Notifications\Change;

use App\Models\Change\ChangeRequest;
use App\Notifications\BaseNotification;

/** Sent to the requester, owner and implementer when a stage is cleared or the change is approved / rejected. */
class ChangeDecisionNotification extends BaseNotification
{
    protected string $type = 'changes.decision';

    protected string $module = 'changes';

    public function __construct(public ChangeRequest $change, public string $decision, public string $stageName, public ?string $note = null)
    {
        $this->actionUrl = route('changes.show', $change->id);
    }

    protected function getSubject(): string
    {
        return "{$this->change->change_code}: ".ucfirst(str_replace('_', ' ', $this->decision));
    }

    protected function getMessage(): string
    {
        $what = match ($this->decision) {
            'approved' => 'has been approved and may be scheduled',
            'rejected' => 'was rejected',
            default => "cleared the {$this->stageName} stage",
        };

        return "{$this->change->change_code} \"{$this->change->title}\" {$what}.".($this->note ? " Note: {$this->note}" : '');
    }
}
