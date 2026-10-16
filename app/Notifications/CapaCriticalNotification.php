<?php

namespace App\Notifications;

use App\Models\Issue;

/** IT-GOV-CAPA-001 §6: a critical CAPA is notified to the CIO, CISO and Risk Committee within 2 hours. */
class CapaCriticalNotification extends BaseNotification
{
    protected string $type = 'issues.capa_critical';

    protected string $module = 'issues';

    public function __construct(public Issue $issue, public array $notified)
    {
        $this->actionUrl = route('issues.show', $issue->id);
    }

    protected function getSubject(): string
    {
        return "Critical CAPA raised: {$this->issue->issue_code}";
    }

    protected function getMessage(): string
    {
        return "{$this->issue->issue_code} \"{$this->issue->title}\" is a critical CAPA (".round($this->issue->sla_minutes / 60).'-hour remediation window). Notified: '.implode(', ', $this->notified).'. Daily status calls apply until it is remediated.';
    }
}
