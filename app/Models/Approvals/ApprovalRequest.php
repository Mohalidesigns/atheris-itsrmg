<?php

namespace App\Models\Approvals;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** One run of an approval process for one record: its steps (snapshotted stages) and decisions. */
class ApprovalRequest extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['completed_at' => 'datetime'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'withdrawn'];

    public function steps()
    {
        return $this->hasMany(ApprovalStep::class, 'request_id')->orderBy('sequence')->orderBy('id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function currentStep(): ?ApprovalStep
    {
        return $this->steps->firstWhere('status', 'pending');
    }
}
