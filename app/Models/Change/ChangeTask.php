<?php

namespace App\Models\Change;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A checklist item on a change: pre-implementation, implementation, validation, back-out or communication. */
class ChangeTask extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'sequence' => 'integer',
    ];

    public const TYPES = ['pre_implementation', 'implementation', 'validation', 'backout', 'communication'];

    public const STATUSES = ['pending', 'in_progress', 'done', 'skipped'];

    public function change(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }
}
