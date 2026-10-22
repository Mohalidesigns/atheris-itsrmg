<?php

namespace App\Events\Finance;

use App\Models\Finance\ExpenseRequest;
use Illuminate\Foundation\Events\Dispatchable;

/** An expense request was submitted unbudgeted, over budget or as an emergency (FIN-03.08). Reason: unbudgeted, over_budget or emergency. */
class ExpenseFlagged
{
    use Dispatchable;

    public function __construct(public ExpenseRequest $expense, public string $reason) {}
}
