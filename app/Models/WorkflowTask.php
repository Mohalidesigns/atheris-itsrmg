<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTask extends Model
{
    protected $guarded = [];

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime'];
}
