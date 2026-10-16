<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** No renewal decision a week before the notice deadline (ATH-FIN-001 §5.4). Carries no amounts. */
class ContractRenewalEscalationNotification extends BaseNotification
{
    protected string $type = 'finance.contract_renewal_escalation';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $contractLabel, public string $vendor, public string $noticeDate, public int $daysLeft, public ?string $ownerName, string $url)
    {
        $this->actionUrl = $url;
    }

    protected function getSubject(): string
    {
        return "Escalation: no renewal decision for {$this->contractLabel}";
    }

    protected function getMessage(): string
    {
        return "Notice deadline {$this->noticeDate} ({$this->daysLeft} days) for {$this->contractLabel} ({$this->vendor}); "
            .($this->ownerName ? "owner {$this->ownerName}" : 'the contract has no owner and nobody').' has not submitted a recommendation.';
    }
}
