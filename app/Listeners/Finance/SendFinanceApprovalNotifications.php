<?php

namespace App\Listeners\Finance;

use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Services\Finance\FinanceApprovalNotifier;

/** Auto-discovered (plan C35): do not register in AppServiceProvider. */
class SendFinanceApprovalNotifications
{
    public function __construct(private readonly FinanceApprovalNotifier $notifier) {}

    public function handleRequested(FinanceApprovalRequested $event): void
    {
        $this->notifier->requested($event);
    }

    public function handleDecided(FinanceApprovalDecided $event): void
    {
        $this->notifier->decided($event);
    }
}
