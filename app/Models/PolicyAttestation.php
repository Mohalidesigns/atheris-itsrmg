<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAttestation extends Model
{
    use BelongsToOrganization, HasFactory;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'policy_id', 'user_id', 'status',
        'acknowledged_at', 'due_date', 'reminder_sent_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'due_date' => 'date',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public const STATUSES = [
        'pending', 'acknowledged', 'declined', 'overdue',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
