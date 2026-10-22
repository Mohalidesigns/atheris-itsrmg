<?php

namespace App\Events\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** A finance record needs a decision: the approval started, or moved on to its next stage. */
class FinanceApprovalRequested
{
    use Dispatchable;

    public function __construct(
        public string $process,
        public Model $subject,
        public ?ApprovalRequest $request,
        public ?User $actor,
    ) {}
}
