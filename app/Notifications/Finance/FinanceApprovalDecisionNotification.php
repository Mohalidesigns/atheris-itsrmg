<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;
use App\Services\Approvals\ApprovalProcesses;

/** A finance approval ended: approved, returned, rejected or withdrawn (ATH-FIN-001 §7). */
class FinanceApprovalDecisionNotification extends BaseNotification
{
    protected string $type = 'finance.approval_decided';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $process, public string $subjectLabel, public string $outcome, public string $actorName, public ?string $note, string $url)
    {
        $this->actionUrl = $url;
    }

    protected function getSubject(): string
    {
        return "{$this->subjectLabel} {$this->outcome}";
    }

    protected function getMessage(): string
    {
        $label = ApprovalProcesses::PROCESSES[$this->process]['label'] ?? $this->process;

        return "{$label} {$this->subjectLabel} was {$this->outcome} by {$this->actorName}".($this->note ? ": {$this->note}" : '').'.';
    }
}
