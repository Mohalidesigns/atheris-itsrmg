<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Survey — B2, the definition half of the campaign engine.
 *
 * ATH-EAR-002 §3.3 studies Ardoq Broadcasts as "the single most valuable thing
 * to copy": audiences resolved from graph references, staleness triggers such
 * as "not updated in 6 months", recurring schedules, and automated reminders at
 * 7–14 day intervals. §4 row 13 scores Atheris 0 against Ardoq's 4.
 *
 * A survey is a template. Each execution is a SurveyCampaign, so the same
 * definition can run quarterly without its history being overwritten.
 */
class Survey extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_surveys';

    protected $guarded = [];

    protected $casts = [
        'scope_filter' => 'array',
        'fields' => 'array',
        'audience_roles' => 'array',
        'additional_recipients' => 'array',
        'is_active' => 'boolean',
        'next_run_at' => 'datetime',
    ];

    public const CADENCES = ['once', 'weekly', 'monthly', 'quarterly', 'annually'];

    public function campaigns()
    {
        return $this->hasMany(SurveyCampaign::class, 'survey_id');
    }

    public function latestCampaign()
    {
        return $this->hasOne(SurveyCampaign::class, 'survey_id')->latestOfMany();
    }

    public function isRecurring(): bool
    {
        return $this->cadence !== 'once';
    }

    /** How far forward the next run sits, given the cadence. */
    public function advanceFrom(CarbonInterface $from): ?CarbonInterface
    {
        return match ($this->cadence) {
            'weekly' => $from->copy()->addWeek(),
            'monthly' => $from->copy()->addMonth(),
            'quarterly' => $from->copy()->addMonths(3),
            'annually' => $from->copy()->addYear(),
            default => null,
        };
    }
}
