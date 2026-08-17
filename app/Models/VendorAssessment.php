<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorAssessment extends Model
{
    use BelongsToOrganization, HasFactory;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'vendor_id', 'title', 'assessment_type',
        'overall_score', 'status', 'assessed_by', 'assessment_date',
        'findings', 'recommendations', 'next_review_date',
    ];

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'next_review_date' => 'date',
            'overall_score' => 'decimal:2',
        ];
    }

    public const ASSESSMENT_TYPES = ['onboarding', 'periodic', 'incident_driven'];

    public const STATUSES = ['pending', 'in_progress', 'completed'];

    // Relationships
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
