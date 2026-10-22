<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** An expense request was submitted unbudgeted, over budget or as an emergency (ATH-FIN-001 §7, FIN-03.08). Carries no amounts. */
class UnbudgetedSpendNotification extends BaseNotification
{
    protected string $type = 'finance.unbudgeted_spend';

    protected string $module = 'IT Financial Management';

    /** @param string $reason unbudgeted, over_budget or emergency */
    public function __construct(public string $code, public string $title, public string $reason, public string $costCentre, public string $requester, string $url)
    {
        $this->actionUrl = $url;
    }

    private function reasonLabel(): string
    {
        return match ($this->reason) {
            'over_budget' => 'over budget',
            'emergency' => 'an emergency',
            default => 'unbudgeted',
        };
    }

    protected function getSubject(): string
    {
        return "{$this->code}: {$this->reasonLabel()} expense request submitted";
    }

    protected function getMessage(): string
    {
        return "{$this->requester} submitted {$this->code} ({$this->title}) for {$this->costCentre} as {$this->reasonLabel()} spend. It needs a higher approval and a recorded justification.";
    }
}
