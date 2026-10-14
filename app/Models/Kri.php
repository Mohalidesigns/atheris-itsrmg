<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A key risk indicator (ATH-VAL-014): thresholds read in the KRI's direction, an owner, the risk
 * it indicates, and where its readings come from (recorded by hand, measured from platform data,
 * or published from EA).
 */
class Kri extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['threshold_green' => 'float', 'threshold_amber' => 'float', 'threshold_red' => 'float'];

    public const FREQUENCIES = ['weekly' => 7, 'monthly' => 31, 'quarterly' => 92];

    public const SOURCES = ['manual' => 'Recorded by the owner', 'platform' => 'Measured from platform data', 'ea' => 'Published from EA'];

    public function readings()
    {
        return $this->hasMany(KriReading::class);
    }

    public function breaches()
    {
        return $this->hasMany(KriBreach::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function risk()
    {
        return $this->belongsTo(Risk::class);
    }

    public function latestReading()
    {
        return $this->hasOne(KriReading::class)->latestOfMany('recorded_at');
    }

    public function openBreach()
    {
        return $this->hasOne(KriBreach::class)->whereNull('resolved_at')->latestOfMany('occurred_at');
    }

    /** green / amber / red for a value, read in the KRI's direction. */
    public function statusFor(float $value): string
    {
        if ($this->direction === 'lower_worse') {
            return $value >= $this->threshold_green ? 'green' : ($value >= $this->threshold_amber ? 'amber' : 'red');
        }

        return $value <= $this->threshold_green ? 'green' : ($value <= $this->threshold_amber ? 'amber' : 'red');
    }

    /** A value with its unit, as people read it: 7, 99.5%, 72h, ₦6m. */
    public function formatValue(float|int|null $value): string
    {
        $n = rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');

        return match ($this->unit) {
            'count' => $n,
            '%' => "{$n}%",
            'hours' => "{$n}h",
            'NGN_M' => "₦{$n}m",
            default => "{$n} {$this->unit}",
        };
    }

    /** The reporting period a date falls in for this KRI's frequency. */
    public function periodFor($date): string
    {
        $d = now()->parse($date);

        return match ($this->frequency) {
            'monthly' => $d->format('Y-m'),
            'quarterly' => $d->format('Y').'-Q'.$d->quarter,
            default => $d->format('o-\WW'),
        };
    }

    /** No reading within the KRI's reporting frequency. */
    public function isStale(): bool
    {
        $last = $this->latestReading?->recorded_at;

        return ! $last || $last->lt(now()->subDays(self::FREQUENCIES[$this->frequency] ?? 7));
    }

    /** Issue::SOURCES reads a title from the source record. */
    public function getTitleAttribute(): string
    {
        return $this->name;
    }
}
