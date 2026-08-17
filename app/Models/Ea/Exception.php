<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Exception extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_exceptions';

    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'expires_at' => 'date'];

    public function standard()
    {
        return $this->belongsTo(Standard::class, 'standard_id');
    }

    public function principle()
    {
        return $this->belongsTo(Principle::class, 'principle_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function daysToExpiry(): ?int
    {
        if (! $this->expires_at) {
            return null;
        }

        return (int) round(now()->diffInDays($this->expires_at, false));
    }
}
