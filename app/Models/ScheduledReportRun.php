<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ScheduledReportRun extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['summary' => 'array', 'ran_at' => 'datetime'];
}
