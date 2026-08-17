<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DrExercise extends Model
{
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
