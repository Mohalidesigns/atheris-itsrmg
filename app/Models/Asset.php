<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Ea\BusinessService;
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
        'asset_type', 'category', 'criticality', 'criticality_inherited', 'status',
        'source', 'external_id', 'last_seen_at',
        'owner_id', 'custodian_id', 'department', 'location', 'ip_address',
        'hostname', 'vendor', 'version', 'license_type',
        'data_classification', 'purchase_date', 'end_of_life', 'tags',
        'reviewed_at', 'reviewed_by',
    ];

    protected $appends = ['effective_criticality'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'purchase_date' => 'date',
            'end_of_life' => 'date',
            'last_seen_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public const ASSET_TYPES = [
        'hardware', 'software', 'cloud_service', 'database', 'network', 'facility',
    ];

    public const CRITICALITIES = ['critical', 'high', 'medium', 'low'];

    public const STATUSES = ['active', 'inactive', 'decommissioned', 'under_review'];

    public const DATA_CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'];

    /** Where a register row came from: keyed in by hand, or found by a discovery connector. */
    public const SOURCES = ['manual', 'ad', 'entra', 'aws', 'tenable', 'qualys', 'csv'];

    public const CRITICALITY_RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /** Sortable index columns; anything else falls back to the default order. */
    public const SORTABLE = ['asset_id_code', 'name', 'asset_type', 'criticality', 'status', 'department', 'end_of_life', 'last_seen_at', 'created_at'];

    // Relationships
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The EA business services this asset supports (GRC-side pivot, see the 2027_01_16 migration). */
    public function businessServices(): BelongsToMany
    {
        return $this->belongsToMany(BusinessService::class, 'asset_business_service', 'asset_id', 'business_service_id')
            ->withPivot('role', 'organization_id')
            ->withTimestamps();
    }

    /**
     * The higher of the owner's own rating and the criticality inherited from the services the
     * asset supports. This is what downstream scoring (vulnerability prioritiser) should read.
     */
    public function getEffectiveCriticalityAttribute(): string
    {
        return self::higherCriticality($this->criticality, $this->criticality_inherited) ?? 'medium';
    }

    public static function higherCriticality(?string $a, ?string $b): ?string
    {
        return (self::CRITICALITY_RANK[$a] ?? 0) >= (self::CRITICALITY_RANK[$b] ?? 0) ? ($a ?? $b) : $b;
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

    /**
     * Next code in the bank's own series. Seeded registers use "{BANK}-AST-{segment}-NNN"
     * (KHB-AST-CB-001); records added in the app continue "{BANK}-AST-NNNN" so they never collide
     * with a seeded segment. Soft-deleted rows count, so a code is never reissued.
     */
    public static function generateNextCode(int $organizationId): string
    {
        $codes = static::withoutGlobalScopes()->where('organization_id', $organizationId)->pluck('asset_id_code');

        $bank = $codes->map(fn ($c) => preg_match('/^([A-Z0-9]+)-AST-/', (string) $c, $m) ? $m[1] : null)->filter()->first();
        $prefix = $bank ? "{$bank}-AST-" : 'AST-';

        $max = $codes->map(fn ($c) => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) $c, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            // A nightly connector run re-confirming an asset is not a change to it.
            ->logExcept(['criticality_inherited'])
            ->dontLogIfAttributesChangedOnly(['last_seen_at', 'updated_at'])
            ->dontLogEmptyChanges();
    }
}
