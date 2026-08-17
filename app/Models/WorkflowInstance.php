<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowInstance extends Model
{
    protected $guarded = [];

    protected $casts = ['state_json' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function tasks()
    {
        return $this->hasMany(WorkflowTask::class, 'instance_id');
    }
}
