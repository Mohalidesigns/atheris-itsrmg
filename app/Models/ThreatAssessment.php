<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreatAssessment extends Model
{
    use BelongsToOrganization;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'threat_id', 'risk_id', 'assessed_by',
        'likelihood', 'impact', 'score', 'analysis',
        'recommendations', 'assessment_date',
    ];

    protected function casts(): array
    {
        return ['assessment_date' => 'date'];
    }

    public function threat(): BelongsTo
    {
        return $this->belongsTo(Threat::class);
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
