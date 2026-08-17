<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Site — ATH-EAR-002 A5, §8.1.
 *
 * §6.3: "Nigeria has **28 data centres, 21 of them in Lagos** — severe
 * geographic concentration for DR purposes. Power is an under-acknowledged
 * cause of payment failure and was **entirely absent from stakeholder
 * checklists** for achieving PSV 2028 targets. The ITSB adopts **TIA-942** for
 * data centre tiering. A DR architecture that ignores diesel is fiction in this
 * market."
 *
 * No global EA tool models generator autonomy, fuel dependency or grid zone,
 * because in the markets they sell into those are not the binding constraint.
 * Here they are.
 */
class Site extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_sites';

    protected $guarded = [];

    protected $casts = [
        'power_sources' => 'array',
        'connectivity_providers' => 'array',
        'certifications' => 'array',
        'is_sovereign' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public const SITE_TYPES = ['owned', 'colocation', 'cloud_region', 'branch', 'office'];

    public const RISK_BANDS = ['low', 'medium', 'high'];

    public function applications()
    {
        return $this->hasMany(EaApplication::class, 'hosting_site_id');
    }

    public function drApplications()
    {
        return $this->hasMany(EaApplication::class, 'dr_site_id');
    }

    public function instances()
    {
        return $this->hasMany(ApplicationInstance::class, 'hosting_site_id');
    }

    /**
     * Whether this site can carry a workload the localisation directive
     * constrains. §6.3 A2: the 15 June 2026 circular requires Nigerian payment
     * transaction data to be stored and managed **within Nigeria**, affecting
     * primary *and* disaster recovery infrastructure, by 1 January 2027.
     */
    public function isNigerian(): bool
    {
        return $this->country === 'NG';
    }

    /**
     * A resilience score that reflects what actually fails here.
     *
     * TIA-942 tier alone overstates readiness in a market where the grid is the
     * binding constraint — a Tier III facility with four hours of diesel is
     * less resilient in practice than a Tier II with seventy-two.
     */
    public function resilienceScore(): int
    {
        $score = 0;

        $score += match ((int) $this->tia942_tier) {
            4 => 40, 3 => 30, 2 => 18, 1 => 8, default => 0,
        };

        $score += match (true) {
            $this->generator_autonomy_hours >= 72 => 30,
            $this->generator_autonomy_hours >= 48 => 24,
            $this->generator_autonomy_hours >= 24 => 16,
            $this->generator_autonomy_hours >= 8 => 8,
            default => 0,
        };

        $score += match ($this->grid_reliability_band) {
            'high' => 12, 'medium' => 7, 'low' => 2, default => 0,
        };

        $score += match ($this->flood_risk) {
            'low' => 10, 'medium' => 5, 'high' => 0, default => 0,
        };

        $score += min(8, count($this->connectivity_providers ?? []) * 4);

        return min(100, $score);
    }

    public function resilienceBand(): string
    {
        $score = $this->resilienceScore();

        return match (true) {
            $score >= 75 => 'strong',
            $score >= 50 => 'adequate',
            $score >= 25 => 'weak',
            default => 'inadequate',
        };
    }

    /** Hours a workload here survives a total grid outage. */
    public function outageAutonomyHours(): float
    {
        return (float) ($this->generator_autonomy_hours ?? 0)
            + round(($this->ups_autonomy_minutes ?? 0) / 60, 2);
    }
}
