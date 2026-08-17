<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('gap_code');

        if ($last && preg_match('/GAP-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'GAP-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
