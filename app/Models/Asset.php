<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Asset extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity, SoftDeletes;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'asset_id_code', 'name', 'description',
        'asset_type', 'category', 'criticality', 'status',
        'owner_id', 'department', 'location', 'ip_address',
        'hostname', 'vendor', 'version', 'license_type',
        'data_classification', 'purchase_date', 'end_of_life', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'purchase_date' => 'date',
            'end_of_life' => 'date',
        ];
    }

    public const ASSET_TYPES = [
        'hardware', 'software', 'cloud_service', 'database', 'network', 'facility',
    ];

    public const CRITICALITIES = ['critical', 'high', 'medium', 'low'];

    public const STATUSES = ['active', 'inactive', 'decommissioned', 'under_review'];

    public const DATA_CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'];

    // Relationships
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Risks affecting this asset.
     */
    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'asset_risk')
            ->withPivot('notes')
            ->withTimestamps();
    }

    /**
     * Vulnerabilities identified on this asset.
     */
    public function vulnerabilities(): BelongsToMany
    {
        return $this->belongsToMany(Vulnerability::class, 'asset_vulnerability')
            ->withPivot('notes')
            ->withTimestamps();
    }

    // Helpers
    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('asset_id_code');

        if ($last && preg_match('/AST-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'AST-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
