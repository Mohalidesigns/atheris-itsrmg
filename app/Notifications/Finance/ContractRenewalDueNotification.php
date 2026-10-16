<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** T-minus reminder before a contract's end date or notice deadline (ATH-FIN-001 §7). Carries no amounts. */
class ContractRenewalDueNotification extends BaseNotification
{
    protected string $type = 'finance.contract_renewal_due';

    protected string $module = 'IT Financial Management';

    /** @param string $series 'end' or 'notice' */
    public function __construct(public string $contractLabel, public string $vendor, public string $series, public int $offset, public int $daysLeft, public string $date, string $url)
    {
        $this->actionUrl = $url;
    }

    protected function getSubject(): string
    {
        $what = $this->series === 'notice' ? 'notice deadline' : 'ends';
        $when = $this->daysLeft <= 0 ? 'today' : ($this->daysLeft === 1 ? 'in 1 day' : "in {$this->daysLeft} days");

        return $this->series === 'notice' ? "{$this->contractLabel}: notice deadline {$when}" : "{$this->contractLabel}: {$what} {$when}";
    }

    protected function getMessage(): string
    {
        return "{$this->contractLabel} ({$this->vendor}): record a renewal decision before {$this->date}.";
    }
}
