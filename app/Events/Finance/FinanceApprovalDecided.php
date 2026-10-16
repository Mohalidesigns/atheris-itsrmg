<?php

namespace App\Events\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** A finance approval ended: outcome is approved, returned, rejected or withdrawn. */
class FinanceApprovalDecided
{
    use Dispatchable;

    public function __construct(
        public string $process,
        public Model $subject,
        public string $outcome,
        public User $actor,
        public ?string $note = null,
    ) {}
}
