<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class DataBreach extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'incident_id', 'breach_id_code', 'title',
        'description', 'breach_type', 'data_types_affected', 'records_affected',
        'status', 'ndpa_notification_required', 'ndpa_notified_at',
        'ndpa_notification_deadline', 'regulatory_body_notified',
        'individuals_notified', 'individuals_notified_at', 'root_cause',
        'remedial_actions', 'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'data_types_affected' => 'array',
            'ndpa_notification_required' => 'boolean',
            'regulatory_body_notified' => 'boolean',
            'individuals_notified' => 'boolean',
            'ndpa_notified_at' => 'datetime',
            'ndpa_notification_deadline' => 'datetime',
            'individuals_notified_at' => 'datetime',
        ];
    }

    // Relationships
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // Helpers
    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('breach_id_code');

        if ($last && preg_match('/BRH-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'BRH-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
