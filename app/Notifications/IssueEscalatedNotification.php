<?php

namespace App\Notifications;

use App\Models\Issue;

/** Sent to the people at an issue's new escalation level (owner → CISO → CRO by default). */
class IssueEscalatedNotification extends BaseNotification
{
    protected string $type = 'issues.escalated';

    protected string $module = 'issues';

    public function __construct(public Issue $issue, public int $level, public string $step, public string $reason)
    {
        $this->actionUrl = route('issues.show', $issue->id);
    }

    protected function getSubject(): string
    {
        return "Escalated to you: {$this->issue->issue_code} ({$this->issue->severity})";
    }

    protected function getMessage(): string
    {
        return "{$this->issue->issue_code} \"{$this->issue->title}\" has been escalated to level {$this->level} ({$this->step}). {$this->reason}";
    }
}
