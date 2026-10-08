<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Gap extends Model
{
    use BelongsToOrganization, LogsActivity;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'assessment_id', 'requirement_id', 'control_id',
        'gap_code', 'title', 'description', 'severity', 'status',
        'remediation_plan', 'assigned_to', 'due_date', 'completed_at',
        'priority', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'date',
        ];
    }

    public const STATUSES = [
        'identified', 'remediation_planned', 'in_progress',
        'remediated', 'accepted', 'closed',
    ];

    /** Remediated, risk-accepted and closed gaps no longer need work. */
    public const RESOLVED_STATUSES = ['remediated', 'accepted', 'closed'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /** Severity drives the default remediation priority (1 = most urgent). */
    public const SEVERITY_PRIORITY = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];

    protected $appends = ['is_overdue'];

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', self::RESOLVED_STATUSES);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast() && ! $this->due_date->isToday()
            && ! in_array($this->status, self::RESOLVED_STATUSES, true);
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'evidenceable');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ComplianceAssessment::class, 'assessment_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'requirement_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Next code in the organisation's sequence, keeping its prefix (e.g. KHB-GAP-016 after KHB-GAP-015). */
    public static function generateNextCode(int $organizationId): string
    {
        $codes = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->pluck('gap_code');

        $prefix = 'GAP-';
        $width = 4;
        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/^(.*?)(\d+)$/', (string) $code, $m) && (int) $m[2] >= $max) {
                [$max, $prefix, $width] = [(int) $m[2], $m[1], strlen($m[2])];
            }
        }

        return $prefix.str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
