<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Policy extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity, SoftDeletes;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'policy_code', 'title', 'content', 'description',
        'category', 'status', 'version_number', 'owner_id', 'approved_by',
        'approved_at', 'published_at', 'effective_date', 'review_date',
        'expiry_date', 'is_mandatory', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_mandatory' => 'boolean',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'effective_date' => 'date',
            'review_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public const STATUSES = [
        'draft', 'in_review', 'approved', 'published', 'retired',
    ];

    public const CATEGORIES = [
        'information_security' => 'Information Security',
        'acceptable_use' => 'Acceptable Use',
        'data_protection' => 'Data Protection',
        'incident_response' => 'Incident Response',
        'access_control' => 'Access Control',
        'bcdr' => 'BCDR',
        'vendor_management' => 'Vendor Management',
        'other' => 'Other',
    ];

    // Relationships
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class);
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(PolicyAttestation::class);
    }

    // Helpers
    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('policy_code');

        if ($last && preg_match('/POL-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'POL-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
