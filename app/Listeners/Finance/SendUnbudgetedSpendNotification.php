<?php

namespace App\Listeners\Finance;

use App\Events\Finance\ExpenseFlagged;
use App\Notifications\Finance\UnbudgetedSpendNotification;
use App\Services\Finance\ContractService;
use App\Services\Finance\ContractSweeper;
use App\Services\Finance\FinanceApprovalNotifier;
use Illuminate\Support\Facades\Notification;

/** Auto-discovered: Finance and the Head of IT hear once about each flagged request and reason (FIN-03.08). */
class SendUnbudgetedSpendNotification
{
    public function __construct(private readonly ContractService $contracts, private readonly ContractSweeper $sweeper, private readonly FinanceApprovalNotifier $notifier) {}

    public function handle(ExpenseFlagged $e): void
    {
        $x = $e->expense;
        $orgId = (int) $x->organization_id;
        $users = $this->contracts->financeUsers($orgId)->merge($this->notifier->escalationUsers($orgId))
            ->filter(fn ($u) => $u->is_active && (int) $u->id !== (int) $x->requested_by_id)->unique('id')->values();
        $users = $this->sweeper->claim($x, 'expense_flagged:'.$e->reason, 0, $users);
        if ($users->isEmpty()) {
            return;
        }
        Notification::send($users, new UnbudgetedSpendNotification(
            (string) $x->request_code, (string) $x->title, $e->reason, (string) ($x->costCentre?->code ?? 'no cost centre'),
            (string) ($x->requestedBy?->name ?? 'A requester'), route('finance.expenses.show', $x->id)));
    }
}
