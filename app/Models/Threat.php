<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Threat extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'threat_id_code', 'name', 'description',
        'category', 'source', 'type', 'likelihood', 'capability',
        'intent', 'severity', 'is_active', 'countermeasures', 'tags', 'last_seen',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tags' => 'array',
            'last_seen' => 'date',
        ];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(ThreatAssessment::class);
    }

    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('threat_id_code');

        if ($last && preg_match('/THR-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'THR-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
