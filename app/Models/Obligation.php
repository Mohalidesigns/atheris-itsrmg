<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Obligation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['applicability' => 'array', 'effective_date' => 'date'];

    /** Next submission/review date: the effective date rolled forward by the review cycle until it is today or later. */
    public function nextDue(): Carbon
    {
        $cycle = max(1, (int) ($this->review_cycle_days ?: 365));
        $next = $this->effective_date ? $this->effective_date->copy()->addDays($cycle) : today()->addDays(30);
        while ($next->lt(today())) {
            $next->addDays($cycle);
        }

        return $next;
    }
}
