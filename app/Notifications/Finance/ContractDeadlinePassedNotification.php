<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** A contract's notice deadline or end date passed with no decision (ATH-FIN-001 §7). Carries no amounts. */
class ContractDeadlinePassedNotification extends BaseNotification
{
    protected string $type = 'finance.contract_deadline_passed';

    protected string $module = 'IT Financial Management';

    /** @param string $which 'notice' or 'end' */
    public function __construct(public string $contractLabel, public string $vendor, public string $which, public string $date, public int $daysOverdue, public bool $autoRenew, string $url)
    {
        $this->actionUrl = $url;
    }

    private function whichLabel(): string
    {
        return $this->which === 'notice' ? 'notice deadline' : 'end date';
    }

    protected function getSubject(): string
    {
        return "{$this->contractLabel}: {$this->whichLabel()} passed with no decision";
    }

    protected function getMessage(): string
    {
        return "The {$this->whichLabel()} of {$this->contractLabel} ({$this->vendor}) was {$this->date}, {$this->daysOverdue} day(s) ago, with no renewal decision recorded."
            .($this->autoRenew ? ' It will auto-renew under its contract terms.' : '');
    }
}
