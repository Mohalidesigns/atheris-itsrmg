<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** An approved renewal was never executed and the contract end date is near or past (plan M1). Carries no amounts. */
class ContractApprovedNotExecutedNotification extends BaseNotification
{
    protected string $type = 'finance.contract_approved_not_executed';

    protected string $module = 'IT Financial Management';

    /** @param int $daysLeft days to the end date; negative once it has passed */
    public function __construct(public string $contractLabel, public string $vendor, public string $renewalCode, public string $date, public int $daysLeft, string $url)
    {
        $this->actionUrl = $url;
    }

    protected function getSubject(): string
    {
        return $this->daysLeft < 0
            ? "{$this->contractLabel}: approved renewal {$this->renewalCode} lapsed unexecuted"
            : "{$this->contractLabel}: approved renewal {$this->renewalCode} still to execute";
    }

    protected function getMessage(): string
    {
        $when = match (true) {
            $this->daysLeft < 0 => 'ended on '.$this->date.', '.abs($this->daysLeft).' day(s) ago',
            $this->daysLeft === 0 => 'ends today ('.$this->date.')',
            default => "ends on {$this->date} ({$this->daysLeft} day(s) left)",
        };

        return "Renewal {$this->renewalCode} for {$this->contractLabel} ({$this->vendor}) is approved but has not been executed, and the contract {$when}. Execute it to create the next term.";
    }
}
