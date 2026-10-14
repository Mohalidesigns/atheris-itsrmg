<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /** The business services this plan recovers. */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Ea\BusinessService::class, 'bcp_plan_service', 'plan_id', 'business_service_id')
            ->withPivot('organization_id')->withTimestamps();
    }

    // Helpers
    /** Next code in the bank's own series ("KHB-BCP-011"); never reissued. */
    public static function generateNextCode(int $organizationId): string
    {
        $codes = static::withoutGlobalScopes()->where('organization_id', $organizationId)->pluck('plan_code');

        [$prefix, $max, $width] = [null, 0, 4];
        foreach ($codes as $code) {
            if (preg_match('/^(.*BCP-)(\d+)$/', (string) $code, $m) && (int) $m[2] >= $max) {
                [$prefix, $max, $width] = [$m[1], (int) $m[2], strlen($m[2])];
            }
        }

        return ($prefix ?? 'BCP-').str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
