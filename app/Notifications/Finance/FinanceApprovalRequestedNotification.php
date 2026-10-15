<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;
use App\Services\Approvals\ApprovalProcesses;

/** A finance record is awaiting the recipient's approval (ATH-FIN-001 §7). */
class FinanceApprovalRequestedNotification extends BaseNotification
{
    protected string $type = 'finance.approval_requested';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $process, public string $subjectLabel, public ?string $stepName, public string $amountText, string $url)
    {
        $this->actionUrl = $url;
    }

    protected function getSubject(): string
    {
        return "Approval needed: {$this->subjectLabel}";
    }

    protected function getMessage(): string
    {
        $label = ApprovalProcesses::PROCESSES[$this->process]['label'] ?? $this->process;

        return "{$label} {$this->subjectLabel} ({$this->amountText}) is awaiting ".($this->stepName ?: 'your approval').'.';
    }
}
