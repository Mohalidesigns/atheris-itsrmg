<?php

namespace App\Models\Change;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a change's timeline; reached only through its (tenant-scoped) change. */
class ChangeEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    public const TYPES = ['created', 'updated', 'submitted', 'routed', 'approval', 'cab_vote', 'cab_override', 'status', 'scheduled', 'conflict', 'freeze_override', 'task', 'comment', 'pir', 'assigned', 'notified'];

    public function change(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
