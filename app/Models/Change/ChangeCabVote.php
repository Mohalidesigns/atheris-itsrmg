<?php

namespace App\Models\Change;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member's vote on a board approval; re-voting updates the row. */
class ChangeCabVote extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['voted_at' => 'datetime'];

    public const VOTES = ['approve', 'reject', 'abstain', 'defer'];

    public function change(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ChangeApproval::class, 'approval_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
