<?php

namespace App\Listeners\Finance;

use App\Events\Finance\ContractRenewed;
use App\Notifications\Finance\ContractRenewedNotification;
use App\Services\Finance\ContractService;
use App\Services\Finance\ContractSweeper;
use Illuminate\Support\Facades\Notification;

/** Auto-discovered (plan C35): tells the contract's people and Finance that a contract renewed. Idempotent per contract. */
class SendContractRenewedNotification
{
    public function __construct(private readonly ContractService $contracts, private readonly ContractSweeper $sweeper) {}

    public function handle(ContractRenewed $e): void
    {
        $c = $e->contract;
        $users = $this->contracts->recipients($c)->merge($this->contracts->financeUsers((int) $c->organization_id))->unique('id')->values();
        $users = $this->sweeper->claim($c, 'contract_renewed', 0, $users);
        if ($users->isEmpty()) {
            return;
        }
        $outcome = $e->auto ? 'auto_renewed' : ($e->renewal->recommendation === 'replace' ? 'replaced' : 'renewed');
        $target = $e->successor ?? $c;
        Notification::send($users, new ContractRenewedNotification(
            $c->label, $e->successor?->label, $outcome, $e->successor?->ends_on?->format('d M Y'), route('finance.contracts.show', $target->id)));
    }
}
