<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        ];
    }

    public const RISK_LEVELS = ['critical', 'high', 'medium', 'low'];

    public const STATUSES = ['active', 'inactive', 'under_review', 'terminated'];

    public const DATA_ACCESS_LEVELS = ['none', 'limited', 'full'];

    // Relationships
    public function assessments(): HasMany
    {
        return $this->hasMany(VendorAssessment::class);
    }

    // Helpers
    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('vendor_code');

        if ($last && preg_match('/VND-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'VND-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
