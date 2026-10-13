<?php

namespace App\Models\Change;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stage instance on one change: a snapshot of the policy stage at routing time (ATH-CHG-001 §2.3). */
class ChangeApproval extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'decided_at' => 'datetime',
        'due_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'skipped', 'expired'];

    public function change(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ChangeApprovalStage::class, 'stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(ChangeCabBoard::class, 'board_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ChangeCabVote::class, 'approval_id');
    }

    public function isBoard(): bool
    {
        return $this->approver_type === 'board' && $this->board_id;
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_at && $this->due_at->lt(now());
    }
}
