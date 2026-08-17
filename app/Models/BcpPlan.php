<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BcpPlan extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'plan_code', 'title', 'description',
        'plan_type', 'status', 'owner_id', 'version',
        'scope', 'objectives', 'rto_hours', 'rpo_hours',
        'last_tested', 'next_test_date', 'next_review_date',
        'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'last_tested' => 'date',
            'next_test_date' => 'date',
            'next_review_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public const PLAN_TYPES = ['bcp', 'dr', 'crisis'];

    public const STATUSES = ['draft', 'active', 'under_review', 'tested', 'expired'];

    // Relationships
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(BcpTest::class, 'plan_id');
    }

    // Helpers
    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('plan_code');

        if ($last && preg_match('/BCP-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'BCP-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
