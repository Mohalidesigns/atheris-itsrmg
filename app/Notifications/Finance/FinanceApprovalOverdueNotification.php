<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;
use App\Services\Approvals\ApprovalProcesses;

/** Sent by fin:approvals-sweep when a finance approval step is past its due time (ATH-FIN-001 §7). */
class FinanceApprovalOverdueNotification extends BaseNotification
{
    protected string $type = 'finance.approval_overdue';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $process, public string $subjectLabel, public string $stepName, public string $dueAt, string $url)
    {
        $this->actionUrl = $url;
    }

    protected function getSubject(): string
    {
        return "Overdue approval: {$this->subjectLabel} — {$this->stepName}";
    }

    protected function getMessage(): string
    {
        $label = ApprovalProcesses::PROCESSES[$this->process]['label'] ?? $this->process;

        return "{$label} {$this->subjectLabel} has been waiting on {$this->stepName} since {$this->dueAt}.";
    }
}
