<?php

namespace App\Events\Finance;

use App\Models\Finance\Contract;
use App\Models\Finance\Renewal;
use Illuminate\Foundation\Events\Dispatchable;

/** A contract renewal was executed (or the contract auto-rolled). P3 listens to raise the expense request (plan C52). */
class ContractRenewed
{
    use Dispatchable;

    public function __construct(
        public Renewal $renewal,
        public Contract $contract,
        public ?Contract $successor,
        public bool $auto = false,
    ) {}
}
