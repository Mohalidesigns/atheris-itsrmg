<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Vendor extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'vendor_code', 'name', 'description',
        'category', 'risk_level', 'status', 'contact_name',
        'contact_email', 'contact_phone', 'website', 'country',
        'services_provided', 'contract_start', 'contract_end',
        'contract_value', 'contract_currency', 'data_access_level',
        'sla_details', 'last_assessed', 'next_review_date', 'tags',
        'owner_id', 'tier', 'domain', 'processes_personal_data', 'dpa_signed_at',
        'material_outsourcing', 'regulator_notified_at', 'shared_directory_id',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'contract_start' => 'date',
            'contract_end' => 'date',
            'contract_value' => 'decimal:2',
            'last_assessed' => 'date',
            'next_review_date' => 'date',
            'dpa_signed_at' => 'date',
            'regulator_notified_at' => 'date',
            'processes_personal_data' => 'boolean',
            'material_outsourcing' => 'boolean',
            'tier' => 'integer',
        ];
    }

    public const RISK_LEVELS = ['critical', 'high', 'medium', 'low'];

    public const STATUSES = ['active', 'inactive', 'under_review', 'terminated'];

    public const DATA_ACCESS_LEVELS = ['none', 'limited', 'full'];

    public const TIERS = [1 => 'Tier 1 — critical', 2 => 'Tier 2 — important', 3 => 'Tier 3 — standard'];

    /** Sortable index columns; anything else falls back to the default order. */
    public const SORTABLE = ['vendor_code', 'name', 'category', 'risk_level', 'tier', 'status', 'contract_end', 'last_assessed', 'next_review_date'];

    // Relationships
    public function assessments(): HasMany
    {
        return $this->hasMany(VendorAssessment::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function directoryEntry(): BelongsTo
    {
        return $this->belongsTo(SharedVendorDirectory::class, 'shared_directory_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(TprmSecurityRating::class);
    }

    public function latestRating(): HasOne
    {
        return $this->hasOne(TprmSecurityRating::class)->latestOfMany('captured_at');
    }

    public function breachEvents(): HasMany
    {
        return $this->hasMany(TprmBreachEvent::class);
    }

    /** A processor of personal data with no signed data-processing agreement (NDPA 2023 s.29). */
    public function needsDpa(): bool
    {
        return $this->processes_personal_data && ! $this->dpa_signed_at && $this->status !== 'terminated';
    }

    // Helpers

    /**
     * Next code in the bank's own series ("KHB-VEN-036"). Reference-pack vendors carry "REF-…"
     * codes and are skipped when working out the series. Codes are never reissued.
     */
    public static function generateNextCode(int $organizationId): string
    {
        $codes = static::withoutGlobalScopes()->where('organization_id', $organizationId)->pluck('vendor_code');

        [$prefix, $max, $width] = [null, 0, 4];
        foreach ($codes as $code) {
            if (preg_match('/^(.*-VEN-|VND-)(\d+)$/', (string) $code, $m) && (int) $m[2] >= $max) {
                [$prefix, $max, $width] = [$m[1], (int) $m[2], strlen($m[2])];
            }
        }

        return ($prefix ?? 'VND-').str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
