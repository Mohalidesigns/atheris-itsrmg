<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** A budget line is in amber or red variance at a period end (ATH-FIN-001 §7, FIN-04.03). Carries no amounts or percentages. */
class BudgetVarianceNotification extends BaseNotification
{
    protected string $type = 'finance.budget_variance_amber';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $line, public string $costCentre, public string $period, public string $severity, public string $direction, string $url)
    {
        $this->actionUrl = $url;
        $this->type = 'finance.budget_variance_'.($severity === 'red' ? 'red' : 'amber');
    }

    protected function getSubject(): string
    {
        $word = $this->direction === 'under' ? 'underspend' : 'overspend';

        return "{$this->period}: {$this->severity} {$word} on {$this->line} ({$this->costCentre})";
    }

    protected function getMessage(): string
    {
        $word = $this->direction === 'under' ? 'under' : 'over';

        return "{$this->line} ({$this->costCentre}) is in {$this->severity} variance, {$word} budget, at the end of {$this->period}. Please add an explanation before the period is closed.";
    }
}
