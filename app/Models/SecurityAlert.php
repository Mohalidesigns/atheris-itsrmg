<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityAlert extends Model
{
    use BelongsToOrganization, HasFactory;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'alert_id_code', 'title', 'description',
        'source', 'severity', 'status', 'incident_id', 'raw_data',
        'received_at', 'acknowledged_at', 'acknowledged_by',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'received_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    // Relationships
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    public const STATUSES = ['new', 'acknowledged', 'investigating', 'resolved', 'dismissed'];

    public static function generateNextCode(int $organizationId): string
    {
        $count = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->count();

        do {
            $code = 'ALR-'.str_pad(++$count, 4, '0', STR_PAD_LEFT);
        } while (static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('alert_id_code', $code)
            ->exists());

        return $code;
    }
}
