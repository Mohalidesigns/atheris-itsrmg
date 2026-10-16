<?php

namespace App\Models\Capa;

use App\Models\Change\ChangeRequest;
use App\Models\Issue;
use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CAPA stage 4/5: one task of the corrective action plan, with an owner and a deadline. System
 * changes route through Change Management (change_request_id).
 */
class CapaAction extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['planned', 'in_progress', 'done', 'cancelled'];

    protected $table = 'capa_actions';

    protected $guarded = [];

    protected $casts = ['due_date' => 'date', 'completed_at' => 'datetime'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['planned', 'in_progress'], true);
    }

    /** Past its deadline: still open after the due date, or finished after it. */
    public function isOverdue(): bool
    {
        if (! $this->due_date || $this->status === 'cancelled') {
            return false;
        }
        $deadline = $this->due_date->copy()->endOfDay();

        return $this->status === 'done'
            ? ($this->completed_at && $this->completed_at->gt($deadline))
            : now()->gt($deadline);
    }
}
