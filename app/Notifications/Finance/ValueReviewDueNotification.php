<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** A post-implementation value review is coming due or is overdue (ATH-FIN-001 §7, FIN-05.07). Carries no amounts or percentages. */
class ValueReviewDueNotification extends BaseNotification
{
    public const KINDS = ['due', 'overdue'];

    protected string $type = 'finance.value_review_due';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $investment, public string $reviewType, public string $dueOn, public string $kind, public int $daysOverdue, string $url)
    {
        $this->actionUrl = $url;
        $this->type = 'finance.value_review_'.($kind === 'overdue' ? 'overdue' : 'due');
    }

    protected function getSubject(): string
    {
        return $this->kind === 'overdue'
            ? "{$this->investment}: {$this->reviewType} is overdue by {$this->daysOverdue} day(s)"
            : "{$this->investment}: {$this->reviewType} is due on {$this->dueOn}";
    }

    protected function getMessage(): string
    {
        return $this->kind === 'overdue'
            ? "The {$this->reviewType} of {$this->investment} was due on {$this->dueOn} and is {$this->daysOverdue} day(s) overdue. Record the benefits and complete the review."
            : "The {$this->reviewType} of {$this->investment} is due on {$this->dueOn}. Record the benefit readings and complete the review.";
    }
}
