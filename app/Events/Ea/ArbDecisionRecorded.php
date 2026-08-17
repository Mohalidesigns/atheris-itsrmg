<?php

namespace App\Events\Ea;

use App\Models\Ea\ArbSubmission;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §7.5: Ea\Events\ArbDecisionRecorded → Issues\Listeners\OpenConditionIssues
 *
 * Contract I-9. An approval carrying conditions is not a decision, it is a
 * to-do list; until the conditions become owned Issues with due dates nobody
 * tracks them.
 */
class ArbDecisionRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ArbSubmission $submission,
        public array $conditions = [],
    ) {
    }
}
