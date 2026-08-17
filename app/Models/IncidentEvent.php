<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id', 'event_type', 'description', 'performed_by',
        'metadata', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    // Relationships
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
