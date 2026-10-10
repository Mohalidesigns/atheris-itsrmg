<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

/**
 * A regulator circular. organization_id NULL is the platform feed curated by Content-Ops; a set
 * organization_id is a circular a bank ingested privately through Document Intelligence.
 */
class RegulatoryCircular extends Model
{
    use BelongsToTenant;

    /** Platform circulars reach tenants only after Content-Ops editorial review. */
    public const TENANT_VISIBLE = ['published', 'superseded'];

    public const REGULATORS = ['CBN', 'NDPC', 'SEC', 'NCC', 'NAICOM', 'PENCOM', 'NDIC'];

    protected $guarded = [];

    protected $casts = [
        'impact_assessment' => 'array',
        'issued_at' => 'date:Y-m-d',
        'ingested_at' => 'datetime',
    ];

    /** What the signed-in user may see: published platform circulars plus their own bank's. */
    public function scopeVisibleToTenant(Builder $query): Builder
    {
        if (Auth::user()?->hasRole('Super Admin')) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNotNull('organization_id')
            ->orWhereIn('status', self::TENANT_VISIBLE));
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(CircularAssessment::class, 'circular_id');
    }

    /** The signed-in bank's own triage of this circular (strictly org-scoped by the model). */
    public function assessment(): HasOne
    {
        return $this->hasOne(CircularAssessment::class, 'circular_id');
    }

    public function impacts(): HasMany
    {
        return $this->hasMany(CircularImpact::class, 'circular_id');
    }

    public function isPrivate(): bool
    {
        return $this->organization_id !== null;
    }
}
