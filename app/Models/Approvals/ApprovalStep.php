<?php

namespace App\Models\Approvals;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ApprovalStep extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime'];

    public function request()
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function decisions()
    {
        return $this->hasMany(ApprovalDecision::class, 'step_id')->orderBy('decided_at');
    }
}
