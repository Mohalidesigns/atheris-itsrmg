<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SurveyCampaign — one execution of a Survey against a point-in-time audience.
 *
 * The audience is snapshotted at launch so a completion rate stays meaningful
 * after subscriptions change. Ardoq tracks surveys sent, recipients and
 * submission rate; §9 WS 1.2 requires a completion dashboard, and R5 makes
 * completion analytics a day-one mitigation for the risk that recipients simply
 * ignore magic links.
 */
class SurveyCampaign extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_survey_campaigns';

    protected $guarded = [];

    protected $casts = [
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'audience_snapshot' => 'array',
    ];

    public const RUNNING = 'running';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public function survey()
    {
        return $this->belongsTo(Survey::class, 'survey_id');
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class, 'campaign_id');
    }

    public function isOpen(): bool
    {
        return $this->state === self::RUNNING
            && ($this->closes_at === null || $this->closes_at->isFuture());
    }

    public function completionRate(): float
    {
        if (! $this->recipients_count) {
            return 0.0;
        }

        return round($this->responses_count / $this->recipients_count * 100, 1);
    }

    public function daysRemaining(): ?int
    {
        if (! $this->closes_at) {
            return null;
        }

        return (int) round(now()->diffInDays($this->closes_at, false));
    }
}
