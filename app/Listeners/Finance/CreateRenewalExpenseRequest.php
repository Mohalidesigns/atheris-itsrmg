<?php

namespace App\Listeners\Finance;

use App\Events\Finance\ContractRenewed;
use App\Services\Finance\ExpenseService;
use Throwable;

/** Auto-discovered (plan P3-16, C52): an executed or auto-rolled renewal raises one draft expense request. Never breaks the renewal. */
class CreateRenewalExpenseRequest
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function handle(ContractRenewed $e): void
    {
        if (! $e->successor) {
            return;
        }
        try {
            $this->expenses->createFromRenewal($e->renewal, $e->successor);
        } catch (Throwable $ex) {
            report($ex);
        }
    }
}
