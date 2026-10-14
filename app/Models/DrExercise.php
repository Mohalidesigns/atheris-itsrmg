<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** One run of a DR runbook, measured against its RTO; strictly per bank. */
class DrExercise extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = [
        'participants' => 'array',
        'evidence_ids' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function runbook()
    {
        return $this->belongsTo(DrRunbook::class, 'runbook_id');
    }
}
