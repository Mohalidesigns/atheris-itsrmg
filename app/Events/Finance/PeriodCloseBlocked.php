<?php

namespace App\Events\Finance;

use Illuminate\Foundation\Events\Dispatchable;

/** A period close was refused by the checklist; dispatched before the refusal is thrown, outside the transaction. */
class PeriodCloseBlocked
{
    use Dispatchable;

    /** @param list<string> $blockers */
    public function __construct(public int $periodId, public array $blockers, public int $byUserId) {}
}
