<?php

namespace App\Notifications\Change;

use App\Models\Change\ChangeRequest;
use App\Notifications\BaseNotification;

/** Sent to the implementer, owner and CAB chair when a change is scheduled or rescheduled. */
class ChangeScheduledNotification extends BaseNotification
{
    protected string $type = 'changes.scheduled';

    protected string $module = 'changes';

    public function __construct(public ChangeRequest $change, public bool $rescheduled = false)
    {
        $this->actionUrl = route('changes.show', $change->id);
    }

    protected function getSubject(): string
    {
        return "{$this->change->change_code} ".($this->rescheduled ? 'rescheduled' : 'scheduled');
    }

    protected function getMessage(): string
    {
        $start = $this->change->scheduled_start_at?->copy()->timezone('Africa/Lagos')->format('j M Y H:i');
        $end = $this->change->scheduled_end_at?->copy()->timezone('Africa/Lagos')->format('j M Y H:i');

        return "{$this->change->change_code} \"{$this->change->title}\" is ".($this->rescheduled ? 'now ' : '')."scheduled for {$start}".($end ? " to {$end}" : '').'.';
    }
}
