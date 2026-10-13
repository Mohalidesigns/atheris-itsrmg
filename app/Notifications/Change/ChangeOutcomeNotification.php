<?php

namespace App\Notifications\Change;

use App\Models\Change\ChangeRequest;
use App\Notifications\BaseNotification;

/** Sent to the requester, owner, change manager and CAB chair on implemented / backed_out / failed / PIR closed. */
class ChangeOutcomeNotification extends BaseNotification
{
    protected string $type = 'changes.outcome';

    protected string $module = 'changes';

    public function __construct(public ChangeRequest $change, public string $outcome, public ?string $note = null)
    {
        $this->actionUrl = route('changes.show', $change->id);
    }

    protected function getSubject(): string
    {
        return "{$this->change->change_code}: ".ucfirst(str_replace('_', ' ', $this->outcome));
    }

    protected function getMessage(): string
    {
        $what = match ($this->outcome) {
            'implemented' => 'was implemented',
            'backed_out' => 'was backed out',
            'failed' => 'failed during implementation',
            'closed' => 'has completed its post-implementation review and is closed',
            default => "is {$this->outcome}",
        };

        return "{$this->change->change_code} \"{$this->change->title}\" {$what}.".($this->note ? " {$this->note}" : '');
    }
}
