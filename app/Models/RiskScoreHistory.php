<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskScoreHistory extends Model
{
    use HasTenantIdAlias;

    protected $table = 'risk_scores_history';

    protected $fillable = [
        'risk_id', 'organization_id',
        'inherent_likelihood', 'inherent_impact', 'inherent_score', 'inherent_rating',
        'residual_likelihood', 'residual_impact', 'residual_score', 'residual_rating',
        'change_reason', 'changed_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
