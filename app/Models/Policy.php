<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'submitted_by', 'submitted_at', 'retired_at', 'status_note',
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
            'submitted_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /** Lifecycle (PolicyWorkflow): draft → in_review → approved → active → retired. */
    public const STATUSES = [
        'draft', 'in_review', 'approved', 'active', 'retired',
    ];

    /** Sortable index columns; anything else falls back to the default order. */
    public const SORTABLE = ['policy_code', 'title', 'category', 'status', 'version_number', 'effective_date', 'review_date', 'updated_at'];

    public const CATEGORIES = [
        'information_security' => 'Information Security',
        'acceptable_use' => 'Acceptable Use',
        'data_protection' => 'Data Protection',
        'incident_response' => 'Incident Response',
        'access_control' => 'Access Control',
        'governance' => 'Governance',
        'risk_management' => 'Risk Management',
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

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** The controls that implement this policy (Control Standards). */
    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class, 'control_policy')->withPivot('clause', 'organization_id')->withTimestamps();
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(PolicyException::class);
    }

    public function isReviewOverdue(): bool
    {
        return $this->status === 'active' && $this->review_date && $this->review_date->lt(today());
    }

    // Helpers

    /** Next code in the bank's own series ("KHB-POL-026"); never reissued. */
    public static function generateNextCode(int $organizationId): string
    {
        $codes = static::withoutGlobalScopes()->where('organization_id', $organizationId)->pluck('policy_code');

        [$prefix, $max, $width] = [null, 0, 4];
        foreach ($codes as $code) {
            if (preg_match('/^(.*POL-)(\d+)$/', (string) $code, $m) && (int) $m[2] >= $max) {
                [$prefix, $max, $width] = [$m[1], (int) $m[2], strlen($m[2])];
            }
        }

        return ($prefix ?? 'POL-').str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
