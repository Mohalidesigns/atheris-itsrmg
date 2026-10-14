<?php

namespace App\Models;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A time-bound, risk-accepted departure from a policy (or one control implementing it).
 * requested → approved | rejected; approved → revoked, or expired once its end date passes.
 */
class PolicyException extends Model
{
    use BelongsToOrganization, GeneratesSequentialCode, LogsActivity;

    public const STATUSES = ['requested', 'approved', 'rejected', 'revoked', 'expired'];

    public const RISK_RATINGS = ['critical', 'high', 'medium', 'low'];

    /** Longest an exception may run, by risk rating (months). */
    public const MAX_MONTHS = ['critical' => 3, 'high' => 6, 'medium' => 12, 'low' => 12];

    protected $guarded = [];

    protected $casts = ['starts_on' => 'date', 'expires_on' => 'date', 'decided_at' => 'datetime'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public static function generateNextCode(int $organizationId): string
    {
        $bank = Policy::withoutGlobalScopes()->where('organization_id', $organizationId)
            ->pluck('policy_code')->map(fn ($c) => preg_match('/^([A-Z0-9]+)-POL-/', (string) $c, $m) ? $m[1] : null)->filter()->first();

        return static::nextSequentialCode($organizationId, 'exception_code', $bank ? "{$bank}-EXC-" : 'EXC-');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'expires_on', 'risk_rating', 'decision_notes'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
